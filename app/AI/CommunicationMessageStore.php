<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class CommunicationMessageStore
{
    private const FOLDERS = [
        'inbox',
        'outbox',
        'processed',
        'failed',
        'locks',
    ];

    private const MESSAGE_TYPES = [
        'ai_task',
        'ai_task_result',
        'approval_request',
        'approval_result',
        'health_check',
        'health_result',
    ];

    private const DIRECTIONS = [
        'builderx_to_codex',
        'codex_to_builderx',
    ];

    private const ACTORS = [
        'builderx',
        'codex_desktop',
        'phase_manager',
        'mcp_server',
    ];

    private const STATUSES = [
        'queued',
        'delivered',
        'processed',
        'failed',
        'expired',
    ];

    private const MAX_MESSAGE_BYTES = 131072;

    private string $root;

    public function __construct(string $root)
    {
        $rootPath = realpath($root);
        if ($rootPath === false || !is_dir($rootPath) || is_link($root)) {
            throw new RuntimeException('Communication root is missing or unsafe.');
        }

        $this->root = rtrim($rootPath, DIRECTORY_SEPARATOR);
    }

    public static function projectDefault(): self
    {
        return new self(dirname(__DIR__, 2) . '/storage/codex-communication');
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function write(array $message, string $folder = 'inbox'): array
    {
        $folderPath = $this->folderPath($folder);
        $normalized = $this->normalizeMessage($message);
        $encoded = $this->encode($normalized);

        if (strlen($encoded) > self::MAX_MESSAGE_BYTES) {
            throw new InvalidArgumentException('Communication message exceeds the size limit.');
        }

        $messageId = (string) $normalized['message_id'];
        $path = $folderPath . DIRECTORY_SEPARATOR . $messageId . '.json';
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('A communication message with this ID already exists.');
        }

        $temporaryPath = tempnam($folderPath, '.message-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create an atomic message file.');
        }

        try {
            if (file_put_contents($temporaryPath, $encoded, LOCK_EX) !== strlen($encoded)) {
                throw new RuntimeException('Unable to write the communication message.');
            }

            chmod($temporaryPath, 0660);
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException('Unable to publish the communication message.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $messageId, string $folder = 'inbox'): ?array
    {
        $this->validateMessageId($messageId);
        $path = $this->folderPath($folder) . DIRECTORY_SEPARATOR . $messageId . '.json';
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $realPath = realpath($path);
        $folderPath = $this->folderPath($folder);
        if ($realPath === false || !str_starts_with($realPath, $folderPath . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Communication message path escaped its folder.');
        }

        $contents = file_get_contents($realPath);
        if ($contents === false || strlen($contents) > self::MAX_MESSAGE_BYTES) {
            throw new RuntimeException('Communication message cannot be read.');
        }

        try {
            $message = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Communication message contains invalid JSON.', 0, $error);
        }

        if (!is_array($message) || (string) ($message['message_id'] ?? '') !== $messageId) {
            throw new RuntimeException('Communication message identity does not match its filename.');
        }

        $this->validateMessage($message);
        $checksum = (string) ($message['checksum'] ?? '');
        $withoutChecksum = $message;
        unset($withoutChecksum['checksum']);
        $expectedChecksum = hash('sha256', $this->encode($withoutChecksum));
        if ($checksum === '' || !hash_equals($expectedChecksum, $checksum)) {
            throw new RuntimeException('Communication message checksum is invalid.');
        }

        return $message;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $folder = 'inbox', int $limit = 50): array
    {
        $folderPath = $this->folderPath($folder);
        $limit = max(1, min($limit, 200));
        $paths = glob($folderPath . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($paths, SORT_STRING);

        $messages = [];
        foreach (array_slice($paths, 0, $limit) as $path) {
            if (is_link($path) || !is_file($path)) {
                continue;
            }

            $messageId = pathinfo($path, PATHINFO_FILENAME);
            if (!is_string($messageId) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $messageId)) {
                continue;
            }

            $message = $this->read($messageId, $folder);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function move(string $messageId, string $from, string $to): void
    {
        $this->validateMessageId($messageId);
        $fromPath = $this->folderPath($from) . DIRECTORY_SEPARATOR . $messageId . '.json';
        $toPath = $this->folderPath($to) . DIRECTORY_SEPARATOR . $messageId . '.json';

        if (!is_file($fromPath) || is_link($fromPath)) {
            throw new RuntimeException('The source communication message does not exist.');
        }
        if (file_exists($toPath) || is_link($toPath)) {
            throw new RuntimeException('The destination communication message already exists.');
        }
        if (!rename($fromPath, $toPath)) {
            throw new RuntimeException('Unable to move the communication message.');
        }
    }

    /** @return array<string, mixed>|null */
    public function claim(string $messageId, string $from = 'inbox', int $leaseSeconds = 60): ?array
    {
        $message = $this->read($messageId, $from);
        if ($message === null) {
            return null;
        }
        if ($this->isExpired($message)) {
            $this->move($messageId, $from, 'failed');
            return null;
        }
        $this->move($messageId, $from, 'locks');
        return $message + ['lease_seconds' => max(1, min($leaseSeconds, 900))];
    }

    public function release(string $messageId, string $to = 'processed'): void
    {
        if (!in_array($to, ['processed', 'failed'], true)) {
            throw new InvalidArgumentException('Unsupported communication release folder.');
        }
        $this->move($messageId, 'locks', $to);
    }

    public function cleanupExpired(int $limit = 100): int
    {
        $count = 0;
        foreach (['inbox', 'outbox', 'locks'] as $folder) {
            foreach ($this->list($folder, $limit) as $message) {
                if (!$this->isExpired($message)) {
                    continue;
                }
                $this->move((string) $message['message_id'], $folder, 'failed');
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array<string, bool|int|string>
     */
    public function health(): array
    {
        $folders = [];
        $healthy = true;
        foreach (self::FOLDERS as $folder) {
            $path = $this->folderPath($folder);
            $folders[$folder] = is_dir($path) && is_writable($path) && !is_link($path);
            $healthy = $healthy && $folders[$folder];
        }

        return [
            'healthy' => $healthy,
            'root' => $this->root,
            'max_message_bytes' => self::MAX_MESSAGE_BYTES,
            'folders' => $folders,
        ];
    }

    private function folderPath(string $folder): string
    {
        if (!in_array($folder, self::FOLDERS, true)) {
            throw new InvalidArgumentException('Unsupported communication folder.');
        }

        $path = $this->root . DIRECTORY_SEPARATOR . $folder;
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath) || is_link($path) || !str_starts_with($realPath, $this->root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Communication folder is missing or unsafe.');
        }

        return $realPath;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function normalizeMessage(array $message): array
    {
        $normalized = [
            'message_id' => (string) ($message['message_id'] ?? bin2hex(random_bytes(16))),
            'correlation_id' => (string) ($message['correlation_id'] ?? bin2hex(random_bytes(16))),
            'schema_version' => 'communication-message-v1',
            'message_type' => (string) ($message['message_type'] ?? 'ai_task'),
            'direction' => (string) ($message['direction'] ?? 'builderx_to_codex'),
            'sender' => (string) ($message['sender'] ?? 'builderx'),
            'recipient' => (string) ($message['recipient'] ?? 'codex_desktop'),
            'status' => (string) ($message['status'] ?? 'queued'),
            'created_at' => (string) ($message['created_at'] ?? gmdate('c')),
            'expires_at' => $message['expires_at'] ?? null,
            'attempt' => (int) ($message['attempt'] ?? 1),
            'payload' => $message['payload'] ?? [],
        ];

        $this->validateMessage($normalized);
        $withoutChecksum = $normalized;
        unset($withoutChecksum['checksum']);
        $normalized['checksum'] = hash('sha256', $this->encode($withoutChecksum));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function validateMessage(array $message): void
    {
        foreach (['message_id', 'correlation_id', 'schema_version', 'message_type', 'direction', 'sender', 'recipient', 'status', 'created_at'] as $field) {
            if (!isset($message[$field]) || !is_string($message[$field]) || trim($message[$field]) === '') {
                throw new InvalidArgumentException('Communication message is missing ' . $field . '.');
            }
        }

        $this->validateMessageId((string) $message['message_id']);
        $this->validateMessageId((string) $message['correlation_id']);
        $this->assertAllowed((string) $message['message_type'], self::MESSAGE_TYPES, 'message type');
        $this->assertAllowed((string) $message['direction'], self::DIRECTIONS, 'message direction');
        $this->assertAllowed((string) $message['sender'], self::ACTORS, 'message sender');
        $this->assertAllowed((string) $message['recipient'], self::ACTORS, 'message recipient');
        $this->assertAllowed((string) $message['status'], self::STATUSES, 'message status');

        if ((string) $message['schema_version'] !== 'communication-message-v1') {
            throw new InvalidArgumentException('Unsupported communication message schema.');
        }
        if (!is_int($message['attempt']) || $message['attempt'] < 1 || $message['attempt'] > 5) {
            throw new InvalidArgumentException('Communication message attempt is invalid.');
        }
        if (!is_array($message['payload'])) {
            throw new InvalidArgumentException('Communication message payload must be an object.');
        }
    }

    private function validateMessageId(string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value)) {
            throw new InvalidArgumentException('Communication message ID is invalid.');
        }
    }

    /**
     * @param list<string> $allowed
     */
    private function assertAllowed(string $value, array $allowed, string $label): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported communication ' . $label . '.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $error) {
            throw new RuntimeException('Communication message could not be encoded.', 0, $error);
        }
    }

    /** @param array<string, mixed> $message */
    private function isExpired(array $message): bool
    {
        $expiresAt = $message['expires_at'] ?? null;
        if (!is_string($expiresAt) || trim($expiresAt) === '') {
            return false;
        }
        try {
            return new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')) < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return true;
        }
    }
}
