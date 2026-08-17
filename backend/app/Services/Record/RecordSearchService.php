<?php
declare(strict_types=1);

namespace App\Services\Record;

use App\Security\PhysicalTableNameGuard;
use mysqli;
use RuntimeException;

final class RecordSearchService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly PhysicalTableNameGuard $tableNameGuard = new PhysicalTableNameGuard(),
    ) {
    }

    public function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $this->tableNameGuard->assertNoFrontendTableName($filters);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        [$sortColumn, $sortDirection] = $this->resolveSort($filters);

        [$where, $types, $params] = $this->buildWhere($filters);
        $sql = "SELECT SQL_CALC_FOUND_ROWS *
            FROM data_record_index
            {$where}
            ORDER BY {$sortColumn} {$sortDirection}, x_id DESC
            LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($this->db->error);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $total = (int) $this->db->query('SELECT FOUND_ROWS()')->fetch_row()[0];

        return [
            'rows' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    public function grid(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $result = $this->search($filters, $page, $perPage);
        $columns = [
            ['key' => 'display_value', 'label' => 'Record'],
            ['key' => 'document_number', 'label' => 'Document Number'],
            ['key' => 'record_status', 'label' => 'Status'],
            ['key' => 'updated_at', 'label' => 'Updated'],
        ];

        if (!empty($filters['columns']) && is_array($filters['columns'])) {
            $columns = $this->filterColumns($filters['columns']);
        }

        $result['columns'] = $columns;
        $result['sort'] = [
            'field' => $filters['sort'] ?? 'updated_at',
            'direction' => strtoupper((string) ($filters['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        ];

        return $result;
    }

    private function buildWhere(array $filters): array
    {
        $clauses = ["record_status <> 'DELETED'"];
        $types = '';
        $params = [];

        foreach (['form_key', 'branch_key', 'project_key', 'data_record_key'] as $field) {
            if (!empty($filters[$field])) {
                $this->assertUuid((string) $filters[$field], $field);
                $clauses[] = "{$field} = ?";
                $types .= 's';
                $params[] = (string) $filters[$field];
            }
        }

        if (!empty($filters['record_status'])) {
            $clauses[] = 'record_status = ?';
            $types .= 's';
            $params[] = (string) $filters['record_status'];
        }

        if (!empty($filters['document_number'])) {
            $clauses[] = 'document_number = ?';
            $types .= 's';
            $params[] = (string) $filters['document_number'];
        }

        if (!empty($filters['created_from'])) {
            $clauses[] = 'created_at >= ?';
            $types .= 's';
            $params[] = (string) $filters['created_from'];
        }

        if (!empty($filters['created_to'])) {
            $clauses[] = 'created_at <= ?';
            $types .= 's';
            $params[] = (string) $filters['created_to'];
        }

        if (!empty($filters['q'])) {
            $clauses[] = 'search_text LIKE ?';
            $types .= 's';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
        }

        return ['WHERE ' . implode(' AND ', $clauses), $types, $params];
    }

    private function resolveSort(array $filters): array
    {
        $allowed = [
            'display_value' => 'display_value',
            'document_number' => 'document_number',
            'record_status' => 'record_status',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
        $sort = (string) ($filters['sort'] ?? 'updated_at');
        $direction = strtoupper((string) ($filters['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        return [$allowed[$sort] ?? 'updated_at', $direction];
    }

    private function filterColumns(array $columns): array
    {
        $labels = [
            'display_value' => 'Record',
            'document_number' => 'Document Number',
            'record_status' => 'Status',
            'created_at' => 'Created',
            'updated_at' => 'Updated',
            'direct_url' => 'URL',
        ];
        $filtered = [];

        foreach ($columns as $column) {
            $key = is_array($column) ? (string) ($column['key'] ?? '') : (string) $column;
            if (isset($labels[$key])) {
                $filtered[] = ['key' => $key, 'label' => $labels[$key]];
            }
        }

        return $filtered ?: [
            ['key' => 'display_value', 'label' => 'Record'],
            ['key' => 'document_number', 'label' => 'Document Number'],
            ['key' => 'record_status', 'label' => 'Status'],
            ['key' => 'updated_at', 'label' => 'Updated'],
        ];
    }

    private function assertUuid(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value) !== 1) {
            throw new RuntimeException("Invalid {$field}.");
        }
    }
}
