#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/foundation.php';
require_once __DIR__ . '/../app/AI/FileServiceGateway.php';
require_once __DIR__ . '/../app/AI/FileService.php';
require_once __DIR__ . '/../app/AI/FileServiceClient.php';
require_once __DIR__ . '/../app/AI/CodexDesktopTaskConnector.php';
require_once __DIR__ . '/../app/AI/McpFileServer.php';

$root = getenv('BUILDERX_FILE_ROOT');
if ($root === false || trim($root) === '') {
    fwrite(STDERR, "BUILDERX_FILE_ROOT is required.\n");
    exit(2);
}

/**
 * Resolve the worker target from the Phase Manager setting first. The Desktop
 * MCP environment remains a fallback for first install or database outages.
 */
function builderxDesktopWorkerChatId(): string
{
    try {
        $setting = \bx_db()->GetRow(
            "SELECT setting_value FROM builder_system_setting WHERE setting_name = 'codex_chat_id' AND setting_status = 'ACTIVE' LIMIT 1"
        );
        if (is_array($setting)) {
            return trim((string) ($setting['setting_value'] ?? ''));
        }
    } catch (Throwable) {
        // Fall back to the Desktop MCP environment when the settings table is unavailable.
    }

    return trim((string) (getenv('BUILDERX_CODEX_CHAT_ID') ?: builderxConfigValue('codex_chat_id')));
}

try {
    $socketPath = trim((string) (getenv('BUILDERX_FILE_SERVICE_SOCKET') ?: ''));
    $gateway = $socketPath !== ''
        ? new \BuilderX\AI\FileServiceClient($socketPath)
        : new \BuilderX\AI\FileService([$root]);
    $server = new \BuilderX\AI\McpFileServer(
        $gateway,
        new \BuilderX\AI\CodexDesktopTaskConnector(
            \BuilderX\AI\CommunicationMessageStore::projectDefault(),
            new \BuilderX\AI\AiTaskStore(),
            builderxDesktopWorkerChatId()
        )
    );
} catch (Throwable $error) {
    fwrite(STDERR, "MCP File Service startup failed.\n");
    exit(3);
}

while (($line = fgets(STDIN)) !== false) {
    try {
        $request = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($request)) {
            throw new InvalidArgumentException('Request must be a JSON object.');
        }
        $response = $server->handle($request);
        if ($response !== null) {
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            fflush(STDOUT);
        }
    } catch (Throwable $error) {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32700, 'message' => 'Parse error.'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        fflush(STDOUT);
    }
}
