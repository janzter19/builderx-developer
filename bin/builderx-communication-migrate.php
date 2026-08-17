#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/foundation.php';

$apply = in_array('--apply', $argv, true);
$messages = new \BuilderX\AI\CommunicationMessageStore(__DIR__ . '/../storage/codex-communication');
$tasks = new \BuilderX\AI\AiTaskStore();
$candidates = [];

foreach ($messages->list('outbox', 200) as $message) {
    if (($message['message_type'] ?? '') !== 'ai_task'
        || ($message['direction'] ?? '') !== 'builderx_to_codex'
        || ($message['status'] ?? '') !== 'queued') {
        continue;
    }

    $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];
    $taskId = (string) ($payload['task_id'] ?? '');
    $task = $taskId !== '' ? $tasks->find($taskId) : null;
    if ($task === null || (string) ($task['status'] ?? '') !== 'queued') {
        continue;
    }

    $candidates[] = (string) $message['message_id'];
}

if (!$apply) {
    fwrite(STDOUT, 'Dry run: ' . count($candidates) . " request message(s) would move from outbox to inbox.\n");
    exit(0);
}

foreach ($candidates as $messageId) {
    $messages->move($messageId, 'outbox', 'inbox');
}

fwrite(STDOUT, 'Migrated ' . count($candidates) . " request message(s) from outbox to inbox.\n");
