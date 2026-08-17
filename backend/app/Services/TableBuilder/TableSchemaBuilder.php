<?php
declare(strict_types=1);

namespace App\Services\TableBuilder;

final class TableSchemaBuilder
{
    public function createStandardTableSql(string $tableName, array $standardFields = []): string
    {
        $quotedTable = $this->quoteIdentifier($tableName);
        $fields = $standardFields === [] ? $this->fallbackStandardFields() : $standardFields;
        $columns = [];
        $indexes = [
            'PRIMARY' => 'PRIMARY KEY (`x_id`)',
        ];

        foreach ($fields as $field) {
            $columns[] = $this->columnSql($field);
            foreach ($this->indexSql($field) as $indexName => $indexSql) {
                $indexes[$indexName] = $indexSql;
            }
        }

        return "CREATE TABLE IF NOT EXISTS {$quotedTable} (\n            "
            . implode(",\n            ", array_merge($columns, array_values($indexes)))
            . "\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public function columnSql(array $field): string
    {
        $fieldName = (string) ($field['field_name'] ?? '');
        $fieldType = strtoupper(trim((string) ($field['field_type'] ?? '')));
        $fieldLength = trim((string) ($field['field_length'] ?? ''));
        $fieldDefault = $field['field_default'] ?? null;

        if (preg_match('/^[a-z][a-z0-9_]*$/', $fieldName) !== 1) {
            throw new \InvalidArgumentException('Invalid standard field name.');
        }

        if (preg_match("/^[A-Z0-9_ (),'\\-]+$/", $fieldType) !== 1) {
            throw new \InvalidArgumentException('Invalid standard field type.');
        }

        $sql = $this->quoteIdentifier($fieldName) . ' ' . $fieldType;
        if ($fieldLength !== '' && !str_contains($fieldType, '(')) {
            if (preg_match('/^[0-9, ]+$/', $fieldLength) !== 1) {
                throw new \InvalidArgumentException('Invalid standard field length.');
            }
            $sql .= '(' . $fieldLength . ')';
        }

        $sql .= (int) ($field['is_nullable'] ?? 1) === 1 ? ' NULL' : ' NOT NULL';

        if ($fieldDefault !== null && $fieldDefault !== '') {
            $default = strtoupper((string) $fieldDefault);
            if (in_array($default, ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'], true)) {
                $sql .= ' DEFAULT ' . $default;
                if ($default === 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP') {
                    $sql = str_replace(' DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', ' DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql);
                }
            } else {
                $sql .= " DEFAULT '" . str_replace("'", "''", (string) $fieldDefault) . "'";
            }
        }

        return $sql;
    }

    public function indexSql(array $field): array
    {
        if ((int) ($field['is_indexed'] ?? 0) !== 1) {
            return [];
        }

        $indexName = (string) ($field['index_name'] ?? '');
        $indexColumns = $field['index_columns'] ?? null;
        if (is_string($indexColumns)) {
            $decoded = json_decode($indexColumns, true);
            $indexColumns = is_array($decoded) ? $decoded : null;
        }

        if ($indexName === '' || !is_array($indexColumns) || $indexColumns === []) {
            return [];
        }

        $columns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $indexColumns);

        if ($indexName === 'PRIMARY') {
            return ['PRIMARY' => 'PRIMARY KEY (' . implode(', ', $columns) . ')'];
        }

        if (str_starts_with($indexName, 'uq_')) {
            return [$indexName => 'UNIQUE KEY ' . $this->quoteIdentifier($indexName) . ' (' . implode(', ', $columns) . ')'];
        }

        return [$indexName => 'INDEX ' . $this->quoteIdentifier($indexName) . ' (' . implode(', ', $columns) . ')'];
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL identifier.');
        }

        return '`' . $identifier . '`';
    }

    public function fallbackStandardFields(): array
    {
        $path = dirname(__DIR__, 3) . '/database/seeders/StandardFieldSeeder.php';
        $fields = is_file($path) ? require $path : [];

        return is_array($fields) ? $fields : [];
    }
}
