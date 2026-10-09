<?php

namespace App\Services\Automation;

use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\NetworkDevice;
use App\Models\Plan;
use App\Models\Repeater;
use App\Models\Ticket;
use App\Models\Zone;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo para el constructor visual de reglas  Evento → Condición → Acción:
 * sujeto de cada evento, campos evaluables (hechos de AutomationEngine::facts()),
 * operadores, parámetros por acción, descripciones legibles y plantillas de ejemplo.
 */
class RuleCatalog
{
    public const OPERATORS = [
        '=' => 'es igual a',
        '!=' => 'es distinto de',
        '>' => 'mayor que',
        '>=' => 'mayor o igual a',
        '<' => 'menor que',
        '<=' => 'menor o igual a',
        'in' => 'es alguno de',
        'contains' => 'contiene',
    ];

    public const SUBJECTS = [
        'client' => 'Cliente',
        'repeater' => 'Repetidor',
        'device' => 'Equipo',
        'ticket' => 'Ticket',
        'inventory_item' => 'Artículo de inventario',
    ];

    /** Acciones que necesitan un cliente (el sujeto o el cliente del ticket). */
    public const CLIENT_ACTIONS = ['suspend_service', 'reconnect_service', 'send_message', 'add_late_fee', 'set_status'];

    public const ACTION_ICONS = [
        'suspend_service' => 'no-symbol',
        'reconnect_service' => 'arrow-path',
        'send_message' => 'chat-bubble-left-right',
        'notify_staff' => 'bell-alert',
        'create_ticket' => 'ticket',
        'assign_on_call' => 'user-plus',
        'create_alert' => 'exclamation-triangle',
        'add_late_fee' => 'currency-dollar',
        'set_status' => 'tag',
    ];

    public const PLACEHOLDERS = ['{cliente}', '{codigo}', '{repetidor}', '{afectados}', '{zona}', '{saldo}', '{folio}'];

    public static function subjectFor(string $event): string
    {
        return match ($event) {
            'repeater_down', 'repeater_saturated' => 'repeater',
            'device_offline' => 'device',
            'ticket_created', 'ticket_sla_risk' => 'ticket',
            'low_stock' => 'inventory_item',
            default => 'client',
        };
    }

    public static function eventIcon(string $event): string
    {
        return match (static::subjectFor($event)) {
            'repeater' => 'signal',
            'device' => 'server',
            'ticket' => 'ticket',
            'inventory_item' => 'archive-box',
            default => in_array($event, ['payment_overdue', 'payment_due_soon', 'payment_received']) ? 'banknotes' : 'user',
        };
    }

    /**
     * Campos evaluables por evento: field => [label, type(enum|number|bool|text), options[]].
     */
    public static function fields(string $event): array
    {
        $enum = fn (string $catalog) => Catalog::options($catalog);

        $fields = match (static::subjectFor($event)) {
            'repeater' => [
                'affected_clients' => ['Clientes afectados', 'number', []],
                'utilization' => ['Utilización (%)', 'number', []],
                'zone_id' => ['Zona', 'enum', static::zones()],
                'status' => ['Estado del repetidor', 'enum', $enum('device_status')],
            ],
            'ticket' => [
                'priority' => ['Prioridad', 'enum', $enum('ticket_priorities')],
                'category' => ['Categoría', 'enum', $enum('ticket_categories')],
                'status' => ['Estado del ticket', 'enum', $enum('ticket_status')],
            ],
            'device' => [
                'type' => ['Tipo de equipo', 'enum', $enum('device_types')],
                'status' => ['Estado', 'enum', $enum('device_status')],
                'repeater_id' => ['Repetidor', 'enum', static::repeaters()],
                'latency_ms' => ['Latencia (ms)', 'number', []],
                'packet_loss' => ['Pérdida de paquetes (%)', 'number', []],
                'signal_dbm' => ['Señal (dBm)', 'number', []],
            ],
            'inventory_item' => [
                'stock' => ['Existencia', 'number', []],
                'min_stock' => ['Mínimo', 'number', []],
                'category' => ['Categoría', 'enum', $enum('inventory_categories')],
            ],
            default => [
                'service_status' => ['Estado del servicio', 'enum', $enum('client_status')],
                'balance' => ['Saldo', 'number', []],
                'overdue_days' => ['Días de atraso', 'number', []],
                'risk_level' => ['Riesgo de morosidad', 'enum', $enum('risk_levels')],
                'risk_score' => ['Puntaje de riesgo (0-100)', 'number', []],
                'zone_id' => ['Zona', 'enum', static::zones()],
                'plan_id' => ['Plan', 'enum', static::plans()],
                'repeater_id' => ['Repetidor', 'enum', static::repeaters()],
                'online' => ['En línea', 'bool', []],
            ],
        };

        if ($event === 'payment_received') {
            $fields += [
                'amount' => ['Monto del pago', 'number', []],
                'method' => ['Método de pago', 'enum', $enum('payment_methods')],
            ];
        }

        return $fields;
    }

    /** Valores de contexto que el evento aporta al dispararse (se piden en la prueba). */
    public static function contextFields(string $event): array
    {
        return match ($event) {
            'payment_overdue' => ['overdue_days' => 'Días de atraso'],
            'payment_received' => ['amount' => 'Monto del pago', 'method' => 'Método (clave)', 'folio' => 'Folio'],
            'repeater_down' => ['affected_clients' => 'Clientes afectados'],
            default => [],
        };
    }

    public static function actionDefaults(string $type): array
    {
        return match ($type) {
            'send_message' => ['event' => 'pending', 'channels' => []],
            'notify_staff' => ['roles' => ['admin', 'supervisor'], 'message' => ''],
            'create_ticket' => ['category' => 'sin_internet', 'priority' => 'alta', 'subject' => '', 'description' => ''],
            'create_alert' => ['alert_type' => 'automation', 'severity' => 'warning', 'title' => '', 'message' => ''],
            'set_status' => ['status' => 'en_revision'],
            'suspend_service' => ['notify' => false],
            'reconnect_service' => ['require_zero_balance' => true, 'notify' => true],
            default => [],
        };
    }

    public static function alertTypes(): array
    {
        return ['automation' => 'Automatización'] + config('wisp.alert_types');
    }

    public static function alertTypeLabel(?string $type): string
    {
        return static::alertTypes()[$type] ?? Catalog::label('alert_types', $type);
    }

    public static function staffRoles(): array
    {
        return collect(config('wisp.roles'))->except('superadmin')->all();
    }

    // ------------------------------------------------------------ Descripciones

    public static function fieldLabel(string $event, ?string $field): string
    {
        return static::fields($event)[$field][0] ?? (string) $field;
    }

    public static function valueLabel(string $event, ?string $field, mixed $value): string
    {
        $def = static::fields($event)[$field] ?? null;
        if ($def && $def[1] === 'enum') {
            return collect(explode(',', (string) $value))->map(fn ($v) => $def[2][trim($v)] ?? trim($v))->implode(', ');
        }
        if ($def && $def[1] === 'number' && in_array($field, ['balance', 'amount'])) {
            return money($value);
        }

        return (string) $value;
    }

    public static function describeCondition(string $event, array $c): string
    {
        $field = $c['field'] ?? '';
        $def = static::fields($event)[$field] ?? null;
        if ($def && $def[1] === 'bool') {
            $yes = (($c['operator'] ?? '=') === '=') === ((string) ($c['value'] ?? '1') === '1');

            return $def[0].': '.($yes ? 'sí' : 'no');
        }

        return static::fieldLabel($event, $field).' '.(static::OPERATORS[$c['operator'] ?? '='] ?? $c['operator']).' '.static::valueLabel($event, $field, $c['value'] ?? '');
    }

    public static function describeAction(array $action): string
    {
        $type = $action['type'] ?? '';
        $p = $action['params'] ?? [];
        $label = config("wisp.automation_actions.$type", $type);

        $detail = match ($type) {
            'send_message' => Catalog::label('message_events', $p['event'] ?? 'pending')
                .(! empty($p['channels']) ? ' por '.collect($p['channels'])->map(fn ($c) => config("wisp.channels.$c", $c))->implode(', ') : ''),
            'notify_staff' => collect($p['roles'] ?? ['admin', 'supervisor'])->map(fn ($r) => config("wisp.roles.$r", $r))->implode(', '),
            'create_ticket' => Catalog::label('ticket_priorities', $p['priority'] ?? 'alta').' · '.Catalog::label('ticket_categories', $p['category'] ?? 'sin_internet'),
            'create_alert' => Catalog::label('alert_severity', $p['severity'] ?? 'warning'),
            'set_status' => Catalog::label('client_status', $p['status'] ?? ''),
            'suspend_service' => ! empty($p['notify']) ? 'con aviso' : null,
            'reconnect_service' => ($p['require_zero_balance'] ?? true) ? 'si saldo en cero' : null,
            default => null,
        };

        return $detail ? "$label ($detail)" : $label;
    }

    // ------------------------------------------------------------ Sujetos para pruebas

    public static function searchSubjects(string $type, string $term = '', int $limit = 12): array
    {
        $term = trim($term);
        $like = '%'.$term.'%';

        return match ($type) {
            'repeater' => Repeater::query()->when($term, fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)))
                ->orderBy('code')->limit($limit)->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->code} · {$r->name}"])->all(),
            'device' => NetworkDevice::query()->when($term, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('ip', 'like', $like)->orWhere('mac', 'like', $like)))
                ->whereNotIn('type', ['cpe'])->orderBy('name')->limit($limit)->get()->mapWithKeys(fn ($d) => [$d->id => $d->name.($d->ip ? " · {$d->ip}" : '')])->all(),
            'ticket' => Ticket::query()->when($term, fn ($q) => $q->where(fn ($w) => $w->where('folio', 'like', $like)->orWhere('subject', 'like', $like)))
                ->latest()->limit($limit)->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->folio} · ".str($t->subject)->limit(40)])->all(),
            'inventory_item' => InventoryItem::query()->when($term, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)))
                ->orderBy('name')->limit($limit)->get()->mapWithKeys(fn ($i) => [$i->id => "{$i->sku} · {$i->name}"])->all(),
            default => Client::query()->search($term)->orderBy('code')->limit($limit)->get()
                ->mapWithKeys(fn ($c) => [$c->id => "#{$c->code} · {$c->full_name} · ".Catalog::label('client_status', $c->service_status)])->all(),
        };
    }

    public static function findSubject(string $type, int|string|null $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return match ($type) {
            'repeater' => Repeater::find($id),
            'device' => NetworkDevice::find($id),
            'ticket' => Ticket::find($id),
            'inventory_item' => InventoryItem::find($id),
            default => Client::find($id),
        };
    }

    public static function describeSubject(?Model $s): ?string
    {
        return match (true) {
            $s === null => null,
            $s instanceof Client => "#{$s->code} · {$s->full_name}",
            $s instanceof Repeater => "{$s->code} · {$s->name}",
            $s instanceof Ticket => "{$s->folio} · ".str($s->subject)->limit(40),
            $s instanceof InventoryItem => "{$s->sku} · {$s->name}",
            default => $s->name ?? '#'.$s->getKey(),
        };
    }

    /** URL del sujeto de una ejecución/alerta (mismo mapeo que Alert::subjectUrl). */
    public static function subjectUrl(?string $type, $id): ?string
    {
        $map = ['client' => 'clients.show', 'repeater' => 'network.repeaters.show', 'device' => 'network.devices.show', 'ticket' => 'tickets.show'];
        $route = $map[$type] ?? null;

        return $route && $id && \Illuminate\Support\Facades\Route::has($route) ? route($route, $id) : null;
    }

    // ------------------------------------------------------------ Plantillas (sección 46)

    public static function templates(): array
    {
        $grace = (int) (tenant()?->setting('billing.grace_days', 3) ?? 3);

        return [
            'corte' => [
                'name' => 'Pago vencido: corte y aviso por WhatsApp',
                'description' => 'Pago vencido → el cliente supera los días de tolerancia → suspender servicio en MikroTik → enviar WhatsApp → registro de suspensión en el historial del cliente.',
                'event' => 'payment_overdue',
                'conditions' => [
                    ['field' => 'overdue_days', 'operator' => '>=', 'value' => (string) $grace],
                    ['field' => 'service_status', 'operator' => 'in', 'value' => 'activo,moroso'],
                ],
                'actions' => [
                    ['type' => 'suspend_service', 'params' => ['notify' => false]],
                    ['type' => 'send_message', 'params' => ['event' => 'suspended', 'channels' => ['whatsapp']]],
                ],
            ],
            'repetidor' => [
                'name' => 'Repetidor desconectado: alerta, ticket y técnico de guardia',
                'description' => 'Repetidor desconectado → detectar clientes afectados → alerta crítica → ticket automático → asignar técnico de guardia → notificar al personal.',
                'event' => 'repeater_down',
                'conditions' => [['field' => 'affected_clients', 'operator' => '>=', 'value' => '10']],
                'actions' => [
                    ['type' => 'create_alert', 'params' => ['alert_type' => 'automation', 'severity' => 'critical', 'title' => 'Repetidor {repetidor} fuera de servicio', 'message' => '{afectados} clientes afectados en {zona}.']],
                    ['type' => 'create_ticket', 'params' => ['category' => 'infraestructura', 'priority' => 'critica', 'subject' => 'Falla en repetidor {repetidor}', 'description' => 'Repetidor sin comunicación. {afectados} clientes afectados en {zona}.']],
                    ['type' => 'assign_on_call', 'params' => []],
                    ['type' => 'notify_staff', 'params' => ['roles' => ['admin', 'supervisor', 'soporte'], 'message' => 'Repetidor {repetidor} caído: {afectados} clientes afectados. Ticket asignado al técnico de guardia.']],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------ Opciones

    protected static array $cache = [];

    protected static function zones(): array
    {
        return static::$cache['zones'.tenant()?->id] ??= Zone::orderBy('name')->pluck('name', 'id')->all();
    }

    protected static function plans(): array
    {
        return static::$cache['plans'.tenant()?->id] ??= Plan::orderBy('price')->pluck('name', 'id')->all();
    }

    protected static function repeaters(): array
    {
        return static::$cache['rep'.tenant()?->id] ??= Repeater::orderBy('code')->get()->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name])->all();
    }
}
