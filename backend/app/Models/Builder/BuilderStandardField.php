<?php
declare(strict_types=1);

namespace App\Models\Builder;

final class BuilderStandardField
{
    public const TABLE = 'builder_standard_field';
    public const PRIMARY_KEY = 'standard_field_key';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INACTIVE = 'INACTIVE';
    public const STATUS_DELETED = 'DELETED';

    public const FILLABLE = [
        'field_name',
        'field_type',
        'field_length',
        'field_default',
        'is_nullable',
        'is_required',
        'is_indexed',
        'index_name',
        'index_columns',
        'schema_version',
        'field_status',
        'field_sort_order',
    ];
}
