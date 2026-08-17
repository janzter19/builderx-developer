<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class AiSpecialistRegistry
{
    private const STAGES = ['Think', 'Design', 'Build', 'Validate', 'Document', 'Preserve'];
    private const WRITE_SCOPES = ['none', 'communication_only', 'build_allowlist', 'phase_manager_approval'];
    private const TOOLS = ['read_files', 'search_files', 'read_communication', 'write_communication'];

    public function ensureSystemSpecialists(): void
    {
        $builtins = [
            ['coordinator', 'Coordinator', 'Route approved tasks to registered specialists.', ['Think', 'Design', 'Build', 'Validate', 'Document', 'Preserve'], ['routing', 'handoff'], ['read_files', 'search_files', 'read_communication', 'write_communication'], 'communication_only', ['project-rules', 'task-contracts']],
            ['rephrase', 'Rephrase Specialist', 'Correct spelling, grammar, punctuation, and sentence clarity.', ['Validate'], ['grammar', 'spelling'], ['read_communication', 'write_communication'], 'communication_only', ['project-rules', 'language-rules']],
            ['requirements', 'Requirements Specialist', 'Turn approved narrative into traceable functional and production-readiness requirements.', ['Think', 'Design', 'Validate', 'Document'], ['requirements', 'traceability', 'acceptance-criteria', 'mobile-platform', 'synchronization', 'security', 'installation', 'deployment', 'backup-recovery'], ['read_files', 'search_files', 'read_communication'], 'none', ['project-rules', 'task-contracts']],
            ['database', 'Database Specialist', 'Review data boundaries, schema implications, transactions, read-back, offline synchronization, and local retrieval requirements.', ['Design', 'Validate', 'Document'], ['database', 'transactions', 'read-back', 'offline-first', 'outbox', 'idempotency', 'retry-backoff', 'conflict-resolution', 'reconciliation', 'firestore', 'room', 'sqlite', 'local-rag', 'provenance', 'context-versioning', 'invalidation'], ['read_files', 'search_files', 'read_communication'], 'none', ['project-rules', 'task-contracts', 'saved-narrative', 'local-rag-context']],
            ['ui_ux', 'UI/UX Specialist', 'Review interface, interaction, accessibility, responsive behavior, mobile workflows, and visible synchronization state.', ['Design', 'Validate', 'Document'], ['ui-ux', 'accessibility', 'responsive', 'mobile-ui', 'mobile-accessibility', 'offline-state', 'sync-status', 'barcode-camera'], ['read_files', 'search_files', 'read_communication'], 'none', ['project-rules', 'task-contracts']],
            ['mobile_sync', 'Mobile and Synchronization Specialist', 'Review Kotlin mobile behavior, Firestore synchronization, durable offline queues, ordering, conflicts, and reconciliation.', ['Design', 'Validate', 'Document'], ['kotlin', 'android', 'firestore', 'room', 'sqlite', 'offline-first', 'outbox', 'queueing', 'retry', 'backoff', 'idempotency', 'conflict-resolution', 'event-ordering', 'reconciliation', 'connectivity', 'sync-status', 'barcode', 'camera', 'mobile-security', 'authentication'], ['read_files', 'search_files', 'read_communication'], 'none', ['project-rules', 'task-contracts', 'saved-narrative']],
            ['local_rag', 'Local RAG Specialist', 'Define bounded local context ingestion, retrieval, provenance, versioning, invalidation, and offline cache behavior.', ['Think', 'Design', 'Validate', 'Document'], ['context-ingestion', 'chunking', 'indexing', 'local-retrieval', 'citations', 'provenance', 'context-versioning', 'offline-rag-cache', 'stale-context-detection', 'rag-sync', 'invalidation'], ['read_files', 'search_files', 'read_communication'], 'none', ['project-rules', 'task-contracts', 'saved-narrative', 'local-rag-context']],
        ];
        $db = \bx_db();
        $transactionStarted = false;
        try {
            $db->BeginTrans();
            $transactionStarted = true;
            foreach ($builtins as $builtin) {
                $row = $db->GetRow('SELECT * FROM builder_ai_specialist WHERE specialist_key = ? FOR UPDATE', [$builtin[0]]);
                if (!is_array($row)) {
                    $saved = $db->Execute(
                        "INSERT INTO builder_ai_specialist (specialist_key, specialist_version, specialist_name, purpose, stages_json, skills_json, allowed_tools_json, write_scope, rag_scopes_json, specialist_status, review_status, evidence_json) VALUES (?, '1.1.0', ?, ?, ?, ?, ?, ?, ?, 'active', 'approved', ?)",
                        [$builtin[0], $builtin[1], $builtin[2], $this->encode($builtin[3]), $this->encode($builtin[4]), $this->encode($builtin[5]), $builtin[6], $this->encode($builtin[7]), $this->encode(['system_defined' => true, 'capability_catalog' => 'mobile-sync-and-local-rag'])]
                    );
                    if ($saved === false) {
                        throw new RuntimeException('The system specialist catalog could not be saved.');
                    }
                    continue;
                }

                $storedStages = $this->decodeStoredList($row['stages_json'] ?? null);
                $storedSkills = $this->decodeStoredList($row['skills_json'] ?? null);
                $storedTools = $this->decodeStoredList($row['allowed_tools_json'] ?? null);
                $storedRagScopes = $this->decodeStoredList($row['rag_scopes_json'] ?? null);
                $nextStages = $this->mergeList($storedStages, $builtin[3]);
                $nextSkills = $this->mergeList($storedSkills, $builtin[4]);
                $nextTools = $this->mergeList($storedTools, $builtin[5]);
                $nextRagScopes = $this->mergeList($storedRagScopes, $builtin[7]);
                if ($nextStages === $storedStages && $nextSkills === $storedSkills && $nextTools === $storedTools && $nextRagScopes === $storedRagScopes) {
                    continue;
                }
                $saved = $db->Execute(
                    'UPDATE builder_ai_specialist SET specialist_version = ?, stages_json = ?, skills_json = ?, allowed_tools_json = ?, rag_scopes_json = ? WHERE specialist_key = ?',
                    ['1.1.0', $this->encode($nextStages), $this->encode($nextSkills), $this->encode($nextTools), $this->encode($nextRagScopes), $builtin[0]]
                );
                if ($saved === false) {
                    throw new RuntimeException('The system specialist capability update could not be saved.');
                }
                \bx_audit('UPDATE', 'builder_ai_specialist', $builtin[0], ['skills_added' => array_values(array_diff($nextSkills, $storedSkills)), 'capability_catalog' => 'mobile-sync-and-local-rag']);
            }
            $db->CommitTrans();
            $transactionStarted = false;
        } catch (\Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            throw $error;
        }
    }

    /**
     * Create a proposal only. New specialists remain pending until a Phase
     * Manager approval explicitly activates them.
     *
     * @param list<string> $stages
     * @param list<string> $skills
     * @param list<string> $allowedTools
     * @param list<string> $ragScopes
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public function propose(
        string $key,
        string $name,
        string $purpose,
        array $stages,
        array $skills,
        array $allowedTools,
        string $writeScope = 'none',
        array $ragScopes = [],
        bool $temporary = false,
        array $evidence = [],
        ?string $ownerUserKey = null
    ): array {
        $key = $this->bounded($key, 'specialist_key', 128, '/^[a-z0-9][a-z0-9_-]{0,127}$/');
        $name = $this->bounded($name, 'specialist_name', 120, '/^.{1,120}$/');
        $purpose = trim($purpose);
        if ($purpose === '' || strlen($purpose) > 4000) {
            throw new InvalidArgumentException('Specialist purpose is required and must be 4,000 bytes or fewer.');
        }
        $stages = $this->normalizeList($stages, self::STAGES, 'stages', 6, 40);
        $skills = $this->normalizeList($skills, null, 'skills', 20, 128);
        $allowedTools = $this->normalizeList($allowedTools, self::TOOLS, 'allowed_tools', 10, 80);
        if (!in_array($writeScope, self::WRITE_SCOPES, true)) {
            throw new InvalidArgumentException('Specialist write scope is invalid.');
        }
        $ragScopes = $this->normalizeList($ragScopes, null, 'rag_scopes', 20, 160);
        $ownerUserKey = $ownerUserKey !== null && trim($ownerUserKey) !== ''
            ? $this->bounded($ownerUserKey, 'owner_user_key', 36, '/^[A-Za-z0-9-]+$/')
            : (isset($_SESSION['builderx_user_key']) ? (string) $_SESSION['builderx_user_key'] : null);

        $keyExists = (int) \bx_db()->GetOne('SELECT COUNT(*) FROM builder_ai_specialist WHERE specialist_key = ?', [$key]);
        if ($keyExists > 0) {
            throw new InvalidArgumentException('A specialist with this key already exists.');
        }

        $saved = \bx_db()->Execute(
            'INSERT INTO builder_ai_specialist (specialist_key, specialist_name, purpose, stages_json, skills_json, allowed_tools_json, write_scope, rag_scopes_json, specialist_status, review_status, is_temporary, owner_user_key, evidence_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending_approval\', \'unreviewed\', ?, ?, ?)',
            [$key, $name, $purpose, $this->encode($stages), $this->encode($skills), $this->encode($allowedTools), $writeScope, $this->encode($ragScopes), $temporary ? 1 : 0, $ownerUserKey, $this->encode($evidence)]
        );
        if ($saved === false) {
            throw new RuntimeException('The specialist proposal could not be saved.');
        }

        \bx_audit('CREATE', 'builder_ai_specialist', $key, [
            'specialist_name' => $name,
            'status' => 'pending_approval',
            'write_scope' => $writeScope,
            'temporary' => $temporary,
        ]);

        return $this->find($key) ?? throw new RuntimeException('The specialist proposal could not be read back.');
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        $key = $this->bounded($key, 'specialist_key', 128, '/^[a-z0-9][a-z0-9_-]{0,127}$/');
        $row = \bx_db()->GetRow('SELECT * FROM builder_ai_specialist WHERE specialist_key = ? LIMIT 1', [$key]);

        return $row ? $this->toContract($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function listAll(int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $rows = \bx_db()->GetAll('SELECT * FROM builder_ai_specialist ORDER BY FIELD(specialist_status, \'pending_approval\', \'active\', \'inactive\', \'retired\'), specialist_name, x_id LIMIT ' . $limit) ?: [];
        return array_map(fn (array $row): array => $this->toContract($row), $rows);
    }

    /** @return list<array<string, mixed>> */
    public function availableForStage(string $stage, ?string $skill = null): array
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Specialist stage is invalid.');
        }
        $rows = \bx_db()->GetAll("SELECT * FROM builder_ai_specialist WHERE specialist_status = 'active' AND review_status = 'approved' ORDER BY specialist_name, x_id") ?: [];
        $results = [];
        foreach ($rows as $row) {
            $specialist = $this->toContract($row);
            if (!in_array($stage, $specialist['stages'], true)) {
                continue;
            }
            if ($skill !== null && !in_array($skill, $specialist['skills'], true)) {
                continue;
            }
            $results[] = $specialist;
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function approve(string $key, string $approvalReference): array
    {
        $approvalReference = $this->bounded($approvalReference, 'approval_reference', 128, '/^[A-Za-z0-9._:-]+$/');
        $current = $this->find($key);
        if ($current === null) {
            throw new InvalidArgumentException('The specialist proposal was not found.');
        }
        if ($current['status'] !== 'pending_approval' || $current['review_status'] !== 'unreviewed') {
            throw new InvalidArgumentException('Only an unreviewed pending specialist can be approved.');
        }

        $saved = \bx_db()->Execute(
            "UPDATE builder_ai_specialist SET specialist_status = 'active', review_status = 'approved', approval_reference = ? WHERE specialist_key = ? AND specialist_status = 'pending_approval' AND review_status = 'unreviewed'",
            [$approvalReference, $key]
        );
        if ($saved === false) {
            throw new RuntimeException('The specialist approval could not be saved.');
        }
        \bx_audit('APPROVE', 'builder_ai_specialist', $key, ['status' => 'active'], 'Phase Manager approval reference: ' . $approvalReference);

        return $this->find($key) ?? throw new RuntimeException('The specialist approval could not be read back.');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function toContract(array $row): array
    {
        return [
            'specialist_key' => (string) $row['specialist_key'],
            'version' => (string) $row['specialist_version'],
            'name' => (string) $row['specialist_name'],
            'purpose' => (string) $row['purpose'],
            'stages' => $this->decodeList($row['stages_json'], 'stages'),
            'skills' => $this->decodeList($row['skills_json'], 'skills'),
            'allowed_tools' => $this->decodeList($row['allowed_tools_json'], 'allowed_tools'),
            'write_scope' => (string) $row['write_scope'],
            'rag_scopes' => $this->decodeList($row['rag_scopes_json'], 'rag_scopes'),
            'status' => (string) $row['specialist_status'],
            'review_status' => (string) $row['review_status'],
            'approval_reference' => $row['approval_reference'] !== null ? (string) $row['approval_reference'] : null,
            'temporary' => (int) $row['is_temporary'] === 1,
            'owner_user_key' => $row['owner_user_key'] !== null ? (string) $row['owner_user_key'] : null,
            'evidence' => $this->decodeObject($row['evidence_json'], 'evidence'),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param list<string>|null $allowed @return list<string> */
    private function normalizeList(array $values, ?array $allowed, string $field, int $maxItems, int $maxLength): array
    {
        if (count($values) > $maxItems) {
            throw new InvalidArgumentException('Specialist ' . $field . ' has too many entries.');
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > $maxLength || ($allowed !== null && !in_array($value, $allowed, true))) {
                throw new InvalidArgumentException('Specialist ' . $field . ' contains an invalid entry.');
            }
            $result[] = trim($value);
        }

        return array_values(array_unique($result));
    }

    private function bounded(string $value, string $field, int $maxLength, string $pattern): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || !preg_match($pattern, $value)) {
            throw new InvalidArgumentException('Specialist ' . $field . ' is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException('Specialist JSON is invalid.', 0, $error);
        }
    }

    /** @return list<string> */
    private function decodeStoredList(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }

    /** @param list<string> $current @param list<string> $additional @return list<string> */
    private function mergeList(array $current, array $additional): array
    {
        return array_values(array_unique(array_merge($current, $additional)));
    }

    /** @return list<string> */
    private function decodeList(mixed $value, string $field): array
    {
        $decoded = $this->decodeObject($value, $field);
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw new RuntimeException('Stored specialist ' . $field . ' is invalid.');
            }
        }

        return array_values($decoded);
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value, string $field): array
    {
        try {
            $decoded = json_decode((string) $value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Stored specialist ' . $field . ' is invalid.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored specialist ' . $field . ' is invalid.');
        }

        return $decoded;
    }
}
