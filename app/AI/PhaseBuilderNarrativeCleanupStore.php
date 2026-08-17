<?php
declare(strict_types=1);

namespace BuilderX\AI;

use RuntimeException;

final class PhaseBuilderNarrativeCleanupStore
{
    /** @var list<string> */
    private const FIELDS = [
        'product_goal',
        'users_and_roles',
        'main_user_journey',
        'web_requirements',
        'android_requirements',
        'database_and_synchronization',
        'security_and_permissions',
        'validation_and_error_handling',
        'open_questions',
    ];

    /** @var list<string> */
    private const GRAMMAR_CHANGE_CATEGORIES = ['grammar', 'punctuation', 'spelling'];

    private static function contextHash(string $draftKey): string
    {
        return substr(hash('sha256', $draftKey !== '' ? $draftKey : 'standalone'), 0, 24);
    }

    private static function comparableText(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    /**
     * Validate the read-only Database Specialist result against the server-owned
     * Phase Builder Narrative & Cleanup context before persistence is allowed.
     *
     * @return array<string, string>
     */
    public static function validateDatabaseApproval(string $draftKey, array $reply, array $sourceSnapshot): array
    {
        $draftKey = trim($draftKey);
        $contextPath = __DIR__ . '/../../storage/coordinator-context/phase2-database-' . self::contextHash($draftKey) . '.json';
        $contextJson = @file_get_contents($contextPath);
        $context = is_string($contextJson) ? json_decode($contextJson, true) : null;
        if (!is_array($context)) {
            throw new RuntimeException('PHASE2_CONTEXT_UNAVAILABLE');
        }

        if (
            ($context['workflow'] ?? '') !== 'database_validation_after_grammar'
            || strcasecmp(trim((string) ($context['draft_key'] ?? '')), $draftKey) !== 0
            || !is_array($context['source_snapshot'] ?? null)
            || !is_array($context['grammar_response'] ?? null)
        ) {
            throw new RuntimeException('PHASE2_CONTEXT_UNAVAILABLE');
        }

        $contextSourceSnapshot = $context['source_snapshot'];
        if (array_diff(self::FIELDS, array_keys($contextSourceSnapshot)) !== [] || array_diff(array_keys($contextSourceSnapshot), self::FIELDS) !== []) {
            throw new RuntimeException('PHASE2_CONTEXT_UNAVAILABLE');
        }

        if (array_diff(self::FIELDS, array_keys($sourceSnapshot)) !== [] || array_diff(array_keys($sourceSnapshot), self::FIELDS) !== []) {
            throw new RuntimeException('PHASE2_CONTEXT_UNAVAILABLE');
        }

        foreach (self::FIELDS as $field) {
            if (
                !array_key_exists($field, $sourceSnapshot)
                || !is_string($sourceSnapshot[$field])
                || !array_key_exists($field, $contextSourceSnapshot)
                || !is_string($contextSourceSnapshot[$field])
                || $sourceSnapshot[$field] !== $contextSourceSnapshot[$field]
            ) {
                throw new RuntimeException('The Phase Builder Narrative & Cleanup source context changed before persistence.');
            }
        }

        $grammarResponse = $context['grammar_response'];
        $grammarSections = $grammarResponse['corrected_sections'] ?? null;
        if (
            ($grammarResponse['role'] ?? '') !== 'grammar_specialist'
            || ($grammarResponse['status'] ?? '') !== 'completed'
            || !self::hasCompleteSections($grammarSections)
            || !self::hasGrammarOnlyChangeHistory($grammarResponse['change_history'] ?? null)
        ) {
            throw new RuntimeException('PHASE2_CONTEXT_UNAVAILABLE');
        }

        $requiredReplyKeys = [
            'role',
            'status',
            'database_specialist_approved',
            'draft_key',
            'validation',
            'reason',
        ];
        if (array_diff($requiredReplyKeys, array_keys($reply)) !== [] || array_diff(array_keys($reply), $requiredReplyKeys) !== []) {
            throw new RuntimeException('The Database Specialist returned an invalid approval object.');
        }

        if (
            ($reply['role'] ?? '') !== 'database_specialist'
            || ($reply['status'] ?? '') !== 'approved'
            || ($reply['database_specialist_approved'] ?? false) !== true
            || strcasecmp(trim((string) ($reply['draft_key'] ?? '')), $draftKey) !== 0
            || !is_string($reply['reason'])
            || trim($reply['reason']) === ''
        ) {
            throw new RuntimeException('The Database Specialist did not approve the complete Phase Builder Narrative & Cleanup result.');
        }

        $validation = $reply['validation'] ?? null;
        $requiredValidationKeys = ['complete', 'meaning_preserved', 'write_allowed'];
        if (
            !is_array($validation)
            || array_diff($requiredValidationKeys, array_keys($validation)) !== []
            || array_diff(array_keys($validation), $requiredValidationKeys) !== []
            || ($validation['complete'] ?? false) !== true
            || ($validation['meaning_preserved'] ?? false) !== true
            || ($validation['write_allowed'] ?? false) !== true
            || !self::meaningPreserved($contextSourceSnapshot, $grammarSections)
        ) {
            throw new RuntimeException('The Database Specialist did not return complete approval validation.');
        }

        $normalizedSections = [];
        foreach (self::FIELDS as $field) {
            if (
                !array_key_exists($field, $grammarSections)
                || !is_string($grammarSections[$field])
                || ($field !== 'open_questions' && trim($grammarSections[$field]) === '')
            ) {
                throw new RuntimeException('The Grammar Specialist did not return the complete nine-section result.');
            }
            $normalizedSections[$field] = $grammarSections[$field];
        }

        return $normalizedSections;
    }

    private static function hasGrammarOnlyChangeHistory(mixed $changeHistory): bool
    {
        if (!is_array($changeHistory)) {
            return false;
        }

        foreach ($changeHistory as $change) {
            if (
                !is_array($change)
                || array_diff(['original_text', 'updated_text', 'category', 'reason'], array_keys($change)) !== []
                || array_diff(array_keys($change), ['original_text', 'updated_text', 'category', 'reason']) !== []
                || !is_string($change['original_text'] ?? null)
                || !is_string($change['updated_text'] ?? null)
                || !is_string($change['category'] ?? null)
                || !is_string($change['reason'] ?? null)
                || trim($change['original_text']) === ''
                || trim($change['updated_text']) === ''
                || trim($change['reason']) === ''
                || !in_array(strtolower(trim($change['category'])), self::GRAMMAR_CHANGE_CATEGORIES, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Preserve URLs, quantities, route paths, and technical identifiers while
     * allowing ordinary spelling, grammar, and punctuation corrections.
     */
    private static function meaningPreserved(array $sourceSections, array $correctedSections): bool
    {
        foreach (self::FIELDS as $field) {
            if (self::meaningAnchors((string) $sourceSections[$field]) !== self::meaningAnchors((string) $correctedSections[$field])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function meaningAnchors(string $text): array
    {
        $anchors = [];
        $patterns = [
            '/https?:\/\/[^\s<>"\']+/i',
            '/\b\d+(?:\.\d+)*\b/',
            '/(?<![A-Za-z0-9])(?:UI\/UX|Node\.js|React|PHP|MySQL|Firebase|Firestore|Kotlin|Android|NoSQL|CRUD|JWTs?|SQL|UPDATE)(?![A-Za-z0-9])/i',
            '/\b[A-Z]{2,}\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches) !== false) {
                foreach ($matches[0] as $match) {
                    $anchors[] = strtolower(rtrim($match, '.,;:!?'));
                }
            }
        }
        sort($anchors);
        return $anchors;
    }

    private static function hasCompleteSections(mixed $sections): bool
    {
        if (!is_array($sections)) {
            return false;
        }

        if (
            count($sections) !== count(self::FIELDS)
            || array_diff(self::FIELDS, array_keys($sections)) !== []
            || array_diff(array_keys($sections), self::FIELDS) !== []
        ) {
            return false;
        }

        foreach (self::FIELDS as $field) {
            if (!is_string($sections[$field]) || ($field !== 'open_questions' && trim($sections[$field]) === '')) {
                return false;
            }
        }

        return true;
    }

    public function persist(string $draftKey, array $reply, array $sourceSnapshot, ?string $userKey = null): array
    {
        $draftKey = trim($draftKey);
        $correctedSections = $reply['corrected_sections'] ?? null;
        if (!is_array($correctedSections)) {
            throw new RuntimeException('Narrative & Cleanup returned no corrected sections.');
        }

        $correctedFields = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $correctedSections) || !is_string($correctedSections[$field])) {
                throw new RuntimeException('Narrative & Cleanup did not return the complete Tab 1 context.');
            }
            $value = trim($correctedSections[$field]);
            if (strlen($value) > 1000000) {
                throw new RuntimeException('A corrected Tab 1 section is too large to save.');
            }
            $correctedFields[$field] = $value;
        }

        $db = \bx_db();
        if ($draftKey === '') {
            throw new RuntimeException('The current BuilderX draft was not found.');
        }

        $db->BeginTrans();
        $transactionStarted = true;
        try {
            $existing = $db->GetRow('SELECT draft_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions FROM phase_builder_narrative_draft WHERE draft_key = ? FOR UPDATE', [$draftKey]);

            if (is_array($existing)) {
                    $backupWhere = 'draft_key = ?';
                    $backupParams = [$draftKey];
                $backupCopied = $db->Execute(
                    'INSERT INTO phase_builder_narrative_draft_backup (x_id, draft_key, phase_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions, created_by_user_key, updated_by_user_key, created_at, updated_at) SELECT x_id, draft_key, phase_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions, created_by_user_key, updated_by_user_key, created_at, updated_at FROM phase_builder_narrative_draft WHERE ' . $backupWhere . ' ON DUPLICATE KEY UPDATE draft_key = VALUES(draft_key), phase_key = VALUES(phase_key), product_goal = VALUES(product_goal), users_and_roles = VALUES(users_and_roles), main_user_journey = VALUES(main_user_journey), web_requirements = VALUES(web_requirements), android_requirements = VALUES(android_requirements), database_and_synchronization = VALUES(database_and_synchronization), security_and_permissions = VALUES(security_and_permissions), validation_and_error_handling = VALUES(validation_and_error_handling), open_questions = VALUES(open_questions), created_by_user_key = VALUES(created_by_user_key), updated_by_user_key = VALUES(updated_by_user_key), created_at = VALUES(created_at), updated_at = VALUES(updated_at)',
                    $backupParams
                );
                if ($backupCopied === false) {
                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException('Tab 1 backup copy failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                }
                $backup = $db->GetRow(
                    'SELECT draft_key, phase_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions FROM phase_builder_narrative_draft_backup WHERE draft_key = ? LIMIT 1',
                    [(string) ($existing['draft_key'] ?? '')]
                );
                if (!is_array($backup)) {
                    throw new RuntimeException('Tab 1 backup read-back returned no row.');
                }
                foreach (self::FIELDS as $field) {
                    if ((string) ($backup[$field] ?? '') !== (string) ($existing[$field] ?? '')) {
                        throw new RuntimeException('Tab 1 backup read-back mismatch for ' . $field . '.');
                    }
                }
                $alreadySaved = true;
                foreach (self::FIELDS as $field) {
                    if (self::comparableText((string) ($existing[$field] ?? '')) !== self::comparableText($correctedFields[$field])) {
                        $alreadySaved = false;
                        break;
                    }
                }
                if ($alreadySaved) {
                    $db->CommitTrans();
                    $transactionStarted = false;
                    return $this->result('already_saved', (string) ($existing['draft_key'] ?? ''), $correctedFields, $reply);
                }

                foreach (self::FIELDS as $field) {
                    if (
                        array_key_exists($field, $sourceSnapshot)
                        && self::comparableText((string) ($existing[$field] ?? '')) !== self::comparableText((string) $sourceSnapshot[$field])
                    ) {
                        throw new RuntimeException('The Tab 1 draft changed while Narrative & Cleanup was processing. Reload the page, save the current draft, and try again.');
                    }
                }
            }

            $existingDraftKey = is_array($existing) ? trim((string) ($existing['draft_key'] ?? '')) : '';
            $draftKey = $existingDraftKey !== '' ? $existingDraftKey : \bx_uuid();
            $writeUserKey = $userKey !== null && trim($userKey) !== '' ? trim($userKey) : null;
            $saved = $db->Execute(
                'INSERT INTO phase_builder_narrative_draft (draft_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE product_goal = VALUES(product_goal), users_and_roles = VALUES(users_and_roles), main_user_journey = VALUES(main_user_journey), web_requirements = VALUES(web_requirements), android_requirements = VALUES(android_requirements), database_and_synchronization = VALUES(database_and_synchronization), security_and_permissions = VALUES(security_and_permissions), validation_and_error_handling = VALUES(validation_and_error_handling), open_questions = VALUES(open_questions), updated_by_user_key = VALUES(updated_by_user_key), updated_at = CURRENT_TIMESTAMP',
                [
                    $draftKey,
                    $correctedFields['product_goal'],
                    $correctedFields['users_and_roles'],
                    $correctedFields['main_user_journey'],
                    $correctedFields['web_requirements'],
                    $correctedFields['android_requirements'],
                    $correctedFields['database_and_synchronization'],
                    $correctedFields['security_and_permissions'],
                    $correctedFields['validation_and_error_handling'],
                    $correctedFields['open_questions'],
                    $writeUserKey,
                    $writeUserKey,
                ]
            );
            if ($saved === false) {
                $databaseError = trim((string) $db->ErrorMsg());
                throw new RuntimeException('Narrative & Cleanup draft upsert failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
            }

            $readBack = $db->GetRow(
                'SELECT draft_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions FROM phase_builder_narrative_draft WHERE draft_key = ? LIMIT 1',
                [$draftKey]
            );
            if (!is_array($readBack)) {
                throw new RuntimeException('Narrative & Cleanup read-back returned no row.');
            }
            if (strcasecmp(trim((string) ($readBack['draft_key'] ?? '')), $draftKey) !== 0) {
                throw new RuntimeException('Narrative & Cleanup draft key read-back mismatch.');
            }
            foreach ($correctedFields as $field => $value) {
                if ((string) ($readBack[$field] ?? '') !== $value) {
                    throw new RuntimeException('Narrative & Cleanup field read-back mismatch for ' . $field . '.');
                }
            }

            $changeHistory = is_array($reply['change_history'] ?? null) ? array_values($reply['change_history']) : [];
            \bx_audit($existingDraftKey !== '' ? 'UPDATE' : 'CREATE', 'phase_builder_narrative_draft', $draftKey, [
                'draft_key' => $draftKey,
                'source' => 'narrative_cleanup_ai',
                'change_count' => count($changeHistory),
            ]);
            $db->CommitTrans();
            $transactionStarted = false;

            return $this->result('saved', $draftKey, $correctedFields, $reply);
        } catch (\Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            throw $error;
        }
    }

    /** @param array<string, string> $correctedFields */
    private function result(string $status, string $draftKey, array $correctedFields, array $reply): array
    {
        return [
            'status' => $status,
            'draft_key' => $draftKey,
            'corrected_sections' => $correctedFields,
            'change_history' => is_array($reply['change_history'] ?? null) ? array_values($reply['change_history']) : [],
        ];
    }
}
