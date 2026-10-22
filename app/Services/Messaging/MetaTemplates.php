<?php

namespace App\Services\Messaging;

use App\Models\NotificationTemplate;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Plantillas de WhatsApp en Meta (WhatsApp Business Account del WISP): enviarlas a revisión desde
 * Comunicación → Plantillas y consultar su estado, sin capturarlas a mano en WhatsApp Manager.
 *
 *   app(MetaTemplates::class)->submit($tenant, 'reminder');   // crea "recordatorio_pago" en Meta
 *   app(MetaTemplates::class)->sync($tenant);                 // APPROVED / PENDING / REJECTED
 */
class MetaTemplates
{
    /** Nombre sugerido en Meta por evento (minúsculas, números y guion bajo). */
    public const NAMES = [
        'reminder' => 'recordatorio_pago',
        'due' => 'vencimiento_hoy',
        'pending' => 'pago_pendiente',
        'suspension_warning' => 'aviso_suspension',
        'suspended' => 'servicio_suspendido',
        'payment_received' => 'pago_recibido',
        'reconnected' => 'servicio_reactivado',
        'ticket_created' => 'reporte_recibido',
        'ticket_assigned' => 'reporte_asignado',
        'ticket_updated' => 'reporte_actualizado',
        'technician_on_way' => 'tecnico_en_camino',
        'maintenance' => 'mantenimiento_programado',
        'outage' => 'falla_en_zona',
        'promotion' => 'promocion',
        'welcome' => 'bienvenida',
        'payment_link' => 'enlace_pago',
    ];

    /** Eventos que Meta clasifica como marketing (el resto son de utilidad). */
    public const MARKETING = ['promotion'];

    public const STATUS = [
        'APPROVED' => ['Aprobada', 'success'],
        'PENDING' => ['En revisión', 'warning'],
        'IN_APPEAL' => ['En apelación', 'warning'],
        'REJECTED' => ['Rechazada', 'danger'],
        'PAUSED' => ['Pausada', 'danger'],
        'DISABLED' => ['Deshabilitada', 'danger'],
        'NOT_FOUND' => ['No existe en Meta', 'slate'],
    ];

    /** Valores de ejemplo que Meta pide para revisar cada variable. */
    public const EXAMPLES = [
        'nombre' => 'Juan', 'cliente' => 'Juan Pérez', 'codigo' => '1024', 'plan' => 'Hogar 20 Mbps',
        'monto' => '$450.00', 'saldo' => '$450.00', 'fecha_limite' => '15 de noviembre', 'telefono_empresa' => '5512345678',
        'folio' => 'T-001258', 'tecnico' => 'Luis Gómez', 'fecha' => '15 de noviembre', 'zona' => 'Centro', 'descripcion' => 'En proceso',
    ];

    public static function suggestedName(string $event): string
    {
        return self::NAMES[$event] ?? 'aviso_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($event));
    }

    public static function statusLabel(?string $status): ?array
    {
        return $status ? (self::STATUS[$status] ?? [ucfirst(strtolower($status)), 'slate']) : null;
    }

    /** Configuración de Meta del WISP o error claro si falta algo. */
    protected function config(Tenant $tenant): array
    {
        $cfg = $tenant->settingsFor('messaging');
        if (($cfg['whatsapp_provider'] ?? '') !== 'meta' || empty($cfg['whatsapp_token'])) {
            throw new \RuntimeException('Configura WhatsApp Cloud API (Meta) en Configuración → Mensajería.');
        }
        if (empty($cfg['whatsapp_waba_id'])) {
            throw new \RuntimeException('Captura el WhatsApp Business Account ID en Configuración → Mensajería.');
        }

        return $cfg;
    }

    /**
     * Envía a revisión la plantilla de WhatsApp del evento con su texto actual. Si ya existía en
     * Meta con ese nombre e idioma, solo se consulta su estado. Devuelve la plantilla guardada.
     */
    public function submit(Tenant $tenant, string $event, ?string $name = null): NotificationTemplate
    {
        $cfg = $this->config($tenant);
        $tpl = NotificationTemplate::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenant->id, 'event' => $event, 'channel' => 'whatsapp']);
        $body = $tpl->body ?: DefaultTemplates::body($event);
        if ($warning = self::warning($body)) {
            throw new \RuntimeException($warning);
        }
        $name = $name ?: ($tpl->provider_template && MessageService::isMetaTemplate($tpl->provider_template) ? $tpl->provider_template : self::suggestedName($event));

        $component = ['type' => 'BODY', 'text' => MessageService::providerText($body)];
        if ($vars = MessageService::templateVariables($body)) {
            $component['example'] = ['body_text' => [array_map(fn ($v) => $this->example($tenant, $v), $vars)]];
        }
        $response = Http::withToken((string) $cfg['whatsapp_token'])->timeout(20)
            ->post(MessageService::graphUrl($cfg['whatsapp_waba_id'].'/message_templates'), [
                'name' => $name,
                'language' => $cfg['whatsapp_language'] ?? 'es_MX',
                'category' => in_array($event, self::MARKETING, true) ? 'MARKETING' : 'UTILITY',
                'components' => [$component],
            ]);

        $tpl->fill([
            'body' => $body,
            'active' => $tpl->exists ? $tpl->active : true,
            'provider_template' => $name,
        ]);
        if ($response->successful()) {
            $tpl->fill(['provider_status' => (string) $response->json('status', 'PENDING'), 'provider_note' => null])->save();

            return $tpl;
        }
        // Ya existe en Meta con ese nombre e idioma: se toma su estado actual.
        if (in_array((int) $response->json('error.error_subcode'), [2388024, 2388023], true)) {
            $tpl->save();
            $this->sync($tenant, $tpl);

            return $tpl->refresh();
        }
        // Errores de acceso con la explicación propia; los de contenido, con el detalle que da Meta.
        $code = (int) $response->json('error.code');
        $detail = $response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta respondió '.$response->status();
        throw new \RuntimeException(in_array($code, [0, 3, 10, 190, 368], true) ? MetaErrors::describe($code) : (string) $detail);
    }

    /**
     * Actualiza el estado de las plantillas de WhatsApp del WISP (o solo de $only) consultando Meta.
     * Devuelve cuántas plantillas se revisaron.
     */
    public function sync(Tenant $tenant, ?NotificationTemplate $only = null): int
    {
        $cfg = $this->config($tenant);
        $language = $cfg['whatsapp_language'] ?? 'es_MX';
        $remote = $this->remote($cfg)->filter(fn ($t) => ($t['language'] ?? '') === $language)->keyBy('name');

        $local = $only ? collect([$only]) : NotificationTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('channel', 'whatsapp')->whereNotNull('provider_template')->get();
        foreach ($local as $tpl) {
            $found = $remote->get($tpl->provider_template);
            $reason = $found['rejected_reason'] ?? null;
            $tpl->update([
                'provider_status' => $found['status'] ?? 'NOT_FOUND',
                'provider_note' => $reason && $reason !== 'NONE' ? self::reasonText($reason) : null,
            ]);
        }

        return $local->count();
    }

    /** Plantillas de la cuenta en Meta (todas las páginas, hasta 1,000). */
    public function remote(array $cfg): Collection
    {
        $url = MessageService::graphUrl($cfg['whatsapp_waba_id'].'/message_templates');
        $query = ['fields' => 'name,status,language,category,rejected_reason', 'limit' => 200];
        $all = collect();
        for ($page = 0; $url && $page < 5; $page++) {
            $response = Http::withToken((string) $cfg['whatsapp_token'])->timeout(20)->get($url, $query);
            if ($response->failed()) {
                $code = $response->json('error.code');
                throw new \RuntimeException(MetaErrors::describe($code) ?? ('Meta respondió '.$response->status().': '.($response->json('error.message') ?? '')));
            }
            $all = $all->concat((array) $response->json('data', []));
            $url = $response->json('paging.next');
            $query = [];
        }

        return $all;
    }

    /** Reglas de WhatsApp por las que rechaza una plantilla (null si el texto está bien). */
    public static function warning(string $body): ?string
    {
        $text = trim($body);
        if (preg_match('/^\{[a-z_]+\}/', $text) || preg_match('/\{[a-z_]+\}[\s.!?]*$/u', $text)) {
            return 'WhatsApp no aprueba plantillas que empiezan o terminan con una variable: agrega texto antes o después (p. ej. "Gracias." al final).';
        }
        if (preg_match('/\}\s*\{/', $text)) {
            return 'WhatsApp no aprueba dos variables juntas: sepáralas con texto.';
        }

        return null;
    }

    protected function example(Tenant $tenant, string $var): string
    {
        return match ($var) {
            'empresa' => $tenant->name,
            'link_pago' => MessageCatalog::sampleLink(),
            'link_comprobante' => rtrim(config('app.url'), '/').'/api/v1/receipts/1024?signature=ejemplo',
            default => self::EXAMPLES[$var] ?? 'ejemplo',
        };
    }

    public static function reasonText(string $reason): string
    {
        return match ($reason) {
            'ABUSIVE_CONTENT' => 'Meta consideró el contenido abusivo.',
            'INVALID_FORMAT' => 'Formato inválido (revisa variables y texto).',
            'PROMOTIONAL' => 'Meta la considera promocional: ajusta el texto o usa la categoría de marketing.',
            'TAG_CONTENT_MISMATCH' => 'El texto no corresponde a la categoría elegida.',
            'SCAM' => 'Meta la marcó como posible fraude.',
            default => 'Rechazada por Meta ('.$reason.').',
        };
    }
}
