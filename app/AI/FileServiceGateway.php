<?php
declare(strict_types=1);

namespace BuilderX\AI;

interface FileServiceGateway
{
    /** @return list<array<string, string|int>> */
    public function list(string $path = '.', int $limit = 100): array;

    /** @return list<array<string, string|int>> */
    public function search(string $query, string $path = '.', int $limit = 100): array;

    /** @return array<string, string|int> */
    public function read(string $path): array;

    /** @return array<string, string|int> */
    public function write(string $path, string $contents, bool $overwrite): array;
}
