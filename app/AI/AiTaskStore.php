<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class AiTaskStore
{
    private const ACTIONS = [
        'rephrase_text',
        'validate_text',
        'retrieve_context',
        'specialist_handoff',
    ];

    private const STAGES = [
        'Think',
        'Design',
        'Build',
        'Validate',
        'Document',
        'Preserve',
    ];

    private const SPECIALISTS = [
        'coordinator',
        'requirements',
        'architecture',
        'ui_ux',
        'database',
        'solution_design',
        'frontend',
        'backend',
        'security',
        'testing',
        'accessibility',
        'documentation',
        'preservation',
        'rephrase',
        'grammar_validator',
    ];

    private const STATUSES = [
        'queued',
        'running',
        'awaiting_approval',
        'completed',
        'failed',
        'cancelled',
    ];

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $permissions
     * @return array<string, mixed>
     */
    public function create(
        string $action,
        string $stage,
        string $specialist,
        array $input,
        array $permissions = [],
        ?string $correlationId = null,
        ?string $parentTaskId = null,
        ?string $createdByKey = null
    ): array {
        $this->assertEnum($action, self::ACTIONS, 'action');
        $this->assertEnum($stage, self::STAGES, 'stage');
        $this->assertEnum($specialist, self::SPECIALISTS, 'specialist');
        $this->assertTextInput($input);

        $taskId = \bx_uuid();
        $correlationId = $this->boundedId($correlationId ?: $taskId, 'correlation_id');
        $parentTaskId = $parentTaskId === null || trim($parentTaskId) === ''
            ? null
            : $this->boundedId($parentTaskId, 'parent_task_id');
        $permissions = $this->normalizePermissions($permissions);
        $createdByKey = $createdByKey !== null && trim($createdByKey) !== ''
            ? $this->boundedKey($createdByKey, 'created_by_key', 36)
            : (isset($_SESSION['builderx_user_key']) ? (string) $_SESSION['builderx_user_key'] : null);

        $db = \bx_db();
        $saved = $db->Execute(
            'INSERT INTO builder_ai_task (task_key, correlation_id, parent_task_key, action, stage, specialist, task_status, input_json, permissions_json, attempt, created_by_key) VALUES (?, ?, ?, ?, ?, ?, \'queued\', ?, ?, 1, ?)',
            [
                $taskId,
                $correlationId,
                $parentTaskId,
                $action,
                $stage,
                $specialist,
                $this->encode($input),
                $this->encode($permissions),
                $createdByKey,
            ]
        );
        if ($saved === false) {
            throw new RuntimeException('The AI task could not be created.');
        }

        \bx_audit('CREATE', 'builder_ai_task', $taskId, [
            'action' => $action,
            'stage' => $stage,
            'specialist' => $specialist,
            'status' => 'queued',
        ]);

        return $this->find($taskId) ?? throw new RuntimeException('The created AI task could not be read back.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $taskId, ?string $createdByKey = null): ?array
    {
        $taskId = $this->boundedId($taskId, 'task_id');
        $sql = 'SELECT * FROM builder_ai_task WHERE task_key = ?';
        $params = [$taskId];
        if ($createdByKey !== null) {
            $sql .= ' AND created_by_key = ?';
            $params[] = $this->boundedKey($createdByKey, 'created_by_key', 36);
        }
        $sql .= ' LIMIT 1';
        $row = \bx_db()->GetRow($sql, $params);

        return $row ? $this->toContract($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(string $createdByKey, int $limit = 50): array
    {
        $createdByKey = $this->boundedKey($createdByKey, 'created_by_key', 36);
        $limit = max(1, min($limit, 200));
        $rows = \bx_db()->GetAll(
            'SELECT * FROM builder_ai_task WHERE created_by_key = ? ORDER BY x_id DESC LIMIT ' . $limit,
            [$createdByKey]
        ) ?: [];

        return array_map(fn (array $row): array => $this->toContract($row), $rows);
    }

    /**
     * @param array<string, mixed>|null $output
     * @param array<string, mixed>|null $error
     * @return array<string, mixed>
     */
    public function transition(string $taskId, string $status, ?array $output = null, ?array $error = null): array
    {
        $this->assertEnum($status, self::STATUSES, 'status');
        $current = $this->find($taskId);
        if ($current === null) {
            throw new InvalidArgumentException('The AI task was not found.');
        }
        $this->assertTransition((string) $current['status'], $status);
        if ($output !== null) {
            $this->assertJsonObject($output, 'output');
            if ($status === 'completed' && $current['action'] === 'rephrase_text') {
                $rewrittenText = $output['rewritten_text'] ?? null;
                $correctedSections = $output['corrected_sections'] ?? null;
                $hasRewrittenText = is_string($rewrittenText) && trim($rewrittenText) !== '' && strlen($rewrittenText) <= 50000;
                $hasCorrectedSections = is_array($correctedSections)
                    && count($correctedSections) === 9
                    && array_diff([
                        'product_goal',
                        'users_and_roles',
                        'main_user_journey',
                        'web_requirements',
                        'android_requirements',
                        'database_and_synchronization',
                        'security_and_permissions',
                        'validation_and_error_handling',
                        'open_questions',
                    ], array_keys($correctedSections)) === []
                    && array_diff(array_keys($correctedSections), [
                        'product_goal',
                        'users_and_roles',
                        'main_user_journey',
                        'web_requirements',
                        'android_requirements',
                        'database_and_synchronization',
                        'security_and_permissions',
                        'validation_and_error_handling',
                        'open_questions',
                    ]) === []
                    && count(array_filter($correctedSections, static fn (mixed $section): bool => !is_string($section) || strlen($section) > 50000)) === 0;
                if (!$hasRewrittenText && !$hasCorrectedSections) {
                    throw new InvalidArgumentException('A completed rephrase task requires output.rewritten_text or all nine output.corrected_sections values.');
                }
            }
        }
        if ($error !== null) {
            $this->assertJsonObject($error, 'error');
        }

        $startedAt = $status === 'running' && $current['started_at'] === null ? ', started_at = CURRENT_TIMESTAMP' : '';
        $completedAt = in_array($status, ['completed', 'failed', 'cancelled'], true) ? ', completed_at = CURRENT_TIMESTAMP' : '';
        $saved = \bx_db()->Execute(
            'UPDATE builder_ai_task SET task_status = ?, output_json = ?, error_json = ?' . $startedAt . $completedAt . ' WHERE task_key = ?',
            [$status, $output === null ? ($current['output'] === null ? null : $this->encode((array) $current['output'])) : $this->encode($output), $error === null ? ($current['error'] === null ? null : $this->encode((array) $current['error'])) : $this->encode($error), $taskId]
        );
        if ($saved === false) {
            throw new RuntimeException('The AI task status could not be saved.');
        }

        \bx_audit('UPDATE', 'builder_ai_task', $taskId, [
            'previous_status' => $current['status'],
            'status' => $status,
        ]);

        return $this->find($taskId) ?? throw new RuntimeException('The AI task status could not be read back.');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function toContract(array $row): array
    {
        return [
            'task_id' => (string) $row['task_key'],
            'correlation_id' => (string) $row['correlation_id'],
            'parent_task_id' => $row['parent_task_key'] !== null ? (string) $row['parent_task_key'] : null,
            'action' => (string) $row['action'],
            'stage' => (string) $row['stage'],
            'specialist' => (string) $row['specialist'],
            'status' => (string) $row['task_status'],
            'input' => $this->decodeObject($row['input_json'], 'input'),
            'permissions' => $this->decodeObject($row['permissions_json'], 'permissions'),
            'attempt' => (int) $row['attempt'],
            'output' => $row['output_json'] !== null ? $this->decodeObject($row['output_json'], 'output') : null,
            'error' => $row['error_json'] !== null ? $this->decodeObject($row['error_json'], 'error') : null,
            'created_by_key' => $row['created_by_key'] !== null ? (string) $row['created_by_key'] : null,
            'created_at' => (string) $row['created_at'],
            'started_at' => $row['started_at'] !== null ? (string) $row['started_at'] : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $input */
    private function assertTextInput(array $input): void
    {
        $text = $input['text'] ?? null;
        if (!is_string($text) || trim($text) === '' || strlen($text) > 50000) {
            throw new InvalidArgumentException('AI task input.text is required and must be 50,000 bytes or fewer.');
        }
        if (isset($input['style_profile']) && $input['style_profile'] !== null && (!is_string($input['style_profile']) || strlen($input['style_profile']) > 128)) {
            throw new InvalidArgumentException('AI task input.style_profile is invalid.');
        }
        if (isset($input['context_refs'])) {
            if (!is_array($input['context_refs']) || count($input['context_refs']) > 50) {
                throw new InvalidArgumentException('AI task input.context_refs is invalid.');
            }
            foreach ($input['context_refs'] as $reference) {
                if (!is_string($reference) || $reference === '' || strlen($reference) > 512) {
                    throw new InvalidArgumentException('AI task input.context_refs contains an invalid reference.');
                }
            }
        }
    }

    /** @param array<string, mixed> $permissions @return array<string, mixed> */
    private function normalizePermissions(array $permissions): array
    {
        $scope = (string) ($permissions['write_scope'] ?? 'none');
        if (!in_array($scope, ['none', 'communication_only', 'build_allowlist', 'phase_manager_approval'], true)) {
            throw new InvalidArgumentException('AI task permissions.write_scope is invalid.');
        }
        $paths = $permissions['allowed_paths'] ?? [];
        if (!is_array($paths) || count($paths) > 20) {
            throw new InvalidArgumentException('AI task permissions.allowed_paths is invalid.');
        }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || strlen($path) > 512) {
                throw new InvalidArgumentException('AI task permissions.allowed_paths contains an invalid path.');
            }
        }

        return ['write_scope' => $scope, 'allowed_paths' => array_values($paths)];
    }

    /** @param list<string> $allowed */
    private function assertEnum(string $value, array $allowed, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('AI task ' . $field . ' is invalid.');
        }
    }

    private function boundedId(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            throw new InvalidArgumentException('AI task ' . $field . ' is invalid.');
        }

        return $value;
    }

    private function boundedKey(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || !preg_match('/^[A-Za-z0-9-]+$/', $value)) {
            throw new InvalidArgumentException('AI task ' . $field . ' is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function assertJsonObject(array $value, string $field): void
    {
        if (count($value) > 100) {
            throw new InvalidArgumentException('AI task ' . $field . ' is too large.');
        }
        $this->encode($value);
    }

    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException('AI task JSON is invalid.', 0, $error);
        }
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value, string $field): array
    {
        try {
            $decoded = json_decode((string) $value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Stored AI task ' . $field . ' is invalid.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored AI task ' . $field . ' is invalid.');
        }

        return $decoded;
    }

    private function assertTransition(string $from, string $to): void
    {
        $allowed = [
            'queued' => ['running', 'awaiting_approval', 'completed', 'failed', 'cancelled'],
            'running' => ['completed', 'awaiting_approval', 'failed', 'cancelled'],
            'awaiting_approval' => ['queued', 'running', 'failed', 'cancelled'],
            'completed' => [],
            'failed' => [],
            'cancelled' => [],
        ];
        if (!in_array($to, $allowed[$from] ?? [], true) && $from !== $to) {
            throw new InvalidArgumentException('AI task status transition is not allowed.');
        }
    }
}
