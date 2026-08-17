<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class MemoryStore
{
    private const MEMORY_TYPES = ['brand_rule', 'decision', 'instruction', 'example', 'task_result', 'reference'];
    private const RETRIEVAL_TYPES = ['keyword', 'vector', 'hybrid', 'metadata', 'hierarchical', 'graph', 'temporal', 'structured', 'reranked'];
    private const MAX_CONTENT_BYTES = 100000;

    /** @param list<string> $retrievalTypes @param list<string> $tags @param array<string, mixed> $metadata @return array<string, mixed> */
    public function propose(
        string $title,
        string $content,
        string $memoryType,
        array $retrievalTypes,
        array $tags = [],
        array $metadata = [],
        ?string $source = null,
        ?string $parentMemoryId = null,
        ?string $ownerUserKey = null
    ): array {
        $title = $this->bounded($title, 'title', 240);
        $content = trim($content);
        if ($content === '' || strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new InvalidArgumentException('Memory content is required and must be 100,000 bytes or fewer.');
        }
        if (!in_array($memoryType, self::MEMORY_TYPES, true)) {
            throw new InvalidArgumentException('Memory type is invalid.');
        }
        $retrievalTypes = $this->list($retrievalTypes, self::RETRIEVAL_TYPES, 'retrieval_types', 12, 40);
        $tags = $this->list($tags, null, 'tags', 30, 80);
        if (count($metadata) > 50) {
            throw new InvalidArgumentException('Memory metadata has too many fields.');
        }
        $source = $source === null || trim($source) === '' ? null : $this->bounded($source, 'source', 512);
        $parentMemoryId = $parentMemoryId === null || trim($parentMemoryId) === '' ? null : $this->bounded($parentMemoryId, 'parent_memory_id', 128);
        $ownerUserKey = $ownerUserKey !== null && trim($ownerUserKey) !== '' ? $this->bounded($ownerUserKey, 'owner_user_key', 36) : (isset($_SESSION['builderx_user_key']) ? (string) $_SESSION['builderx_user_key'] : null);
        $memoryId = \bx_uuid();

        $saved = \bx_db()->Execute(
            'INSERT INTO builder_ai_memory (memory_key, memory_version, title, content, memory_type, retrieval_types_json, tags_json, metadata_json, source_reference, parent_memory_key, memory_status, review_status, owner_user_key) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, \'pending_approval\', \'unreviewed\', ?)',
            [$memoryId, $title, $content, $memoryType, $this->encode($retrievalTypes), $this->encode($tags), $this->encode($metadata), $source, $parentMemoryId, $ownerUserKey]
        );
        if ($saved === false) {
            throw new RuntimeException('The memory proposal could not be saved.');
        }
        \bx_audit('CREATE', 'builder_ai_memory', $memoryId, ['memory_type' => $memoryType, 'status' => 'pending_approval']);

        return $this->find($memoryId) ?? throw new RuntimeException('The memory proposal could not be read back.');
    }

    /** @return array<string, mixed>|null */
    public function find(string $memoryId): ?array
    {
        $memoryId = $this->bounded($memoryId, 'memory_id', 128);
        $row = \bx_db()->GetRow('SELECT * FROM builder_ai_memory WHERE memory_key = ? LIMIT 1', [$memoryId]);
        return $row ? $this->toContract($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function listRecent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $rows = \bx_db()->GetAll('SELECT * FROM builder_ai_memory ORDER BY x_id DESC LIMIT ' . $limit) ?: [];
        return array_map(fn (array $row): array => $this->toContract($row), $rows);
    }

    /** @return array<string, mixed> */
    public function approve(string $memoryId, string $approvedByKey): array
    {
        $memoryId = $this->bounded($memoryId, 'memory_id', 128);
        $approvedByKey = $this->bounded($approvedByKey, 'approved_by_key', 36);
        $current = $this->find($memoryId);
        if ($current === null || $current['status'] !== 'pending_approval' || $current['review_status'] !== 'unreviewed') {
            throw new InvalidArgumentException('Only an unreviewed pending memory can be approved.');
        }
        $vaultPath = $this->writeObsidian($current);
        $saved = \bx_db()->Execute(
            "UPDATE builder_ai_memory SET memory_status = 'approved', review_status = 'approved', approved_by_key = ?, vault_path = ?, approved_at = CURRENT_TIMESTAMP WHERE memory_key = ? AND memory_status = 'pending_approval' AND review_status = 'unreviewed'",
            [$approvedByKey, $vaultPath, $memoryId]
        );
        if ($saved === false) {
            throw new RuntimeException('The memory approval could not be saved.');
        }
        \bx_audit('APPROVE', 'builder_ai_memory', $memoryId, ['status' => 'approved', 'vault_path' => $vaultPath]);
        return $this->find($memoryId) ?? throw new RuntimeException('The approved memory could not be read back.');
    }

    /** @param array<string, string> $filters @return list<array<string, mixed>> */
    public function search(string $query, array $filters = [], int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 512) {
            throw new InvalidArgumentException('A memory search query is required.');
        }
        $limit = max(1, min($limit, 50));
        $rows = \bx_db()->GetAll("SELECT * FROM builder_ai_memory WHERE memory_status = 'approved' AND review_status = 'approved' ORDER BY updated_at DESC, x_id DESC LIMIT 200") ?: [];
        $terms = preg_split('/\s+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $results = [];
        foreach ($rows as $row) {
            $memory = $this->toContract($row);
            if (isset($filters['memory_type']) && $filters['memory_type'] !== $memory['memory_type']) {
                continue;
            }
            if (isset($filters['tag']) && !in_array((string) $filters['tag'], $memory['tags'], true)) {
                continue;
            }
            $haystack = strtolower($memory['title'] . ' ' . $memory['content'] . ' ' . implode(' ', $memory['tags']));
            $score = 0;
            foreach ($terms as $term) {
                $score += substr_count(strtolower($memory['title']), $term) * 5;
                $score += substr_count($haystack, $term);
            }
            if ($score === 0) {
                continue;
            }
            $memory['retrieval'] = ['method' => 'hybrid', 'keyword_score' => $score, 'metadata_filters' => $filters, 'vector_used' => false];
            $results[] = $memory;
        }
        usort($results, static fn (array $left, array $right): int => (int) ($right['retrieval']['keyword_score'] ?? 0) <=> (int) ($left['retrieval']['keyword_score'] ?? 0));
        return array_slice($results, 0, $limit);
    }

    /** @param array<string, mixed> $memory */
    private function writeObsidian(array $memory): string
    {
        $vault = dirname(__DIR__, 2) . '/storage/ai-memory/obsidian';
        if (!is_dir($vault) && !mkdir($vault, 02770, true) && !is_dir($vault)) {
            throw new RuntimeException('The Obsidian memory vault could not be created.');
        }
        if (is_link($vault)) {
            throw new RuntimeException('The Obsidian memory vault is unsafe.');
        }
        chmod($vault, 02770);
        $fileName = (string) $memory['memory_id'] . '.md';
        $path = $vault . DIRECTORY_SEPARATOR . $fileName;
        $frontmatter = [
            'memory_id' => $memory['memory_id'],
            'title' => $memory['title'],
            'memory_type' => $memory['memory_type'],
            'tags' => $memory['tags'],
            'retrieval_types' => $memory['retrieval_types'],
            'status' => 'approved',
            'source' => $memory['source'],
        ];
        $yaml = "---\n";
        foreach ($frontmatter as $key => $value) {
            $yaml .= $key . ': ' . (is_array($value) ? '[' . implode(', ', array_map(static fn (string $item): string => '"' . str_replace('"', '\\"', $item) . '"', $value)) . ']' : '"' . str_replace('"', '\\"', (string) $value) . '"') . "\n";
        }
        $yaml .= "---\n\n# " . $memory['title'] . "\n\n" . $memory['content'] . "\n";
        $temporary = tempnam($vault, '.memory-');
        if ($temporary === false || file_put_contents($temporary, $yaml, LOCK_EX) !== strlen($yaml)) {
            throw new RuntimeException('The Obsidian memory note could not be written.');
        }
        chmod($temporary, 0660);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('The Obsidian memory note could not be published.');
        }
        chmod($path, 0660);
        return 'storage/ai-memory/obsidian/' . $fileName;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function toContract(array $row): array
    {
        return [
            'memory_id' => (string) $row['memory_key'],
            'version' => (int) $row['memory_version'],
            'title' => (string) $row['title'],
            'content' => (string) $row['content'],
            'memory_type' => (string) $row['memory_type'],
            'retrieval_types' => $this->decodeList($row['retrieval_types_json']),
            'tags' => $this->decodeList($row['tags_json']),
            'metadata' => $this->decodeObject($row['metadata_json']),
            'source' => $row['source_reference'] !== null ? (string) $row['source_reference'] : null,
            'parent_memory_id' => $row['parent_memory_key'] !== null ? (string) $row['parent_memory_key'] : null,
            'status' => (string) $row['memory_status'],
            'review_status' => (string) $row['review_status'],
            'vault_path' => $row['vault_path'] !== null ? (string) $row['vault_path'] : null,
            'owner_user_key' => $row['owner_user_key'] !== null ? (string) $row['owner_user_key'] : null,
            'approved_by_key' => $row['approved_by_key'] !== null ? (string) $row['approved_by_key'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param list<string> $values @param list<string>|null $allowed @return list<string> */
    private function list(array $values, ?array $allowed, string $field, int $maxItems, int $maxLength): array
    {
        if (count($values) > $maxItems) {
            throw new InvalidArgumentException('Memory ' . $field . ' has too many entries.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > $maxLength || ($allowed !== null && !in_array($value, $allowed, true))) {
                throw new InvalidArgumentException('Memory ' . $field . ' contains an invalid entry.');
            }
        }
        return array_values(array_unique(array_map('trim', $values)));
    }

    private function bounded(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Memory ' . $field . ' is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        catch (\JsonException $error) { throw new InvalidArgumentException('Memory JSON is invalid.', 0, $error); }
    }

    /** @return list<string> */
    private function decodeList(mixed $value): array
    {
        $decoded = $this->decodeObject($value);
        foreach ($decoded as $item) if (!is_string($item)) throw new RuntimeException('Stored memory list is invalid.');
        return array_values($decoded);
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value): array
    {
        try { $decoded = json_decode((string) $value, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('Stored memory JSON is invalid.', 0, $error); }
        return is_array($decoded) ? $decoded : [];
    }
}
