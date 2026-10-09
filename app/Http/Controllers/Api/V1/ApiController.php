<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiAbility;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Base de los controladores de la API v1: validación con mensajes en español,
 * paginación y helpers de tenant / permisos.
 */
abstract class ApiController extends Controller
{
    protected const MESSAGES = [
        'required' => 'El campo :attribute es obligatorio.',
        'required_without' => 'El campo :attribute es obligatorio cuando no se envía :values.',
        'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
        'string' => 'El campo :attribute debe ser texto.',
        'email' => 'El campo :attribute debe ser un correo válido.',
        'numeric' => 'El campo :attribute debe ser numérico.',
        'integer' => 'El campo :attribute debe ser un número entero.',
        'boolean' => 'El campo :attribute debe ser verdadero o falso.',
        'date' => 'El campo :attribute debe ser una fecha válida.',
        'in' => 'El valor de :attribute no es válido.',
        'exists' => 'El :attribute seleccionado no existe.',
        'unique' => 'El :attribute ya está registrado.',
        'ip' => 'El campo :attribute debe ser una IP válida.',
        'regex' => 'El formato de :attribute no es válido.',
        'array' => 'El campo :attribute debe ser una lista.',
        'file' => 'El campo :attribute debe ser un archivo.',
        'image' => 'El campo :attribute debe ser una imagen.',
        'mimes' => 'El archivo :attribute debe ser de tipo: :values.',
        'uploaded' => 'No se pudo subir el archivo :attribute.',
        'confirmed' => 'La confirmación de :attribute no coincide.',
        // Reglas de tamaño: mensaje por tipo de dato
        'between' => [
            'numeric' => 'El campo :attribute debe estar entre :min y :max.',
            'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
            'file' => 'El archivo :attribute debe pesar entre :min y :max KB.',
            'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
        ],
        'min' => [
            'numeric' => 'El campo :attribute debe ser al menos :min.',
            'string' => 'El campo :attribute debe tener al menos :min caracteres.',
            'file' => 'El archivo :attribute debe pesar al menos :min KB.',
            'array' => 'El campo :attribute debe tener al menos :min elementos.',
        ],
        'max' => [
            'numeric' => 'El campo :attribute no debe ser mayor a :max.',
            'string' => 'El campo :attribute no debe exceder :max caracteres.',
            'file' => 'El archivo :attribute no debe pesar más de :max KB.',
            'array' => 'El campo :attribute no debe tener más de :max elementos.',
        ],
        'gt' => [
            'numeric' => 'El campo :attribute debe ser mayor a :value.',
            'string' => 'El campo :attribute debe tener más de :value caracteres.',
            'file' => 'El archivo :attribute debe pesar más de :value KB.',
            'array' => 'El campo :attribute debe tener más de :value elementos.',
        ],
        'after_or_equal' => 'El campo :attribute debe ser una fecha igual o posterior a :date.',
    ];

    protected const ATTRIBUTES = [
        'email' => 'correo', 'password' => 'contraseña', 'device_name' => 'nombre del dispositivo', 'identifier' => 'ID de cliente o usuario PPPoE',
        'first_name' => 'nombre', 'last_name' => 'apellidos', 'phone' => 'teléfono', 'address' => 'dirección', 'plan_id' => 'plan',
        'code' => 'ID de cliente', 'zone_id' => 'zona', 'repeater_id' => 'repetidor', 'pppoe_user' => 'usuario PPPoE', 'billing_day' => 'día de facturación',
        'amount' => 'monto', 'method' => 'método de pago', 'client_id' => 'cliente', 'category' => 'categoría', 'priority' => 'prioridad',
        'subject' => 'asunto', 'description' => 'descripción', 'status' => 'estado', 'technician_id' => 'técnico', 'body' => 'comentario',
        'file' => 'archivo', 'kind' => 'tipo', 'lat' => 'latitud', 'lng' => 'longitud', 'result' => 'resultado', 'promise_date' => 'fecha de promesa',
        'token' => 'token', 'platform' => 'plataforma', 'new_password' => 'nueva contraseña', 'service_status' => 'estado del servicio',
        'mac_address' => 'MAC', 'ip_address' => 'IP', 'reference' => 'referencia', 'note' => 'nota', 'tenant' => 'empresa',
    ];

    /** Valida con mensajes en español; devuelve sólo los campos validados. */
    protected function check(Request $request, array $rules, array $attributes = []): array
    {
        return Validator::make($request->all(), $rules, self::MESSAGES, array_merge(self::ATTRIBUTES, $attributes))->validate();
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->query('per_page', $default)));
    }

    protected function apiUser(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }

    /** Cliente autenticado en la app del cliente. */
    protected function appClient(): Client
    {
        return request()->attributes->get('api_client');
    }

    protected function allows(string $ability): bool
    {
        return ApiAbility::allows($this->apiUser(), $ability);
    }

    protected function deny(string $message = 'No tienes permiso para realizar esta acción.', int $status = 403): never
    {
        abort(response()->json(['message' => $message], $status));
    }

    protected function ok(array $data = [], ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(array_filter(['message' => $message], fn ($v) => $v !== null) + $data, $status);
    }

    /** Regla exists limitada al WISP activo. */
    protected function existsInTenant(string $table, string $column = 'id'): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', tenant()?->id);
    }

    /** Ordenamiento seguro: ?sort=campo o ?sort=-campo */
    protected function applySort(Builder $query, Request $request, array $allowed, string $default = '-id'): Builder
    {
        $sort = (string) $request->query('sort', $default);
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        if (! in_array($col, $allowed, true)) {
            $col = ltrim($default, '-');
            $dir = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        return $query->orderBy($query->getModel()->qualifyColumn($col), $dir);
    }
}
