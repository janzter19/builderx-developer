<?php
declare(strict_types=1);

namespace App\Services\Record;

use App\Security\PhysicalTableNameGuard;
use App\Services\TableBuilder\BackendTableResolver;
use mysqli;
use RuntimeException;

final class RecordAttachmentService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly BackendTableResolver $tableResolver,
        private readonly PhysicalTableNameGuard $tableNameGuard = new PhysicalTableNameGuard(),
    ) {
    }

    public function register(array $payload, ?string $userKey = null): array
    {
        $this->tableNameGuard->assertNoFrontendTableName($payload);
        $registry = $this->tableResolver->resolveByDataRecordKey((string) ($payload['data_record_key'] ?? ''));
        $recordKey = (string) ($payload['record_key'] ?? '');
        $this->assertUuid($recordKey, 'record_key');

        $attachmentKey = $this->uuid();
        $originalName = basename((string) ($payload['original_name'] ?? ''));
        $storedName = (string) ($payload['stored_name'] ?? $attachmentKey);
        $storagePath = (string) ($payload['storage_path'] ?? '');
        $fileSize = (int) ($payload['file_size'] ?? 0);

        if ($originalName === '' || $storagePath === '' || str_contains($storagePath, '..')) {
            throw new RuntimeException('Attachment name and storage path are required.');
        }
        if ($fileSize < 0 || $fileSize > 52428800) {
            throw new RuntimeException('Attachment size exceeds the configured 50 MB limit.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO builder_attachment (
                attachment_key, data_record_key, record_key, form_key, branch_key, project_key, field_key,
                original_name, stored_name, storage_path, mime_type, file_size, checksum_sha256, created_by_key
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $dataRecordKey = (string) $registry['record_key'];
        $formKey = (string) $registry['form_key'];
        $branchKey = (string) $registry['branch_key'];
        $projectKey = (string) $registry['project_key'];
        $fieldKey = $payload['field_key'] ?? null;
        $mimeType = $payload['mime_type'] ?? null;
        $checksum = $payload['checksum_sha256'] ?? null;
        $stmt->bind_param(
            'sssssssssssiss',
            $attachmentKey,
            $dataRecordKey,
            $recordKey,
            $formKey,
            $branchKey,
            $projectKey,
            $fieldKey,
            $originalName,
            $storedName,
            $storagePath,
            $mimeType,
            $fileSize,
            $checksum,
            $userKey
        );
        $stmt->execute();

        return $this->get($attachmentKey);
    }

    public function download(string $attachmentKey, ?string $userKey = null): array
    {
        $this->assertUuid($attachmentKey, 'attachment_key');
        $attachment = $this->get($attachmentKey);
        if ($attachment === [] || (string) ($attachment['attachment_status'] ?? '') !== 'ACTIVE') {
            throw new RuntimeException('Attachment was not found.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO builder_attachment_download (download_key, attachment_key, downloaded_by_key) VALUES (?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $downloadKey = $this->uuid();
        $stmt->bind_param('sss', $downloadKey, $attachmentKey, $userKey);
        $stmt->execute();

        return $attachment;
    }

    public function listForRecord(string $dataRecordKey, string $recordKey): array
    {
        $this->tableResolver->resolveByDataRecordKey($dataRecordKey);
        $this->assertUuid($recordKey, 'record_key');
        $stmt = $this->db->prepare(
            "SELECT * FROM builder_attachment
            WHERE data_record_key = ? AND record_key = ? AND attachment_status = 'ACTIVE'
            ORDER BY created_at DESC, x_id DESC"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('ss', $dataRecordKey, $recordKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function softDelete(string $attachmentKey, ?string $userKey = null): void
    {
        $this->assertUuid($attachmentKey, 'attachment_key');
        $stmt = $this->db->prepare(
            "UPDATE builder_attachment
            SET attachment_status = 'DELETED', deleted_at = CURRENT_TIMESTAMP, deleted_by_key = ?
            WHERE attachment_key = ? AND attachment_status = 'ACTIVE'"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('ss', $userKey, $attachmentKey);
        $stmt->execute();
    }

    private function get(string $attachmentKey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM builder_attachment WHERE attachment_key = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $attachmentKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [];
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
