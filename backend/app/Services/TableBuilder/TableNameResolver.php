<?php
declare(strict_types=1);

namespace App\Services\TableBuilder;

final class TableNameResolver
{
    private const PATTERN = '/^data_record_[1-9][0-9]*$/';

    public function forRegistryId(int $xId): string
    {
        if ($xId < 1) {
            throw new \InvalidArgumentException('Registry x_id must be greater than zero.');
        }

        return 'data_record_' . $xId;
    }

    public function assertValid(string $tableName): string
    {
        if (preg_match(self::PATTERN, $tableName) !== 1) {
            throw new \InvalidArgumentException('Invalid generated table name.');
        }

        return $tableName;
    }
}
