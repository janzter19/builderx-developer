<?php
declare(strict_types=1);

namespace BuilderX\AI;

final class AiTaskResultReconciler
{
    public function __construct(
        private readonly CommunicationMessageStore $messages,
        private readonly AiTaskStore $tasks
    ) {
    }

    /**
     * Read only a matching Codex result from the outbox and persist it before
     * moving the verified message to processed or failed.
     *
     * @return array<string, mixed>|null
     */
    public function reconcile(string $taskId): ?array
    {
        $task = $this->tasks->find($taskId);
        if ($task === null || in_array((string) $task['status'], ['completed', 'failed', 'cancelled'], true)) {
            return $task;
        }

        foreach ($this->messages->list('outbox', 100) as $message) {
            if (!$this->isMatchingResult($message, $task)) {
                continue;
            }

            $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];
            $resultStatus = (string) ($payload['status'] ?? '');
            $messageId = (string) ($message['message_id'] ?? '');
            if ($resultStatus === 'completed') {
                if (!is_array($payload['output'] ?? null)) {
                    continue;
                }
                $updated = $this->tasks->transition(
                    (string) $task['task_id'],
                    'completed',
                    $payload['output'],
                    null
                );
                $this->messages->move($messageId, 'outbox', 'processed');

                return $updated;
            }

            if (in_array($resultStatus, ['awaiting_approval', 'failed', 'cancelled'], true)) {
                $updated = $this->tasks->transition(
                    (string) $task['task_id'],
                    $resultStatus,
                    null,
                    is_array($payload['error'] ?? null) ? $payload['error'] : null
                );
                $this->messages->move($messageId, 'outbox', $resultStatus === 'failed' ? 'failed' : 'processed');

                return $updated;
            }
        }

        return $task;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $task
     */
    private function isMatchingResult(array $message, array $task): bool
    {
        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];

        return ($message['message_type'] ?? '') === 'ai_task_result'
            && ($message['direction'] ?? '') === 'codex_to_builderx'
            && ($message['sender'] ?? '') === 'codex_desktop'
            && ($message['recipient'] ?? '') === 'builderx'
            && ($message['correlation_id'] ?? '') === ($task['correlation_id'] ?? '')
            && ($payload['task_id'] ?? '') === ($task['task_id'] ?? '');
    }
}
