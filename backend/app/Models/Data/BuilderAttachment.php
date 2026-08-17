<?php
declare(strict_types=1);

namespace App\Models\Data;

final class BuilderAttachment
{
    public const TABLE = 'builder_attachment';
    public const PRIMARY_KEY = 'attachment_key';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_DELETED = 'DELETED';

    public const FILLABLE = [
        'data_record_key',
        'record_key',
        'form_key',
        'branch_key',
        'project_key',
        'field_key',
        'original_name',
        'stored_name',
        'storage_path',
        'mime_type',
        'file_size',
        'checksum_sha256',
        'attachment_status',
    ];
}
