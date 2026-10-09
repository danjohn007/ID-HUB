<?php

namespace App\Services\MikroTik;

/**
 * Cliente del protocolo API de RouterOS (puertos 8728 / 8729 SSL) usando streams
 * nativos de PHP (no requiere ext-sockets, compatible con hosting compartido).
 */
class ApiTransport implements Transport
{
    /** @var resource|null */
    protected $socket = null;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $user,
        protected string $password,
        protected bool $ssl = false,
        protected int $timeout = 8,
    ) {}

    protected function connect(): void
    {
        if ($this->socket) {
            return;
        }
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $uri = ($this->ssl ? 'ssl://' : 'tcp://').$this->host.':'.$this->port;
        $socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (! $socket) {
            throw new MikroTikException("No se pudo conectar a {$this->host}:{$this->port} ({$errstr})");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $reply = $this->talk(['/login', '=name='.$this->user, '=password='.$this->password]);
        if (($reply[0]['!type'] ?? '') === '!trap') {
            throw new MikroTikException('Autenticación rechazada: '.($reply[0]['message'] ?? ''));
        }
    }

    public function print(string $path, array $where = []): array
    {
        $words = [$path.'/print'];
        foreach ($where as $k => $v) {
            $words[] = '?'.$k.'='.$this->value($v);
        }

        return array_values(array_filter($this->talk($words), fn ($r) => $r['!type'] === '!re'));
    }

    public function add(string $path, array $attributes): void
    {
        $this->talk(array_merge([$path.'/add'], $this->attrs($attributes)));
    }

    public function update(string $path, array $where, array $attributes): int
    {
        $rows = $this->print($path, $where);
        foreach ($rows as $row) {
            $this->talk(array_merge([$path.'/set', '=.id='.$row['.id']], $this->attrs($attributes)));
        }

        return count($rows);
    }

    public function delete(string $path, array $where): int
    {
        $rows = $this->print($path, $where);
        foreach ($rows as $row) {
            $this->talk([$path.'/remove', '=.id='.$row['.id']]);
        }

        return count($rows);
    }

    public function command(string $path, array $attributes = []): array
    {
        return $this->talk(array_merge([$path], $this->attrs($attributes)));
    }

    public function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function attrs(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $k => $v) {
            $out[] = '='.$k.'='.$this->value($v);
        }

        return $out;
    }

    protected function value(mixed $v): string
    {
        return is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
    }

    /** Envía una sentencia y lee respuestas hasta !done. */
    protected function talk(array $words): array
    {
        if (! $this->socket && $words[0] !== '/login') {
            $this->connect();
        }
        foreach ($words as $word) {
            $this->writeWord($word);
        }
        $this->writeWord('');

        $replies = [];
        $current = null;
        while (true) {
            $word = $this->readWord();
            if ($word === '') {
                if ($current !== null) {
                    $replies[] = $current;
                    if (in_array($current['!type'], ['!done', '!fatal'], true)) {
                        break;
                    }
                }
                $current = null;

                continue;
            }
            if ($word[0] === '!') {
                $current = ['!type' => $word];

                continue;
            }
            if ($word[0] === '=') {
                $pos = strpos($word, '=', 1);
                $current[substr($word, 1, $pos - 1)] = substr($word, $pos + 1);
            }
        }

        foreach ($replies as $r) {
            if ($r['!type'] === '!trap' && $words[0] !== '/login') {
                throw new MikroTikException($r['message'] ?? 'Error de RouterOS');
            }
            if ($r['!type'] === '!fatal') {
                throw new MikroTikException($r['message'] ?? 'Conexión cerrada por RouterOS');
            }
        }

        return $replies;
    }

    protected function writeWord(string $word): void
    {
        $len = strlen($word);
        if ($len < 0x80) {
            $prefix = chr($len);
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            $prefix = chr(($len >> 8) & 0xFF).chr($len & 0xFF);
        } elseif ($len < 0x200000) {
            $len |= 0xC00000;
            $prefix = chr(($len >> 16) & 0xFF).chr(($len >> 8) & 0xFF).chr($len & 0xFF);
        } elseif ($len < 0x10000000) {
            $len |= 0xE0000000;
            $prefix = chr(($len >> 24) & 0xFF).chr(($len >> 16) & 0xFF).chr(($len >> 8) & 0xFF).chr($len & 0xFF);
        } else {
            $prefix = chr(0xF0).pack('N', $len);
        }
        fwrite($this->socket, $prefix.$word);
    }

    protected function readWord(): string
    {
        $b = ord($this->read(1));
        if (($b & 0x80) === 0x00) {
            $len = $b;
        } elseif (($b & 0xC0) === 0x80) {
            $len = (($b & 0x3F) << 8) + ord($this->read(1));
        } elseif (($b & 0xE0) === 0xC0) {
            $len = (($b & 0x1F) << 16) + unpack('n', $this->read(2))[1];
        } elseif (($b & 0xF0) === 0xE0) {
            $r = $this->read(3);
            $len = (($b & 0x0F) << 24) + (ord($r[0]) << 16) + (ord($r[1]) << 8) + ord($r[2]);
        } else {
            $len = unpack('N', $this->read(4))[1];
        }

        return $len > 0 ? $this->read($len) : '';
    }

    protected function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                throw new MikroTikException('Conexión interrumpida con RouterOS');
            }
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                throw new MikroTikException('Tiempo de espera agotado con RouterOS');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
