<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Security\PhysicalTableNameGuard;

final class ValidateJson
{
    public function __construct(private readonly PhysicalTableNameGuard $tableNameGuard = new PhysicalTableNameGuard())
    {
    }

    public function decode(string $body): array
    {
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            throw new \RuntimeException('Request body must be valid JSON object data.');
        }

        $this->tableNameGuard->assertNoFrontendTableName($payload);

        return $payload;
    }
}
