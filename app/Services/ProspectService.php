<?php

namespace App\Services;

use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\Prospect;
use App\Models\Repeater;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prospectos (secciones 19, 20, 40, 53 "Alta de cliente"): validación de cobertura,
 * cotización / orden de instalación y conversión a cliente.
 */
class ProspectService
{
    /** Estados en los que el prospecto sigue "abierto" (demanda vigente). */
    public const OPEN = ['nuevo', 'contactado', 'viable', 'sin_cobertura', 'instalacion_pendiente'];

    /**
     * Calcula cobertura (repetidor más cercano, distancia, cobertura) y ajusta el estado:
     * sin cobertura → sin_cobertura; si vuelve a tener cobertura → viable.
     */
    public function applyCoverage(Prospect $prospect): ?array
    {
        if (! $prospect->lat || ! $prospect->lng) {
            return null;
        }
        $cov = app(CoverageService::class)->check((float) $prospect->lat, (float) $prospect->lng);
        $prospect->nearest_repeater_id = $cov['repeater']?->id;
        $prospect->distance_km = $cov['distance'];
        $prospect->has_coverage = (bool) $cov['has_coverage'];
        if (! $prospect->zone_id && $cov['repeater']?->zone_id) {
            $prospect->zone_id = $cov['repeater']->zone_id;
        }
        if (! $cov['has_coverage'] && in_array($prospect->status, ['nuevo', 'contactado', 'viable'], true)) {
            $prospect->status = 'sin_cobertura';
        } elseif ($cov['has_coverage'] && $prospect->status === 'sin_cobertura') {
            $prospect->status = 'viable';
        }

        return $cov;
    }

    /** URL de WhatsApp (wa.me) con mensaje inicial. */
    public function whatsappUrl(Prospect $prospect, ?string $text = null): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($prospect->whatsapp ?: $prospect->phone));
        if (! $digits) {
            return null;
        }
        if (strlen($digits) === 10) {
            $digits = '52'.$digits;
        }
        $text ??= 'Hola '.Str::of($prospect->name)->before(' ').', te saluda '.(tenant()?->name ?? 'tu proveedor de Internet')
            .'. Recibimos tu solicitud de servicio de Internet, ¿podemos ayudarte a contratar?';

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($text);
    }

    public function markContacted(Prospect $prospect): void
    {
        $prospect->contacted_at = now();
        if ($prospect->status === 'nuevo') {
            $prospect->status = 'contactado';
        }
        $prospect->save();
    }

    /** Partidas de cotización por defecto: instalación del plan (+ descuento de la promoción). */
    public function defaultQuote(?Plan $plan, ?Promotion $promotion = null): array
    {
        $fee = (float) ($plan?->installation_fee ?? 0);
        $items = [['concepto' => 'Instalación de servicio', 'importe' => $fee]];
        if ($promotion && $promotion->type === 'instalacion_gratis' && $fee > 0) {
            $items[] = ['concepto' => 'Promoción: '.$promotion->name, 'importe' => -$fee];
        }

        return $items;
    }

    /** Crea la orden de instalación (etapa de cotización) a partir del prospecto. */
    public function createOrder(Prospect $prospect, array $attrs = []): InstallationOrder
    {
        $plan = Plan::find($attrs['plan_id'] ?? $prospect->desired_plan_id) ?? Plan::where('active', true)->orderBy('price')->first();
        $promotion = ! empty($attrs['promotion_id']) ? Promotion::find($attrs['promotion_id']) : null;
        $items = $this->defaultQuote($plan, $promotion);

        $order = InstallationOrder::create([
            'folio' => next_folio('installation_orders', 'OI', 'folio', 5),
            'prospect_id' => $prospect->id,
            'client_id' => $prospect->client_id,
            'plan_id' => $plan?->id,
            'repeater_id' => $attrs['repeater_id'] ?? $prospect->nearest_repeater_id,
            'promotion_id' => $promotion?->id,
            'technician_id' => $attrs['technician_id'] ?? null,
            'status' => 'cotizacion',
            'quote_items' => $items,
            'quote_amount' => max(0, collect($items)->sum('importe')),
            'checklist' => [],
            'notes' => $attrs['notes'] ?? null,
        ]);

        if (in_array($prospect->status, ['nuevo', 'contactado', 'viable', 'sin_cobertura'], true)) {
            $prospect->update(['status' => 'instalacion_pendiente']);
        }

        return $order;
    }

    /**
     * Convierte el prospecto en cliente (estado "instalación pendiente" salvo que se indique otro).
     * Registra el referido si el prospecto llegó con código.
     */
    public function convertToClient(Prospect $prospect, array $overrides = []): Client
    {
        if ($prospect->client_id && ($existing = Client::find($prospect->client_id))) {
            return $existing;
        }

        return DB::transaction(function () use ($prospect, $overrides) {
            $repeater = Repeater::find($overrides['repeater_id'] ?? $prospect->nearest_repeater_id);
            $name = trim((string) $prospect->name);
            $first = Str::of($name)->before(' ')->toString() ?: $name;
            $last = trim(Str::of($name)->after($first)->toString()) ?: null;
            $code = $this->nextClientCode();

            $client = Client::create(array_merge([
                'code' => $code,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $prospect->phone,
                'whatsapp' => $prospect->whatsapp ?: $prospect->phone,
                'email' => $prospect->email,
                'address' => $prospect->address,
                'lat' => $prospect->lat,
                'lng' => $prospect->lng,
                'zone_id' => $prospect->zone_id ?? $repeater?->zone_id,
                'plan_id' => $prospect->desired_plan_id,
                'repeater_id' => $repeater?->id,
                'router_device_id' => $repeater?->router_device_id,
                'pppoe_user' => 'c'.$code,
                'pppoe_password' => Str::lower(Str::random(8)),
                'service_status' => 'instalacion_pendiente',
                'billing_day' => (int) tenant()->setting('billing.billing_day', 1),
                'notes' => $prospect->notes,
            ], array_filter($overrides, fn ($v) => $v !== null)));

            Audit::log('created', "convirtió prospecto {$prospect->name} en cliente #{$client->code}", $client);
            $referrals = app(ReferralService::class);
            $referrals->ensureCode($client);
            if ($prospect->referral_code) {
                $referrals->register($client, $prospect->referral_code, $prospect->id);
            }

            $prospect->update(['status' => 'convertido', 'converted_at' => now(), 'client_id' => $client->id]);

            return $client;
        });
    }

    public function nextClientCode(): string
    {
        $max = (int) Client::withTrashed()->max(DB::raw('CAST(code AS UNSIGNED)'));

        return (string) max(1001, $max + 1);
    }
}
