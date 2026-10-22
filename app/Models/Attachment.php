<?php

namespace App\Models;

use App\Support\Evidence;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Evidencias y archivos. attachable_type usa el morph map (client, ticket,
 * installation_order, collection_visit, payment, ...). Los nuevos se guardan en el disco
 * privado 'local' (App\Support\Evidence); los anteriores siguen en 'public'.
 */
class Attachment extends TenantModel
{
    protected $casts = ['taken_at' => 'datetime'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** URL para el panel web (sesión del mismo WISP). */
    public function url(): string
    {
        return (string) Evidence::url($this->path);
    }

    /** Enlace firmado temporal para la app de campo. */
    public function signedUrl(): string
    {
        return (string) Evidence::signedUrl($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
