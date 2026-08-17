<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class FileService implements FileServiceGateway
{
    private const MAX_FILE_BYTES = 1048576;
    private const PROTECTED_NAMES = [
        '.env',
        '.git',
        'config.local.php',
        'backups',
        'audit',
        'codex-communication',
    ];

    /** @var list<string> */
    private array $roots;

    /** @param list<string> $roots */
    public function __construct(array $roots)
    {
        if ($roots === []) {
            throw new InvalidArgumentException('At least one file-service root is required.');
        }

        $this->roots = [];
        foreach ($roots as $root) {
            $realRoot = realpath($root);
            if ($realRoot === false || !is_dir($realRoot) || is_link($root)) {
                throw new InvalidArgumentException('A file-service root is missing or unsafe.');
            }
            $this->roots[] = rtrim($realRoot, DIRECTORY_SEPARATOR);
        }
        $this->roots = array_values(array_unique($this->roots));
    }

    /** @return list<array<string, string|int>> */
    public function list(string $path = '.', int $limit = 100): array
    {
        $directory = $this->resolve($path, true);
        $limit = max(1, min($limit, 200));
        $items = [];
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('The directory could not be listed.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $directory . DIRECTORY_SEPARATOR . $entry;
            if ($this->isProtected($fullPath) || is_link($fullPath)) {
                continue;
            }
            $items[] = $this->describe($fullPath);
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** @return list<array<string, string|int>> */
    public function search(string $query, string $path = '.', int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 256) {
            throw new InvalidArgumentException('A search query is required and must be 256 bytes or fewer.');
        }
        $root = $this->resolve($path, true);
        $limit = max(1, min($limit, 200));
        $results = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $fullPath = $file->getPathname();
            if ($this->isProtected($fullPath) || $file->isLink() || !$file->isFile()) {
                continue;
            }
            $relative = $this->relativePath($fullPath);
            $matched = stripos($relative, $query) !== false;
            if (!$matched && $file->getSize() <= self::MAX_FILE_BYTES) {
                $contents = file_get_contents($fullPath);
                $matched = $contents !== false && stripos($contents, $query) !== false;
            }
            if ($matched) {
                $results[] = $this->describe($fullPath);
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /** @return array<string, string|int> */
    public function read(string $path): array
    {
        $file = $this->resolve($path, false);
        if (!is_file($file) || is_link($file)) {
            throw new RuntimeException('The requested file does not exist.');
        }
        $size = filesize($file);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('The requested file exceeds the size limit.');
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('The requested file could not be read.');
        }

        return $this->describe($file) + [
            'contents' => $contents,
            'sha256' => hash_file('sha256', $file),
        ];
    }

    /** @return array<string, string|int> */
    public function write(string $path, string $contents, bool $overwrite): array
    {
        $this->assertWriterIdentity();
        if (strlen($contents) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('The file exceeds the size limit.');
        }
        $target = $this->resolveForWrite($path);
        if (!$overwrite && file_exists($target)) {
            throw new RuntimeException('The target file already exists.');
        }
        $temporary = tempnam(dirname($target), '.builderx-file-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create an atomic file.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Unable to write the file contents.');
            }
            chmod($temporary, 0660);
            if (!rename($temporary, $target)) {
                throw new RuntimeException('Unable to publish the file atomically.');
            }
            chmod($target, 0660);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $this->read($path);
    }

    private function resolve(string $path, bool $directory): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = '.';
        }
        $candidate = $this->isAbsolute($path) ? $path : $this->roots[0] . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        $realPath = realpath($candidate);
        if ($realPath === false || !$this->isAllowed($realPath) || $this->isProtected($realPath) || is_link($candidate)) {
            throw new InvalidArgumentException('The requested path is outside the file-service allowlist.');
        }
        if ($directory && !is_dir($realPath)) {
            throw new InvalidArgumentException('The requested path is not a directory.');
        }
        if (!$directory && !is_file($realPath)) {
            throw new InvalidArgumentException('The requested path is not a file.');
        }

        return $realPath;
    }

    private function resolveForWrite(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_ends_with($path, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('A target file path is required.');
        }
        $candidate = $this->isAbsolute($path) ? $path : $this->roots[0] . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        $parent = realpath(dirname($candidate));
        if ($parent === false || !$this->isAllowed($parent) || $this->isProtected($parent) || is_link(dirname($candidate))) {
            throw new InvalidArgumentException('The target directory is outside the file-service allowlist.');
        }
        $target = $parent . DIRECTORY_SEPARATOR . basename($candidate);
        if (is_link($target) || $this->isProtected($target)) {
            throw new InvalidArgumentException('The target file is protected or unsafe.');
        }

        return $target;
    }

    private function isAllowed(string $path): bool
    {
        foreach ($this->roots as $root) {
            if ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function isProtected(string $path): bool
    {
        $parts = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
        foreach ($parts as $part) {
            if (in_array($part, self::PROTECTED_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $path): string
    {
        foreach ($this->roots as $root) {
            if ($path === $root) {
                return '.';
            }
            if (str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                return ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
            }
        }

        return $path;
    }

    /** @return array<string, string|int> */
    private function describe(string $path): array
    {
        $isDirectory = is_dir($path);
        return [
            'path' => $this->relativePath($path),
            'type' => $isDirectory ? 'directory' : 'file',
            'size' => $isDirectory ? 0 : (int) (filesize($path) ?: 0),
            'mode' => substr(sprintf('%o', fileperms($path) ?: 0), -4),
        ];
    }

    private function assertWriterIdentity(): void
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwnam')) {
            $wwwData = posix_getpwnam('www-data');
            if (is_array($wwwData) && posix_geteuid() !== (int) $wwwData['uid']) {
                throw new RuntimeException('File writes must be performed by the www-data File Service identity.');
            }
        }
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR);
    }
}
