<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use Throwable;

final class McpFileServer
{
    private const SERVER_NAME = 'builderx-file-service';
    private const SERVER_VERSION = '1.0.0';
    private const LEGACY_PROTOCOL_VERSION = '2025-11-25';

    public function __construct(
        private readonly FileServiceGateway $fileService,
        private readonly ?CodexDesktopTaskConnector $taskConnector = null
    )
    {
    }

    /** @return array<string, mixed>|null */
    public function handle(array $request): ?array
    {
        $hasId = array_key_exists('id', $request);
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? null;

        if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || $method === '') {
            return $hasId ? $this->error($id, -32600, 'Invalid Request.') : null;
        }

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $this->success($id, $this->initialize($request['params'] ?? [])),
                'ping' => $this->success($id, []),
                'tools/list' => $this->success($id, ['tools' => $this->tools()]),
                'tools/call' => $this->success($id, $this->callTool($request['params'] ?? [])),
                default => $this->error($id, -32601, 'Method not found.'),
            };
        } catch (InvalidArgumentException $error) {
            return $this->error($id, -32602, $error->getMessage());
        } catch (Throwable $error) {
            return $this->error($id, -32603, 'Internal MCP server error.');
        }
    }

    /** @return array<string, mixed> */
    private function initialize(mixed $params): array
    {
        if (!is_array($params)) {
            throw new InvalidArgumentException('initialize params must be an object.');
        }

        $requested = (string) ($params['protocolVersion'] ?? '');
        $protocolVersion = $requested !== '' ? $requested : self::LEGACY_PROTOCOL_VERSION;

        return [
            'protocolVersion' => $protocolVersion,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
            'instructions' => $this->taskConnector === null
                ? 'Use only the allowlisted BuilderX file tools. Sensitive, database, backup, audit, delete, move, and AI communication operations are unavailable here.'
                : 'Use the allowlisted BuilderX file tools for project files. For BuilderX AI work, call builderx_ai_tasks_next, claim one queued task with builderx_ai_task_claim, perform the requested work in this Codex Desktop task, then publish the correlated result with builderx_ai_task_complete. Do not access the communication directory as a file path.',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        $path = ['type' => 'string', 'maxLength' => 1024];
        $limit = ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100];

        $tools = [
            [
                'name' => 'builderx_files_list',
                'description' => 'List files and directories under the BuilderX allowlist.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['path' => $path, 'limit' => $limit]],
            ],
            [
                'name' => 'builderx_files_search',
                'description' => 'Search file names and bounded text contents under the BuilderX allowlist.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['query'], 'properties' => ['query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256], 'path' => $path, 'limit' => $limit]],
            ],
            [
                'name' => 'builderx_files_read',
                'description' => 'Read one non-protected BuilderX project file.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['path'], 'properties' => ['path' => $path]],
            ],
            [
                'name' => 'builderx_files_create',
                'description' => 'Create one file through the controlled www-data File Service identity.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['path', 'contents'], 'properties' => ['path' => $path, 'contents' => ['type' => 'string', 'maxLength' => 1048576]]],
            ],
            [
                'name' => 'builderx_files_update',
                'description' => 'Update one file through the controlled www-data File Service identity.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['path', 'contents'], 'properties' => ['path' => $path, 'contents' => ['type' => 'string', 'maxLength' => 1048576]]],
            ],
        ];

        if ($this->taskConnector !== null) {
            $tools[] = [
                'name' => 'builderx_ai_tasks_next',
                'description' => 'List queued BuilderX AI requests available for Codex Desktop. This is the approved inbox-to-Desktop task boundary; it does not expose the communication directory.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 1]]],
            ];
            $tools[] = [
                'name' => 'builderx_ai_task_claim',
                'description' => 'Claim one queued BuilderX AI request for the current Codex Desktop session and return its bounded prompt context.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['task_id'], 'properties' => ['task_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]]],
            ];
            $tools[] = [
                'name' => 'builderx_ai_task_complete',
                'description' => 'Publish a completed or failed Codex Desktop result for BuilderX server reconciliation.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['task_id', 'status'], 'properties' => ['task_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128], 'status' => ['type' => 'string', 'enum' => ['completed', 'failed', 'cancelled']], 'output' => ['type' => 'object'], 'error' => ['type' => 'object']]],
            ];
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    private function callTool(mixed $params): array
    {
        if (!is_array($params) || !is_string($params['name'] ?? null)) {
            throw new InvalidArgumentException('tools/call requires a tool name.');
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new InvalidArgumentException('Tool arguments must be an object.');
        }

        $name = $params['name'];
        $data = match ($name) {
            'builderx_files_list' => $this->fileService->list((string) ($arguments['path'] ?? '.'), (int) ($arguments['limit'] ?? 100)),
            'builderx_files_search' => $this->fileService->search((string) ($arguments['query'] ?? ''), (string) ($arguments['path'] ?? '.'), (int) ($arguments['limit'] ?? 100)),
            'builderx_files_read' => $this->fileService->read((string) ($arguments['path'] ?? '')),
            'builderx_files_create' => $this->fileService->write((string) ($arguments['path'] ?? ''), (string) ($arguments['contents'] ?? ''), false),
            'builderx_files_update' => $this->fileService->write((string) ($arguments['path'] ?? ''), (string) ($arguments['contents'] ?? ''), true),
            'builderx_ai_tasks_next' => $this->taskConnectorOrFail()->next((int) ($arguments['limit'] ?? 1)),
            'builderx_ai_task_claim' => $this->taskConnectorOrFail()->claim((string) ($arguments['task_id'] ?? '')),
            'builderx_ai_task_complete' => $this->taskConnectorOrFail()->complete(
                (string) ($arguments['task_id'] ?? ''),
                (string) ($arguments['status'] ?? ''),
                isset($arguments['output']) && is_array($arguments['output']) ? $arguments['output'] : null,
                isset($arguments['error']) && is_array($arguments['error']) ? $arguments['error'] : null
            ),
            default => throw new InvalidArgumentException('Unknown BuilderX MCP tool.'),
        };

        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
            'structuredContent' => ['data' => $data],
        ];
    }

    private function taskConnectorOrFail(): CodexDesktopTaskConnector
    {
        if ($this->taskConnector === null) {
            throw new InvalidArgumentException('Codex Desktop task tools are not enabled.');
        }

        return $this->taskConnector;
    }

    /** @return array<string, mixed> */
    private function success(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
