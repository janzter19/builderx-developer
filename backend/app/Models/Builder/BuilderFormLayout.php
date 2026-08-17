<?php
declare(strict_types=1);

namespace App\Models\Builder;

final class BuilderFormLayout
{
    public const TABLE = 'builder_form_layout';
    public const PRIMARY_KEY = 'layout_key';

    public const TYPE_FORM = 'FORM';
    public const TYPE_TABLE = 'TABLE';
    public const TYPE_DETAIL = 'DETAIL';
    public const TYPE_PRINT = 'PRINT';
    public const TYPE_MOBILE = 'MOBILE';

    public const TYPES = [
        self::TYPE_FORM,
        self::TYPE_TABLE,
        self::TYPE_DETAIL,
        self::TYPE_PRINT,
        self::TYPE_MOBILE,
    ];

    public const FILLABLE = [
        'form_key',
        'version_key',
        'layout_name',
        'layout_type',
        'layout_status',
        'layout_schema',
        'layout_sort_order',
    ];
}
