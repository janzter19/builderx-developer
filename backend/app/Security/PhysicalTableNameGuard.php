<?php
declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class PhysicalTableNameGuard
{
    private const FORBIDDEN_KEYS = [
        'table',
        'table_name',
        'tableName',
        'physical_table',
        'physicalTable',
        'physical_table_name',
        'physicalTableName',
        'record_table_name',
        'recordTableName',
        'generated_table',
        'generatedTable',
    ];

    private const GENERATED_TABLE_PATTERN = '/^data_record_[1-9][0-9]*$/';

    public function assertNoFrontendTableName(array $payload): void
    {
        $this->walk($payload);
    }

    public function isGeneratedTableName(string $value): bool
    {
        return preg_match(self::GENERATED_TABLE_PATTERN, $value) === 1;
    }

    private function walk(array $payload, string $path = ''): void
    {
        foreach ($payload as $key => $value) {
            $keyName = is_string($key) ? $key : (string) $key;
            $currentPath = $path === '' ? $keyName : $path . '.' . $keyName;

            if (in_array($keyName, self::FORBIDDEN_KEYS, true)) {
                throw new RuntimeException("Frontend-supplied physical table name is not allowed: {$currentPath}");
            }

            if (is_string($value) && $this->isGeneratedTableName($value)) {
                throw new RuntimeException("Frontend-supplied generated table name is not allowed: {$currentPath}");
            }

            if (is_array($value)) {
                $this->walk($value, $currentPath);
            }
        }
    }
}
