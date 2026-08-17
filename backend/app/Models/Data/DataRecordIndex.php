<?php
declare(strict_types=1);

namespace App\Models\Data;

final class DataRecordIndex
{
    public const TABLE = 'data_record_index';
    public const PRIMARY_KEY = 'index_key';

    public const FILLABLE = [
        'record_key',
        'data_record_key',
        'form_key',
        'branch_key',
        'project_key',
        'record_table_name',
        'display_value',
        'document_number',
        'record_status',
        'search_text',
        'direct_url',
    ];

    public const DISCOVERY_COLUMNS = [
        'display_value',
        'document_number',
        'record_status',
        'search_text',
        'direct_url',
    ];
}
