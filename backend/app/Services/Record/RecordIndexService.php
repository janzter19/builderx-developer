<?php
declare(strict_types=1);

namespace App\Services\Record;

use mysqli;
use RuntimeException;

final class RecordIndexService
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function upsert(array $record): void
    {
        $required = [
            'record_key',
            'data_record_key',
            'form_key',
            'branch_key',
            'project_key',
            'record_table_name',
            'record_status',
        ];

        foreach ($required as $key) {
            if (trim((string) ($record[$key] ?? '')) === '') {
                throw new RuntimeException("Missing required index field: {$key}");
            }
        }

        $indexKey = (string) ($record['index_key'] ?? $this->uuid());
        $displayValue = $this->nullable($record['display_value'] ?? null);
        $documentNumber = $this->nullable($record['document_number'] ?? null);
        $searchText = $this->nullable($record['search_text'] ?? $displayValue);
        $directUrl = $this->nullable($record['direct_url'] ?? null);

        $stmt = $this->db->prepare(
            "INSERT INTO data_record_index (
                index_key,
                record_key,
                data_record_key,
                form_key,
                branch_key,
                project_key,
                record_table_name,
                display_value,
                document_number,
                record_status,
                search_text,
                direct_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                data_record_key = VALUES(data_record_key),
                form_key = VALUES(form_key),
                branch_key = VALUES(branch_key),
                project_key = VALUES(project_key),
                record_table_name = VALUES(record_table_name),
                display_value = VALUES(display_value),
                document_number = VALUES(document_number),
                record_status = VALUES(record_status),
                search_text = VALUES(search_text),
                direct_url = VALUES(direct_url),
                updated_at = CURRENT_TIMESTAMP"
        );

        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param(
            'ssssssssssss',
            $indexKey,
            $record['record_key'],
            $record['data_record_key'],
            $record['form_key'],
            $record['branch_key'],
            $record['project_key'],
            $record['record_table_name'],
            $displayValue,
            $documentNumber,
            $record['record_status'],
            $searchText,
            $directUrl
        );
        $stmt->execute();
    }

    public function remove(string $recordKey): void
    {
        $stmt = $this->db->prepare('DELETE FROM data_record_index WHERE record_key = ?');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $recordKey);
        $stmt->execute();
    }

    public function markStatus(string $recordKey, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE data_record_index SET record_status = ?, updated_at = CURRENT_TIMESTAMP WHERE record_key = ?');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('ss', $status, $recordKey);
        $stmt->execute();
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(16384, 20479),
            random_int(32768, 49151),
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(0, 65535)
        );
    }
}
