<?php
declare(strict_types=1);

namespace App\Models\Builder;

final class BuilderForm
{
    public const TABLE = 'builder_form';
    public const PRIMARY_KEY = 'form_key';

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

    public const FILLABLE = [
        'branch_key',
        'project_key',
        'form_code',
        'form_name',
        'form_description',
        'form_table_name',
        'form_schema_version',
        'form_status',
        'form_settings',
    ];
}
