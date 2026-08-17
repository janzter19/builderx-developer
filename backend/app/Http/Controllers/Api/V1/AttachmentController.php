<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Record\RecordAttachmentService;

final class AttachmentController
{
    public function __construct(private readonly RecordAttachmentService $attachments)
    {
    }

    public function index(string $dataRecordKey, string $recordKey): array
    {
        return $this->attachments->listForRecord($dataRecordKey, $recordKey);
    }

    public function store(array $payload, ?string $userKey = null): array
    {
        return $this->attachments->register($payload, $userKey);
    }

    public function download(string $attachmentKey, ?string $userKey = null): array
    {
        return $this->attachments->download($attachmentKey, $userKey);
    }

    public function destroy(string $attachmentKey, ?string $userKey = null): array
    {
        $this->attachments->softDelete($attachmentKey, $userKey);
        return ['deleted' => true, 'attachment_key' => $attachmentKey];
    }
}
