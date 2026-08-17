#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/AI/FileServiceGateway.php';
require_once __DIR__ . '/../app/AI/FileService.php';

$root = getenv('BUILDERX_FILE_ROOT');
if ($root === false || trim($root) === '') {
    fwrite(STDERR, "BUILDERX_FILE_ROOT is required.\n");
    exit(2);
}

try {
    $service = new \BuilderX\AI\FileService([$root]);
} catch (Throwable $error) {
    fwrite(STDERR, "File Service startup failed.\n");
    exit(3);
}

while (($line = fgets(STDIN)) !== false) {
    $requestId = '';
    try {
        $request = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($request)) {
            throw new InvalidArgumentException('Request must be a JSON object.');
        }
        $requestId = (string) ($request['request_id'] ?? '');
        if ($requestId === '' || strlen($requestId) > 128) {
            throw new InvalidArgumentException('request_id is required.');
        }
        $operation = (string) ($request['operation'] ?? '');
        $limit = (int) ($request['limit'] ?? 100);
        $data = match ($operation) {
            'list' => $service->list((string) ($request['path'] ?? '.'), $limit),
            'search' => $service->search((string) ($request['query'] ?? ''), (string) ($request['path'] ?? '.'), $limit),
            'read' => $service->read((string) ($request['path'] ?? '')),
            'create' => $service->write((string) ($request['path'] ?? ''), (string) ($request['contents'] ?? ''), false),
            'update' => $service->write((string) ($request['path'] ?? ''), (string) ($request['contents'] ?? ''), true),
            default => throw new InvalidArgumentException('Unsupported file-service operation.'),
        };
        echo json_encode(['request_id' => $requestId, 'ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        echo json_encode(['request_id' => $requestId, 'ok' => false, 'error' => ['code' => 'file_service_error', 'message' => $error->getMessage()]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    fflush(STDOUT);
}
