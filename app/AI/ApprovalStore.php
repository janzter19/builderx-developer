<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class ApprovalStore
{
    private const OPERATIONS = ['delete', 'move', 'database', 'backup', 'audit'];

    /** @return array<string, mixed> */
    public function create(
        string $operation,
        string $target,
        string $targetHash,
        int $expiresInSeconds = 300,
        ?string $actorUserKey = null
    ): array {
        $this->assertOperation($operation);
        $target = $this->bounded($target, 'target', 1024);
        $targetHash = $this->bounded($targetHash, 'target_hash', 128);
        $expiresInSeconds = max(30, min($expiresInSeconds, 3600));
        $actorUserKey = $actorUserKey !== null && trim($actorUserKey) !== ''
            ? $this->bounded($actorUserKey, 'actor_user_key', 36)
            : (isset($_SESSION['builderx_user_key']) ? (string) $_SESSION['builderx_user_key'] : null);
        $approvalId = \bx_uuid();

        $saved = \bx_db()->Execute(
            'INSERT INTO builder_ai_approval (approval_key, operation, target, target_hash, actor_user_key, approval_status, expires_at) VALUES (?, ?, ?, ?, ?, \'pending\', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $expiresInSeconds . ' SECOND))',
            [$approvalId, $operation, $target, $targetHash, $actorUserKey]
        );
        if ($saved === false) {
            throw new RuntimeException('The approval request could not be saved.');
        }
        \bx_audit('CREATE', 'builder_ai_approval', $approvalId, ['operation' => $operation, 'target' => $target, 'status' => 'pending']);

        return $this->find($approvalId) ?? throw new RuntimeException('The approval request could not be read back.');
    }

    /** @return array<string, mixed>|null */
    public function find(string $approvalId): ?array
    {
        $approvalId = $this->bounded($approvalId, 'approval_id', 128);
        $row = \bx_db()->GetRow('SELECT * FROM builder_ai_approval WHERE approval_key = ? LIMIT 1', [$approvalId]);
        return $row ? $this->toContract($row) : null;
    }

    /** @return array<string, mixed> */
    public function approve(string $approvalId, string $approvedByKey): array
    {
        $approvalId = $this->bounded($approvalId, 'approval_id', 128);
        $approvedByKey = $this->bounded($approvedByKey, 'approved_by_key', 36);
        $current = $this->find($approvalId);
        if ($current === null || $current['status'] !== 'pending') {
            throw new InvalidArgumentException('Only pending approvals can be approved.');
        }
        if ((int) \bx_db()->GetOne("SELECT COUNT(*) FROM builder_ai_approval WHERE approval_key = ? AND approval_status = 'pending' AND expires_at > CURRENT_TIMESTAMP", [$approvalId]) !== 1) {
            $this->expire($approvalId);
            throw new InvalidArgumentException('The approval has expired.');
        }
        $saved = \bx_db()->Execute(
            "UPDATE builder_ai_approval SET approval_status = 'approved', approved_by_key = ?, approved_at = CURRENT_TIMESTAMP WHERE approval_key = ? AND approval_status = 'pending' AND expires_at > CURRENT_TIMESTAMP",
            [$approvedByKey, $approvalId]
        );
        if ($saved === false) {
            throw new RuntimeException('The approval could not be activated.');
        }
        \bx_audit('APPROVE', 'builder_ai_approval', $approvalId, ['status' => 'approved']);
        return $this->find($approvalId) ?? throw new RuntimeException('The approval could not be read back.');
    }

    /** @return array<string, mixed> */
    public function consume(string $approvalId, string $operation, string $target, string $targetHash, ?string $actorUserKey = null): array
    {
        $this->assertOperation($operation);
        $approvalId = $this->bounded($approvalId, 'approval_id', 128);
        $target = $this->bounded($target, 'target', 1024);
        $targetHash = $this->bounded($targetHash, 'target_hash', 128);
        $actorUserKey = $actorUserKey !== null && trim($actorUserKey) !== '' ? $this->bounded($actorUserKey, 'actor_user_key', 36) : null;
        $current = $this->find($approvalId);
        if ($current === null) {
            throw new InvalidArgumentException('The approval was not found.');
        }
        if ($current['status'] !== 'approved') {
            throw new InvalidArgumentException('The approval is not active or was already consumed.');
        }
        if ((int) \bx_db()->GetOne("SELECT COUNT(*) FROM builder_ai_approval WHERE approval_key = ? AND approval_status = 'approved' AND expires_at > CURRENT_TIMESTAMP", [$approvalId]) !== 1) {
            $this->expire($approvalId);
            throw new InvalidArgumentException('The approval has expired.');
        }
        if ($current['operation'] !== $operation || $current['target'] !== $target || !hash_equals((string) $current['target_hash'], $targetHash) || ($current['actor_user_key'] !== null && $current['actor_user_key'] !== $actorUserKey)) {
            throw new InvalidArgumentException('The approval target, operation, hash, or actor does not match.');
        }
        $saved = \bx_db()->Execute(
            "UPDATE builder_ai_approval SET approval_status = 'consumed', consumed_at = CURRENT_TIMESTAMP WHERE approval_key = ? AND approval_status = 'approved' AND expires_at > CURRENT_TIMESTAMP",
            [$approvalId]
        );
        if ($saved === false) {
            throw new RuntimeException('The approval could not be consumed.');
        }
        \bx_audit('CONSUME', 'builder_ai_approval', $approvalId, ['operation' => $operation, 'status' => 'consumed']);
        return $this->find($approvalId) ?? throw new RuntimeException('The consumed approval could not be read back.');
    }

    private function expire(string $approvalId): void
    {
        \bx_db()->Execute("UPDATE builder_ai_approval SET approval_status = 'expired' WHERE approval_key = ? AND approval_status IN ('pending','approved')", [$approvalId]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function toContract(array $row): array
    {
        return [
            'approval_id' => (string) $row['approval_key'],
            'operation' => (string) $row['operation'],
            'target' => (string) $row['target'],
            'target_hash' => (string) $row['target_hash'],
            'actor_user_key' => $row['actor_user_key'] !== null ? (string) $row['actor_user_key'] : null,
            'status' => (string) $row['approval_status'],
            'approved_by_key' => $row['approved_by_key'] !== null ? (string) $row['approved_by_key'] : null,
            'expires_at' => (string) $row['expires_at'],
            'created_at' => (string) $row['created_at'],
            'approved_at' => $row['approved_at'] !== null ? (string) $row['approved_at'] : null,
            'consumed_at' => $row['consumed_at'] !== null ? (string) $row['consumed_at'] : null,
        ];
    }

    private function assertOperation(string $operation): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('The approval operation is invalid.');
        }
    }

    private function bounded(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('The approval ' . $field . ' is invalid.');
        }
        return $value;
    }
}
