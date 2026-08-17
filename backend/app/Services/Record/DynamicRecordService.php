<?php
declare(strict_types=1);

namespace App\Services\Record;

use App\Security\PhysicalTableNameGuard;
use App\Services\TableBuilder\BackendTableResolver;
use App\Services\TableBuilder\TableSchemaBuilder;
use mysqli;
use RuntimeException;

final class DynamicRecordService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly BackendTableResolver $tableResolver,
        private readonly PhysicalTableNameGuard $tableNameGuard = new PhysicalTableNameGuard(),
        private readonly TableSchemaBuilder $schemaBuilder = new TableSchemaBuilder(),
        private readonly RecordIndexService|null $recordIndexService = null,
    ) {
    }

    public function prepareCreate(array $payload): array
    {
        $this->tableNameGuard->assertNoFrontendTableName($payload);

        $formKey = (string) ($payload['form_key'] ?? '');
        $branchKey = (string) ($payload['branch_key'] ?? '');
        $projectKey = (string) ($payload['project_key'] ?? '');
        $registry = $this->tableResolver->resolveByFormKey($formKey, $branchKey ?: null, $projectKey ?: null);

        return [
            'registry' => $registry,
            'fields' => is_array($payload['fields'] ?? null) ? $payload['fields'] : [],
        ];
    }

    public function prepareUpdate(array $payload): array
    {
        $this->tableNameGuard->assertNoFrontendTableName($payload);

        $dataRecordKey = (string) ($payload['data_record_key'] ?? '');
        $registry = $this->tableResolver->resolveByDataRecordKey($dataRecordKey);

        return [
            'registry' => $registry,
            'record_key' => (string) ($payload['record_key'] ?? ''),
            'fields' => is_array($payload['fields'] ?? null) ? $payload['fields'] : [],
        ];
    }

    public function create(array $payload, ?string $userKey = null): array
    {
        $prepared = $this->prepareCreate($payload);
        $registry = $prepared['registry'];
        $tableName = (string) $registry['record_table_name'];
        $recordKey = $this->uuid();
        $fields = $this->filterWritableFields((string) $registry['form_key'], $tableName, $prepared['fields']);
        $values = array_merge([
            'record_key' => $recordKey,
            'branch_key' => (string) $registry['branch_key'],
            'project_key' => (string) $registry['project_key'],
            'form_key' => (string) $registry['form_key'],
            'record_status' => (string) ($payload['record_status'] ?? 'ACTIVE'),
            'created_by_key' => $userKey,
            'updated_by_key' => $userKey,
        ], $fields);

        $this->db->begin_transaction();
        try {
            $this->insertRow($tableName, $values);
            $record = $this->getByRecordKey((string) $registry['record_key'], $recordKey);
            $this->indexService()->upsert($this->indexPayload($registry, $record));
            $this->db->commit();
            return $record;
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function update(array $payload, ?string $userKey = null): array
    {
        $prepared = $this->prepareUpdate($payload);
        $registry = $prepared['registry'];
        $recordKey = $prepared['record_key'];
        $tableName = (string) $registry['record_table_name'];
        $fields = $this->filterWritableFields((string) $registry['form_key'], $tableName, $prepared['fields']);
        $fields['updated_by_key'] = $userKey;

        $this->db->begin_transaction();
        try {
            $this->updateRow($tableName, $recordKey, $fields);
            $record = $this->getByRecordKey((string) $registry['record_key'], $recordKey);
            $this->indexService()->upsert($this->indexPayload($registry, $record));
            $this->db->commit();
            return $record;
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function getByRecordKey(string $dataRecordKey, string $recordKey): array
    {
        $registry = $this->tableResolver->resolveByDataRecordKey($dataRecordKey);
        $tableName = (string) $registry['record_table_name'];
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . $this->schemaBuilder->quoteIdentifier($tableName) . " WHERE record_key = ? AND record_status <> 'DELETED' LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $recordKey);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        if (!$record) {
            throw new RuntimeException('Record was not found.');
        }

        return $record;
    }

    private function insertRow(string $tableName, array $values): void
    {
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO ' . $this->schemaBuilder->quoteIdentifier($tableName)
            . ' (' . implode(', ', array_map([$this->schemaBuilder, 'quoteIdentifier'], $columns)) . ') VALUES (' . $placeholders . ')';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $params = array_values($values);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
    }

    private function updateRow(string $tableName, string $recordKey, array $values): void
    {
        if ($values === []) {
            return;
        }

        $assignments = array_map(
            fn (string $column): string => $this->schemaBuilder->quoteIdentifier($column) . ' = ?',
            array_keys($values)
        );
        $sql = 'UPDATE ' . $this->schemaBuilder->quoteIdentifier($tableName)
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE record_key = ? AND record_status <> \'DELETED\'';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $params = array_values($values);
        $params[] = $recordKey;
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            throw new RuntimeException('Record update did not affect an active row.');
        }
    }

    private function filterWritableFields(string $formKey, string $tableName, array $fields): array
    {
        $allowed = $this->getAllowedColumns($formKey, $tableName);
        $filtered = [];

        foreach ($fields as $column => $value) {
            if (isset($allowed[(string) $column])) {
                $filtered[(string) $column] = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return $filtered;
    }

    private function getAllowedColumns(string $formKey, string $tableName): array
    {
        $stmt = $this->db->prepare(
            "SELECT database_column_name
            FROM builder_form_field
            WHERE form_key = ?
                AND field_status <> 'DELETED'
                AND database_column_name IS NOT NULL
                AND database_column_name <> ''"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $formKey);
        $stmt->execute();
        $allowed = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $column = (string) $row['database_column_name'];
            if ($this->columnExists($tableName, $column)) {
                $allowed[$column] = true;
            }
        }

        return $allowed;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_row()[0] > 0;
    }

    private function indexPayload(array $registry, array $record): array
    {
        $displayValue = (string) ($record['record_key'] ?? '');
        return [
            'record_key' => (string) $record['record_key'],
            'data_record_key' => (string) $registry['record_key'],
            'form_key' => (string) $registry['form_key'],
            'branch_key' => (string) $registry['branch_key'],
            'project_key' => (string) $registry['project_key'],
            'record_table_name' => (string) $registry['record_table_name'],
            'record_status' => (string) $record['record_status'],
            'display_value' => $displayValue,
            'document_number' => null,
            'direct_url' => '/records/' . $registry['record_key'] . '/' . $record['record_key'],
            'search_text' => implode(' ', array_filter(array_map('strval', $record))),
        ];
    }

    private function indexService(): RecordIndexService
    {
        return $this->recordIndexService ?? new RecordIndexService($this->db);
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
