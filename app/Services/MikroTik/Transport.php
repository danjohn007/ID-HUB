<?php

namespace App\Services\MikroTik;

/**
 * Transporte de bajo nivel hacia un RouterOS. Las rutas usan formato de menú con "/"
 * (ej. "/ppp/secret") y los filtros son pares atributo => valor exactos.
 */
interface Transport
{
    /** Lista registros del menú, filtrados por igualdad de atributos. */
    public function print(string $path, array $where = []): array;

    /** Crea un registro. */
    public function add(string $path, array $attributes): void;

    /** Actualiza los registros que coinciden con $where. Devuelve cuántos (o -1 si es diferido). */
    public function update(string $path, array $where, array $attributes): int;

    /** Elimina los registros que coinciden con $where. */
    public function delete(string $path, array $where): int;

    /** Ejecuta un comando sin menú de registros (ej. "/ppp/active/remove"). */
    public function command(string $path, array $attributes = []): array;

    public function close(): void;
}
