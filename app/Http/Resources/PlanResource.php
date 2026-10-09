<?php

namespace App\Http\Resources;

class PlanResource extends ApiResource
{
    public function toArray($request): array
    {
        $price = $this->priceBreakdown();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'download_mbps' => (float) $this->download_mbps,
            'upload_mbps' => (float) $this->upload_mbps,
            'speed_label' => $this->speedLabel(),
            'price' => self::money($this->price),
            'price_total' => $price['total'],
            'price_label' => money($price['total']),
            'tax_rate' => (float) $this->tax_rate,
            'price_includes_tax' => (bool) $this->price_includes_tax,
            'periodicity' => $this->periodicity,
            'periodicity_label' => $this->periodicityLabel(),
            'data_cap_gb' => $this->data_cap_gb,
            'installation_fee' => self::money($this->installation_fee),
            'reconnection_fee' => self::money($this->reconnection_fee),
            'color' => $this->color,
            'active' => (bool) $this->active,
            'public' => (bool) $this->public,
        ];
    }
}
