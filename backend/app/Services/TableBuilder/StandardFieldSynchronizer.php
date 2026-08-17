<?php
declare(strict_types=1);

namespace App\Services\TableBuilder;

use mysqli;
use RuntimeException;

final class StandardFieldSynchronizer
{
    public function __construct(
        private readonly mysqli $db,
        private readonly TableNameResolver $tableNameResolver = new TableNameResolver(),
        private readonly TableSchemaBuilder $schemaBuilder = new TableSchemaBuilder(),
    ) {
    }

    public function synchronizeActiveTables(): array
    {
        $fields = $this->getActiveStandardFields();
        $tables = $this->getActiveGeneratedTables();
        $results = [];

        foreach ($tables as $registry) {
            $tableName = (string) $registry['record_table_name'];
            $this->assertValidRegistryTable($registry);
            $results[$tableName] = $this->synchronizeTable($tableName, $fields, (int) $registry['record_schema_version']);
        }

        return $results;
    }

    public function synchronizeTable(string $tableName, array $fields, int $currentVersion = 1): array
    {
        $this->assertTableExists($tableName);
        $existingColumns = $this->getExistingColumns($tableName);
        $added = [];
        $maxVersion = $currentVersion;

        foreach ($fields as $field) {
            $fieldName = (string) ($field['field_name'] ?? '');
            $maxVersion = max($maxVersion, (int) ($field['schema_version'] ?? 1));

            if ($fieldName === 'x_id' || isset($existingColumns[$fieldName])) {
                continue;
            }

            $this->backupSchema($tableName);
            $sql = 'ALTER TABLE ' . $this->schemaBuilder->quoteIdentifier($tableName)
                . ' ADD COLUMN ' . $this->schemaBuilder->columnSql($field);
            if (!$this->db->query($sql)) {
                throw new RuntimeException($this->db->error);
            }
            $added[] = $fieldName;
            $existingColumns[$fieldName] = true;
        }

        if ($added !== []) {
            $stmt = $this->db->prepare('UPDATE data_record SET record_schema_version = ? WHERE record_table_name = ?');
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $stmt->bind_param('is', $maxVersion, $tableName);
            $stmt->execute();
        }

        return [
            'added' => $added,
            'schema_version' => $maxVersion,
        ];
    }

    private function getActiveStandardFields(): array
    {
        $result = $this->db->query(
            "SELECT *
            FROM builder_standard_field
            WHERE field_status = 'ACTIVE'
            ORDER BY field_sort_order ASC, x_id ASC"
        );
        if (!$result) {
            throw new RuntimeException($this->db->error);
        }

        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $fields[] = $row;
        }

        return $fields;
    }

    private function getActiveGeneratedTables(): array
    {
        $result = $this->db->query(
            "SELECT *
            FROM data_record
            WHERE record_status = 'ACTIVE'
                AND publication_status = 'ACTIVE'
                AND record_table_name IS NOT NULL
                AND record_table_name <> ''
            ORDER BY x_id ASC"
        );
        if (!$result) {
            throw new RuntimeException($this->db->error);
        }

        $tables = [];
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row;
        }

        return $tables;
    }

    private function assertValidRegistryTable(array $registry): void
    {
        $expected = $this->tableNameResolver->forRegistryId((int) $registry['x_id']);
        $actual = (string) $registry['record_table_name'];

        if ($actual !== $expected) {
            throw new RuntimeException('Invalid generated table registry entry.');
        }
    }

    private function assertTableExists(string $tableName): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_row()[0] !== 1) {
            throw new RuntimeException('Generated table does not exist.');
        }
    }

    private function getExistingColumns(string $tableName): array
    {
        $stmt = $this->db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $result = $stmt->get_result();
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['COLUMN_NAME']] = true;
        }

        return $columns;
    }

    private function backupSchema(string $tableName): void
    {
        $dir = dirname(__DIR__, 3) . '/database/generated/rollback';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $result = $this->db->query('SHOW CREATE TABLE ' . $this->schemaBuilder->quoteIdentifier($tableName));
        if (!$result) {
            throw new RuntimeException($this->db->error);
        }

        $row = $result->fetch_assoc();
        $schema = (string) ($row['Create Table'] ?? '');
        file_put_contents($dir . '/' . $tableName . '-' . date('YmdHis') . '.sql', $schema . ";\n");
    }
}
