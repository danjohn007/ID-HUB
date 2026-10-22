<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Evidencias privadas (fotos de trabajo, firmas, evidencia de cobro).
 *
 * Los archivos nuevos se guardan en el disco privado 'local' (storage/app/private) y se sirven:
 * - Panel web: ruta evidence.show (sesión iniciada + mismo WISP).
 * - App de campo / PDFs: enlace firmado temporal (api.v1.evidence) o data URI en base64.
 * Compatibilidad: los archivos anteriores siguen en el disco 'public' y se muestran con su URL pública.
 *
 * Todas las rutas siguen el formato {carpeta}/{tenant_id}/... con una carpeta permitida.
 */
class Evidence
{
    public const DISK = 'local';

    /** Carpetas de evidencias que se pueden servir (el resto del disco privado, p. ej. respaldos, no). */
    public const FOLDERS = ['attachments', 'evidence', 'evidences', 'signatures', 'payments', 'visits'];

    /** Minutos de validez de los enlaces firmados para la app. */
    public const SIGNED_MINUTES = 30;

    public static function store(UploadedFile $file, string $dir): string
    {
        return $file->store($dir, self::DISK);
    }

    public static function put(string $path, string $contents): void
    {
        Storage::disk(self::DISK)->put($path, $contents);
    }

    /** Disco donde está el archivo: 'local' (nuevo, privado), 'public' (anterior) o null si no existe. */
    public static function disk(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
            Storage::disk('public')->delete($path);
        }
    }

    /** URL para el panel web (requiere sesión del mismo WISP). */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return self::disk($path) === 'public'
            ? Storage::disk('public')->url($path)
            : route('evidence.show', ['path' => $path]);
    }

    /** URL firmada temporal (app de campo): funciona sin sesión durante SIGNED_MINUTES. */
    public static function signedUrl(?string $path, ?int $minutes = null): ?string
    {
        if (! $path) {
            return null;
        }

        return self::disk($path) === 'public'
            ? Storage::disk('public')->url($path)
            : URL::temporarySignedRoute('api.v1.evidence', now()->addMinutes($minutes ?? self::SIGNED_MINUTES), ['path' => $path]);
    }

    /** Imagen embebida en base64 (PDF con dompdf), de cualquiera de los dos discos. */
    public static function dataUri(?string $path): ?string
    {
        $disk = self::disk($path);
        if (! $disk) {
            return null;
        }
        $contents = Storage::disk($disk)->get($path);
        $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $contents);
    }

    /**
     * Momento de la captura enviado por la app (taken_at). Se acepta si no está en el futuro
     * (5 min de tolerancia por el reloj del teléfono) ni tiene más de 24 h; si no, ahora.
     */
    public static function takenAt(mixed $value): Carbon
    {
        try {
            $at = filled($value) ? Carbon::parse((string) $value)->setTimezone(config('app.timezone')) : null;
        } catch (\Throwable) {
            $at = null;
        }

        return $at && $at->lte(now()->addMinutes(5)) && $at->gte(now()->subDay()) ? ($at->gt(now()) ? now() : $at) : now();
    }

    /** Ruta de evidencia válida (carpeta permitida, sin "..") y, si se indica, del WISP dado. */
    public static function allowed(string $path, ?int $tenantId = null): bool
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        if (count($parts) < 3 || in_array('..', $parts, true) || in_array('', $parts, true) || ! in_array($parts[0], self::FOLDERS, true)) {
            return false;
        }

        return $tenantId === null || $parts[1] === (string) $tenantId;
    }

    /** Respuesta HTTP con el archivo (inline). 404 si no existe o la ruta no es de evidencias. */
    public static function response(string $path, ?int $tenantId = null)
    {
        $disk = self::allowed($path, $tenantId) ? self::disk($path) : null;
        abort_unless($disk, 404);

        return Storage::disk($disk)->response($path, null, [
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
