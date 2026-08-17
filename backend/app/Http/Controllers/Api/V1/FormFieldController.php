<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use mysqli;
use RuntimeException;

final class FormFieldController
{
    private const FIELD_TYPES = ['text', 'textarea', 'number', 'currency', 'date', 'datetime', 'select', 'checkbox', 'file', 'signature', 'lookup', 'formula', 'child_table', 'section'];
    private const DATA_TYPES = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'json'];
    private const STATUSES = ['DRAFT', 'ACTIVE', 'INACTIVE', 'DELETED'];

    public function __construct(private readonly mysqli $db)
    {
    }

    public function index(string $formKey): array
    {
        $this->assertUuid($formKey, 'form_key');
        $stmt = $this->db->prepare(
            "SELECT
                *,
                JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.visibility_rule')) AS visibility_rule,
                JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.editable_rule')) AS editable_rule,
                JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.role_permission')) AS role_permission,
                JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.grid_width')) AS grid_width
            FROM builder_form_field
            WHERE form_key = ? AND field_status <> 'DELETED'
            ORDER BY field_sort_order ASC, x_id ASC"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $formKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function save(array $payload): array
    {
        $formKey = (string) ($payload['form_key'] ?? '');
        $fieldKey = (string) ($payload['field_key'] ?? '');
        $fieldCode = strtolower(trim((string) ($payload['field_code'] ?? '')));
        $databaseColumn = strtolower(trim((string) ($payload['database_column_name'] ?? $fieldCode)));
        $this->assertUuid($formKey, 'form_key');

        if ($fieldKey !== '') {
            $this->assertUuid($fieldKey, 'field_key');
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,79}$/', $fieldCode) !== 1 || preg_match('/^[a-z][a-z0-9_]{1,99}$/', $databaseColumn) !== 1) {
            throw new RuntimeException('Field code and database column must use lowercase snake_case.');
        }

        $fieldType = $this->oneOf((string) ($payload['field_type'] ?? 'text'), self::FIELD_TYPES, 'field_type');
        $dataType = $this->oneOf((string) ($payload['data_type'] ?? 'string'), self::DATA_TYPES, 'data_type');
        $status = $this->oneOf((string) ($payload['field_status'] ?? 'ACTIVE'), self::STATUSES, 'field_status');
        $validation = $this->jsonOrNull($payload['validation_rules'] ?? null, 'validation_rules');
        $options = $this->jsonOrNull($payload['option_source'] ?? null, 'option_source');
        $settings = json_encode([
            'visibility_rule' => trim((string) ($payload['visibility_rule'] ?? '')),
            'editable_rule' => trim((string) ($payload['editable_rule'] ?? '')),
            'role_permission' => trim((string) ($payload['role_permission'] ?? '')),
            'grid_width' => max(60, min(600, (int) ($payload['grid_width'] ?? 160))),
        ], JSON_UNESCAPED_SLASHES);

        $duplicate = $this->scalar(
            'SELECT COUNT(*) FROM builder_form_field WHERE form_key = ? AND (field_code = ? OR database_column_name = ?) AND field_key <> ?',
            'ssss',
            [$formKey, $fieldCode, $databaseColumn, $fieldKey ?: '__new__']
        );
        if ((int) $duplicate > 0) {
            throw new RuntimeException('Field code or database column already exists for this form.');
        }

        $values = [
            $formKey,
            $fieldCode,
            trim((string) ($payload['field_name'] ?? $fieldCode)),
            trim((string) ($payload['field_label'] ?? $fieldCode)),
            $fieldType,
            $dataType,
            $databaseColumn,
            max(0, (int) ($payload['field_sort_order'] ?? 0)),
            $status,
            !empty($payload['is_required']) ? 1 : 0,
            !empty($payload['is_unique']) ? 1 : 0,
            !empty($payload['is_searchable']) ? 1 : 0,
            !empty($payload['is_sortable']) ? 1 : 0,
            trim((string) ($payload['default_value'] ?? '')),
            $validation,
            $options,
            trim((string) ($payload['formula_expression'] ?? '')),
            $settings,
        ];

        if ($fieldKey === '') {
            $fieldKey = $this->uuid();
            $stmt = $this->db->prepare(
                'INSERT INTO builder_form_field (field_key, form_key, field_code, field_name, field_label, field_type, data_type, database_column_name, field_sort_order, field_status, is_required, is_unique, is_searchable, is_sortable, default_value, validation_rules, option_source, formula_expression, field_settings) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $stmt->bind_param('ssssssssisiiissssss', $fieldKey, ...$values);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare(
                'UPDATE builder_form_field SET form_key = ?, field_code = ?, field_name = ?, field_label = ?, field_type = ?, data_type = ?, database_column_name = ?, field_sort_order = ?, field_status = ?, is_required = ?, is_unique = ?, is_searchable = ?, is_sortable = ?, default_value = ?, validation_rules = ?, option_source = ?, formula_expression = ?, field_settings = ? WHERE field_key = ?'
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $stmt->bind_param('sssssssisiiiissssss', ...array_merge($values, [$fieldKey]));
            $stmt->execute();
        }

        return $this->show($fieldKey);
    }

    public function show(string $fieldKey): array
    {
        $this->assertUuid($fieldKey, 'field_key');
        $stmt = $this->db->prepare('SELECT * FROM builder_form_field WHERE field_key = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param('s', $fieldKey);
        $stmt->execute();
        $field = $stmt->get_result()->fetch_assoc();
        if (!$field) {
            throw new RuntimeException('Form field was not found.');
        }

        return $field;
    }

    public function setStatus(string $fieldKey, string $status): array
    {
        $this->assertUuid($fieldKey, 'field_key');
        $status = $this->oneOf($status, ['ACTIVE', 'INACTIVE', 'DELETED'], 'field_status');
        $stmt = $this->db->prepare('UPDATE builder_form_field SET field_status = ? WHERE field_key = ?');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param('ss', $status, $fieldKey);
        $stmt->execute();
        return $this->show($fieldKey);
    }

    public function move(string $fieldKey, string $direction): array
    {
        $this->assertUuid($fieldKey, 'field_key');
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Invalid field reorder direction.');
        }

        $field = $this->show($fieldKey);
        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $stmt = $this->db->prepare(
            "SELECT * FROM builder_form_field
            WHERE form_key = ? AND field_status <> 'DELETED' AND field_sort_order {$operator} ?
            ORDER BY field_sort_order {$order}, x_id {$order}
            LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $sortOrder = (int) $field['field_sort_order'];
        $stmt->bind_param('si', $field['form_key'], $sortOrder);
        $stmt->execute();
        $neighbor = $stmt->get_result()->fetch_assoc();
        if ($neighbor) {
            $this->setSortOrder($fieldKey, (int) $neighbor['field_sort_order']);
            $this->setSortOrder((string) $neighbor['field_key'], $sortOrder);
        }

        return $this->show($fieldKey);
    }

    private function setSortOrder(string $fieldKey, int $sortOrder): void
    {
        $stmt = $this->db->prepare('UPDATE builder_form_field SET field_sort_order = ? WHERE field_key = ?');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param('is', $sortOrder, $fieldKey);
        $stmt->execute();
    }

    private function jsonOrNull(mixed $value, string $field): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("{$field} must be valid JSON.");
        }

        return $value;
    }

    private function scalar(string $sql, string $types, array $params): mixed
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_row()[0] ?? null;
    }

    private function oneOf(string $value, array $allowed, string $field): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new RuntimeException("Invalid {$field}.");
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
