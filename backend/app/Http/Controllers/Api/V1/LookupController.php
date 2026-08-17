<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Builder\BuilderLookupService;

final class LookupController
{
    public function __construct(private readonly BuilderLookupService $lookups)
    {
    }

    public function tables(): array
    {
        return $this->lookups->listTables();
    }

    public function saveTable(array $payload, ?string $userKey = null): array
    {
        return $this->lookups->saveTable($payload, $userKey);
    }

    public function options(string $lookupTableKey): array
    {
        return $this->lookups->listOptions($lookupTableKey);
    }

    public function saveOption(array $payload, ?string $userKey = null): array
    {
        return $this->lookups->saveOption($payload, $userKey);
    }

    public function deleteTable(string $lookupTableKey, ?string $userKey = null): array
    {
        $this->lookups->deleteTable($lookupTableKey, $userKey);
        return ['deleted' => true, 'lookup_table_key' => $lookupTableKey];
    }

    public function deleteOption(string $lookupOptionKey, ?string $userKey = null): array
    {
        $this->lookups->deleteOption($lookupOptionKey, $userKey);
        return ['deleted' => true, 'lookup_option_key' => $lookupOptionKey];
    }
}
