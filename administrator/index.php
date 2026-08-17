<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/foundation.php';

function bx_admin_redirect(string $tab): void
{
    header('Location: ./?tab=' . rawurlencode($tab));
    exit;
}

function bx_admin_redirect_with_state(string $tab, array $state): void
{
    $_SESSION['builderx_admin_state'] = $state;
    bx_admin_redirect($tab);
}

function bx_admin_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function bx_project_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim(dirname(dirname($scriptName)), '/');

    return ($basePath === '' ? '' : $basePath) . '/';
}

function bx_template_default_presets(): array
{
    return [
        [
            'label' => 'Base b0',
            'preset_arg' => '--preset b0',
            'preset' => 'b0',
            'template' => 'next',
        ],
        [
            'label' => 'Preset b5rR41Mtnc',
            'preset_arg' => '--preset b5rR41Mtnc',
            'preset' => 'b5rR41Mtnc',
            'template' => 'next',
        ],
    ];
}

function bx_template_preset_code_from_arg(string $presetArg): string
{
    $presetArg = trim($presetArg);
    if (!preg_match('/^--preset\s+([A-Za-z0-9_-]{1,64})$/', $presetArg, $matches)) {
        throw new InvalidArgumentException('Preset argument must use this format: --preset b5rR41Mtnc');
    }

    return $matches[1];
}

function bx_template_normalize_template(string $template): string
{
    $template = trim($template);
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $template)) {
        throw new InvalidArgumentException('Template must use letters, numbers, underscores, or hyphens only.');
    }

    return $template;
}

function bx_template_normalize_preset(array $preset): ?array
{
    try {
        $presetArg = trim((string) ($preset['preset_arg'] ?? ''));
        if ($presetArg === '' && isset($preset['preset'])) {
            $presetArg = '--preset ' . trim((string) $preset['preset']);
        }
        $presetCode = bx_template_preset_code_from_arg($presetArg);
        $template = bx_template_normalize_template((string) ($preset['template'] ?? 'next'));
    } catch (Throwable) {
        return null;
    }

    $label = trim((string) ($preset['label'] ?? ''));
    if ($label === '') {
        $label = $presetCode . ' / ' . $template;
    }

    return [
        'label' => substr($label, 0, 80),
        'preset_arg' => '--preset ' . $presetCode,
        'preset' => $presetCode,
        'template' => $template,
        'command' => sprintf('npx shadcn@latest init --preset %s --template %s', $presetCode, $template),
    ];
}

function bx_template_presets(): array
{
    $raw = (string) bx_db()->GetOne(
        "SELECT setting_value FROM builder_system_setting WHERE setting_name = 'template_presets' AND setting_status = 'ACTIVE'"
    );
    $decoded = json_decode($raw, true);
    $items = is_array($decoded) ? $decoded : bx_template_default_presets();
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $preset = bx_template_normalize_preset($item);
        if ($preset) {
            $normalized[$preset['preset'] . ':' . $preset['template']] = $preset;
        }
    }

    foreach (bx_template_default_presets() as $item) {
        $preset = bx_template_normalize_preset($item);
        if ($preset) {
            $normalized[$preset['preset'] . ':' . $preset['template']] = $normalized[$preset['preset'] . ':' . $preset['template']] ?? $preset;
        }
    }

    return array_values($normalized);
}

function bx_template_save_presets(array $presets): void
{
    $json = json_encode(array_values($presets), JSON_UNESCAPED_SLASHES);
    if ((int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_system_setting WHERE setting_name = 'template_presets'") === 0) {
        bx_db()->Execute(
            'INSERT INTO builder_system_setting (setting_key, setting_name, setting_value, setting_group) VALUES (?, ?, ?, ?)',
            [bx_uuid(), 'template_presets', $json, 'template']
        );
        return;
    }

    bx_db()->Execute(
        "UPDATE builder_system_setting SET setting_value = ?, setting_group = 'template', setting_status = 'ACTIVE' WHERE setting_name = 'template_presets'",
        [$json]
    );
}

function bx_template_store_preset(string $label, string $presetArg, string $template): array
{
    $preset = bx_template_normalize_preset([
        'label' => $label,
        'preset_arg' => $presetArg,
        'template' => $template,
    ]);
    if (!$preset) {
        throw new InvalidArgumentException('Template preset is invalid.');
    }

    $presets = bx_template_presets();
    $stored = [];
    foreach ($presets as $existing) {
        $stored[$existing['preset'] . ':' . $existing['template']] = $existing;
    }
    $stored[$preset['preset'] . ':' . $preset['template']] = $preset;
    bx_template_save_presets(array_values($stored));

    return $preset;
}

function bx_template_run_shell_step(string $workingDirectory, string $displayCommand, string $command): array
{
    if (!is_dir($workingDirectory) || !is_writable($workingDirectory)) {
        throw new RuntimeException("Working directory is not writable by the web server: {$workingDirectory}");
    }

    $shellCommand = sprintf(
        'cd %s && timeout 240s env HOME=/tmp XDG_CONFIG_HOME=/tmp NPM_CONFIG_CACHE=/tmp/builderx-npm-cache %s 2>&1',
        escapeshellarg($workingDirectory),
        $command
    );

    $startedAt = microtime(true);
    $output = [];
    $exitCode = 0;
    exec($shellCommand, $output, $exitCode);

    return [
        'command' => $displayCommand,
        'root_path' => $workingDirectory,
        'exit_code' => $exitCode,
        'duration_seconds' => round(microtime(true) - $startedAt, 2),
        'output' => implode("\n", $output),
    ];
}

function bx_template_shadcn_targets(string $projectRoot): array
{
    $candidates = [
        $projectRoot . '/frontend',
    ];
    $targets = [];

    foreach ($candidates as $candidate) {
        $realPath = realpath($candidate);
        if ($realPath && bx_template_is_buildable_shadcn_target($realPath)) {
            $targets[$realPath] = $realPath;
        }
    }

    return array_values($targets);
}

function bx_template_is_buildable_shadcn_target(string $workingDirectory): bool
{
    $componentsFile = $workingDirectory . '/components.json';
    if (!is_file($componentsFile) || !bx_template_has_npm_build($workingDirectory)) {
        return false;
    }

    $components = json_decode((string) file_get_contents($componentsFile), true);
    $tailwindCss = is_array($components) ? (string) ($components['tailwind']['css'] ?? '') : '';
    if ($tailwindCss === '') {
        return false;
    }

    return is_file($workingDirectory . '/' . ltrim($tailwindCss, '/'));
}

function bx_template_has_npm_build(string $workingDirectory): bool
{
    $packageFile = $workingDirectory . '/package.json';
    if (!is_file($packageFile)) {
        return false;
    }

    $package = json_decode((string) file_get_contents($packageFile), true);
    return is_array($package)
        && isset($package['scripts'])
        && is_array($package['scripts'])
        && isset($package['scripts']['build'])
        && trim((string) $package['scripts']['build']) !== '';
}

function bx_template_cleanup_generated_ui_imports(string $workingDirectory): array
{
    $uiDirectory = $workingDirectory . '/src/components/ui';
    if (!is_dir($uiDirectory)) {
        return [];
    }

    $changed = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uiDirectory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'tsx') {
            continue;
        }

        $path = $file->getPathname();
        $contents = (string) file_get_contents($path);
        if (!str_contains($contents, 'React.')) {
            $updated = preg_replace('/^import \* as React from ["\']react["\']\R/m', '', $contents);
            if (is_string($updated) && $updated !== $contents) {
                file_put_contents($path, $updated);
                $changed[] = substr($path, strlen($workingDirectory) + 1);
            }
        }
    }

    return $changed;
}

function bx_admin_run_template_command(string $presetArg, string $template): array
{
    $preset = bx_template_preset_code_from_arg($presetArg);
    $template = bx_template_normalize_template($template);
    $projectRoot = dirname(__DIR__);
    $sharedFrontend = realpath($projectRoot . '/frontend') ?: $projectRoot . '/frontend';

    if (!is_dir($projectRoot) || !is_writable($projectRoot)) {
        throw new RuntimeException('Project root is not writable by the web server.');
    }

    $displayCommand = sprintf('npx shadcn@latest init --preset %s --template %s', $preset, $template);
    $startedAt = microtime(true);
    $steps = [];
    $steps[] = [
        'command' => $displayCommand,
        'root_path' => $projectRoot,
        'exit_code' => 0,
        'duration_seconds' => 0,
        'output' => 'BuilderX root is a PHP project. The validated preset is applied to detected buildable shadcn frontend targets that share this project template.',
    ];
    $appliedTargets = [];
    $targets = bx_template_shadcn_targets($projectRoot);
    if ($targets === []) {
        $steps[] = [
            'command' => 'Detect buildable shadcn targets',
            'root_path' => $projectRoot,
            'exit_code' => 1,
            'duration_seconds' => 0,
            'output' => 'No buildable shadcn frontend target was found. Expected frontend with components.json, package.json build script, and configured Tailwind CSS file.',
        ];
    }

    foreach ($targets as $target) {
        if (!is_dir($target . '/node_modules')) {
            $installStep = bx_template_run_shell_step($target, 'npm install', 'npm install');
            $steps[] = $installStep;

            if ((int) $installStep['exit_code'] !== 0) {
                continue;
            }
        }

        $applyCommand = sprintf('npx shadcn@latest apply %s --yes', $preset);
        $step = bx_template_run_shell_step(
            $target,
            $applyCommand,
            sprintf('npx -y shadcn@latest apply %s --yes', escapeshellarg($preset))
        );
        $steps[] = $step;

        if ((int) $step['exit_code'] !== 0) {
            continue;
        }

        $appliedTargets[] = $target;
        $cleanedFiles = bx_template_cleanup_generated_ui_imports($target);
        if ($cleanedFiles !== []) {
            $steps[] = [
                'command' => 'Clean generated shadcn imports',
                'root_path' => $target,
                'exit_code' => 0,
                'duration_seconds' => 0,
                'output' => 'Removed unused React imports from: ' . implode(', ', $cleanedFiles),
            ];
        }

        if ($target === $sharedFrontend) {
            $steps[] = bx_template_run_shell_step(
                $target,
                'npm run build',
                'npm run build'
            );
        }
    }

    $exitCode = 0;
    foreach ($steps as $step) {
        if ((int) $step['exit_code'] !== 0) {
            $exitCode = (int) $step['exit_code'];
            break;
        }
    }

    $combinedOutput = [];
    foreach ($steps as $index => $step) {
        $combinedOutput[] = sprintf(
            "[%d] %s\nPath: %s\nExit code: %s\nDuration: %ss\n%s",
            $index + 1,
            $step['command'],
            $step['root_path'],
            (string) $step['exit_code'],
            (string) $step['duration_seconds'],
            $step['output'] !== '' ? $step['output'] : '(No command output)'
        );
    }

    $sharedFrontendWasApplied = in_array($sharedFrontend, $appliedTargets, true);

    return [
        'command' => $displayCommand,
        'root_path' => $projectRoot,
        'exit_code' => $exitCode,
        'duration_seconds' => round(microtime(true) - $startedAt, 2),
        'output' => implode("\n\n", $combinedOutput),
        'steps' => $steps,
        'applied_targets' => $appliedTargets,
        'administrator_target' => $sharedFrontend,
        'administrator_applied' => $sharedFrontendWasApplied,
        'refresh_administrator' => $sharedFrontendWasApplied && $exitCode === 0,
    ];
}

function bx_post_array(string $key): array
{
    $value = $_POST[$key] ?? [];
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value)));
}

function bx_replace_user_links(string $table, string $userKey, string $targetColumn, array $targetKeys): void
{
    bx_db()->Execute("DELETE FROM {$table} WHERE user_key = ?", [$userKey]);

    foreach (array_unique($targetKeys) as $targetKey) {
        if ($targetKey === '') {
            continue;
        }

        bx_db()->Execute(
            "INSERT IGNORE INTO {$table} (user_key, {$targetColumn}) VALUES (?, ?)",
            [$userKey, $targetKey]
        );
    }
}

function bx_validate_existing_keys(string $table, string $keyColumn, array $keys, string $statusColumn): bool
{
    foreach (array_unique($keys) as $key) {
        if ($key === '') {
            continue;
        }

        $exists = (int) bx_db()->GetOne(
            "SELECT COUNT(*) FROM {$table} WHERE {$keyColumn} = ? AND {$statusColumn} <> 'DELETED'",
            [$key]
        );

        if ($exists === 0) {
            return false;
        }
    }

    return true;
}

function bx_ini_bytes(string $value): int
{
    $raw = trim($value);
    if ($raw === '-1') {
        return PHP_INT_MAX;
    }
    if ($raw === '') {
        return 0;
    }

    $unit = strtolower(substr($raw, -1));
    $number = (float) $raw;

    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function bx_format_bytes(int $bytes): string
{
    if ($bytes === PHP_INT_MAX) {
        return 'unlimited';
    }

    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / 1024 / 1024 / 1024, 1) . ' GB';
    }

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }

    return number_format(max(0, $bytes) / 1024, 1) . ' KB';
}

function bx_command_version(string $command): string
{
    $allowed = [
        'php' => 'php -v 2>&1',
        'composer' => 'composer --version 2>&1',
        'node' => 'node --version 2>&1',
        'npm' => 'npm --version 2>&1',
        'git' => 'git --version 2>&1',
        'apache' => 'apache2 -v 2>&1',
        'nginx' => 'nginx -v 2>&1',
        'mysql' => 'mysql --version 2>&1',
        'smartctl' => 'smartctl --version 2>&1',
        'sensors' => 'sensors 2>&1',
    ];

    if (!isset($allowed[$command]) || !function_exists('shell_exec')) {
        return 'Not available';
    }

    $output = trim((string) shell_exec($allowed[$command]));
    if ($output === '') {
        return 'Not available';
    }

    return strtok($output, "\n") ?: $output;
}

function bx_system_memory(): array
{
    $info = ['total' => 0, 'available' => 0, 'free' => 0, 'swap_total' => 0, 'swap_free' => 0];
    if (!is_readable('/proc/meminfo')) {
        return $info;
    }

    foreach (file('/proc/meminfo') ?: [] as $line) {
        if (!preg_match('/^([A-Za-z_()]+):\s+(\d+)/', $line, $match)) {
            continue;
        }
        $bytes = (int) $match[2] * 1024;
        match ($match[1]) {
            'MemTotal' => $info['total'] = $bytes,
            'MemAvailable' => $info['available'] = $bytes,
            'MemFree' => $info['free'] = $bytes,
            'SwapTotal' => $info['swap_total'] = $bytes,
            'SwapFree' => $info['swap_free'] = $bytes,
            default => null,
        };
    }

    return $info;
}

function bx_mount_usage(): array
{
    $mounts = [];
    if (!is_readable('/proc/mounts')) {
        return [['mount' => '/', 'total' => @disk_total_space('/') ?: 0, 'free' => @disk_free_space('/') ?: 0]];
    }

    $seen = [];
    foreach (file('/proc/mounts') ?: [] as $line) {
        $parts = explode(' ', $line);
        $mount = str_replace('\\040', ' ', $parts[1] ?? '');
        $type = $parts[2] ?? '';
        if ($mount === '' || isset($seen[$mount]) || in_array($type, ['proc', 'sysfs', 'tmpfs', 'devtmpfs', 'devpts', 'cgroup', 'cgroup2', 'overlay', 'squashfs'], true)) {
            continue;
        }
        $total = @disk_total_space($mount);
        $free = @disk_free_space($mount);
        if ($total === false || $free === false || $total <= 0) {
            continue;
        }
        $seen[$mount] = true;
        $mounts[] = ['mount' => $mount, 'type' => $type, 'total' => (int) $total, 'free' => (int) $free, 'used' => (int) ($total - $free)];
    }

    return $mounts;
}

function bx_temperatures(): array
{
    $items = [];
    foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $path) {
        $raw = trim((string) @file_get_contents($path));
        if ($raw === '' || !is_numeric($raw)) {
            continue;
        }
        $labelPath = dirname($path) . '/type';
        $items[] = [
            'label' => is_readable($labelPath) ? trim((string) file_get_contents($labelPath)) : basename(dirname($path)),
            'value' => number_format(((float) $raw) / 1000, 1) . ' C',
        ];
    }

    return $items;
}

function bx_path_owner_label(string $path): string
{
    if (!file_exists($path)) {
        return 'missing';
    }

    $owner = @fileowner($path);
    $group = @filegroup($path);
    $ownerLabel = (string) $owner;
    $groupLabel = (string) $group;

    if ($owner !== false && function_exists('posix_getpwuid')) {
        $ownerInfo = @posix_getpwuid($owner);
        $ownerLabel = is_array($ownerInfo) && isset($ownerInfo['name']) ? (string) $ownerInfo['name'] : (string) $owner;
    }

    if ($group !== false && function_exists('posix_getgrgid')) {
        $groupInfo = @posix_getgrgid($group);
        $groupLabel = is_array($groupInfo) && isset($groupInfo['name']) ? (string) $groupInfo['name'] : (string) $group;
    }

    return $ownerLabel . ':' . $groupLabel;
}

function bx_permission_mode(string $path): string
{
    if (!file_exists($path)) {
        return 'missing';
    }

    $perms = @fileperms($path);
    return $perms === false ? 'unknown' : substr(sprintf('%o', $perms), -4);
}

function bx_required_folder_checks(): array
{
    $root = dirname(__DIR__);
    $folders = [
        ['label' => 'Storage root', 'path' => $root . '/storage', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Phase note attachments', 'path' => $root . '/storage/phase-note-attachments', 'required' => '0755 directory, uploaded images 0644'],
        ['label' => 'Uploads', 'path' => $root . '/storage/uploads', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Backups', 'path' => $root . '/storage/backups', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Logs', 'path' => $root . '/storage/logs', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Imports', 'path' => $root . '/storage/imports', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Exports', 'path' => $root . '/storage/exports', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Reports', 'path' => $root . '/storage/reports', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Queue', 'path' => $root . '/storage/queue', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Shared frontend build', 'path' => $root . '/frontend/dist', 'required' => 'Readable and traversable'],
    ];

    return array_map(static function (array $folder): array {
        $path = $folder['path'];
        $exists = is_dir($path);
        $readable = $exists && is_readable($path);
        $writable = $exists && is_writable($path);
        $traversable = $exists && is_executable($path);
        $requiresWrite = $folder['label'] !== 'Shared frontend build';
        $ok = $exists && $readable && $traversable && (!$requiresWrite || $writable);

        return [
            'label' => $folder['label'],
            'path' => $path,
            'required' => $folder['required'],
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
            'traversable' => $traversable,
            'mode' => bx_permission_mode($path),
            'owner' => bx_path_owner_label($path),
            'status' => $ok ? 'OK' : 'Needs Attention',
        ];
    }, $folders);
}

function bx_tail_file_lines(string $path, int $bytes = 65536): array
{
    if (!is_readable($path) || !is_file($path)) {
        return [];
    }

    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return [];
    }

    $size = @filesize($path) ?: 0;
    if ($size > $bytes) {
        fseek($handle, -$bytes, SEEK_END);
    }

    $content = stream_get_contents($handle);
    fclose($handle);

    return array_values(array_filter(array_map('trim', explode("\n", (string) $content))));
}

function bx_recent_error_log_entries(): array
{
    $root = dirname(__DIR__);
    $paths = [
        '/var/log/apache2/error.log',
        '/var/log/apache2/builderX-error.log',
    ];

    foreach (glob($root . '/storage/logs/*/*.log') ?: [] as $path) {
        $paths[] = $path;
    }

    $entries = [];
    foreach (array_unique($paths) as $path) {
        if (!file_exists($path)) {
            continue;
        }
        if (!is_readable($path)) {
            $entries[] = ['source' => $path, 'message' => 'Log file exists but is not readable by the web server process.', 'level' => 'warning'];
            continue;
        }

        foreach (array_slice(array_reverse(bx_tail_file_lines($path)), 0, 80) as $line) {
            if (!preg_match('/(fatal|warning|error|permission denied|not found|404|phase-note-attachments|note-attachment|upload_note_attachment|failed)/i', $line)) {
                continue;
            }
            $entries[] = [
                'source' => $path,
                'message' => substr($line, 0, 500),
                'level' => preg_match('/(fatal|permission denied)/i', $line) ? 'error' : 'warning',
            ];
            if (count($entries) >= 25) {
                return $entries;
            }
        }
    }

    return $entries;
}

function bx_attachment_storage_check(): array
{
    $issues = [];
    $checked = 0;
    $active = 0;

    try {
        $rows = bx_db()->GetAll(
            "SELECT attachment_key, original_name, storage_path FROM builder_phase_task_note_attachment WHERE attachment_status = 'ACTIVE' ORDER BY x_id DESC LIMIT 100"
        ) ?: [];
        $active = (int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_phase_task_note_attachment WHERE attachment_status = 'ACTIVE'");
    } catch (Throwable $exception) {
        return [
            'status' => 'Needs Attention',
            'checked' => 0,
            'active' => 0,
            'issues' => [['message' => 'Attachment metadata could not be checked: ' . $exception->getMessage()]],
        ];
    }

    foreach ($rows as $row) {
        $checked++;
        $path = (string) ($row['storage_path'] ?? '');
        $label = (string) ($row['original_name'] ?? $row['attachment_key'] ?? 'attachment');
        if ($path === '' || !is_file($path)) {
            $issues[] = ['message' => $label . ' is missing from disk.'];
            continue;
        }
        if (!is_readable($path)) {
            $issues[] = ['message' => $label . ' exists but is not readable.'];
        }
        if (bx_permission_mode($path) !== '0644') {
            $issues[] = ['message' => $label . ' has mode ' . bx_permission_mode($path) . '; expected 0644 for web thumbnails.'];
        }
        if (count($issues) >= 10) {
            break;
        }
    }

    return [
        'status' => count($issues) === 0 ? 'OK' : 'Needs Attention',
        'checked' => $checked,
        'active' => $active,
        'issues' => $issues,
    ];
}

function bx_runtime_health_snapshot(): array
{
    $requiredUpload = 1073741824;
    $memory = bx_system_memory();
    $safeMemory = $memory['total'] > 0 ? (int) floor($memory['total'] * 0.75) : 0;
    $phpSettings = [];
    foreach (['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars'] as $key) {
        $raw = (string) ini_get($key);
        $bytes = in_array($key, ['upload_max_filesize', 'post_max_size', 'memory_limit'], true) ? bx_ini_bytes($raw) : 0;
        $target = in_array($key, ['upload_max_filesize', 'post_max_size'], true) ? $requiredUpload : ($key === 'memory_limit' ? min($requiredUpload, $safeMemory ?: $requiredUpload) : ($key === 'max_input_vars' ? 10000 : 300));
        $ok = in_array($key, ['upload_max_filesize', 'post_max_size', 'memory_limit'], true) ? $bytes >= $target : ((int) $raw === 0 || (int) $raw >= $target);
        $phpSettings[] = [
            'name' => $key,
            'current' => $raw,
            'recommended' => in_array($key, ['upload_max_filesize', 'post_max_size', 'memory_limit'], true) ? bx_format_bytes($target) : (string) $target,
            'status' => $ok ? 'OK' : 'Upgrade Available',
        ];
    }

    $mysqlVariables = [];
    foreach (['version', 'max_allowed_packet', 'innodb_buffer_pool_size', 'max_connections', 'wait_timeout', 'interactive_timeout'] as $name) {
        try {
            $row = bx_db()->GetRow("SHOW VARIABLES LIKE '" . str_replace("'", "''", $name) . "'");
            if ($row) {
                $mysqlVariables[] = ['name' => $name, 'value' => (string) ($row['Value'] ?? $row['value'] ?? '')];
            }
        } catch (Throwable) {
            $mysqlVariables[] = ['name' => $name, 'value' => 'Permission unavailable'];
        }
    }

    $internet = 'Not checked';
    $latencyMs = '';
    $start = microtime(true);
    $connection = @fsockopen('1.1.1.1', 53, $errno, $errstr, 1.5);
    if (is_resource($connection)) {
        fclose($connection);
        $latencyMs = number_format((microtime(true) - $start) * 1000, 0) . ' ms';
        $internet = 'Reachable';
    } else {
        $internet = 'Unavailable';
    }

    $configFiles = [
        ['path' => realpath(__DIR__ . '/../.user.ini') ?: __DIR__ . '/../.user.ini', 'writable' => is_writable(__DIR__ . '/../.user.ini')],
        ['path' => realpath(__DIR__ . '/../.htaccess') ?: __DIR__ . '/../.htaccess', 'writable' => is_writable(__DIR__ . '/../.htaccess')],
        ['path' => realpath(__DIR__ . '/../deployment/php/php.ini') ?: __DIR__ . '/../deployment/php/php.ini', 'writable' => is_writable(__DIR__ . '/../deployment/php/php.ini')],
        ['path' => realpath(__DIR__ . '/../deployment/php/php-fpm.conf') ?: __DIR__ . '/../deployment/php/php-fpm.conf', 'writable' => is_writable(__DIR__ . '/../deployment/php/php-fpm.conf')],
        ['path' => realpath(__DIR__ . '/../deployment/mysql/my.cnf') ?: __DIR__ . '/../deployment/mysql/my.cnf', 'writable' => is_writable(__DIR__ . '/../deployment/mysql/my.cnf')],
    ];
    $requiredFolders = bx_required_folder_checks();
    $errorLogs = bx_recent_error_log_entries();
    $attachmentStorage = bx_attachment_storage_check();
    $runtimeAlerts = [];

    foreach ($requiredFolders as $folder) {
        if ($folder['status'] !== 'OK') {
            $runtimeAlerts[] = [
                'level' => 'error',
                'message' => $folder['label'] . ' permission check needs attention.',
            ];
        }
    }

    if ($attachmentStorage['status'] !== 'OK') {
        $runtimeAlerts[] = [
            'level' => 'error',
            'message' => 'Phase note attachment storage has unreadable or missing files.',
        ];
    }

    foreach (array_slice($errorLogs, 0, 5) as $entry) {
        $runtimeAlerts[] = [
            'level' => (string) ($entry['level'] ?? 'warning'),
            'message' => 'Recent log notification: ' . (string) ($entry['message'] ?? ''),
        ];
    }

    return [
        'generatedAt' => date('Y-m-d H:i:s'),
        'versions' => [
            ['name' => 'PHP', 'value' => PHP_VERSION],
            ['name' => 'PHP SAPI', 'value' => PHP_SAPI],
            ['name' => 'MySQL/MariaDB', 'value' => (string) bx_db()->GetOne('SELECT VERSION()')],
            ['name' => 'Web Server', 'value' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI/unknown')],
            ['name' => 'OS', 'value' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m')],
            ['name' => 'Composer', 'value' => bx_command_version('composer')],
            ['name' => 'Node', 'value' => bx_command_version('node')],
            ['name' => 'npm', 'value' => bx_command_version('npm')],
            ['name' => 'Git', 'value' => bx_command_version('git')],
            ['name' => 'MySQL Client', 'value' => bx_command_version('mysql')],
        ],
        'phpSettings' => $phpSettings,
        'mysqlSettings' => $mysqlVariables,
        'hardware' => [
            'cpu' => ['cores' => function_exists('shell_exec') ? trim((string) shell_exec('nproc 2>/dev/null')) : 'Unknown', 'load' => implode(', ', sys_getloadavg() ?: [])],
            'memory' => [
                'total' => bx_format_bytes($memory['total']),
                'available' => bx_format_bytes($memory['available']),
                'safe75' => $safeMemory > 0 ? bx_format_bytes($safeMemory) : 'Unknown',
                'swap' => bx_format_bytes(max(0, $memory['swap_total'] - $memory['swap_free'])) . ' used / ' . bx_format_bytes($memory['swap_total']),
            ],
            'disks' => bx_mount_usage(),
            'temperatures' => bx_temperatures(),
        ],
        'network' => ['internet' => $internet, 'latency' => $latencyMs, 'dns' => gethostbyname('example.com') !== 'example.com' ? 'OK' : 'Failed'],
        'configFiles' => $configFiles,
        'requiredFolders' => $requiredFolders,
        'attachmentStorage' => $attachmentStorage,
        'errorLogs' => $errorLogs,
        'runtimeAlerts' => $runtimeAlerts,
        'recommendations' => [
            'PHP upload/post should be 1G, memory limit should be at least 1G when hardware allows, and execution/input timeouts should be 300 seconds.',
            'Tune PHP-FPM pm.max_children from available RAM divided by average PHP worker memory.',
            'Tune MySQL innodb_buffer_pool_size up to roughly 75% of database-dedicated memory on a database-only server, lower when PHP and MySQL share the host.',
        ],
    ];
}

function bx_write_runtime_project_config(): void
{
    $root = dirname(__DIR__);
    $files = [
        $root . '/.user.ini' => "upload_max_filesize = 1G\npost_max_size = 1G\nmemory_limit = 1G\nmax_execution_time = 300\nmax_input_time = 300\nmax_input_vars = 10000\n",
        $root . '/.htaccess' => "<IfModule mod_php.c>\n    php_value upload_max_filesize 1G\n    php_value post_max_size 1G\n    php_value memory_limit 1G\n    php_value max_execution_time 300\n    php_value max_input_time 300\n    php_value max_input_vars 10000\n</IfModule>\n",
        $root . '/deployment/php/php.ini' => "; BuilderX production PHP baseline.\nfile_uploads = On\nupload_max_filesize = 1G\npost_max_size = 1G\nmax_file_uploads = 100\nmemory_limit = 1G\nmax_execution_time = 300\nmax_input_time = 300\nmax_input_vars = 10000\nopcache.enable = 1\nopcache.memory_consumption = 256\nopcache.interned_strings_buffer = 32\nopcache.max_accelerated_files = 30000\nopcache.validate_timestamps = 0\nopcache.save_comments = 1\n",
    ];

    foreach ($files as $path => $content) {
        if (file_exists($path) && !is_writable($path)) {
            throw new RuntimeException($path . ' is not writable.');
        }
        file_put_contents($path, $content);
    }
}

function bx_safe_layout_schema(array $schema): array
{
    $encoded = json_encode($schema, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return [];
    }

    $blockedPatterns = [
        '/<\s*script/i',
        '/javascript\s*:/i',
        '/on[a-z]+\s*=/i',
        '/expression\s*\(/i',
        '/behavior\s*:/i',
        '/@import/i',
        '/url\s*\(/i',
    ];

    foreach ($blockedPatterns as $pattern) {
        if (preg_match($pattern, $encoded)) {
            return [];
        }
    }

    return $schema;
}

function bx_admin_seed_settings(): void
{
    $softwareName = bx_setting('software_name', 'BuilderX');
    $settings = [
        ['software_name', 'BuilderX', 'general'],
        ['software_description', 'Dynamic Enterprise Form, Workflow, Reporting, and Accounting Builder', 'general'],
        ['version', '0.1.0-foundation', 'general'],
        ['default_language', 'en', 'localization'],
        ['default_time_zone', 'Asia/Manila', 'localization'],
        ['default_currency', 'PHP', 'localization'],
        ['session_timeout_minutes', '120', 'security'],
        ['password_min_length', '10', 'security'],
        ['password_expiration_days', '90', 'security'],
        ['password_history_count', '3', 'security'],
        ['password_reset_token_minutes', '30', 'security'],
        ['account_recovery_2fa_policy', 'optional-planned', 'security'],
        ['account_recovery_email_delivery', 'placeholder', 'security'],
        ['debug_enabled', '0', 'debug'],
        ['debug_show_queries', '0', 'debug'],
        ['debug_show_files', '1', 'debug'],
        ['debug_show_phase_task', '1', 'debug'],
        ['debug_log_traces', '0', 'debug'],
        ['debug_allowed_roles', 'administrator', 'debug'],
        ['debug_trace_retention_days', '7', 'debug'],
        ['app_url', 'http://localhost/builderX', 'application'],
        ['public_path', '/', 'application'],
        ['admin_path', '/administrator', 'application'],
        ['system_path', '/phases', 'application'],
        ['contact_name', '', 'contact'],
        ['contact_email', '', 'contact'],
        ['contact_phone', '', 'contact'],
        ['contact_address', '', 'contact'],
        ['admin_default_tab', 'dashboard', 'interface'],
        ['login_header_title', $softwareName, 'login'],
        ['login_header_subtitle', 'Administrator Portal', 'login'],
        ['login_badge_label', 'Administrator Portal', 'login'],
        ['login_title', $softwareName, 'login'],
        ['login_description', 'Manage users, roles, branches, projects, settings, audit logs, and runtime health from one operational workspace.', 'login'],
        ['login_feature_1_title', 'Protected Portal', 'login'],
        ['login_feature_1_description', 'Administrator role is required for access.', 'login'],
        ['login_feature_2_title', 'Session Tracking', 'login'],
        ['login_feature_2_description', 'Login history and active sessions are recorded.', 'login'],
        ['login_feature_3_title', 'Phase Manager', 'login'],
        ['login_feature_3_description', 'Open the project control surface when planning work.', 'login'],
        ['login_setup_feature_1_title', 'First Administrator', 'login'],
        ['login_setup_feature_1_description', 'Create the initial account for this project.', 'login'],
        ['login_setup_feature_2_title', 'No Shared Default', 'login'],
        ['login_setup_feature_2_description', 'Use a project-specific password before continuing.', 'login'],
        ['login_setup_feature_3_title', 'Phase Manager', 'login'],
        ['login_setup_feature_3_description', 'Review phase notes and build targets anytime.', 'login'],
        ['login_form_title', 'Administrator Login', 'login'],
        ['login_form_description', 'Administrator role is required to access this portal.', 'login'],
        ['login_username_label', 'Username or Email', 'login'],
        ['login_password_label', 'Password', 'login'],
        ['login_submit_label', 'Login', 'login'],
        ['login_setup_form_title', 'Create Initial Administrator', 'login'],
        ['login_setup_form_description', 'Define the first administrator before opening protected screens.', 'login'],
        ['login_setup_full_name_label', 'Full Name', 'login'],
        ['login_setup_email_label', 'Email', 'login'],
        ['login_setup_username_label', 'Username', 'login'],
        ['login_setup_password_label', 'Password', 'login'],
        ['login_setup_password_confirm_label', 'Confirm Password', 'login'],
        ['login_setup_submit_label', 'Create Administrator', 'login'],
        ['login_user_portal_label', 'User Portal', 'login'],
        ['login_phase_manager_label', 'Phase Manager', 'login'],
        ['template_presets', json_encode(bx_template_default_presets(), JSON_UNESCAPED_SLASHES), 'template'],
    ];

    foreach ($settings as $setting) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_system_setting WHERE setting_name = ?', [$setting[0]]) === 0) {
            bx_db()->Execute(
                'INSERT INTO builder_system_setting (setting_key, setting_name, setting_value, setting_group) VALUES (?, ?, ?, ?)',
                [bx_uuid(), $setting[0], $setting[1], $setting[2]]
            );
        }
    }
}

function bx_audit_filters_from_request(): array
{
    $filterKeys = [
        'audit_user',
        'audit_action',
        'audit_module',
        'audit_record',
        'audit_ip',
        'audit_reason',
        'audit_date_from',
        'audit_date_to',
    ];
    $filters = [];

    foreach ($filterKeys as $key) {
        $filters[$key] = trim((string) ($_GET[$key] ?? ''));
    }

    foreach (['audit_date_from', 'audit_date_to'] as $dateKey) {
        if ($filters[$dateKey] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateKey])) {
            $filters[$dateKey] = '';
        }
    }

    return $filters;
}

function bx_audit_rows(array $filters, int $limit = 250): array
{
    $where = ['1 = 1'];
    $params = [];

    $likeFilters = [
        'audit_user' => ["CONCAT(COALESCE(u.user_login, ''), ' ', COALESCE(u.user_name, ''), ' ', COALESCE(u.user_email, ''))"],
        'audit_action' => ['a.action'],
        'audit_module' => ['a.module'],
        'audit_record' => ['a.record_key'],
        'audit_ip' => ['a.ip_address'],
        'audit_reason' => ['a.reason'],
    ];

    foreach ($likeFilters as $filterKey => $columns) {
        if (($filters[$filterKey] ?? '') === '') {
            continue;
        }

        $or = [];
        foreach ($columns as $column) {
            $or[] = "{$column} LIKE ?";
            $params[] = '%' . $filters[$filterKey] . '%';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }

    if (($filters['audit_date_from'] ?? '') !== '') {
        $where[] = 'a.created_at >= ?';
        $params[] = $filters['audit_date_from'] . ' 00:00:00';
    }

    if (($filters['audit_date_to'] ?? '') !== '') {
        $where[] = 'a.created_at <= ?';
        $params[] = $filters['audit_date_to'] . ' 23:59:59';
    }

    $limit = max(1, min($limit, 1000));

    return bx_db()->GetAll("
        SELECT
            a.created_at,
            a.action,
            a.module,
            a.record_key,
            a.ip_address,
            a.user_agent,
            a.reason,
            a.branch_key,
            a.project_key,
            u.user_login,
            u.user_name,
            u.user_email
        FROM builder_audit_log a
        LEFT JOIN builder_user u ON u.user_key = a.user_key
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.x_id DESC
        LIMIT {$limit}
    ", $params);
}

function bx_audit_export_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="builderx-audit-log.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['created_at', 'user', 'email', 'action', 'module', 'record_key', 'branch_key', 'project_key', 'ip_address', 'user_agent', 'reason']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['created_at'] ?? '',
            $row['user_name'] ?: ($row['user_login'] ?? ''),
            $row['user_email'] ?? '',
            $row['action'] ?? '',
            $row['module'] ?? '',
            $row['record_key'] ?? '',
            $row['branch_key'] ?? '',
            $row['project_key'] ?? '',
            $row['ip_address'] ?? '',
            $row['user_agent'] ?? '',
            $row['reason'] ?? '',
        ]);
    }

    exit;
}

function bx_admin_table_exists(string $table): bool
{
    return (int) bx_db()->GetOne(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [BUILDERX_DB_NAME, $table]
    ) > 0;
}

function bx_family_report_filters_from_request(): array
{
    $filters = [];
    foreach (['family_report_search', 'family_report_status', 'family_report_relationship', 'family_report_date_from', 'family_report_date_to'] as $key) {
        $filters[$key] = trim((string) ($_GET[$key] ?? ''));
    }

    foreach (['family_report_date_from', 'family_report_date_to'] as $key) {
        if ($filters[$key] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
            $filters[$key] = '';
        }
    }

    return $filters;
}

function bx_family_report_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="family-member-report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['member_key', 'full_name', 'relationship', 'email', 'phone', 'vehicles', 'education_records', 'status', 'updated_at']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['member_key'] ?? '',
            $row['full_name'] ?? '',
            $row['relationship_to_user'] ?? '',
            $row['contact_email'] ?? '',
            $row['contact_phone'] ?? '',
            $row['vehicle_count'] ?? 0,
            $row['education_count'] ?? 0,
            $row['member_status'] ?? '',
            $row['member_updated_at'] ?? $row['member_created_at'] ?? '',
        ]);
    }
    exit;
}

function bx_family_report_data(?array $user, array $filters, bool $allowed): array
{
    $empty = [
        'allowed' => $allowed,
        'filters' => $filters,
        'summary' => ['members' => 0, 'vehicles' => 0, 'education' => 0],
        'rows' => [],
        'pagination' => ['page' => 1, 'page_size' => 25, 'total' => 0, 'pages' => 1],
    ];
    if (!$allowed || !$user || !bx_admin_table_exists('builder_family_member') || !bx_admin_table_exists('builder_family_member_vehicle') || !bx_admin_table_exists('builder_family_member_education')) {
        return $empty;
    }

    $where = ["m.member_status <> 'DELETED'"];
    $params = [];
    if (($filters['family_report_search'] ?? '') !== '') {
        $where[] = "CONCAT_WS(' ', m.member_key, m.first_name, m.middle_name, m.last_name, m.relationship_to_user, m.contact_email, m.contact_phone) LIKE ?";
        $params[] = '%' . $filters['family_report_search'] . '%';
    }
    if (($filters['family_report_status'] ?? '') !== '') {
        $where[] = 'm.member_status = ?';
        $params[] = $filters['family_report_status'];
    }
    if (($filters['family_report_relationship'] ?? '') !== '') {
        $where[] = 'm.relationship_to_user LIKE ?';
        $params[] = '%' . $filters['family_report_relationship'] . '%';
    }
    if (($filters['family_report_date_from'] ?? '') !== '') {
        $where[] = 'm.member_created_at >= ?';
        $params[] = $filters['family_report_date_from'] . ' 00:00:00';
    }
    if (($filters['family_report_date_to'] ?? '') !== '') {
        $where[] = 'm.member_created_at <= ?';
        $params[] = $filters['family_report_date_to'] . ' 23:59:59';
    }

    $whereSql = implode(' AND ', $where);
    $total = (int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_family_member m WHERE {$whereSql}", $params);
    $page = max(1, (int) ($_GET['family_report_page'] ?? 1));
    $pageSize = 25;
    $pages = max(1, (int) ceil($total / $pageSize));
    $page = min($page, $pages);
    $offset = ($page - 1) * $pageSize;
    $rows = bx_db()->GetAll(
        "SELECT
            m.member_key, m.first_name, m.middle_name, m.last_name, m.suffix, m.relationship_to_user,
            m.contact_email, m.contact_phone, m.member_status, m.member_created_at, m.member_updated_at,
            COUNT(DISTINCT CASE WHEN v.vehicle_status <> 'DELETED' THEN v.vehicle_key END) AS vehicle_count,
            COUNT(DISTINCT CASE WHEN e.education_status <> 'DELETED' THEN e.education_key END) AS education_count
        FROM builder_family_member m
        LEFT JOIN builder_family_member_vehicle v ON v.member_key = m.member_key AND v.owner_user_key = m.owner_user_key
        LEFT JOIN builder_family_member_education e ON e.member_key = m.member_key AND e.owner_user_key = m.owner_user_key
        WHERE {$whereSql}
        GROUP BY m.x_id, m.member_key, m.first_name, m.middle_name, m.last_name, m.suffix, m.relationship_to_user,
            m.contact_email, m.contact_phone, m.member_status, m.member_created_at, m.member_updated_at
        ORDER BY m.member_updated_at DESC, m.x_id DESC
        LIMIT {$pageSize} OFFSET {$offset}",
        $params
    ) ?: [];

    $safeRows = [];
    foreach ($rows as $row) {
        $row['full_name'] = trim(implode(' ', array_filter([
            (string) ($row['first_name'] ?? ''),
            (string) ($row['middle_name'] ?? ''),
            (string) ($row['last_name'] ?? ''),
            (string) ($row['suffix'] ?? ''),
        ])));
        $row['contact_email'] = bx_mask_email((string) ($row['contact_email'] ?? ''));
        $row['contact_phone'] = bx_mask_phone((string) ($row['contact_phone'] ?? ''));
        $safeRows[] = $row;
    }

    $summary = [
        'members' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_family_member m WHERE {$whereSql}", $params),
        'vehicles' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_family_member_vehicle v JOIN builder_family_member m ON m.member_key = v.member_key AND m.owner_user_key = v.owner_user_key WHERE v.vehicle_status <> 'DELETED' AND {$whereSql}", $params),
        'education' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_family_member_education e JOIN builder_family_member m ON m.member_key = e.member_key AND m.owner_user_key = e.owner_user_key WHERE e.education_status <> 'DELETED' AND {$whereSql}", $params),
    ];

    return [
        'allowed' => true,
        'filters' => $filters,
        'summary' => $summary,
        'rows' => $safeRows,
        'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'pages' => $pages],
    ];
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST') {
    bx_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_admin') {
        $adminInput = [
            'login' => trim((string) $_POST['login']),
            'email' => trim((string) $_POST['email']),
            'name' => trim((string) $_POST['name']),
            'password' => (string) $_POST['password'],
            'password_confirm' => (string) $_POST['password_confirm'],
        ];
        if (!bx_create_initial_admin($adminInput)) {
            bx_admin_redirect_with_state('overview', [
                'initialAdmin' => [
                    'name' => $adminInput['name'],
                    'email' => $adminInput['email'],
                    'login' => $adminInput['login'],
                ],
            ]);
        }
        header('Location: ./');
        exit;
    }

    if ($action === 'login') {
        if (bx_login(trim((string) $_POST['login']), (string) $_POST['password'])) {
            $user = bx_current_user();
            if (!$user || !bx_is_admin($user)) {
                bx_logout();
                bx_flash('Administrator role is required.', 'error');
            } else {
                bx_flash('Signed in to administrator portal.', 'success');
            }
        } else {
            bx_flash('Invalid administrator login or password.', 'error');
        }
        header('Location: ./');
        exit;
    }

    if ($action === 'logout') {
        bx_logout();
        bx_flash('Signed out.', 'success');
        header('Location: ./');
        exit;
    }

    if (in_array($action, ['save_branch', 'set_branch_status', 'save_project', 'set_project_status', 'save_user', 'set_user_status', 'reset_user_password', 'save_group', 'set_group_status', 'save_role', 'set_role_status', 'set_permission_status', 'save_permission_matrix', 'save_form', 'set_form_status', 'clone_form', 'publish_form', 'unpublish_form', 'import_form_json', 'export_form_json', 'save_form_field', 'set_form_field_status', 'move_form_field', 'save_form_layout', 'set_form_layout_status', 'save_system_settings', 'apply_runtime_project_config', 'save_template_preset', 'run_template_command'], true)) {
        $currentUser = bx_current_user();
        if (!$currentUser || !bx_is_admin($currentUser)) {
            bx_flash('Administrator role is required.', 'error');
            header('Location: ./');
            exit;
        }

        if ($action === 'save_branch') {
            $branchKey = trim((string) ($_POST['branch_key'] ?? ''));
            $branchCode = strtoupper(trim((string) ($_POST['branch_code'] ?? '')));
            $branchName = trim((string) ($_POST['branch_name'] ?? ''));
            $branchAddress = trim((string) ($_POST['branch_address'] ?? ''));
            $branchContact = trim((string) ($_POST['branch_contact'] ?? ''));
            $branchStatus = trim((string) ($_POST['branch_status'] ?? 'ACTIVE'));
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($branchCode === '' || $branchName === '') {
                bx_flash('Branch code and branch name are required.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $branchCode)) {
                bx_flash('Branch code must use 2-40 uppercase letters, numbers, underscores, or hyphens.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            if (!in_array($branchStatus, $allowedStatuses, true)) {
                bx_flash('Invalid branch status.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_branch WHERE branch_code = ? AND branch_key <> ?',
                [$branchCode, $branchKey ?: '__new__']
            );

            if ($duplicate > 0) {
                bx_flash('Branch code already exists.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            if ($branchKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_branch WHERE branch_key = ?', [$branchKey]);
                if (!$existing) {
                    bx_flash('Branch was not found.', 'error');
                    header('Location: ./?tab=branches');
                    exit;
                }

                bx_db()->Execute(
                    'UPDATE builder_branch SET branch_code = ?, branch_name = ?, branch_status = ?, branch_address = ?, branch_contact = ? WHERE branch_key = ?',
                    [$branchCode, $branchName, $branchStatus, $branchAddress, $branchContact, $branchKey]
                );
                bx_audit('UPDATE', 'builder_branch', $branchKey, [
                    'branch_code' => $branchCode,
                    'branch_name' => $branchName,
                    'branch_status' => $branchStatus,
                ], 'Administrator updated branch.');
                bx_flash('Branch updated.', 'success');
            } else {
                $branchKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_branch (branch_key, branch_code, branch_name, branch_status, branch_address, branch_contact) VALUES (?, ?, ?, ?, ?, ?)',
                    [$branchKey, $branchCode, $branchName, $branchStatus, $branchAddress, $branchContact]
                );
                bx_audit('CREATE', 'builder_branch', $branchKey, [
                    'branch_code' => $branchCode,
                    'branch_name' => $branchName,
                    'branch_status' => $branchStatus,
                ], 'Administrator created branch.');
                bx_flash('Branch created.', 'success');
            }

            header('Location: ./?tab=branches');
            exit;
        }

        if ($action === 'set_branch_status') {
            $branchKey = trim((string) ($_POST['branch_key'] ?? ''));
            $branchStatus = trim((string) ($_POST['branch_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($branchKey === '' || !in_array($branchStatus, $allowedStatuses, true)) {
                bx_flash('Invalid branch status request.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_branch WHERE branch_key = ?', [$branchKey]);
            if (!$existing) {
                bx_flash('Branch was not found.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            bx_db()->Execute('UPDATE builder_branch SET branch_status = ? WHERE branch_key = ?', [$branchStatus, $branchKey]);
            bx_audit($branchStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_branch', $branchKey, [
                'branch_code' => $existing['branch_code'],
                'branch_status' => $branchStatus,
            ], 'Administrator changed branch status.');
            bx_flash('Branch status updated.', 'success');
            header('Location: ./?tab=branches');
            exit;
        }

        if ($action === 'save_project') {
            $projectKey = trim((string) ($_POST['project_key'] ?? ''));
            $branchKey = trim((string) ($_POST['branch_key'] ?? ''));
            $projectCode = strtoupper(trim((string) ($_POST['project_code'] ?? '')));
            $projectName = trim((string) ($_POST['project_name'] ?? ''));
            $projectDescription = trim((string) ($_POST['project_description'] ?? ''));
            $projectStatus = trim((string) ($_POST['project_status'] ?? 'ACTIVE'));
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($branchKey === '' || $projectCode === '' || $projectName === '') {
                bx_flash('Branch, project code, and project name are required.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $projectCode)) {
                bx_flash('Project code must use 2-40 uppercase letters, numbers, underscores, or hyphens.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            if (!in_array($projectStatus, $allowedStatuses, true)) {
                bx_flash('Invalid project status.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            $branchExists = (int) bx_db()->GetOne(
                "SELECT COUNT(*) FROM builder_branch WHERE branch_key = ? AND branch_status <> 'DELETED'",
                [$branchKey]
            );
            if ($branchExists === 0) {
                bx_flash('Selected branch was not found or is deleted.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_project WHERE project_code = ? AND project_key <> ?',
                [$projectCode, $projectKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Project code already exists.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            if ($projectKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_project WHERE project_key = ?', [$projectKey]);
                if (!$existing) {
                    bx_flash('Project was not found.', 'error');
                    header('Location: ./?tab=projects');
                    exit;
                }

                bx_db()->Execute(
                    'UPDATE builder_project SET branch_key = ?, project_code = ?, project_name = ?, project_status = ?, project_description = ? WHERE project_key = ?',
                    [$branchKey, $projectCode, $projectName, $projectStatus, $projectDescription, $projectKey]
                );
                bx_audit('UPDATE', 'builder_project', $projectKey, [
                    'project_code' => $projectCode,
                    'project_name' => $projectName,
                    'project_status' => $projectStatus,
                    'branch_key' => $branchKey,
                ], 'Administrator updated project.');
                bx_flash('Project updated.', 'success');
            } else {
                $projectKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_project (project_key, branch_key, project_name, project_code, project_status, project_description) VALUES (?, ?, ?, ?, ?, ?)',
                    [$projectKey, $branchKey, $projectName, $projectCode, $projectStatus, $projectDescription]
                );
                bx_audit('CREATE', 'builder_project', $projectKey, [
                    'project_code' => $projectCode,
                    'project_name' => $projectName,
                    'project_status' => $projectStatus,
                    'branch_key' => $branchKey,
                ], 'Administrator created project.');
                bx_flash('Project created.', 'success');
            }

            header('Location: ./?tab=projects');
            exit;
        }

        if ($action === 'set_project_status') {
            $projectKey = trim((string) ($_POST['project_key'] ?? ''));
            $projectStatus = trim((string) ($_POST['project_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($projectKey === '' || !in_array($projectStatus, $allowedStatuses, true)) {
                bx_flash('Invalid project status request.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_project WHERE project_key = ?', [$projectKey]);
            if (!$existing) {
                bx_flash('Project was not found.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            bx_db()->Execute('UPDATE builder_project SET project_status = ? WHERE project_key = ?', [$projectStatus, $projectKey]);
            bx_audit($projectStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_project', $projectKey, [
                'project_code' => $existing['project_code'],
                'project_status' => $projectStatus,
            ], 'Administrator changed project status.');
            bx_flash('Project status updated.', 'success');
            header('Location: ./?tab=projects');
            exit;
        }

        if ($action === 'save_form') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $branchKey = trim((string) ($_POST['branch_key'] ?? ''));
            $projectKey = trim((string) ($_POST['project_key'] ?? ''));
            $formCode = strtoupper(trim((string) ($_POST['form_code'] ?? '')));
            $formName = trim((string) ($_POST['form_name'] ?? ''));
            $formDescription = trim((string) ($_POST['form_description'] ?? ''));
            $formStatus = trim((string) ($_POST['form_status'] ?? 'DRAFT'));
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($branchKey === '' || $projectKey === '' || $formCode === '' || $formName === '') {
                bx_flash('Branch, project, form code, and form name are required.', 'error');
                bx_admin_redirect('forms');
            }

            if (!preg_match('/^[A-Z0-9_-]{2,80}$/', $formCode)) {
                bx_flash('Form code must use 2-80 uppercase letters, numbers, underscores, or hyphens.', 'error');
                bx_admin_redirect('forms');
            }

            if (!in_array($formStatus, $allowedStatuses, true)) {
                bx_flash('Invalid form status.', 'error');
                bx_admin_redirect('forms');
            }

            $project = bx_db()->GetRow(
                "SELECT project_key FROM builder_project WHERE project_key = ? AND branch_key = ? AND project_status <> 'DELETED'",
                [$projectKey, $branchKey]
            );
            if (!$project) {
                bx_flash('Selected project was not found under the selected branch.', 'error');
                bx_admin_redirect('forms');
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_form WHERE form_code = ? AND form_key <> ?',
                [$formCode, $formKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Form code already exists.', 'error');
                bx_admin_redirect('forms');
            }

            if ($formKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
                if (!$existing) {
                    bx_flash('Form was not found.', 'error');
                    bx_admin_redirect('forms');
                }

                bx_db()->Execute(
                    'UPDATE builder_form SET branch_key = ?, project_key = ?, form_code = ?, form_name = ?, form_description = ?, form_status = ?, form_updated_by_key = ? WHERE form_key = ?',
                    [$branchKey, $projectKey, $formCode, $formName, $formDescription, $formStatus, $currentUser['user_key'], $formKey]
                );
                bx_audit('UPDATE', 'builder_form', $formKey, [
                    'form_code' => $formCode,
                    'form_status' => $formStatus,
                    'branch_key' => $branchKey,
                    'project_key' => $projectKey,
                ], 'Administrator updated form.');
                bx_flash('Form updated.', 'success');
            } else {
                $formKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_form (form_key, branch_key, project_key, form_code, form_name, form_description, form_status, form_created_by_key, form_updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$formKey, $branchKey, $projectKey, $formCode, $formName, $formDescription, $formStatus, $currentUser['user_key'], $currentUser['user_key']]
                );
                bx_audit('CREATE', 'builder_form', $formKey, [
                    'form_code' => $formCode,
                    'form_status' => $formStatus,
                    'branch_key' => $branchKey,
                    'project_key' => $projectKey,
                ], 'Administrator created form.');
                bx_flash('Form created.', 'success');
            }

            bx_admin_redirect('forms');
        }

        if ($action === 'set_form_status') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $formStatus = trim((string) ($_POST['form_status'] ?? ''));
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];
            if ($formKey === '' || !in_array($formStatus, $allowedStatuses, true)) {
                bx_flash('Invalid form status request.', 'error');
                bx_admin_redirect('forms');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
            if (!$existing) {
                bx_flash('Form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            $deletedSql = $formStatus === 'DELETED' ? ', form_deleted_at = CURRENT_TIMESTAMP, form_deleted_by_key = ?' : '';
            $params = $formStatus === 'DELETED'
                ? [$formStatus, $currentUser['user_key'], $currentUser['user_key'], $formKey]
                : [$formStatus, $currentUser['user_key'], $formKey];
            bx_db()->Execute(
                "UPDATE builder_form SET form_status = ?{$deletedSql}, form_updated_by_key = ? WHERE form_key = ?",
                $params
            );
            bx_audit($formStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_form', $formKey, [
                'form_code' => $existing['form_code'],
                'form_status' => $formStatus,
            ], 'Administrator changed form status.');
            bx_flash('Form status updated.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'clone_form') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $existing = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
            if (!$existing) {
                bx_flash('Form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            $cloneKey = bx_uuid();
            $baseCode = substr((string) $existing['form_code'], 0, 68);
            $cloneCode = $baseCode . '_COPY';
            $suffix = 2;
            while ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_form WHERE form_code = ?', [$cloneCode]) > 0) {
                $cloneCode = $baseCode . '_COPY_' . $suffix;
                $suffix++;
            }

            bx_db()->Execute(
                'INSERT INTO builder_form (form_key, branch_key, project_key, form_code, form_name, form_description, form_status, form_settings, form_created_by_key, form_updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$cloneKey, $existing['branch_key'], $existing['project_key'], $cloneCode, $existing['form_name'] . ' Copy', $existing['form_description'], 'DRAFT', $existing['form_settings'], $currentUser['user_key'], $currentUser['user_key']]
            );
            bx_audit('CLONE', 'builder_form', $cloneKey, [
                'source_form_key' => $formKey,
                'form_code' => $cloneCode,
            ], 'Administrator cloned form.');
            bx_flash('Form cloned as draft.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'publish_form' || $action === 'unpublish_form') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $existing = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
            if (!$existing) {
                bx_flash('Form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            if ($action === 'publish_form') {
                $versionNumber = ((int) bx_db()->GetOne('SELECT COALESCE(MAX(version_number), 0) FROM builder_form_version WHERE form_key = ?', [$formKey])) + 1;
                bx_db()->Execute(
                    'INSERT INTO builder_form_version (version_key, form_key, version_number, version_status, schema_snapshot, published_at, created_by_key) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)',
                    [bx_uuid(), $formKey, $versionNumber, 'PUBLISHED', json_encode($existing, JSON_UNESCAPED_SLASHES), $currentUser['user_key']]
                );
                bx_db()->Execute('UPDATE builder_form SET form_status = ?, form_schema_version = ?, form_updated_by_key = ? WHERE form_key = ?', ['ACTIVE', $versionNumber, $currentUser['user_key'], $formKey]);
                bx_audit('PUBLISH', 'builder_form', $formKey, ['version_number' => $versionNumber], 'Administrator published form.');
                bx_flash('Form published.', 'success');
            } else {
                bx_db()->Execute('UPDATE builder_form SET form_status = ?, form_updated_by_key = ? WHERE form_key = ?', ['INACTIVE', $currentUser['user_key'], $formKey]);
                bx_audit('UNPUBLISH', 'builder_form', $formKey, ['form_code' => $existing['form_code']], 'Administrator unpublished form.');
                bx_flash('Form unpublished.', 'success');
            }
            bx_admin_redirect('forms');
        }

        if ($action === 'import_form_json') {
            $rawJson = trim((string) ($_POST['form_json'] ?? ''));
            $import = json_decode($rawJson, true);
            if (!is_array($import)) {
                bx_flash('Import requires valid JSON.', 'error');
                bx_admin_redirect('forms');
            }
            $form = is_array($import['form'] ?? null) ? $import['form'] : $import;

            $branchKey = trim((string) ($form['branch_key'] ?? ''));
            $projectKey = trim((string) ($form['project_key'] ?? ''));
            $formCode = strtoupper(trim((string) ($form['form_code'] ?? '')));
            $formName = trim((string) ($form['form_name'] ?? ''));
            $formDescription = trim((string) ($form['form_description'] ?? ''));

            if ($branchKey === '' || $projectKey === '' || $formCode === '' || $formName === '') {
                bx_flash('Imported JSON must include branch_key, project_key, form_code, and form_name.', 'error');
                bx_admin_redirect('forms');
            }

            if (!preg_match('/^[A-Z0-9_-]{2,80}$/', $formCode)) {
                bx_flash('Imported form code is invalid.', 'error');
                bx_admin_redirect('forms');
            }

            $project = bx_db()->GetRow(
                "SELECT project_key FROM builder_project WHERE project_key = ? AND branch_key = ? AND project_status <> 'DELETED'",
                [$projectKey, $branchKey]
            );
            if (!$project) {
                bx_flash('Imported project was not found under the imported branch.', 'error');
                bx_admin_redirect('forms');
            }

            $baseCode = substr($formCode, 0, 68);
            $importCode = $baseCode;
            $suffix = 2;
            while ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_form WHERE form_code = ?', [$importCode]) > 0) {
                $importCode = $baseCode . '_IMPORT_' . $suffix;
                $suffix++;
            }

            $formKey = bx_uuid();
            bx_db()->Execute(
                'INSERT INTO builder_form (form_key, branch_key, project_key, form_code, form_name, form_description, form_status, form_settings, form_created_by_key, form_updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$formKey, $branchKey, $projectKey, $importCode, $formName, $formDescription, 'DRAFT', json_encode($form, JSON_UNESCAPED_SLASHES), $currentUser['user_key'], $currentUser['user_key']]
            );
            bx_audit('IMPORT', 'builder_form', $formKey, ['form_code' => $importCode], 'Administrator imported form JSON.');
            bx_flash('Form imported as draft.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'export_form_json') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $form = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
            if (!$form) {
                bx_flash('Form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $form['form_code']) . '.json"');
            echo json_encode(['form' => $form], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'save_form_field') {
            $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $fieldCode = strtolower(trim((string) ($_POST['field_code'] ?? '')));
            $fieldName = trim((string) ($_POST['field_name'] ?? ''));
            $fieldLabel = trim((string) ($_POST['field_label'] ?? ''));
            $fieldType = trim((string) ($_POST['field_type'] ?? 'text'));
            $dataType = trim((string) ($_POST['data_type'] ?? 'string'));
            $databaseColumnName = strtolower(trim((string) ($_POST['database_column_name'] ?? $fieldCode)));
            $fieldSortOrder = max(0, (int) ($_POST['field_sort_order'] ?? 0));
            $fieldStatus = trim((string) ($_POST['field_status'] ?? 'ACTIVE'));
            $defaultValue = trim((string) ($_POST['default_value'] ?? ''));
            $formulaExpression = trim((string) ($_POST['formula_expression'] ?? ''));
            $validationRaw = trim((string) ($_POST['validation_rules'] ?? ''));
            $optionRaw = trim((string) ($_POST['option_source'] ?? ''));
            $visibilityRule = trim((string) ($_POST['visibility_rule'] ?? ''));
            $editableRule = trim((string) ($_POST['editable_rule'] ?? ''));
            $rolePermission = trim((string) ($_POST['role_permission'] ?? ''));
            $gridWidth = max(60, min(600, (int) ($_POST['grid_width'] ?? 160)));
            $allowedFieldTypes = ['text', 'textarea', 'number', 'currency', 'date', 'datetime', 'select', 'checkbox', 'file', 'signature', 'lookup', 'formula', 'child_table', 'section'];
            $allowedDataTypes = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'json'];
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'DELETED'];

            if ($formKey === '' || $fieldCode === '' || $fieldName === '' || $fieldLabel === '' || $databaseColumnName === '') {
                bx_flash('Form, field code, name, label, and database column are required.', 'error');
                bx_admin_redirect('forms');
            }

            if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/', $fieldCode) || !preg_match('/^[a-z][a-z0-9_]{1,99}$/', $databaseColumnName)) {
                bx_flash('Field code and database column must use lowercase snake_case.', 'error');
                bx_admin_redirect('forms');
            }

            if (!in_array($fieldType, $allowedFieldTypes, true) || !in_array($dataType, $allowedDataTypes, true) || !in_array($fieldStatus, $allowedStatuses, true)) {
                bx_flash('Invalid field type, data type, or status.', 'error');
                bx_admin_redirect('forms');
            }

            $form = bx_db()->GetRow("SELECT form_key FROM builder_form WHERE form_key = ? AND form_status <> 'DELETED'", [$formKey]);
            if (!$form) {
                bx_flash('Selected form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            foreach (['validation_rules' => $validationRaw, 'option_source' => $optionRaw] as $jsonLabel => $jsonValue) {
                if ($jsonValue !== '' && json_decode($jsonValue, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                    bx_flash(str_replace('_', ' ', ucfirst($jsonLabel)) . ' must be valid JSON.', 'error');
                    bx_admin_redirect('forms');
                }
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_form_field WHERE form_key = ? AND (field_code = ? OR database_column_name = ?) AND field_key <> ?',
                [$formKey, $fieldCode, $databaseColumnName, $fieldKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Field code or database column already exists for this form.', 'error');
                bx_admin_redirect('forms');
            }

            $fieldSettings = [
                'visibility_rule' => $visibilityRule,
                'editable_rule' => $editableRule,
                'role_permission' => $rolePermission,
                'grid_width' => $gridWidth,
            ];
            $validationJson = $validationRaw === '' ? null : $validationRaw;
            $optionJson = $optionRaw === '' ? null : $optionRaw;
            $settingsJson = json_encode($fieldSettings, JSON_UNESCAPED_SLASHES);
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            $isUnique = isset($_POST['is_unique']) ? 1 : 0;
            $isSearchable = isset($_POST['is_searchable']) ? 1 : 0;
            $isSortable = isset($_POST['is_sortable']) ? 1 : 0;

            if ($fieldKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_form_field WHERE field_key = ?', [$fieldKey]);
                if (!$existing) {
                    bx_flash('Field was not found.', 'error');
                    bx_admin_redirect('forms');
                }

                bx_db()->Execute(
                    'UPDATE builder_form_field SET form_key = ?, field_code = ?, field_name = ?, field_label = ?, field_type = ?, data_type = ?, database_column_name = ?, field_sort_order = ?, field_status = ?, is_required = ?, is_unique = ?, is_searchable = ?, is_sortable = ?, default_value = ?, validation_rules = ?, option_source = ?, formula_expression = ?, field_settings = ? WHERE field_key = ?',
                    [$formKey, $fieldCode, $fieldName, $fieldLabel, $fieldType, $dataType, $databaseColumnName, $fieldSortOrder, $fieldStatus, $isRequired, $isUnique, $isSearchable, $isSortable, $defaultValue, $validationJson, $optionJson, $formulaExpression, $settingsJson, $fieldKey]
                );
                bx_audit('UPDATE', 'builder_form_field', $fieldKey, ['field_code' => $fieldCode, 'form_key' => $formKey], 'Administrator updated form field.');
                bx_flash('Form field updated.', 'success');
            } else {
                $fieldKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_form_field (field_key, form_key, field_code, field_name, field_label, field_type, data_type, database_column_name, field_sort_order, field_status, is_required, is_unique, is_searchable, is_sortable, default_value, validation_rules, option_source, formula_expression, field_settings) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$fieldKey, $formKey, $fieldCode, $fieldName, $fieldLabel, $fieldType, $dataType, $databaseColumnName, $fieldSortOrder, $fieldStatus, $isRequired, $isUnique, $isSearchable, $isSortable, $defaultValue, $validationJson, $optionJson, $formulaExpression, $settingsJson]
                );
                bx_audit('CREATE', 'builder_form_field', $fieldKey, ['field_code' => $fieldCode, 'form_key' => $formKey], 'Administrator created form field.');
                bx_flash('Form field created.', 'success');
            }

            bx_admin_redirect('forms');
        }

        if ($action === 'set_form_field_status') {
            $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
            $fieldStatus = trim((string) ($_POST['field_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];
            if ($fieldKey === '' || !in_array($fieldStatus, $allowedStatuses, true)) {
                bx_flash('Invalid field status request.', 'error');
                bx_admin_redirect('forms');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_form_field WHERE field_key = ?', [$fieldKey]);
            if (!$existing) {
                bx_flash('Field was not found.', 'error');
                bx_admin_redirect('forms');
            }

            bx_db()->Execute('UPDATE builder_form_field SET field_status = ? WHERE field_key = ?', [$fieldStatus, $fieldKey]);
            bx_audit($fieldStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_form_field', $fieldKey, ['field_code' => $existing['field_code'], 'field_status' => $fieldStatus], 'Administrator changed form field status.');
            bx_flash('Form field status updated.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'move_form_field') {
            $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
            $direction = trim((string) ($_POST['direction'] ?? ''));
            $existing = bx_db()->GetRow('SELECT * FROM builder_form_field WHERE field_key = ?', [$fieldKey]);
            if (!$existing || !in_array($direction, ['up', 'down'], true)) {
                bx_flash('Invalid field reorder request.', 'error');
                bx_admin_redirect('forms');
            }

            $operator = $direction === 'up' ? '<' : '>';
            $order = $direction === 'up' ? 'DESC' : 'ASC';
            $neighbor = bx_db()->GetRow(
                "SELECT * FROM builder_form_field WHERE form_key = ? AND field_status <> 'DELETED' AND field_sort_order {$operator} ? ORDER BY field_sort_order {$order}, x_id {$order} LIMIT 1",
                [$existing['form_key'], $existing['field_sort_order']]
            );
            if ($neighbor) {
                bx_db()->Execute('UPDATE builder_form_field SET field_sort_order = ? WHERE field_key = ?', [$neighbor['field_sort_order'], $fieldKey]);
                bx_db()->Execute('UPDATE builder_form_field SET field_sort_order = ? WHERE field_key = ?', [$existing['field_sort_order'], $neighbor['field_key']]);
                bx_audit('REORDER', 'builder_form_field', $fieldKey, ['direction' => $direction], 'Administrator reordered form field.');
            }
            bx_flash('Form field order updated.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'save_form_layout') {
            $layoutKey = trim((string) ($_POST['layout_key'] ?? ''));
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $versionKey = trim((string) ($_POST['version_key'] ?? ''));
            $layoutName = trim((string) ($_POST['layout_name'] ?? ''));
            $layoutType = trim((string) ($_POST['layout_type'] ?? 'FORM'));
            $layoutStatus = trim((string) ($_POST['layout_status'] ?? 'DRAFT'));
            $layoutSortOrder = max(0, (int) ($_POST['layout_sort_order'] ?? 0));
            $schemaRaw = trim((string) ($_POST['layout_schema'] ?? ''));
            $customCss = '';
            $allowedTypes = ['FORM', 'TABLE', 'DETAIL', 'PRINT', 'MOBILE'];
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'DELETED'];

            if ($formKey === '' || $layoutName === '') {
                bx_flash('Form and layout name are required.', 'error');
                bx_admin_redirect('forms');
            }

            if (!in_array($layoutType, $allowedTypes, true) || !in_array($layoutStatus, $allowedStatuses, true)) {
                bx_flash('Invalid layout type or status.', 'error');
                bx_admin_redirect('forms');
            }

            $form = bx_db()->GetRow("SELECT form_key FROM builder_form WHERE form_key = ? AND form_status <> 'DELETED'", [$formKey]);
            if (!$form) {
                bx_flash('Selected form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            if ($versionKey !== '' && (int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_form_version WHERE version_key = ? AND form_key = ?', [$versionKey, $formKey]) === 0) {
                bx_flash('Selected version does not belong to the form.', 'error');
                bx_admin_redirect('forms');
            }

            $decodedSchema = $schemaRaw === '' ? [] : json_decode($schemaRaw, true);
            if (!is_array($decodedSchema)) {
                bx_flash('Layout schema must be valid JSON.', 'error');
                bx_admin_redirect('forms');
            }

            $schema = [
                'mode' => trim((string) ($_POST['layout_mode'] ?? 'create_edit')),
                'responsive' => [
                    'desktop_columns' => max(1, min(6, (int) ($_POST['desktop_columns'] ?? 2))),
                    'tablet_columns' => max(1, min(4, (int) ($_POST['tablet_columns'] ?? 2))),
                    'mobile_columns' => max(1, min(2, (int) ($_POST['mobile_columns'] ?? 1))),
                ],
                'components' => bx_post_array('layout_components'),
                'field_order' => bx_post_array('layout_field_order'),
                'custom_css' => '',
                'schema' => $decodedSchema,
                'restrictions' => [
                    'javascript' => 'blocked',
                    'remote_imports' => 'blocked',
                    'inline_events' => 'blocked',
                ],
            ];
            $schema = bx_safe_layout_schema($schema);
            if (empty($schema)) {
                bx_flash('Layout schema or custom CSS contains blocked unsafe content.', 'error');
                bx_admin_redirect('forms');
            }

            $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES);
            $duplicateLayout = bx_db()->GetRow(
                'SELECT layout_key FROM builder_form_layout WHERE form_key = ? AND layout_name = ? AND layout_type = ? AND layout_key <> ? ORDER BY layout_status = ? DESC, updated_at DESC, x_id DESC LIMIT 1',
                [$formKey, $layoutName, $layoutType, $layoutKey ?: '__new__', 'ACTIVE']
            );
            if ($duplicateLayout && $layoutKey === '') {
                $layoutKey = (string) $duplicateLayout['layout_key'];
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_form_layout WHERE form_key = ? AND layout_name = ? AND layout_type = ? AND layout_key <> ?',
                [$formKey, $layoutName, $layoutType, $layoutKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Layout name already exists for this form and type.', 'error');
                bx_admin_redirect('forms');
            }

            if ($layoutKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_form_layout WHERE layout_key = ?', [$layoutKey]);
                if (!$existing) {
                    bx_flash('Layout was not found.', 'error');
                    bx_admin_redirect('forms');
                }

                bx_db()->Execute(
                    'UPDATE builder_form_layout SET form_key = ?, version_key = ?, layout_name = ?, layout_type = ?, layout_status = ?, layout_schema = ?, layout_sort_order = ? WHERE layout_key = ?',
                    [$formKey, $versionKey === '' ? null : $versionKey, $layoutName, $layoutType, $layoutStatus, $schemaJson, $layoutSortOrder, $layoutKey]
                );
                bx_audit('UPDATE', 'builder_form_layout', $layoutKey, ['form_key' => $formKey, 'layout_name' => $layoutName, 'layout_type' => $layoutType], 'Administrator updated form layout.');
                bx_flash('Form layout updated.', 'success');
            } else {
                $layoutKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_form_layout (layout_key, form_key, version_key, layout_name, layout_type, layout_status, layout_schema, layout_sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$layoutKey, $formKey, $versionKey === '' ? null : $versionKey, $layoutName, $layoutType, $layoutStatus, $schemaJson, $layoutSortOrder]
                );
                bx_audit('CREATE', 'builder_form_layout', $layoutKey, ['form_key' => $formKey, 'layout_name' => $layoutName, 'layout_type' => $layoutType], 'Administrator created form layout.');
                bx_flash('Form layout created.', 'success');
            }

            bx_admin_redirect_with_state('forms', [
                'formsSubTab' => 'layouts',
                'designerFormKey' => $formKey,
                'editingLayoutKey' => $layoutKey,
            ]);
        }

        if ($action === 'set_form_layout_status') {
            $layoutKey = trim((string) ($_POST['layout_key'] ?? ''));
            $layoutStatus = trim((string) ($_POST['layout_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];
            if ($layoutKey === '' || !in_array($layoutStatus, $allowedStatuses, true)) {
                bx_flash('Invalid layout status request.', 'error');
                bx_admin_redirect('forms');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_form_layout WHERE layout_key = ?', [$layoutKey]);
            if (!$existing) {
                bx_flash('Layout was not found.', 'error');
                bx_admin_redirect('forms');
            }

            bx_db()->Execute('UPDATE builder_form_layout SET layout_status = ? WHERE layout_key = ?', [$layoutStatus, $layoutKey]);
            bx_audit($layoutStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_form_layout', $layoutKey, ['layout_name' => $existing['layout_name'], 'layout_status' => $layoutStatus], 'Administrator changed form layout status.');
            bx_flash('Form layout status updated.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'save_user') {
            $userKey = trim((string) ($_POST['user_key'] ?? ''));
            $userLogin = trim((string) ($_POST['user_login'] ?? ''));
            $userName = trim((string) ($_POST['user_name'] ?? ''));
            $userEmail = trim((string) ($_POST['user_email'] ?? ''));
            $userStatus = trim((string) ($_POST['user_status'] ?? 'ACTIVE'));
            $password = (string) ($_POST['password'] ?? '');
            $roleKeys = bx_post_array('role_keys');
            $groupKeys = bx_post_array('group_keys');
            $branchKeys = bx_post_array('branch_keys');
            $projectKeys = bx_post_array('project_keys');
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'LOCKED', 'DELETED'];

            if ($userLogin === '' || $userName === '' || $userEmail === '') {
                bx_flash('Username, full name, and email are required.', 'error');
                bx_admin_redirect('users');
            }

            if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $userLogin)) {
                bx_flash('Username must use 3-80 letters, numbers, dots, underscores, or hyphens.', 'error');
                bx_admin_redirect('users');
            }

            if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                bx_flash('Valid email is required.', 'error');
                bx_admin_redirect('users');
            }

            if (!in_array($userStatus, $allowedStatuses, true)) {
                bx_flash('Invalid user status.', 'error');
                bx_admin_redirect('users');
            }

            if ($userKey === '' && strlen($password) < 10) {
                bx_flash('New users require a password with at least 10 characters.', 'error');
                bx_admin_redirect('users');
            }

            if ($password !== '' && strlen($password) < 10) {
                bx_flash('Password must use at least 10 characters.', 'error');
                bx_admin_redirect('users');
            }

            if (!bx_validate_existing_keys('builder_role', 'role_key', $roleKeys, 'role_status')
                || !bx_validate_existing_keys('builder_group', 'group_key', $groupKeys, 'group_status')
                || !bx_validate_existing_keys('builder_branch', 'branch_key', $branchKeys, 'branch_status')
                || !bx_validate_existing_keys('builder_project', 'project_key', $projectKeys, 'project_status')) {
                bx_flash('One or more selected assignments are invalid or deleted.', 'error');
                bx_admin_redirect('users');
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_user WHERE (user_login = ? OR user_email = ?) AND user_key <> ?',
                [$userLogin, $userEmail, $userKey ?: '__new__']
            );

            if ($duplicate > 0) {
                bx_flash('Username or email already exists.', 'error');
                bx_admin_redirect('users');
            }

            if ($userKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_user WHERE user_key = ?', [$userKey]);
                if (!$existing) {
                    bx_flash('User was not found.', 'error');
                    bx_admin_redirect('users');
                }

                if ($password !== '') {
                    bx_db()->Execute(
                        'UPDATE builder_user SET user_login = ?, user_name = ?, user_email = ?, user_status = ?, user_password_hash = ?, user_password_changed_at = NULL, user_updated_by_key = ? WHERE user_key = ?',
                        [$userLogin, $userName, $userEmail, $userStatus, bx_password_hash($password), $currentUser['user_key'], $userKey]
                    );
                } else {
                    bx_db()->Execute(
                        'UPDATE builder_user SET user_login = ?, user_name = ?, user_email = ?, user_status = ?, user_updated_by_key = ? WHERE user_key = ?',
                        [$userLogin, $userName, $userEmail, $userStatus, $currentUser['user_key'], $userKey]
                    );
                }

                bx_audit('UPDATE', 'builder_user', $userKey, [
                    'user_login' => $userLogin,
                    'user_email' => $userEmail,
                    'user_status' => $userStatus,
                ], 'Administrator updated user.');
                bx_flash('User updated.', 'success');
            } else {
                $userKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_user (user_key, user_login, user_password_hash, user_name, user_email, user_status, user_created_by_key, user_updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$userKey, $userLogin, bx_password_hash($password), $userName, $userEmail, $userStatus, $currentUser['user_key'], $currentUser['user_key']]
                );
                bx_audit('CREATE', 'builder_user', $userKey, [
                    'user_login' => $userLogin,
                    'user_email' => $userEmail,
                    'user_status' => $userStatus,
                ], 'Administrator created user.');
                bx_flash('User created.', 'success');
            }

            bx_replace_user_links('builder_user_role', $userKey, 'role_key', $roleKeys);
            bx_replace_user_links('builder_user_group', $userKey, 'group_key', $groupKeys);
            bx_replace_user_links('builder_user_branch', $userKey, 'branch_key', $branchKeys);
            bx_replace_user_links('builder_user_project', $userKey, 'project_key', $projectKeys);

            bx_admin_redirect('users');
        }

        if ($action === 'set_user_status') {
            $targetUserKey = trim((string) ($_POST['user_key'] ?? ''));
            $userStatus = trim((string) ($_POST['user_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'LOCKED', 'DELETED'];

            if ($targetUserKey === '' || !in_array($userStatus, $allowedStatuses, true)) {
                bx_flash('Invalid user status request.', 'error');
                bx_admin_redirect('users');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_user WHERE user_key = ?', [$targetUserKey]);
            if (!$existing) {
                bx_flash('User was not found.', 'error');
                bx_admin_redirect('users');
            }

            if ($userStatus === 'DELETED') {
                bx_db()->Execute(
                    'UPDATE builder_user SET user_status = ?, user_deleted_at = CURRENT_TIMESTAMP, user_deleted_by_key = ?, user_updated_by_key = ? WHERE user_key = ?',
                    [$userStatus, $currentUser['user_key'], $currentUser['user_key'], $targetUserKey]
                );
            } else {
                bx_db()->Execute(
                    'UPDATE builder_user SET user_status = ?, user_failed_login_count = 0, user_updated_by_key = ? WHERE user_key = ?',
                    [$userStatus, $currentUser['user_key'], $targetUserKey]
                );
            }

            bx_audit($userStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_user', $targetUserKey, [
                'user_login' => $existing['user_login'],
                'user_status' => $userStatus,
            ], 'Administrator changed user status.');
            bx_flash('User status updated.', 'success');
            bx_admin_redirect('users');
        }

        if ($action === 'reset_user_password') {
            $targetUserKey = trim((string) ($_POST['user_key'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($targetUserKey === '' || strlen($password) < 10) {
                bx_flash('Password reset requires a user and a password with at least 10 characters.', 'error');
                bx_admin_redirect('users');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_user WHERE user_key = ?', [$targetUserKey]);
            if (!$existing) {
                bx_flash('User was not found.', 'error');
                bx_admin_redirect('users');
            }

            bx_db()->Execute(
                'UPDATE builder_user SET user_password_hash = ?, user_password_changed_at = NULL, user_failed_login_count = 0, user_updated_by_key = ? WHERE user_key = ?',
                [bx_password_hash($password), $currentUser['user_key'], $targetUserKey]
            );
            bx_audit('PASSWORD_RESET', 'builder_user', $targetUserKey, [
                'user_login' => $existing['user_login'],
            ], 'Administrator reset user password.');
            bx_flash('User password reset. Require the user to change it at next sign-in.', 'success');
            bx_admin_redirect('users');
        }

        if ($action === 'save_group') {
            $groupKey = trim((string) ($_POST['group_key'] ?? ''));
            $groupName = trim((string) ($_POST['group_name'] ?? ''));
            $groupDescription = trim((string) ($_POST['group_description'] ?? ''));
            $groupStatus = trim((string) ($_POST['group_status'] ?? 'ACTIVE'));
            $memberUserKeys = bx_post_array('member_user_keys');
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($groupName === '') {
                bx_flash('Group name is required.', 'error');
                bx_admin_redirect('groups');
            }

            if (strlen($groupName) > 120) {
                bx_flash('Group name must be 120 characters or less.', 'error');
                bx_admin_redirect('groups');
            }

            if (!in_array($groupStatus, $allowedStatuses, true)) {
                bx_flash('Invalid group status.', 'error');
                bx_admin_redirect('groups');
            }

            if (!bx_validate_existing_keys('builder_user', 'user_key', $memberUserKeys, 'user_status')) {
                bx_flash('One or more selected users are invalid or deleted.', 'error');
                bx_admin_redirect('groups');
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_group WHERE group_name = ? AND group_key <> ?',
                [$groupName, $groupKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Group name already exists.', 'error');
                bx_admin_redirect('groups');
            }

            if ($groupKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_group WHERE group_key = ?', [$groupKey]);
                if (!$existing) {
                    bx_flash('Group was not found.', 'error');
                    bx_admin_redirect('groups');
                }

                bx_db()->Execute(
                    'UPDATE builder_group SET group_name = ?, group_description = ?, group_status = ? WHERE group_key = ?',
                    [$groupName, $groupDescription, $groupStatus, $groupKey]
                );
                bx_audit('UPDATE', 'builder_group', $groupKey, [
                    'group_name' => $groupName,
                    'group_status' => $groupStatus,
                    'member_count' => count(array_unique($memberUserKeys)),
                ], 'Administrator updated group.');
                bx_flash('Group updated.', 'success');
            } else {
                $groupKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_group (group_key, group_name, group_description, group_status) VALUES (?, ?, ?, ?)',
                    [$groupKey, $groupName, $groupDescription, $groupStatus]
                );
                bx_audit('CREATE', 'builder_group', $groupKey, [
                    'group_name' => $groupName,
                    'group_status' => $groupStatus,
                    'member_count' => count(array_unique($memberUserKeys)),
                ], 'Administrator created group.');
                bx_flash('Group created.', 'success');
            }

            bx_db()->Execute('DELETE FROM builder_user_group WHERE group_key = ?', [$groupKey]);
            foreach (array_unique($memberUserKeys) as $memberUserKey) {
                if ($memberUserKey !== '') {
                    bx_db()->Execute(
                        'INSERT IGNORE INTO builder_user_group (user_key, group_key) VALUES (?, ?)',
                        [$memberUserKey, $groupKey]
                    );
                }
            }

            bx_admin_redirect('groups');
        }

        if ($action === 'set_group_status') {
            $groupKey = trim((string) ($_POST['group_key'] ?? ''));
            $groupStatus = trim((string) ($_POST['group_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($groupKey === '' || !in_array($groupStatus, $allowedStatuses, true)) {
                bx_flash('Invalid group status request.', 'error');
                bx_admin_redirect('groups');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_group WHERE group_key = ?', [$groupKey]);
            if (!$existing) {
                bx_flash('Group was not found.', 'error');
                bx_admin_redirect('groups');
            }

            bx_db()->Execute('UPDATE builder_group SET group_status = ? WHERE group_key = ?', [$groupStatus, $groupKey]);
            bx_audit($groupStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_group', $groupKey, [
                'group_name' => $existing['group_name'],
                'group_status' => $groupStatus,
            ], 'Administrator changed group status.');
            bx_flash('Group status updated.', 'success');
            bx_admin_redirect('groups');
        }

        if ($action === 'save_role') {
            $roleKey = trim((string) ($_POST['role_key'] ?? ''));
            $roleName = trim((string) ($_POST['role_name'] ?? ''));
            $roleDescription = trim((string) ($_POST['role_description'] ?? ''));
            $roleStatus = trim((string) ($_POST['role_status'] ?? 'ACTIVE'));
            $permissionKeys = bx_post_array('permission_keys');
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($roleName === '') {
                bx_flash('Role name is required.', 'error');
                bx_admin_redirect('roles');
            }

            if (strlen($roleName) > 120) {
                bx_flash('Role name must be 120 characters or less.', 'error');
                bx_admin_redirect('roles');
            }

            if (!in_array($roleStatus, $allowedStatuses, true)) {
                bx_flash('Invalid role status.', 'error');
                bx_admin_redirect('roles');
            }

            if (!bx_validate_existing_keys('builder_permission', 'permission_key', $permissionKeys, 'permission_status')) {
                bx_flash('One or more selected permissions are invalid or deleted.', 'error');
                bx_admin_redirect('roles');
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_role WHERE role_name = ? AND role_key <> ?',
                [$roleName, $roleKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Role name already exists.', 'error');
                bx_admin_redirect('roles');
            }

            if ($roleKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_role WHERE role_key = ?', [$roleKey]);
                if (!$existing) {
                    bx_flash('Role was not found.', 'error');
                    bx_admin_redirect('roles');
                }

                bx_db()->Execute(
                    'UPDATE builder_role SET role_name = ?, role_description = ?, role_status = ? WHERE role_key = ?',
                    [$roleName, $roleDescription, $roleStatus, $roleKey]
                );
                bx_audit('UPDATE', 'builder_role', $roleKey, [
                    'role_name' => $roleName,
                    'role_status' => $roleStatus,
                    'permission_count' => count(array_unique($permissionKeys)),
                ], 'Administrator updated role.');
                bx_flash('Role updated.', 'success');
            } else {
                $roleKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_role (role_key, role_name, role_description, role_status) VALUES (?, ?, ?, ?)',
                    [$roleKey, $roleName, $roleDescription, $roleStatus]
                );
                bx_audit('CREATE', 'builder_role', $roleKey, [
                    'role_name' => $roleName,
                    'role_status' => $roleStatus,
                    'permission_count' => count(array_unique($permissionKeys)),
                ], 'Administrator created role.');
                bx_flash('Role created.', 'success');
            }

            bx_db()->Execute('DELETE FROM builder_role_permission WHERE role_key = ?', [$roleKey]);
            foreach (array_unique($permissionKeys) as $permissionKey) {
                if ($permissionKey !== '') {
                    bx_db()->Execute(
                        'INSERT IGNORE INTO builder_role_permission (role_key, permission_key) VALUES (?, ?)',
                        [$roleKey, $permissionKey]
                    );
                }
            }

            bx_admin_redirect('roles');
        }

        if ($action === 'set_role_status') {
            $roleKey = trim((string) ($_POST['role_key'] ?? ''));
            $roleStatus = trim((string) ($_POST['role_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($roleKey === '' || !in_array($roleStatus, $allowedStatuses, true)) {
                bx_flash('Invalid role status request.', 'error');
                bx_admin_redirect('roles');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_role WHERE role_key = ?', [$roleKey]);
            if (!$existing) {
                bx_flash('Role was not found.', 'error');
                bx_admin_redirect('roles');
            }

            bx_db()->Execute('UPDATE builder_role SET role_status = ? WHERE role_key = ?', [$roleStatus, $roleKey]);
            bx_audit($roleStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_role', $roleKey, [
                'role_name' => $existing['role_name'],
                'role_status' => $roleStatus,
            ], 'Administrator changed role status.');
            bx_flash('Role status updated.', 'success');
            bx_admin_redirect('roles');
        }

        if ($action === 'set_permission_status') {
            $permissionKey = trim((string) ($_POST['permission_key'] ?? ''));
            $permissionStatus = trim((string) ($_POST['permission_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE'];

            if ($permissionKey === '' || !in_array($permissionStatus, $allowedStatuses, true)) {
                bx_flash('Invalid permission status request.', 'error');
                bx_admin_redirect('permissions');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_permission WHERE permission_key = ?', [$permissionKey]);
            if (!$existing) {
                bx_flash('Permission was not found.', 'error');
                bx_admin_redirect('permissions');
            }

            bx_db()->Execute('UPDATE builder_permission SET permission_status = ? WHERE permission_key = ?', [$permissionStatus, $permissionKey]);
            bx_audit('STATUS', 'builder_permission', $permissionKey, [
                'permission_code' => $existing['permission_code'],
                'permission_status' => $permissionStatus,
            ], 'Administrator changed permission status.');
            bx_flash('Permission status updated.', 'success');
            bx_admin_redirect('permissions');
        }

        if ($action === 'save_permission_matrix') {
            $roleKeys = bx_post_array('matrix_role_keys');
            $permissionKeys = bx_post_array('matrix_permission_keys');
            $matrix = $_POST['role_permissions'] ?? [];

            if (!is_array($matrix)) {
                $matrix = [];
            }

            if (!bx_validate_existing_keys('builder_role', 'role_key', $roleKeys, 'role_status')
                || !bx_validate_existing_keys('builder_permission', 'permission_key', $permissionKeys, 'permission_status')) {
                bx_flash('One or more matrix roles or permissions are invalid or deleted.', 'error');
                bx_admin_redirect('permissions');
            }

            foreach (array_unique($roleKeys) as $roleKey) {
                if ($roleKey === '') {
                    continue;
                }

                bx_db()->Execute('DELETE FROM builder_role_permission WHERE role_key = ?', [$roleKey]);
                $selectedPermissions = $matrix[$roleKey] ?? [];
                if (!is_array($selectedPermissions)) {
                    $selectedPermissions = [];
                }

                foreach (array_unique(array_map(static fn ($item): string => trim((string) $item), $selectedPermissions)) as $permissionKey) {
                    if ($permissionKey !== '' && in_array($permissionKey, $permissionKeys, true)) {
                        bx_db()->Execute(
                            'INSERT IGNORE INTO builder_role_permission (role_key, permission_key) VALUES (?, ?)',
                            [$roleKey, $permissionKey]
                        );
                    }
                }
            }

            bx_audit('UPDATE', 'builder_role_permission', 'permission-matrix', [
                'role_count' => count(array_unique($roleKeys)),
                'permission_count' => count(array_unique($permissionKeys)),
            ], 'Administrator updated permission matrix.');
            bx_flash('Permission matrix updated.', 'success');
            bx_admin_redirect('permissions');
        }

        if ($action === 'save_system_settings') {
            $settingValues = $_POST['setting_values'] ?? [];
            if (!is_array($settingValues)) {
                bx_flash('Invalid settings request.', 'error');
                bx_admin_redirect('settings');
            }

            $settings = bx_db()->GetAll("SELECT setting_key, setting_name, setting_value, is_secret FROM builder_system_setting WHERE setting_status = 'ACTIVE'");
            $changed = 0;

            foreach ($settings as $setting) {
                $settingKey = (string) $setting['setting_key'];
                if (!array_key_exists($settingKey, $settingValues)) {
                    continue;
                }

                $settingName = (string) $setting['setting_name'];
                if (str_starts_with($settingName, 'ui_')) {
                    continue;
                }

                if ((int) ($setting['is_secret'] ?? 0) === 1) {
                    continue;
                }

                if (!preg_match('/^[A-Za-z0-9_]{2,120}$/', $settingName)) {
                    bx_flash('Invalid setting name found.', 'error');
                    bx_admin_redirect('settings');
                }

                $newValue = trim((string) $settingValues[$settingKey]);
                if (strlen($newValue) > 2000) {
                    bx_flash('Setting values must be 2000 characters or less.', 'error');
                    bx_admin_redirect('settings');
                }

                if (in_array($settingName, ['session_timeout_minutes', 'password_min_length'], true)) {
                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 1) {
                        bx_flash('Security numeric settings must be positive whole numbers.', 'error');
                        bx_admin_redirect('settings');
                    }
                }

                if (in_array($settingName, ['password_expiration_days', 'password_history_count', 'password_reset_token_minutes'], true)) {
                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 0) {
                        bx_flash('Numeric settings must be zero or positive whole numbers.', 'error');
                        bx_admin_redirect('settings');
                    }
                }

                if (in_array($settingName, ['debug_enabled', 'debug_show_queries', 'debug_show_files', 'debug_show_phase_task', 'debug_log_traces'], true) && !in_array($newValue, ['0', '1'], true)) {
                    bx_flash('Debug switches must be on or off.', 'error');
                    bx_admin_redirect('settings');
                }

                if ($settingName === 'debug_trace_retention_days') {
                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 0 || (int) $newValue > 365) {
                        bx_flash('Debug trace retention must be between 0 and 365 days.', 'error');
                        bx_admin_redirect('settings');
                    }
                }

                if ($settingName === 'debug_allowed_roles' && !preg_match('/^[A-Za-z0-9_, -]+$/', $newValue)) {
                    bx_flash('Debug allowed roles may only contain letters, numbers, spaces, commas, hyphens, and underscores.', 'error');
                    bx_admin_redirect('settings');
                }

                if ($settingName === 'contact_email' && $newValue !== '' && !filter_var($newValue, FILTER_VALIDATE_EMAIL)) {
                    bx_flash('Contact email must be valid.', 'error');
                    bx_admin_redirect('settings');
                }

                if ($settingName === 'admin_default_tab' && !in_array($newValue, ['dashboard', 'users', 'groups', 'roles', 'permissions', 'branches', 'projects', 'settings', 'audit', 'forms', 'health', 'template'], true)) {
                    bx_flash('Administrator default tab is invalid.', 'error');
                    bx_admin_redirect('settings');
                }

                if ($newValue !== (string) $setting['setting_value']) {
                    bx_db()->Execute(
                        'UPDATE builder_system_setting SET setting_value = ? WHERE setting_key = ?',
                        [$newValue, $settingKey]
                    );
                    bx_audit('UPDATE', 'builder_system_setting', $settingKey, [
                        'setting_name' => $settingName,
                    ], 'Administrator updated system setting.');
                    $changed++;
                }
            }

            bx_flash($changed > 0 ? 'System settings updated.' : 'No setting changes detected.', 'success');
            bx_admin_redirect('settings');
        }

        if ($action === 'apply_runtime_project_config') {
            try {
                bx_write_runtime_project_config();
                bx_audit('UPDATE', 'runtime_health', 'project-config', [
                    'upload_max_filesize' => '1G',
                    'post_max_size' => '1G',
                    'memory_limit' => '1G',
                    'max_execution_time' => '300',
                ], 'Administrator applied BuilderX project-level runtime config baseline.');
                bx_flash('Project-level runtime config files were updated. Restart/reload PHP or the web server if your host requires it.', 'success');
            } catch (Throwable $error) {
                bx_flash('Runtime config update failed: ' . $error->getMessage(), 'error');
            }
            bx_admin_redirect('health');
        }

        if ($action === 'run_template_command') {
            $presetArg = trim((string) ($_POST['preset_arg'] ?? '--preset b0'));
            $template = trim((string) ($_POST['template'] ?? 'next'));
            $label = trim((string) ($_POST['label'] ?? ''));
            $confirmation = strtoupper(trim((string) ($_POST['confirmation'] ?? '')));

            if ($confirmation !== 'RUN') {
                bx_admin_json_response([
                    'ok' => false,
                    'message' => 'Type RUN before applying the template command.',
                ], 422);
            }

            try {
                $preset = bx_template_store_preset($label, $presetArg, $template);
                $result = bx_admin_run_template_command($preset['preset_arg'], $preset['template']);
                bx_audit('RUN', 'template_command', 'shadcn-init', [
                    'command' => $result['command'],
                    'root_path' => $result['root_path'],
                    'exit_code' => (string) $result['exit_code'],
                    'duration_seconds' => (string) $result['duration_seconds'],
                ], 'Administrator ran shadcn template command.');

                bx_admin_json_response([
                    'ok' => ((int) $result['exit_code']) === 0,
                    'message' => ((int) $result['exit_code']) === 0 ? 'Template command completed.' : 'Template command exited with an error.',
                    'result' => $result,
                    'refreshAdministrator' => (bool) ($result['refresh_administrator'] ?? false),
                    'templatePresets' => bx_template_presets(),
                ], ((int) $result['exit_code']) === 0 ? 200 : 500);
            } catch (Throwable $error) {
                bx_audit('ERROR', 'template_command', 'shadcn-init', [
                    'preset_arg' => $presetArg,
                    'template' => $template,
                    'error' => $error->getMessage(),
                ], 'Administrator template command failed before execution.');

                bx_admin_json_response([
                    'ok' => false,
                    'message' => $error->getMessage(),
                ], 500);
            }
        }

        if ($action === 'save_template_preset') {
            $presetArg = trim((string) ($_POST['preset_arg'] ?? '--preset b0'));
            $template = trim((string) ($_POST['template'] ?? 'next'));
            $label = trim((string) ($_POST['label'] ?? ''));

            try {
                $preset = bx_template_store_preset($label, $presetArg, $template);
                bx_audit('UPDATE', 'template_command', 'template-presets', [
                    'preset_arg' => $preset['preset_arg'],
                    'template' => $preset['template'],
                ], 'Administrator saved shadcn template preset.');

                bx_admin_json_response([
                    'ok' => true,
                    'message' => 'Template preset saved.',
                    'preset' => $preset,
                    'templatePresets' => bx_template_presets(),
                ]);
            } catch (Throwable $error) {
                bx_admin_json_response([
                    'ok' => false,
                    'message' => $error->getMessage(),
                ], 422);
            }
        }
    }
}

bx_admin_seed_settings();
$flash = bx_take_flash();
$user = bx_current_user();
$hasUsers = bx_count('builder_user') > 0;
$isAdmin = $user ? bx_is_admin($user) : false;
$softwareName = bx_setting('software_name', 'BuilderX');
$manifestPath = dirname(__DIR__) . '/frontend/dist/.vite/manifest.json';
$manifest = file_exists($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
$entry = $manifest['index.html'] ?? null;
$assetsBase = '../frontend/dist/';
$auditFilters = bx_audit_filters_from_request();
$audits = $isAdmin ? bx_audit_rows($auditFilters, (string) ($_GET['audit_export'] ?? '') === 'csv' ? 1000 : 250) : [];
$familyReportFilters = bx_family_report_filters_from_request();
$familyReportAllowed = $isAdmin && (bx_user_has_permission($user, 'family_members.report') || bx_user_has_permission($user, 'reports.manage'));
$familyReport = bx_family_report_data($user, $familyReportFilters, $familyReportAllowed);

if ($isAdmin && (string) ($_GET['audit_export'] ?? '') === 'csv') {
    bx_audit_export_csv($audits);
}

if ($familyReportAllowed && (string) ($_GET['family_report_export'] ?? '') === 'csv') {
    bx_family_report_csv($familyReport['rows']);
}

$adminState = $_SESSION['builderx_admin_state'] ?? [];
unset($_SESSION['builderx_admin_state']);
if (!is_array($adminState)) {
    $adminState = [];
}

function bx_admin_payload_rows(mixed $rows): array
{
    return is_array($rows) ? $rows : [];
}

$settingsForPayload = bx_admin_payload_rows(bx_db()->GetAll("SELECT setting_key, setting_group, setting_name, setting_value, setting_status, is_secret FROM builder_system_setting WHERE setting_status = 'ACTIVE' AND setting_name NOT LIKE 'ui\\_%' AND setting_name <> 'template_presets' ORDER BY setting_group ASC, setting_name ASC"));
foreach ($settingsForPayload as &$settingForPayload) {
    $settingName = (string) ($settingForPayload['setting_name'] ?? '');
    if ((int) ($settingForPayload['is_secret'] ?? 0) === 1) {
        $settingForPayload['setting_value'] = '';
        $settingForPayload['is_secret'] = '1';
    }
}
unset($settingForPayload);

$payload = [
    'csrf' => bx_csrf_token(),
    'softwareName' => $softwareName,
    'projectBasePath' => bx_project_base_path(),
    'projectRoot' => dirname(__DIR__),
    'templatePresets' => bx_template_presets(),
    'flash' => $flash,
    'hasUsers' => $hasUsers,
    'isSignedIn' => (bool) $user,
    'isAdmin' => $isAdmin,
    'initialTab' => (string) ($_GET['tab'] ?? 'overview'),
    'initialState' => $adminState,
    'user' => $user ? [
        'key' => $user['user_key'],
        'name' => $user['user_name'],
        'login' => $user['user_login'],
        'email' => $user['user_email'],
    ] : null,
    'metrics' => [
        'Users' => bx_count('builder_user', "user_status <> 'DELETED'"),
        'Branches' => bx_count('builder_branch', "branch_status <> 'DELETED'"),
        'Projects' => bx_count('builder_project', "project_status <> 'DELETED'"),
        'Forms' => bx_count('builder_form', "form_status <> 'DELETED'"),
        'Roles' => bx_count('builder_role', "role_status <> 'DELETED'"),
        'Permissions' => bx_count('builder_permission', "permission_status <> 'DELETED'"),
        'Audit Logs' => bx_count('builder_audit_log'),
    ],
    'users' => bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            u.user_key,
            u.user_login,
            u.user_name,
            u.user_email,
            u.user_status,
            u.user_failed_login_count,
            u.user_last_login_at,
            COALESCE((SELECT GROUP_CONCAT(role_key ORDER BY role_key SEPARATOR ',') FROM builder_user_role WHERE user_key = u.user_key), '') AS role_keys,
            COALESCE((SELECT GROUP_CONCAT(group_key ORDER BY group_key SEPARATOR ',') FROM builder_user_group WHERE user_key = u.user_key), '') AS group_keys,
            COALESCE((SELECT GROUP_CONCAT(branch_key ORDER BY branch_key SEPARATOR ',') FROM builder_user_branch WHERE user_key = u.user_key), '') AS branch_keys,
            COALESCE((SELECT GROUP_CONCAT(project_key ORDER BY project_key SEPARATOR ',') FROM builder_user_project WHERE user_key = u.user_key), '') AS project_keys,
            COALESCE((SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ', ') FROM builder_user_role ur JOIN builder_role r ON r.role_key = ur.role_key WHERE ur.user_key = u.user_key), '') AS role_names,
            COALESCE((SELECT GROUP_CONCAT(g.group_name ORDER BY g.group_name SEPARATOR ', ') FROM builder_user_group ug JOIN builder_group g ON g.group_key = ug.group_key WHERE ug.user_key = u.user_key), '') AS group_names,
            COALESCE((SELECT GROUP_CONCAT(b.branch_code ORDER BY b.branch_code SEPARATOR ', ') FROM builder_user_branch ub JOIN builder_branch b ON b.branch_key = ub.branch_key WHERE ub.user_key = u.user_key), '') AS branch_codes,
            COALESCE((SELECT GROUP_CONCAT(p.project_code ORDER BY p.project_code SEPARATOR ', ') FROM builder_user_project up JOIN builder_project p ON p.project_key = up.project_key WHERE up.user_key = u.user_key), '') AS project_codes
        FROM builder_user u
        ORDER BY u.user_name ASC
    ")),
    'branches' => bx_admin_payload_rows(bx_db()->GetAll('SELECT branch_key, branch_code, branch_name, branch_status, branch_address, branch_contact FROM builder_branch ORDER BY branch_name ASC')),
    'projects' => bx_admin_payload_rows(bx_db()->GetAll('SELECT p.project_key, p.branch_key, p.project_code, p.project_name, p.project_status, p.project_description, b.branch_code, b.branch_name FROM builder_project p LEFT JOIN builder_branch b ON b.branch_key = p.branch_key ORDER BY b.branch_name ASC, p.project_name ASC')),
    'forms' => bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            f.form_key,
            f.branch_key,
            f.project_key,
            f.form_code,
            f.form_name,
            f.form_description,
            f.form_table_name,
            f.form_schema_version,
            f.form_status,
            f.form_created_at,
            f.form_updated_at,
            b.branch_code,
            b.branch_name,
            p.project_code,
            p.project_name,
            (SELECT COUNT(*) FROM builder_form_version v WHERE v.form_key = f.form_key) AS version_count,
            (SELECT MAX(version_number) FROM builder_form_version v WHERE v.form_key = f.form_key) AS latest_version
        FROM builder_form f
        LEFT JOIN builder_branch b ON b.branch_key = f.branch_key
        LEFT JOIN builder_project p ON p.project_key = f.project_key
        ORDER BY b.branch_name ASC, p.project_name ASC, f.form_name ASC
    ")),
    'formVersions' => bx_admin_payload_rows(bx_db()->GetAll("
        SELECT version_key, form_key, version_number, version_status, published_at, created_at
        FROM builder_form_version
        ORDER BY form_key ASC, version_number DESC
    ")),
    'formFields' => bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            field_key,
            form_key,
            field_code,
            field_name,
            field_label,
            field_type,
            data_type,
            database_column_name,
            field_sort_order,
            field_status,
            is_required,
            is_unique,
            is_searchable,
            is_sortable,
            default_value,
            validation_rules,
            option_source,
            formula_expression,
            field_settings,
            JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.visibility_rule')) AS visibility_rule,
            JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.editable_rule')) AS editable_rule,
            JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.role_permission')) AS role_permission,
            JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.grid_width')) AS grid_width
        FROM builder_form_field
        ORDER BY form_key ASC, field_sort_order ASC, x_id ASC
    ")),
    'formLayouts' => bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            l.layout_key,
            l.form_key,
            l.version_key,
            l.layout_name,
            l.layout_type,
            l.layout_status,
            l.layout_schema,
            l.layout_sort_order,
            l.created_at,
            l.updated_at,
            f.form_code,
            f.form_name,
            v.version_number
        FROM builder_form_layout l
        LEFT JOIN builder_form f ON f.form_key = l.form_key
        LEFT JOIN builder_form_version v ON v.version_key = l.version_key
        ORDER BY f.form_code ASC, l.layout_sort_order ASC, l.x_id ASC
    ")),
    'groups' => bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            g.group_key,
            g.group_name,
            g.group_description,
            g.group_status,
            COALESCE((SELECT GROUP_CONCAT(user_key ORDER BY user_key SEPARATOR ',') FROM builder_user_group WHERE group_key = g.group_key), '') AS member_user_keys,
            COALESCE((SELECT GROUP_CONCAT(u.user_name ORDER BY u.user_name SEPARATOR ', ') FROM builder_user_group ug JOIN builder_user u ON u.user_key = ug.user_key WHERE ug.group_key = g.group_key), '') AS member_names,
            (SELECT COUNT(*) FROM builder_user_group ug WHERE ug.group_key = g.group_key) AS member_count
        FROM builder_group g
        ORDER BY g.group_name ASC
    ")),
    'roles' => bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            r.role_key,
            r.role_name,
            r.role_description,
            r.role_status,
            COALESCE((SELECT GROUP_CONCAT(permission_key ORDER BY permission_key SEPARATOR ',') FROM builder_role_permission WHERE role_key = r.role_key), '') AS permission_keys,
            COALESCE((SELECT GROUP_CONCAT(p.permission_code ORDER BY p.permission_scope, p.permission_code SEPARATOR ', ') FROM builder_role_permission rp JOIN builder_permission p ON p.permission_key = rp.permission_key WHERE rp.role_key = r.role_key), '') AS permission_codes,
            COALESCE((SELECT GROUP_CONCAT(DISTINCT p.permission_scope ORDER BY p.permission_scope SEPARATOR ', ') FROM builder_role_permission rp JOIN builder_permission p ON p.permission_key = rp.permission_key WHERE rp.role_key = r.role_key), '') AS permission_scopes,
            (SELECT COUNT(*) FROM builder_role_permission rp WHERE rp.role_key = r.role_key) AS permission_count
        FROM builder_role r
        ORDER BY r.role_name ASC
    ")),
    'permissions' => bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            p.permission_key,
            p.permission_code,
            p.permission_name,
            p.permission_scope,
            p.permission_status,
            COALESCE((SELECT GROUP_CONCAT(role_key ORDER BY role_key SEPARATOR ',') FROM builder_role_permission WHERE permission_key = p.permission_key), '') AS role_keys,
            COALESCE((SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ', ') FROM builder_role_permission rp JOIN builder_role r ON r.role_key = rp.role_key WHERE rp.permission_key = p.permission_key), '') AS role_names
        FROM builder_permission p
        ORDER BY p.permission_scope ASC, p.permission_code ASC
    ")),
    'settings' => $settingsForPayload,
    'auditFilters' => $auditFilters,
    'audits' => $audits,
    'loginHistory' => bx_admin_payload_rows(bx_db()->GetAll('SELECT login_key, user_key, user_login, login_status, ip_address, failure_reason, created_at FROM builder_user_login_history ORDER BY x_id DESC LIMIT 80')),
    'familyReport' => $familyReport,
    'runtimeHealth' => $isAdmin ? bx_runtime_health_snapshot() : null,
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bx_h($softwareName) ?> Administrator</title>
    <?php if ($entry && !empty($entry['css'])): ?>
        <?php foreach ($entry['css'] as $css): ?>
            <link rel="stylesheet" href="<?= bx_h($assetsBase . $css) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <script>
        window.__BUILDERX_ADMIN__ = <?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
</head>
<body>
    <div id="root">
        <?php if (!$entry): ?>
            <main style="max-width: 760px; margin: 40px auto; font-family: Arial, Helvetica, sans-serif;">
                <h1><?= bx_h($softwareName) ?> Administrator</h1>
                <p>The shared React frontend is not built yet. Run <code>npm run build</code> in <code>frontend</code>.</p>
            </main>
        <?php endif; ?>
    </div>
    <?php if ($entry): ?>
        <script type="module" src="<?= bx_h($assetsBase . $entry['file']) ?>"></script>
    <?php endif; ?>
</body>
</html>
