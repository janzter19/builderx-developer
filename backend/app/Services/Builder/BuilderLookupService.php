<?php
declare(strict_types=1);

namespace App\Services\Builder;

use mysqli;
use RuntimeException;

final class BuilderLookupService
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function listTables(): array
    {
        $result = $this->db->query("SELECT * FROM builder_lookup_table WHERE lookup_status <> 'DELETED' ORDER BY lookup_name ASC");
        if (!$result) {
            throw new RuntimeException($this->db->error);
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function saveTable(array $payload, ?string $userKey = null): array
    {
        $lookupKey = (string) ($payload['lookup_table_key'] ?? '');
        $code = $this->assertCode((string) ($payload['lookup_code'] ?? ''));
        $name = trim((string) ($payload['lookup_name'] ?? ''));
        $description = trim((string) ($payload['lookup_description'] ?? ''));
        $status = in_array(($payload['lookup_status'] ?? 'ACTIVE'), ['ACTIVE', 'INACTIVE', 'DELETED'], true) ? (string) $payload['lookup_status'] : 'ACTIVE';

        if ($name === '') {
            throw new RuntimeException('Lookup name is required.');
        }

        if ($lookupKey === '') {
            $lookupKey = $this->uuid();
            $stmt = $this->db->prepare(
                'INSERT INTO builder_lookup_table (lookup_table_key, lookup_code, lookup_name, lookup_description, lookup_status, created_by_key, updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $stmt->bind_param('sssssss', $lookupKey, $code, $name, $description, $status, $userKey, $userKey);
            $stmt->execute();
        } else {
            $this->assertUuid($lookupKey, 'lookup_table_key');
            $stmt = $this->db->prepare(
                'UPDATE builder_lookup_table SET lookup_code = ?, lookup_name = ?, lookup_description = ?, lookup_status = ?, updated_by_key = ? WHERE lookup_table_key = ?'
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $stmt->bind_param('ssssss', $code, $name, $description, $status, $userKey, $lookupKey);
            $stmt->execute();
        }

        return $this->getTable($lookupKey);
    }

    public function listOptions(string $lookupTableKey): array
    {
        $this->assertUuid($lookupTableKey, 'lookup_table_key');
        $stmt = $this->db->prepare(
            "SELECT * FROM builder_lookup_option
            WHERE lookup_table_key = ? AND option_status <> 'DELETED'
            ORDER BY option_sort_order ASC, option_label ASC"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $lookupTableKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function saveOption(array $payload, ?string $userKey = null): array
    {
        $lookupTableKey = (string) ($payload['lookup_table_key'] ?? '');
        $this->assertUuid($lookupTableKey, 'lookup_table_key');
        $optionKey = (string) ($payload['lookup_option_key'] ?? '');
        $value = trim((string) ($payload['option_value'] ?? ''));
        $label = trim((string) ($payload['option_label'] ?? ''));
        $sortOrder = max(0, (int) ($payload['option_sort_order'] ?? 0));
        $status = in_array(($payload['option_status'] ?? 'ACTIVE'), ['ACTIVE', 'INACTIVE', 'DELETED'], true) ? (string) $payload['option_status'] : 'ACTIVE';
        $metadata = json_encode(is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [], JSON_UNESCAPED_SLASHES);

        if ($value === '' || $label === '') {
            throw new RuntimeException('Lookup option value and label are required.');
        }

        if ($optionKey === '') {
            $optionKey = $this->uuid();
            $stmt = $this->db->prepare(
                'INSERT INTO builder_lookup_option (lookup_option_key, lookup_table_key, option_value, option_label, option_color, option_icon, parent_option_key, option_sort_order, option_status, metadata, created_by_key, updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $color = $payload['option_color'] ?? null;
            $icon = $payload['option_icon'] ?? null;
            $parent = $payload['parent_option_key'] ?? null;
            $stmt->bind_param('sssssssissss', $optionKey, $lookupTableKey, $value, $label, $color, $icon, $parent, $sortOrder, $status, $metadata, $userKey, $userKey);
            $stmt->execute();
        } else {
            $this->assertUuid($optionKey, 'lookup_option_key');
            $stmt = $this->db->prepare(
                'UPDATE builder_lookup_option SET option_value = ?, option_label = ?, option_color = ?, option_icon = ?, parent_option_key = ?, option_sort_order = ?, option_status = ?, metadata = ?, updated_by_key = ? WHERE lookup_option_key = ? AND lookup_table_key = ?'
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $color = $payload['option_color'] ?? null;
            $icon = $payload['option_icon'] ?? null;
            $parent = $payload['parent_option_key'] ?? null;
            $stmt->bind_param('sssssisssss', $value, $label, $color, $icon, $parent, $sortOrder, $status, $metadata, $userKey, $optionKey, $lookupTableKey);
            $stmt->execute();
        }

        return $this->getOption($optionKey);
    }

    public function deleteTable(string $lookupTableKey, ?string $userKey = null): void
    {
        $this->assertUuid($lookupTableKey, 'lookup_table_key');
        $stmt = $this->db->prepare("UPDATE builder_lookup_table SET lookup_status = 'DELETED', updated_by_key = ? WHERE lookup_table_key = ?");
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param('ss', $userKey, $lookupTableKey);
        $stmt->execute();
    }

    public function deleteOption(string $lookupOptionKey, ?string $userKey = null): void
    {
        $this->assertUuid($lookupOptionKey, 'lookup_option_key');
        $stmt = $this->db->prepare("UPDATE builder_lookup_option SET option_status = 'DELETED', updated_by_key = ? WHERE lookup_option_key = ?");
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param('ss', $userKey, $lookupOptionKey);
        $stmt->execute();
    }

    private function getTable(string $lookupTableKey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM builder_lookup_table WHERE lookup_table_key = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param('s', $lookupTableKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [];
    }

    private function getOption(string $lookupOptionKey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM builder_lookup_option WHERE lookup_option_key = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param('s', $lookupOptionKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [];
    }

    private function assertCode(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^[A-Z][A-Z0-9_]{1,79}$/', $value) !== 1) {
            throw new RuntimeException('Lookup code must use uppercase letters, numbers, and underscores.');
        }

        return $value;
    }

    private function assertUuid(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value) !== 1) {
            throw new RuntimeException("Invalid {$field}.");
        }
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0, 65535), random_int(0, 65535), random_int(0, 65535), random_int(16384, 20479), random_int(32768, 49151), random_int(0, 65535), random_int(0, 65535), random_int(0, 65535));
    }
}
