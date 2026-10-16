<?php

namespace App\Livewire\Concerns;

use App\Services\ReverseGeocoder;

/**
 * Llena los campos de dirección del formulario a partir de form.lat / form.lng
 * (botón "Usar mi ubicación"). Solo reemplaza campos vacíos o los que se llenaron
 * automáticamente antes, para no borrar lo que el usuario escribió.
 *
 * El componente define addressFields(): [campo del form => parte de la dirección]
 * (partes: address, colonia, community, full).
 */
trait FillsAddressFromLocation
{
    /** Últimos valores puestos automáticamente, por campo. */
    public array $autoAddress = [];

    public function fillAddressFromLocation(): void
    {
        $lat = (float) ($this->form['lat'] ?? 0);
        $lng = (float) ($this->form['lng'] ?? 0);
        if (! $lat || ! $lng) {
            return;
        }

        $found = app(ReverseGeocoder::class)->lookup($lat, $lng);
        if (! $found) {
            $this->toast('Ubicación guardada. No se encontró la dirección de ese punto; escríbela a mano.', 'warning');

            return;
        }

        $filled = 0;
        foreach ($this->addressFields() as $field => $part) {
            $value = $found[$part] ?? null;
            $current = trim((string) ($this->form[$field] ?? ''));
            if ($value && ($current === '' || $current === ($this->autoAddress[$field] ?? null))) {
                $this->form[$field] = $value;
                $this->autoAddress[$field] = $value;
                $filled++;
            }
        }

        $this->toast($filled
            ? 'Dirección obtenida de tu ubicación: '.$found['full'].'. Revísala y completa el número si falta.'
            : 'Ubicación guardada. Se conservó la dirección que ya habías escrito.', $filled ? 'success' : 'info');
    }
}
