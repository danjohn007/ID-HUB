<?php

namespace App\Http\Resources;

/** Referido visto por el cliente que refiere (sólo el nombre de pila del referido). */
class ReferralResource extends ApiResource
{
    public function toArray($request): array
    {
        $name = $this->referred?->first_name;

        return [
            'id' => $this->id,
            'referred_name' => $name ? strtok($name, ' ') : 'Invitado',
            'status' => $this->status,
            'status_label' => match ($this->status) {
                'pendiente' => 'Pendiente de primer pago',
                'validado' => 'Validado',
                'recompensado' => 'Recompensa aplicada',
                default => ucfirst((string) $this->status),
            },
            'reward_type' => $this->reward_type,
            'reward_value' => self::money($this->reward_value),
            'created_at' => self::datetime($this->created_at),
            'rewarded_at' => self::datetime($this->rewarded_at),
        ];
    }
}
