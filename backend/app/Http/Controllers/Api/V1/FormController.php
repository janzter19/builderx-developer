<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Form\FormSchemaVersionService;

final class FormController
{
    public function __construct(private readonly FormSchemaVersionService $versions)
    {
    }

    public function schemaPreview(string $formKey): array
    {
        return $this->versions->preview($formKey);
    }

    public function publish(string $formKey, ?string $userKey = null): array
    {
        return $this->versions->publish($formKey, $userKey);
    }
}
