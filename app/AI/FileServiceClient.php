<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class FileServiceClient implements FileServiceGateway
{
    private const MAX_SOCKET_PATH = 104;
    private const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly string $socketPath)
    {
        if ($socketPath === '' || !str_starts_with($socketPath, '/run/builderx/')) {
            throw new InvalidArgumentException('The File Service socket must be under /run/builderx/.');
        }
        if (strlen($socketPath) > self::MAX_SOCKET_PATH) {
            throw new InvalidArgumentException('The File Service socket path is too long.');
        }
    }

    public function list(string $path = '.', int $limit = 100): array
    {
        return $this->request(['operation' => 'list', 'path' => $path, 'limit' => $limit]);
    }

    public function search(string $query, string $path = '.', int $limit = 100): array
    {
        return $this->request(['operation' => 'search', 'query' => $query, 'path' => $path, 'limit' => $limit]);
    }

    public function read(string $path): array
    {
        return $this->request(['operation' => 'read', 'path' => $path]);
    }

    public function write(string $path, string $contents, bool $overwrite): array
    {
        return $this->request([
            'operation' => $overwrite ? 'update' : 'create',
            'path' => $path,
            'contents' => $contents,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function request(array $payload): array
    {
        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errorCode,
            $errorMessage,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new RuntimeException('The www-data File Service socket is unavailable.');
        }
        stream_set_timeout($socket, self::TIMEOUT_SECONDS);
        $payload['request_id'] = bin2hex(random_bytes(16));
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (fwrite($socket, $encoded . "\n") !== strlen($encoded) + 1) {
            fclose($socket);
            throw new RuntimeException('The File Service request could not be sent.');
        }
        $line = fgets($socket);
        fclose($socket);
        if ($line === false) {
            throw new RuntimeException('The File Service returned no response.');
        }
        $response = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($response) || ($response['request_id'] ?? '') !== $payload['request_id']) {
            throw new RuntimeException('The File Service response did not match the request.');
        }
        if (($response['ok'] ?? false) !== true) {
            $message = (string) ($response['error']['message'] ?? 'The File Service rejected the request.');
            if (($response['error']['code'] ?? '') === 'file_service_error') {
                throw new InvalidArgumentException($message);
            }
            throw new RuntimeException($message);
        }
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('The File Service returned an invalid payload.');
        }

        return $data;
    }
}
