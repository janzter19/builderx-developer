<?php
declare(strict_types=1);

namespace App\Models\Data;

final class DataRecordRegistry
{
    public const TABLE = 'data_record';
    public const PRIMARY_KEY = 'record_key';
    public const TABLE_NAME_PATTERN = '/^data_record_[1-9][0-9]*$/';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INACTIVE = 'INACTIVE';
    public const STATUS_ARCHIVED = 'ARCHIVED';
    public const STATUS_DELETED = 'DELETED';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_ARCHIVED,
        self::STATUS_DELETED,
    ];

    public const PUBLICATION_PENDING = 'PENDING';
    public const PUBLICATION_CREATING_TABLE = 'CREATING_TABLE';
    public const PUBLICATION_ACTIVE = 'ACTIVE';
    public const PUBLICATION_FAILED = 'FAILED';

    public const PUBLICATION_STATUSES = [
        self::PUBLICATION_PENDING,
        self::PUBLICATION_CREATING_TABLE,
        self::PUBLICATION_ACTIVE,
        self::PUBLICATION_FAILED,
    ];

    public const FILLABLE = [
        'form_key',
        'branch_key',
        'project_key',
        'record_table_name',
        'record_schema_version',
        'record_status',
        'schema_hash',
        'schema_snapshot',
        'publication_status',
        'publication_error',
    ];

    public static function tableNameForId(int $xId): string
    {
        return 'data_record_' . $xId;
    }

    public static function isValidGeneratedTableName(string $tableName): bool
    {
        return preg_match(self::TABLE_NAME_PATTERN, $tableName) === 1;
    }
}
