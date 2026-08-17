<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../adodb/adodb.inc.php';
require_once __DIR__ . '/../app/template-assets.php';

builderxDefineDatabaseConstants();
if (!builderxIsConfigured()) {
    builderxRenderMissingConfigPage();
}

const MIN_PHP_VERSION = '8.2.0';
const REQUIRED_UPLOAD_BYTES = 1073741824;
const REQUIRED_MEMORY_BYTES = 1073741824;
const REQUIRED_EXECUTION_SECONDS = 300;

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statusClass(string $status): string
{
    return strtolower(str_replace(' ', '-', $status));
}

function checkItem(string $group, string $label, string $status, string $details): array
{
    return [
        'group' => $group,
        'label' => $label,
        'status' => $status,
        'details' => $details,
    ];
}

function checkWritablePath(string $group, string $label, string $path): array
{
    if (!is_dir($path)) {
        return checkItem($group, $label, 'Missing', $path . ' does not exist yet.');
    }

    if (!is_writable($path)) {
        return checkItem($group, $label, 'Failed', $path . ' exists but is not writable.');
    }

    return checkItem($group, $label, 'Passed', $path . ' exists and is writable.');
}

function iniBytes(string $key): int
{
    $raw = trim((string) ini_get($key));
    if ($raw === '' || $raw === '-1') {
        return $raw === '-1' ? PHP_INT_MAX : 0;
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

function formatBytes(int $bytes): string
{
    if ($bytes === PHP_INT_MAX) {
        return 'unlimited';
    }

    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / 1024 / 1024 / 1024, 1) . ' GB';
    }

    return number_format($bytes / 1024 / 1024, 1) . ' MB';
}

function runtimeLimitCheck(string $setting, int $requiredBytes, string $reason): array
{
    $actualBytes = iniBytes($setting);
    $passed = $actualBytes >= $requiredBytes;

    return checkItem(
        'Runtime',
        'PHP setting: ' . $setting . ' >= ' . formatBytes($requiredBytes),
        $passed ? 'Passed' : 'Warning',
        'Current value: ' . (string) ini_get($setting) . ' (' . formatBytes($actualBytes) . '). ' . $reason
    );
}

function databaseCheck(): array
{
    try {
        $db = ADONewConnection(DB_DRIVER);
        $connected = $db->Connect(builderxDatabaseHost(), DB_USER, DB_PASS, DB_NAME);
        if (!$connected) {
            return checkItem('Database', 'ADOdb database connection', 'Failed', 'Connection returned false.');
        }

        $db->SetFetchMode(ADODB_FETCH_ASSOC);
        $version = (string) $db->GetOne('SELECT VERSION()');
        $createPermission = (string) $db->GetOne("
            SELECT COUNT(*)
            FROM information_schema.SCHEMA_PRIVILEGES
            WHERE GRANTEE LIKE CONCAT('%', ?, '%')
                AND TABLE_SCHEMA = ?
                AND PRIVILEGE_TYPE IN ('CREATE', 'ALTER', 'INDEX', 'INSERT', 'UPDATE', 'DELETE', 'SELECT')
        ", [DB_USER, DB_NAME]);

        $detail = 'Connected to ' . DB_NAME . ' on ' . builderxDatabaseHost() . '. Server version: ' . $version . '.';
        if ((int) $createPermission === 0) {
            $detail .= ' Privilege introspection did not return explicit grants; validate CREATE/ALTER/INDEX manually if installation fails.';
            return checkItem('Database', 'ADOdb database connection', 'Warning', $detail);
        }

        return checkItem('Database', 'ADOdb database connection', 'Passed', $detail);
    } catch (Throwable $e) {
        return checkItem('Database', 'ADOdb database connection', 'Failed', $e->getMessage());
    }
}

function buildChecks(): array
{
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $storage = $root . '/storage';
    $backup = $root . '/storage/backups';

    $checks = [
        checkItem(
            'Runtime',
            'PHP version 8.2 or later',
            version_compare(PHP_VERSION, MIN_PHP_VERSION, '>=') ? 'Passed' : 'Warning',
            'Current CLI/runtime version: ' . PHP_VERSION . '. docs/project/system.md recommends PHP 8.2+ for the full platform.'
        ),
    ];

    $checks[] = runtimeLimitCheck(
        'upload_max_filesize',
        REQUIRED_UPLOAD_BYTES,
        'BuilderX installation should configure PHP-FPM/Apache for 1 GB uploads before production use.'
    );
    $checks[] = runtimeLimitCheck(
        'post_max_size',
        REQUIRED_UPLOAD_BYTES,
        'This must be at least as large as upload_max_filesize so large form/image uploads are accepted.'
    );
    $checks[] = runtimeLimitCheck(
        'memory_limit',
        REQUIRED_MEMORY_BYTES,
        'Set higher on larger servers when imports, exports, PDF, or image processing require it.'
    );
    $maxExecutionTime = (int) ini_get('max_execution_time');
    $checks[] = checkItem(
        'Runtime',
        'PHP setting: max_execution_time >= ' . REQUIRED_EXECUTION_SECONDS . ' seconds',
        ($maxExecutionTime === 0 || $maxExecutionTime >= REQUIRED_EXECUTION_SECONDS) ? 'Passed' : 'Warning',
        'Current value: ' . (string) ini_get('max_execution_time') . ' seconds. Tune based on CPU, memory, disk, and expected import/upload workloads.'
    );

    $requiredExtensions = [
        'mysqli',
        'pdo_mysql',
        'json',
        'session',
        'openssl',
        'mbstring',
        'curl',
        'fileinfo',
        'gd',
        'zip',
    ];

    foreach ($requiredExtensions as $extension) {
        $checks[] = checkItem(
            'Runtime',
            'PHP extension: ' . $extension,
            extension_loaded($extension) ? 'Passed' : 'Failed',
            extension_loaded($extension) ? 'Extension is loaded.' : 'Extension is required before production installation.'
        );
    }

    $checks[] = databaseCheck();
    $checks[] = checkItem('Paths', 'Project root', is_dir($root) ? 'Passed' : 'Failed', $root);
    $checks[] = checkItem('Paths', 'Backend path requirement', is_dir($root . '/backend') ? 'Passed' : 'Missing', $root . '/backend will be created in a later backend phase.');
    $checks[] = checkItem('Paths', 'Frontend path requirement', is_dir($root . '/frontend') ? 'Passed' : 'Missing', $root . '/frontend will be created in a later frontend phase.');
    $checks[] = checkWritablePath('Storage', 'Storage path', $storage);
    $checks[] = checkWritablePath('Storage', 'Backup path', $backup);
    $checks[] = checkItem(
        'Security',
        'Encryption key availability',
        getenv('BUILDERX_APP_KEY') ? 'Passed' : 'Warning',
        getenv('BUILDERX_APP_KEY') ? 'BUILDERX_APP_KEY is set.' : 'Set BUILDERX_APP_KEY before enabling production installation.'
    );
    $checks[] = checkItem(
        'Security',
        'Installation lock',
        file_exists($root . '/storage/install.lock') ? 'Passed' : 'Warning',
        file_exists($root . '/storage/install.lock') ? 'Install lock exists.' : 'Install lock should be created after a successful first-run installation.'
    );

    return $checks;
}

$requirements = [
    'Database Information' => [
        'Database host',
        'Database port',
        'Database name',
        'Database username',
        'Database password',
        'Database character set',
        'Database time zone',
    ],
    'System Information' => [
        'Application URL',
        'Backend path',
        'Frontend path',
        'Storage path',
        'Backup path',
        'Software name',
        'Software description',
        'Version',
        'Developer or company name',
        'Contact number',
        'Email address',
        'Default time zone',
        'Default language',
        'Default currency',
        'Fiscal year start date',
        'PHP upload_max_filesize must be configured to 1G.',
        'PHP post_max_size must be configured to 1G or higher.',
        'PHP memory_limit and execution limits must be tuned from server hardware capacity and expected workload.',
    ],
    'Development AI Skills' => [
        'Install the official shadcn/ui AI skill from https://ui.shadcn.com/docs/skills in the active React frontend.',
        'For this npm-based BuilderX frontend, run: cd frontend && npx skills add shadcn/ui.',
        'If pnpm is available, the official documented command is: pnpm dlx skills add shadcn/ui.',
        'Preserve frontend/.agents/skills/shadcn and migrate-radix-to-base in the installer template so agents can use project-aware shadcn context.',
        'Use the official shadcn Base Nova CSS baseline from frontend/src/index.css; BuilderX Template Studio is not installed.',
        'Do not add custom template CSS or JavaScript; apply layout changes through official shadcn components and source CSS only.',
    ],
    'Initial Administrator' => [
        'Administrator username',
        'Administrator email',
        'Administrator password',
        'Administrator full name',
        'Password must be hashed and must not use fixed production defaults.',
        'Temporary generated passwords must display once, expire, and require first-login change.',
    ],
    'Completion Rules' => [
        'Validate all runtime and database checks before completing installation.',
        'Create an installation lock file after successful installation.',
        'Disable reinstallation unless server-level authorization is present.',
        'Store sensitive configuration outside the public document root.',
        'Route post-install setting changes through System Settings, not the installer.',
    ],
];

$checks = buildChecks();
$counts = ['Passed' => 0, 'Warning' => 0, 'Failed' => 0, 'Missing' => 0];
foreach ($checks as $check) {
    $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
}

$projectBasePath = rtrim(dirname(dirname(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/phases/installation.php')))), '/');
$projectBasePath = ($projectBasePath === '' ? '' : $projectBasePath) . '/';
$themeStorageKey = 'builderx:theme:' . rawurlencode($projectBasePath) . ':phase-manager:' . rawurlencode((string) ($_SESSION['builderx_user_key'] ?? 'guest'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BuilderX Installation Requirements</title>
    <script>
        (function () {
            try {
                var mode = window.localStorage.getItem(<?= json_encode($themeStorageKey) ?>) === 'dark' ? 'dark' : 'light';
                document.documentElement.classList.toggle('dark', mode === 'dark');
                document.documentElement.style.colorScheme = mode;
            } catch (error) {
                document.documentElement.style.colorScheme = 'light';
            }
        })();
    </script>
    <style>
        :root {
            --ink: #1e293b;
            --muted: #64748b;
            --line: #d8dee9;
            --panel: #ffffff;
            --bg: #f6f8fb;
            --accent: #0f766e;
            --warn: #b45309;
            --danger: #b91c1c;
            --ok: #15803d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: Arial, Helvetica, sans-serif;
            letter-spacing: 0;
        }

        .shell {
            width: min(1280px, 100%);
            margin: 0 auto;
            padding: 24px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        h1,
        h2 {
            margin: 0;
            line-height: 1.2;
        }

        h1 {
            font-size: 26px;
            margin-bottom: 8px;
        }

        h2 {
            font-size: 18px;
        }

        p {
            margin: 6px 0 0;
            color: var(--muted);
            line-height: 1.5;
        }

        a {
            color: var(--accent);
            font-weight: 700;
            text-decoration: none;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 420px;
            gap: 18px;
            align-items: start;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
        }

        .panel-head {
            padding: 16px;
            border-bottom: 1px solid var(--line);
            background: #f8fafc;
        }

        .section {
            padding: 16px;
            border-bottom: 1px solid var(--line);
        }

        .section:last-child {
            border-bottom: 0;
        }

        ul {
            margin: 12px 0 0;
            padding-left: 20px;
            color: var(--ink);
            line-height: 1.6;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }

        .metric {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px;
        }

        .metric strong {
            display: block;
            font-size: 24px;
        }

        .metric span {
            color: var(--muted);
            font-size: 13px;
        }

        .check {
            display: grid;
            grid-template-columns: 110px 1fr;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
        }

        .check:last-child {
            border-bottom: 0;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 26px;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid var(--line);
            background: #f8fafc;
        }

        .passed {
            color: var(--ok);
            background: #dcfce7;
            border-color: #bbf7d0;
        }

        .warning,
        .missing {
            color: var(--warn);
            background: #fef3c7;
            border-color: #fde68a;
        }

        .failed {
            color: var(--danger);
            background: #fee2e2;
            border-color: #fecaca;
        }

        .check-title {
            margin: 0;
            color: var(--ink);
            font-weight: 700;
        }

        .check-detail {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 960px) {
            .topbar,
            .grid {
                display: block;
            }

            .panel,
            .summary {
                margin-top: 16px;
            }

            .summary {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
    <?= builderxTemplateAssetHtml(__DIR__ . '/../frontend/dist/.vite/manifest.json', '../frontend/dist/') ?>
    <style>
        :root {
            --ink: var(--foreground);
            --muted: var(--muted-foreground);
            --line: var(--border);
            --panel: var(--card);
            --bg: var(--background);
            --accent: var(--primary);
            --warn: var(--muted-foreground);
            --danger: var(--destructive);
            --ok: var(--primary);
        }

        body {
            background: var(--background);
            color: var(--foreground);
            font-family: var(--font-sans, Arial, Helvetica, sans-serif);
        }

        .panel,
        .panel-head,
        .metric,
        .section,
        .check {
            background: var(--card) !important;
            border-color: var(--border) !important;
            color: var(--card-foreground) !important;
            box-shadow: none !important;
        }

        h1,
        h2,
        .metric strong,
        .check-title,
        ul {
            color: var(--foreground) !important;
        }

        p,
        .metric span,
        .check-detail {
            color: var(--muted-foreground) !important;
        }

        a {
            color: var(--primary);
        }

        .badge,
        .passed,
        .warning,
        .missing,
        .failed {
            background: var(--secondary) !important;
            border-color: var(--border) !important;
            color: var(--secondary-foreground) !important;
        }

        .failed {
            background: color-mix(in oklab, var(--destructive) 12%, var(--card)) !important;
            border-color: color-mix(in oklab, var(--destructive) 35%, var(--border)) !important;
            color: var(--destructive) !important;
        }
    </style>
</head>
<body>
    <main class="shell">
        <div class="topbar">
            <div>
                <h1>Installation Wizard Requirements</h1>
                <p>P1-T2 defines the required installer inputs and live validation checklist before the full BuilderX platform is developed.</p>
            </div>
            <a href="./index.php">Back to Phase Manager</a>
        </div>

        <div class="summary">
            <?php foreach ($counts as $status => $count): ?>
                <div class="metric">
                    <strong><?= (int) $count ?></strong>
                    <span><?= h($status) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="grid">
            <section class="panel">
                <div class="panel-head">
                    <h2>Installer Collection Requirements</h2>
                    <p>These fields must be collected by the first-run installation wizard.</p>
                </div>
                <?php foreach ($requirements as $title => $items): ?>
                    <div class="section">
                        <h2><?= h($title) ?></h2>
                        <ul>
                            <?php foreach ($items as $item): ?>
                                <li><?= h($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <h2>Environment Validation Checklist</h2>
                    <p>These checks run against the current server and database.</p>
                </div>
                <?php foreach ($checks as $check): ?>
                    <div class="check">
                        <div>
                            <span class="badge <?= h(statusClass($check['status'])) ?>"><?= h($check['status']) ?></span>
                        </div>
                        <div>
                            <p class="check-title"><?= h($check['label']) ?></p>
                            <p class="check-detail"><?= h($check['group']) ?> - <?= h($check['details']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        </div>
    </main>
</body>
</html>
