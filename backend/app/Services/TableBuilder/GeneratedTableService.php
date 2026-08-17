<?php
declare(strict_types=1);

namespace App\Services\TableBuilder;

use mysqli;
use RuntimeException;

final class GeneratedTableService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly TableNameResolver $tableNameResolver = new TableNameResolver(),
        private readonly TableSchemaBuilder $schemaBuilder = new TableSchemaBuilder(),
    ) {
    }

    public function ensurePhysicalTableForRecordKey(string $recordKey): string
    {
        $stmt = $this->db->prepare('SELECT * FROM data_record WHERE record_key = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $recordKey);
        $stmt->execute();
        $registry = $stmt->get_result()->fetch_assoc();

        if (!$registry) {
            throw new RuntimeException('data_record registry row was not found.');
        }

        return $this->ensurePhysicalTable($registry);
    }

    public function ensurePhysicalTable(array $registry): string
    {
        $xId = (int) ($registry['x_id'] ?? 0);
        $recordKey = (string) ($registry['record_key'] ?? '');
        $tableName = $this->tableNameResolver->forRegistryId($xId);
        $existingTableName = (string) ($registry['record_table_name'] ?? '');

        if ($recordKey === '') {
            throw new RuntimeException('Registry row is missing record_key.');
        }

        if ($existingTableName !== '' && $existingTableName !== $tableName) {
            throw new RuntimeException('Registry row has a mismatched permanent table name.');
        }

        $this->db->begin_transaction();

        try {
            $this->setPublicationStatus($recordKey, 'CREATING_TABLE', null);
            $this->execute($this->schemaBuilder->createStandardTableSql($tableName, $this->getActiveStandardFields()));

            $stmt = $this->db->prepare(
                "UPDATE data_record
                SET record_table_name = ?,
                    publication_status = 'ACTIVE',
                    publication_error = NULL,
                    record_status = CASE WHEN record_status = 'DRAFT' THEN 'ACTIVE' ELSE record_status END
                WHERE record_key = ?"
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }

            $stmt->bind_param('ss', $tableName, $recordKey);
            $stmt->execute();
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            $this->setPublicationStatus($recordKey, 'FAILED', $error->getMessage());
            throw $error;
        }

        return $tableName;
    }

    private function setPublicationStatus(string $recordKey, string $status, ?string $error): void
    {
        $stmt = $this->db->prepare('UPDATE data_record SET publication_status = ?, publication_error = ? WHERE record_key = ?');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('sss', $status, $error, $recordKey);
        $stmt->execute();
    }

    private function execute(string $sql): void
    {
        if (!$this->db->query($sql)) {
            throw new RuntimeException($this->db->error);
        }
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
            return $this->schemaBuilder->fallbackStandardFields();
        }

        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $fields[] = $row;
        }

        return $fields === [] ? $this->schemaBuilder->fallbackStandardFields() : $fields;
    }
}
