<?php
declare(strict_types=1);

namespace App\Services\TableBuilder;

use mysqli;
use RuntimeException;

final class BackendTableResolver
{
    public function __construct(
        private readonly mysqli $db,
        private readonly TableNameResolver $tableNameResolver = new TableNameResolver(),
    ) {
    }

    public function resolveByFormKey(string $formKey, ?string $branchKey = null, ?string $projectKey = null): array
    {
        $this->assertUuid($formKey, 'form_key');

        $sql = "SELECT *
            FROM data_record
            WHERE form_key = ?
                AND record_status = 'ACTIVE'
                AND publication_status = 'ACTIVE'
                AND record_table_name IS NOT NULL";
        $params = [$formKey];
        $types = 's';

        if ($branchKey !== null) {
            $this->assertUuid($branchKey, 'branch_key');
            $sql .= ' AND branch_key = ?';
            $params[] = $branchKey;
            $types .= 's';
        }

        if ($projectKey !== null) {
            $this->assertUuid($projectKey, 'project_key');
            $sql .= ' AND project_key = ?';
            $params[] = $projectKey;
            $types .= 's';
        }

        $sql .= ' LIMIT 1';

        return $this->resolve($sql, $types, $params);
    }

    public function resolveByDataRecordKey(string $dataRecordKey): array
    {
        $this->assertUuid($dataRecordKey, 'data_record_key');

        return $this->resolve(
            "SELECT *
            FROM data_record
            WHERE record_key = ?
                AND record_status = 'ACTIVE'
                AND publication_status = 'ACTIVE'
                AND record_table_name IS NOT NULL
            LIMIT 1",
            's',
            [$dataRecordKey]
        );
    }

    private function resolve(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $registry = $stmt->get_result()->fetch_assoc();

        if (!$registry) {
            throw new RuntimeException('Active data_record registry entry was not found.');
        }

        $tableName = $this->tableNameResolver->assertValid((string) $registry['record_table_name']);
        $expectedTableName = $this->tableNameResolver->forRegistryId((int) $registry['x_id']);

        if ($tableName !== $expectedTableName) {
            throw new RuntimeException('Resolved table name does not match registry x_id.');
        }

        if (!$this->tableExists($tableName)) {
            throw new RuntimeException('Resolved generated table does not exist.');
        }

        $registry['record_table_name'] = $tableName;
        return $registry;
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();

        return (int) $stmt->get_result()->fetch_row()[0] > 0;
    }

    private function assertUuid(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value) !== 1) {
            throw new RuntimeException("Invalid {$field}.");
        }
    }
}
