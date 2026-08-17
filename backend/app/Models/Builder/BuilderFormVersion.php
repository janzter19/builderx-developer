<?php
declare(strict_types=1);

namespace App\Models\Builder;

final class BuilderFormVersion
{
    public const TABLE = 'builder_form_version';
    public const PRIMARY_KEY = 'version_key';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_ARCHIVED = 'ARCHIVED';
    public const STATUS_DELETED = 'DELETED';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_ARCHIVED,
        self::STATUS_DELETED,
    ];

    public const FILLABLE = [
        'form_key',
        'version_number',
        'version_status',
        'schema_snapshot',
        'published_at',
    ];
}
