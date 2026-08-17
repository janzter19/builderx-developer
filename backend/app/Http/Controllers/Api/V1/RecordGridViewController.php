<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Record\RecordGridViewService;

final class RecordGridViewController
{
    public function __construct(private readonly RecordGridViewService $views)
    {
    }

    public function index(string $formKey): array
    {
        return $this->views->list($formKey);
    }

    public function save(array $payload, ?string $userKey = null): array
    {
        return $this->views->save($payload, $userKey);
    }

    public function destroy(string $viewKey, ?string $userKey = null): array
    {
        $this->views->delete($viewKey, $userKey);
        return ['deleted' => true, 'view_key' => $viewKey];
    }
}
