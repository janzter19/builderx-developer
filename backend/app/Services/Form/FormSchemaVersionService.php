<?php
declare(strict_types=1);

namespace App\Services\Form;

use mysqli;
use RuntimeException;

final class FormSchemaVersionService
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function preview(string $formKey): array
    {
        $snapshot = $this->snapshot($formKey);
        $nextVersion = ((int) $this->scalar('SELECT COALESCE(MAX(version_number), 0) FROM builder_form_version WHERE form_key = ?', 's', [$formKey])) + 1;
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES);

        return [
            'form_key' => $formKey,
            'next_version_number' => $nextVersion,
            'schema_hash' => hash('sha256', (string) $snapshotJson),
            'field_count' => count($snapshot['fields']),
            'layout_count' => count($snapshot['layouts']),
            'warnings' => $this->warnings($snapshot),
            'schema_snapshot' => $snapshot,
        ];
    }

    public function publish(string $formKey, ?string $userKey = null): array
    {
        $preview = $this->preview($formKey);
        $versionKey = $this->uuid();
        $snapshotJson = json_encode($preview['schema_snapshot'], JSON_UNESCAPED_SLASHES);
        $versionNumber = (int) $preview['next_version_number'];

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO builder_form_version
                (version_key, form_key, version_number, version_status, schema_snapshot, published_at, created_by_key)
                VALUES (?, ?, ?, 'PUBLISHED', ?, CURRENT_TIMESTAMP, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $stmt->bind_param('ssiss', $versionKey, $formKey, $versionNumber, $snapshotJson, $userKey);
            $stmt->execute();

            $stmt = $this->db->prepare(
                "UPDATE builder_form
                SET form_schema_version = ?, form_status = 'ACTIVE', form_updated_by_key = ?
                WHERE form_key = ? AND form_status <> 'DELETED'"
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $stmt->bind_param('iss', $versionNumber, $userKey, $formKey);
            $stmt->execute();
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }

        return array_merge($preview, [
            'version_key' => $versionKey,
            'version_number' => $versionNumber,
            'version_status' => 'PUBLISHED',
        ]);
    }

    public function snapshot(string $formKey): array
    {
        $this->assertUuid($formKey, 'form_key');
        $form = $this->row('SELECT * FROM builder_form WHERE form_key = ? AND form_status <> \'DELETED\' LIMIT 1', 's', [$formKey]);
        if (!$form) {
            throw new RuntimeException('Form was not found.');
        }

        return [
            'form' => $form,
            'fields' => $this->rows(
                "SELECT * FROM builder_form_field WHERE form_key = ? AND field_status <> 'DELETED' ORDER BY field_sort_order ASC, x_id ASC",
                's',
                [$formKey]
            ),
            'layouts' => $this->rows(
                "SELECT * FROM builder_form_layout WHERE form_key = ? AND layout_status <> 'DELETED' ORDER BY layout_type ASC, layout_sort_order ASC, x_id ASC",
                's',
                [$formKey]
            ),
        ];
    }

    private function warnings(array $snapshot): array
    {
        $warnings = [];
        $columns = [];
        foreach ($snapshot['fields'] as $field) {
            $columns[(string) $field['database_column_name']] = true;
            if ((int) ($field['is_required'] ?? 0) === 1 && (string) ($field['field_status'] ?? '') !== 'ACTIVE') {
                $warnings[] = 'Required field is not ACTIVE: ' . (string) $field['field_code'];
            }
        }

        foreach ($snapshot['fields'] as $field) {
            $formula = trim((string) ($field['formula_expression'] ?? ''));
            if ($formula === '') {
                continue;
            }
            preg_match_all('/\\{([a-zA-Z][a-zA-Z0-9_]*)\\}/', $formula, $matches);
            foreach ($matches[1] ?? [] as $column) {
                if (!isset($columns[$column])) {
                    $warnings[] = 'Formula references an unknown field column: ' . $column;
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    private function row(string $sql, string $types, array $params): ?array
    {
        $rows = $this->rows($sql, $types, $params);
        return $rows[0] ?? null;
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

    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
