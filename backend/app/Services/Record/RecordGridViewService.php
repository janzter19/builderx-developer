<?php
declare(strict_types=1);

namespace App\Services\Record;

use App\Security\PhysicalTableNameGuard;
use mysqli;
use RuntimeException;

final class RecordGridViewService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly PhysicalTableNameGuard $tableNameGuard = new PhysicalTableNameGuard(),
    ) {
    }

    public function list(string $formKey): array
    {
        $this->assertUuid($formKey, 'form_key');
        $stmt = $this->db->prepare(
            "SELECT * FROM builder_record_grid_view
            WHERE form_key = ? AND view_status <> 'DELETED'
            ORDER BY is_default DESC, view_name ASC"
        );
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $formKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function save(array $payload, ?string $userKey = null): array
    {
        $this->tableNameGuard->assertNoFrontendTableName($payload);
        $viewKey = (string) ($payload['view_key'] ?? '');
        $formKey = (string) ($payload['form_key'] ?? '');
        $this->assertUuid($formKey, 'form_key');
        $schema = $this->normalizeSchema($payload['view_schema'] ?? []);

        if ($viewKey === '') {
            $viewKey = $this->uuid();
            $stmt = $this->db->prepare(
                "INSERT INTO builder_record_grid_view
                (view_key, form_key, view_name, view_schema, is_default, view_status, created_by_key, updated_by_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $name = trim((string) ($payload['view_name'] ?? 'Default view'));
            $status = (string) ($payload['view_status'] ?? 'ACTIVE');
            $isDefault = (int) ($payload['is_default'] ?? 0) === 1 ? 1 : 0;
            $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES);
            $stmt->bind_param('ssssisss', $viewKey, $formKey, $name, $schemaJson, $isDefault, $status, $userKey, $userKey);
            $stmt->execute();
        } else {
            $this->assertUuid($viewKey, 'view_key');
            $stmt = $this->db->prepare(
                "UPDATE builder_record_grid_view
                SET view_name = ?, view_schema = ?, is_default = ?, view_status = ?, updated_by_key = ?
                WHERE view_key = ? AND form_key = ?"
            );
            if (!$stmt) {
                throw new RuntimeException($this->db->error);
            }
            $name = trim((string) ($payload['view_name'] ?? 'Default view'));
            $status = (string) ($payload['view_status'] ?? 'ACTIVE');
            $isDefault = (int) ($payload['is_default'] ?? 0) === 1 ? 1 : 0;
            $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES);
            $stmt->bind_param('ssissss', $name, $schemaJson, $isDefault, $status, $userKey, $viewKey, $formKey);
            $stmt->execute();
        }

        if ((int) ($payload['is_default'] ?? 0) === 1) {
            $this->db->query("UPDATE builder_record_grid_view SET is_default = 0 WHERE form_key = '" . $this->db->real_escape_string($formKey) . "' AND view_key <> '" . $this->db->real_escape_string($viewKey) . "'");
        }

        return $this->get($viewKey);
    }

    public function delete(string $viewKey, ?string $userKey = null): void
    {
        $this->assertUuid($viewKey, 'view_key');
        $stmt = $this->db->prepare("UPDATE builder_record_grid_view SET view_status = 'DELETED', updated_by_key = ? WHERE view_key = ?");
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('ss', $userKey, $viewKey);
        $stmt->execute();
    }

    private function get(string $viewKey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM builder_record_grid_view WHERE view_key = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param('s', $viewKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [];
    }

    private function normalizeSchema(mixed $schema): array
    {
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            $schema = is_array($decoded) ? $decoded : [];
        }
        $schema = is_array($schema) ? $schema : [];

        return [
            'columns' => array_values(array_filter((array) ($schema['columns'] ?? []), 'is_array')),
            'filters' => is_array($schema['filters'] ?? null) ? $schema['filters'] : [],
            'sort' => is_array($schema['sort'] ?? null) ? $schema['sort'] : ['field' => 'updated_at', 'direction' => 'DESC'],
            'density' => in_array(($schema['density'] ?? 'compact'), ['compact', 'standard', 'comfortable'], true) ? $schema['density'] : 'compact',
            'pinned' => is_array($schema['pinned'] ?? null) ? $schema['pinned'] : [],
        ];
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
