<?php
declare(strict_types=1);

namespace App\Services\Record;

use App\Services\TableBuilder\BackendTableResolver;
use App\Services\TableBuilder\TableSchemaBuilder;
use mysqli;
use RuntimeException;

final class RecordSoftDeleteService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly BackendTableResolver $tableResolver,
        private readonly TableSchemaBuilder $schemaBuilder = new TableSchemaBuilder(),
        private readonly RecordIndexService|null $recordIndexService = null,
    ) {
    }

    public function delete(string $dataRecordKey, string $recordKey, ?string $userKey = null): void
    {
        $registry = $this->tableResolver->resolveByDataRecordKey($dataRecordKey);
        $tableName = (string) $registry['record_table_name'];

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE ' . $this->schemaBuilder->quoteIdentifier($tableName)
                . " SET record_status = 'DELETED', deleted_at = CURRENT_TIMESTAMP, deleted_by_key = ?, updated_by_key = ? WHERE record_key = ? AND record_status <> 'DELETED'"
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }

            $stmt->bind_param('sss', $userKey, $userKey, $recordKey);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                throw new RuntimeException('Record soft delete did not affect an active row.');
            }

            ($this->recordIndexService ?? new RecordIndexService($this->db))->markStatus($recordKey, 'DELETED');
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function restore(string $dataRecordKey, string $recordKey, ?string $userKey = null): void
    {
        $registry = $this->tableResolver->resolveByDataRecordKey($dataRecordKey);
        $tableName = (string) $registry['record_table_name'];

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE ' . $this->schemaBuilder->quoteIdentifier($tableName)
                . " SET record_status = 'ACTIVE', deleted_at = NULL, deleted_by_key = NULL, updated_by_key = ? WHERE record_key = ? AND record_status = 'DELETED'"
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }

            $stmt->bind_param('ss', $userKey, $recordKey);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                throw new RuntimeException('Record restore did not affect a deleted row.');
            }

            ($this->recordIndexService ?? new RecordIndexService($this->db))->markStatus($recordKey, 'ACTIVE');
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
