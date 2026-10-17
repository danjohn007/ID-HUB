<?php

namespace App\Http\Resources;

/** Cliente para personal del WISP (no expone contraseñas). */
class ClientResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'curp' => $this->curp,
            'rfc' => $this->rfc,
            'address' => $this->address,
            'references' => $this->references,
            'colonia' => $this->colonia,
            'community' => $this->community,
            'location' => self::coords($this->lat, $this->lng),
            'navigation_url' => $this->lat && $this->lng ? 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='.$this->lat.','.$this->lng : null,
            'zone' => $this->whenLoaded('zone', fn () => $this->zone ? ['id' => $this->zone->id, 'name' => $this->zone->name] : null),
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? new PlanResource($this->plan) : null),
            'repeater' => $this->whenLoaded('repeater', fn () => $this->repeater ? ['id' => $this->repeater->id, 'code' => $this->repeater->code, 'name' => $this->repeater->name] : null),
            'technician' => $this->whenLoaded('technician', fn () => $this->technician ? new UserSummaryResource($this->technician) : null),
            'collector' => $this->whenLoaded('collector', fn () => $this->collector ? new UserSummaryResource($this->collector) : null),
            'service_status' => self::catalog('client_status', $this->service_status),
            'online' => (bool) $this->online,
            'last_online_at' => self::datetime($this->last_online_at),
            'signal_dbm' => $this->signal_dbm !== null ? (float) $this->signal_dbm : null,
            'month_usage_gb' => (float) $this->month_usage_gb,
            'ip_address' => $this->ip_address,
            'mac_address' => $this->mac_address,
            'pppoe_user' => $this->pppoe_user,
            'billing_day' => (int) $this->billing_day,
            'balance' => self::money($this->balance),
            'credit_balance' => self::money($this->credit_balance),
            'next_due_date' => self::date($this->next_due_date),
            'risk' => ['level' => $this->risk_level, 'score' => (int) $this->risk_score],
            'referral_code' => $this->referral_code,
            'installation_date' => self::date($this->installation_date),
            'suspended_at' => self::datetime($this->suspended_at),
            'notes' => $this->notes,
            'created_at' => self::datetime($this->created_at),
            'updated_at' => self::datetime($this->updated_at),
        ];
    }
}
