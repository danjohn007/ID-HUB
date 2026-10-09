<?php

namespace App\Models\Concerns;

use App\Support\Audit;

/**
 * Registra en auditoría altas, cambios y bajas del modelo con valores anterior/nuevo.
 * Definir $auditExclude para omitir columnas (además de timestamps y ocultas).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => Audit::model('created', $m, [], $m->auditableValues($m->getAttributes())));

        static::updated(function ($m) {
            $changes = $m->auditableValues($m->getChanges());
            if (! $changes) {
                return;
            }
            $old = array_intersect_key($m->getOriginal(), $changes);
            Audit::model('updated', $m, $m->auditableValues($old), $changes);
        });

        static::deleted(fn ($m) => Audit::model('deleted', $m, $m->auditableValues($m->getAttributes()), []));
    }

    public function auditableValues(array $values): array
    {
        $exclude = array_merge(
            ['created_at', 'updated_at', 'deleted_at', 'remember_token'],
            $this->getHidden(),
            property_exists($this, 'auditExclude') ? $this->auditExclude : []
        );

        return array_diff_key($values, array_flip($exclude));
    }

    public function auditLabel(): string
    {
        return class_basename($this).' #'.$this->getKey();
    }
}
