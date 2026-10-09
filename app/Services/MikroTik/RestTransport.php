<?php

namespace App\Services\MikroTik;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Transporte REST para RouterOS v7 (https://host/rest/...).
 */
class RestTransport implements Transport
{
    public function __construct(
        protected string $host,
        protected int $port,
        protected string $user,
        protected string $password,
        protected bool $ssl = true,
        protected int $timeout = 8,
    ) {}

    protected function http(): PendingRequest
    {
        $scheme = $this->ssl ? 'https' : 'http';

        return Http::baseUrl("{$scheme}://{$this->host}:{$this->port}/rest")
            ->withBasicAuth($this->user, $this->password)
            ->acceptJson()
            ->timeout($this->timeout)
            ->withOptions(['verify' => false]);
    }

    protected function call(string $method, string $path, array $data = []): array
    {
        try {
            $response = $this->http()->send($method, ltrim($path, '/'), $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (\Throwable $e) {
            throw new MikroTikException('No se pudo conectar a '.$this->host.': '.$e->getMessage());
        }
        if ($response->failed()) {
            throw new MikroTikException($response->json('detail') ?? $response->json('message') ?? 'Error REST '.$response->status());
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function print(string $path, array $where = []): array
    {
        $rows = $this->call('GET', $path, array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $where));

        return array_is_list($rows) ? $rows : [$rows];
    }

    public function add(string $path, array $attributes): void
    {
        $this->call('PUT', $path, $this->normalize($attributes));
    }

    public function update(string $path, array $where, array $attributes): int
    {
        $rows = $this->print($path, $where);
        foreach ($rows as $row) {
            $this->call('PATCH', $path.'/'.$row['.id'], $this->normalize($attributes));
        }

        return count($rows);
    }

    public function delete(string $path, array $where): int
    {
        $rows = $this->print($path, $where);
        foreach ($rows as $row) {
            $this->call('DELETE', $path.'/'.$row['.id']);
        }

        return count($rows);
    }

    public function command(string $path, array $attributes = []): array
    {
        return $this->call('POST', $path, $this->normalize($attributes));
    }

    public function close(): void {}

    protected function normalize(array $attributes): array
    {
        return array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v, $attributes);
    }
}
