<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Todas las respuestas de la API son JSON (incluidos los errores), en UTF-8 legible.
 * Los textos recibidos que no sean UTF-8 válido (clientes antiguos / Windows-1252)
 * se convierten a UTF-8 para no romper la base de datos ni la respuesta.
 */
class ApiForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $request->request->replace(self::utf8($request->request->all()));
        $request->query->replace(self::utf8($request->query->all()));
        if ($request->isJson()) {
            $request->json()->replace(self::utf8($request->json()->all()));
        }

        $response = $next($request);
        $response->headers->set('X-Api-Version', 'v1');
        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $response;
    }

    protected static function utf8(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            }
        });

        return $data;
    }
}
