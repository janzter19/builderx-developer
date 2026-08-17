<?php
declare(strict_types=1);

namespace App\Models\Builder;

final class BuilderFormField
{
    public const TABLE = 'builder_form_field';
    public const PRIMARY_KEY = 'field_key';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INACTIVE = 'INACTIVE';
    public const STATUS_DELETED = 'DELETED';

    public const FIELD_TYPES = [
        'text',
        'textarea',
        'number',
        'currency',
        'date',
        'datetime',
        'select',
        'checkbox',
        'file',
        'signature',
        'lookup',
        'formula',
        'child_table',
        'section',
    ];

    public const DATA_TYPES = [
        'string',
        'text',
        'integer',
        'decimal',
        'boolean',
        'date',
        'datetime',
        'json',
    ];

    public const FILLABLE = [
        'form_key',
        'field_code',
        'field_name',
        'field_label',
        'field_type',
        'data_type',
        'database_column_name',
        'field_sort_order',
        'field_status',
        'is_required',
        'is_unique',
        'is_searchable',
        'is_sortable',
        'default_value',
        'validation_rules',
        'option_source',
        'formula_expression',
        'field_settings',
    ];
}
