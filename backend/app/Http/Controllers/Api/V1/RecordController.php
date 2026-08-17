<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Record\DynamicRecordService;
use App\Services\Record\RecordSearchService;
use App\Services\Record\RecordSoftDeleteService;

final class RecordController
{
    public function __construct(
        private readonly DynamicRecordService $records,
        private readonly RecordSearchService $search,
        private readonly RecordSoftDeleteService $softDelete,
    ) {
    }

    public function index(array $query): array
    {
        return $this->search->grid(
            $query,
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 25)
        );
    }

    public function store(array $payload, ?string $userKey = null): array
    {
        return $this->records->create($payload, $userKey);
    }

    public function show(string $dataRecordKey, string $recordKey): array
    {
        return $this->records->getByRecordKey($dataRecordKey, $recordKey);
    }

    public function update(array $payload, ?string $userKey = null): array
    {
        return $this->records->update($payload, $userKey);
    }

    public function destroy(string $dataRecordKey, string $recordKey, ?string $userKey = null): array
    {
        $this->softDelete->delete($dataRecordKey, $recordKey, $userKey);
        return ['deleted' => true, 'record_key' => $recordKey];
    }

    public function restore(string $dataRecordKey, string $recordKey, ?string $userKey = null): array
    {
        $this->softDelete->restore($dataRecordKey, $recordKey, $userKey);
        return ['restored' => true, 'record_key' => $recordKey];
    }
}
