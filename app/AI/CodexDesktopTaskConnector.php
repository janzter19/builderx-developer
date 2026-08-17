<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class CodexDesktopTaskConnector
{
    public function __construct(
        private readonly CommunicationMessageStore $messages,
        private readonly AiTaskStore $tasks,
        ?string $chatId = null
    ) {
        $chatId = trim((string) ($chatId ?? (getenv('BUILDERX_CODEX_CHAT_ID') ?: '')));
        $this->fallbackChatId = $chatId !== '' ? $this->validateChatId($chatId) : null;
    }

    private readonly ?string $fallbackChatId;

    /**
     * Return queued BuilderX tasks without exposing communication paths.
     *
     * @return list<array<string, mixed>>
     */
    public function next(int $limit = 1): array
    {
        $limit = max(1, min($limit, 10));
        $tasks = [];
        foreach ($this->messages->list('inbox', 200) as $message) {
            if (!$this->isTaskRequest($message)) {
                continue;
            }

            $taskId = (string) ($message['payload']['task_id'] ?? '');
            $task = $taskId !== '' ? $this->tasks->find($taskId) : null;
            if ($task === null || (string) ($task['status'] ?? '') !== 'queued') {
                continue;
            }
            if (!$this->isRoutedToThisChat($task)) {
                continue;
            }

            $tasks[] = [
                'task_id' => $task['task_id'],
                'correlation_id' => $task['correlation_id'],
                'action' => $task['action'],
                'stage' => $task['stage'],
                'specialist' => $task['specialist'],
                'input' => $task['input'],
                'permissions' => $task['permissions'],
                'created_at' => $task['created_at'],
            ];
            if (count($tasks) >= $limit) {
                break;
            }
        }

        return $tasks;
    }

    /**
     * Atomically claim one inbox task and mark its database task as running.
     *
     * @return array<string, mixed>
     */
    public function claim(string $taskId): array
    {
        $taskId = $this->validateTaskId($taskId);
        $task = $this->tasks->find($taskId);
        if ($task === null) {
            throw new InvalidArgumentException('The BuilderX AI task was not found.');
        }
        if ((string) ($task['status'] ?? '') !== 'queued') {
            throw new InvalidArgumentException('The BuilderX AI task is not queued.');
        }
        if (!$this->isRoutedToThisChat($task)) {
            throw new InvalidArgumentException('The BuilderX AI task is routed to a different Codex Desktop chat.');
        }

        $message = $this->messages->read($taskId, 'inbox');
        if (!$this->isTaskRequest($message) || (string) ($message['correlation_id'] ?? '') !== (string) $task['correlation_id']) {
            throw new RuntimeException('The BuilderX inbox request does not match the AI task.');
        }

        $this->messages->claim($taskId, 'inbox', 900);
        try {
            $running = $this->tasks->transition($taskId, 'running');
        } catch (\Throwable $error) {
            $this->messages->move($taskId, 'locks', 'inbox');
            throw $error;
        }

        return [
            'task' => $running,
            'request' => [
                'task_id' => $taskId,
                'correlation_id' => $running['correlation_id'],
                'action' => $running['action'],
                'stage' => $running['stage'],
                'specialist' => $running['specialist'],
                'input' => $running['input'],
                'permissions' => $running['permissions'],
            ],
            'message' => 'BuilderX task claimed by Codex Desktop.',
        ];
    }

    /**
     * Publish a correlated result for PHP reconciliation.
     *
     * @param array<string, mixed>|null $output
     * @param array<string, mixed>|null $error
     * @return array<string, mixed>
     */
    public function complete(string $taskId, string $status, ?array $output = null, ?array $error = null): array
    {
        $taskId = $this->validateTaskId($taskId);
        if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('The BuilderX task result status is invalid.');
        }
        $task = $this->tasks->find($taskId);
        if ($task === null || (string) ($task['status'] ?? '') !== 'running') {
            throw new InvalidArgumentException('The BuilderX AI task is not available for completion.');
        }
        if (!$this->isRoutedToThisChat($task)) {
            throw new InvalidArgumentException('The BuilderX AI task is routed to a different Codex Desktop chat.');
        }
        if ($status === 'completed' && !is_array($output)) {
            throw new InvalidArgumentException('A completed BuilderX task requires an output object.');
        }
        if ($status !== 'completed' && !is_array($error)) {
            $error = ['code' => 'desktop_task_failed', 'message' => 'Codex Desktop did not complete the task.'];
        }

        $result = $this->messages->write([
            'message_id' => bin2hex(random_bytes(16)),
            'correlation_id' => (string) $task['correlation_id'],
            'message_type' => 'ai_task_result',
            'direction' => 'codex_to_builderx',
            'sender' => 'codex_desktop',
            'recipient' => 'builderx',
            'status' => 'delivered',
            'payload' => [
                'task_id' => $taskId,
                'correlation_id' => (string) $task['correlation_id'],
                'status' => $status,
                'output' => $status === 'completed' ? $output : null,
                'error' => $status === 'completed' ? null : $error,
            ],
        ], 'outbox');

        if ($this->messages->read($taskId, 'locks') !== null) {
            $this->messages->move($taskId, 'locks', 'processed');
        }

        return [
            'message_id' => $result['message_id'],
            'task_id' => $taskId,
            'correlation_id' => $task['correlation_id'],
            'status' => 'delivered',
            'message' => 'Codex Desktop result published for BuilderX reconciliation.',
        ];
    }

    /** @param array<string, mixed> $task */
    private function isRoutedToThisChat(array $task): bool
    {
        $input = $task['input'] ?? null;
        if (!is_array($input)) {
            return false;
        }

        $targetChatId = trim((string) ($input['target_chat_id'] ?? ''));
        $chatId = $this->currentChatId();
        return $targetChatId !== ''
            && $chatId !== null
            && hash_equals($targetChatId, $chatId);
    }

    private function currentChatId(): ?string
    {
        try {
            $setting = \bx_db()->GetRow(
                "SELECT setting_value FROM builder_system_setting WHERE setting_name = 'codex_chat_id' AND setting_status = 'ACTIVE' LIMIT 1"
            );
            if (is_array($setting)) {
                $value = trim((string) ($setting['setting_value'] ?? ''));
                return $value !== '' ? $this->validateChatId($value) : null;
            }
        } catch (\Throwable) {
            // Use the MCP environment fallback when the settings table is unavailable.
        }

        return $this->fallbackChatId;
    }

    private function validateChatId(string $chatId): string
    {
        if (strlen($chatId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $chatId)) {
            throw new InvalidArgumentException('The Codex Desktop chat ID is invalid.');
        }

        return $chatId;
    }

    /** @param array<string, mixed>|null $message */
    private function isTaskRequest(?array $message): bool
    {
        return is_array($message)
            && ($message['message_type'] ?? '') === 'ai_task'
            && ($message['direction'] ?? '') === 'builderx_to_codex'
            && ($message['sender'] ?? '') === 'builderx'
            && ($message['recipient'] ?? '') === 'codex_desktop';
    }

    private function validateTaskId(string $taskId): string
    {
        $taskId = trim($taskId);
        if ($taskId === '' || strlen($taskId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $taskId)) {
            throw new InvalidArgumentException('The BuilderX task ID is invalid.');
        }

        return $taskId;
    }

}
