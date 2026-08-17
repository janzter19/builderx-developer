<?php
declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Encrypts application data with an authenticated AES-256-GCM envelope.
 *
 * The four configured slots are key identifiers, not secret values. Their
 * values must be supplied through the environment or dependency injection.
 * Values prefixed with "key:" or "base64:" are base64-encoded 32-byte AES
 * keys, values prefixed with "raw:" are raw 32-byte keys, and values prefixed
 * with "password:" are human-readable passwords derived with PBKDF2.
 * Unprefixed 32-byte values are treated as raw keys, while all other
 * unprefixed values are treated as passwords.
 */
final class EncryptionService
{
    private const KEY_SLOTS = ['AAA', 'BBB', 'CCC', 'DDD'];
    private const CIPHER = 'aes-256-gcm';
    private const ENVELOPE_VERSION = 1;
    private const KEY_LENGTH = 32;
    private const SALT_LENGTH = 16;
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const PBKDF2_ITERATIONS = 310000;
    private const AAD = 'builderx:p14:aes-256-gcm:v1';

    /**
     * @param array<string|int, string|array{type?: string, value: string}> $keyMaterial
     */
    public function __construct(private readonly array $keyMaterial)
    {
        $providedSlots = array_map(static fn (int|string $slot): string => (string) $slot, array_keys($keyMaterial));
        sort($providedSlots);
        $requiredSlots = self::KEY_SLOTS;
        sort($requiredSlots);

        if ($providedSlots !== $requiredSlots) {
            throw new InvalidArgumentException('Encryption material must contain slots AAA, BBB, CCC, and DDD.');
        }

        foreach (self::KEY_SLOTS as $slot) {
            $this->parseMaterial($this->keyMaterial[$slot] ?? $this->keyMaterial[(int) $slot]);
        }
    }

    public static function fromEnvironment(): self
    {
        $material = [];

        foreach (self::KEY_SLOTS as $slot) {
            $environmentName = 'BUILDERX_ENCRYPTION_KEY_' . $slot;
            $value = getenv($environmentName);
            if ($value === false) {
                $value = $_ENV[$environmentName] ?? $_SERVER[$environmentName] ?? false;
            }

            if (!is_string($value) || $value === '') {
                throw new LogicException($environmentName . ' is not configured.');
            }

            $material[$slot] = $value;
        }

        return new self($material);
    }

    public function encrypt(string $plaintext): string
    {
        $salt = random_bytes(self::SALT_LENGTH);
        $encryptionKey = $this->deriveEncryptionKey($salt);
        $dataKey = random_bytes(self::KEY_LENGTH);
        $keyIv = $this->generateNonce();
        $dataIv = $this->generateNonce($keyIv);
        $keyTag = '';
        $dataTag = '';

        $wrappedKey = openssl_encrypt(
            $dataKey,
            self::CIPHER,
            $encryptionKey,
            OPENSSL_RAW_DATA,
            $keyIv,
            $keyTag,
            self::AAD . ':key',
            self::TAG_LENGTH,
        );
        if ($wrappedKey === false || strlen($keyTag) !== self::TAG_LENGTH) {
            throw new RuntimeException('The encryption key could not be wrapped.');
        }

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $dataKey,
            OPENSSL_RAW_DATA,
            $dataIv,
            $dataTag,
            self::AAD . ':data',
            self::TAG_LENGTH,
        );
        if ($ciphertext === false || strlen($dataTag) !== self::TAG_LENGTH) {
            throw new RuntimeException('The plaintext could not be encrypted.');
        }

        $envelope = [
            'v' => self::ENVELOPE_VERSION,
            'salt' => base64_encode($salt),
            'key_iv' => base64_encode($keyIv),
            'key_tag' => base64_encode($keyTag),
            'key' => base64_encode($wrappedKey),
            'iv' => base64_encode($dataIv),
            'tag' => base64_encode($dataTag),
            'data' => base64_encode($ciphertext),
        ];

        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return base64_encode($json);
    }

    public function decrypt(string $encodedEnvelope): string
    {
        $json = base64_decode($encodedEnvelope, true);
        if ($json === false || $json === '') {
            throw new InvalidArgumentException('The encrypted value is not valid base64.');
        }

        try {
            $envelope = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('The encrypted value is not a valid envelope.', 0, $exception);
        }

        if (!is_array($envelope) || ($envelope['v'] ?? null) !== self::ENVELOPE_VERSION) {
            throw new InvalidArgumentException('The encrypted value uses an unsupported envelope version.');
        }

        $salt = $this->decodeField($envelope, 'salt', self::SALT_LENGTH);
        $keyIv = $this->decodeField($envelope, 'key_iv', self::IV_LENGTH);
        $keyTag = $this->decodeField($envelope, 'key_tag', self::TAG_LENGTH);
        $wrappedKey = $this->decodeField($envelope, 'key');
        $dataIv = $this->decodeField($envelope, 'iv', self::IV_LENGTH);
        $dataTag = $this->decodeField($envelope, 'tag', self::TAG_LENGTH);
        $ciphertext = $this->decodeField($envelope, 'data');

        if (hash_equals($keyIv, $dataIv)) {
            throw new InvalidArgumentException('The encrypted envelope reuses a nonce.');
        }

        $dataKey = openssl_decrypt(
            $wrappedKey,
            self::CIPHER,
            $this->deriveEncryptionKey($salt),
            OPENSSL_RAW_DATA,
            $keyIv,
            $keyTag,
            self::AAD . ':key',
        );
        if ($dataKey === false || strlen($dataKey) !== self::KEY_LENGTH) {
            throw new RuntimeException('The encrypted value failed authentication.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $dataKey,
            OPENSSL_RAW_DATA,
            $dataIv,
            $dataTag,
            self::AAD . ':data',
        );
        if ($plaintext === false) {
            throw new RuntimeException('The encrypted value failed authentication.');
        }

        return $plaintext;
    }

    private function deriveEncryptionKey(string $salt): string
    {
        $parts = [];

        foreach (self::KEY_SLOTS as $slot) {
            $material = $this->parseMaterial($this->keyMaterial[$slot] ?? $this->keyMaterial[(int) $slot]);
            if ($material['type'] === 'key') {
                $parts[] = hash_hmac('sha256', $slot, $material['value'], true);
                continue;
            }

            $parts[] = hash_pbkdf2(
                'sha256',
                $material['value'],
                $salt . ':' . $slot,
                self::PBKDF2_ITERATIONS,
                self::KEY_LENGTH,
                true,
            );
        }

        return hash('sha256', implode('', $parts), true);
    }

    private function generateNonce(string ...$usedNonces): string
    {
        do {
            $nonce = random_bytes(self::IV_LENGTH);
        } while (in_array($nonce, $usedNonces, true));

        return $nonce;
    }

    /** @return array{type: 'key'|'password', value: string} */
    private function parseMaterial(string|array $material): array
    {
        if (is_array($material)) {
            $type = strtolower(trim((string) ($material['type'] ?? '')));
            $value = (string) ($material['value'] ?? '');
            if (!in_array($type, ['key', 'raw', 'base64', 'password'], true)) {
                throw new InvalidArgumentException('Encryption material type must be key, raw, base64, or password.');
            }

            if ($type === 'password') {
                return $this->password($value);
            }

            return [
                'type' => 'key',
                'value' => $type === 'raw' ? $this->decodeRawKey($value) : $this->decodeKey($value),
            ];
        }

        if (str_starts_with($material, 'key:') || str_starts_with($material, 'base64:')) {
            $prefixLength = str_starts_with($material, 'key:') ? 4 : 7;

            return ['type' => 'key', 'value' => $this->decodeKey(substr($material, $prefixLength))];
        }

        if (str_starts_with($material, 'raw:')) {
            return ['type' => 'key', 'value' => $this->decodeRawKey(substr($material, 4))];
        }

        if (str_starts_with($material, 'password:')) {
            return $this->password(substr($material, 9));
        }

        return strlen($material) === self::KEY_LENGTH
            ? ['type' => 'key', 'value' => $material]
            : $this->password($material);
    }

    /** @return array{type: 'password', value: string} */
    private function password(string $value): array
    {
        if ($value === '') {
            throw new InvalidArgumentException('Encryption passwords cannot be empty.');
        }

        return ['type' => 'password', 'value' => $value];
    }

    private function decodeKey(string $value): string
    {
        if (strlen($value) === self::KEY_LENGTH) {
            return $value;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) !== self::KEY_LENGTH) {
            throw new InvalidArgumentException('Cryptographic keys must be 32-byte values or base64-encoded 32-byte values.');
        }

        return $decoded;
    }

    private function decodeRawKey(string $value): string
    {
        if (strlen($value) !== self::KEY_LENGTH) {
            throw new InvalidArgumentException('Raw cryptographic keys must be exactly 32 bytes.');
        }

        return $value;
    }

    private function decodeField(array $envelope, string $field, ?int $expectedLength = null): string
    {
        $value = $envelope[$field] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('The encrypted envelope is missing ' . $field . '.');
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false || ($expectedLength !== null && strlen($decoded) !== $expectedLength)) {
            throw new InvalidArgumentException('The encrypted envelope contains an invalid ' . $field . '.');
        }

        return $decoded;
    }
}
