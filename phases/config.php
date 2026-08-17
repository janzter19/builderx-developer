<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

function builderxSystemConfig(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $defaults = [
        'project_name' => '',
        'public_path' => '',
        'portal_mode' => 'product',
        'xSecret' => [],
        'xPassCode' => [],
        'db_driver' => 'mysqli',
        'db_host' => 'localhost',
        'db_port' => 3306,
        'db_name' => '',
        'db_user' => '',
        'db_pass' => '',
        'codex_chat_id' => '',
    ];

    $localPath = __DIR__ . '/config.local.php';
    $local = is_file($localPath) ? require $localPath : [];
    if (!is_array($local)) {
        $local = [];
    }

    $config = array_merge($defaults, $local);

    return $config;
}

function builderxDefineDatabaseConstants(): void
{
    $config = builderxSystemConfig();

    if (!defined('DB_DRIVER')) {
        define('DB_DRIVER', (string) $config['db_driver']);
    }
    if (!defined('DB_HOST')) {
        define('DB_HOST', (string) $config['db_host']);
    }
    if (!defined('DB_PORT')) {
        define('DB_PORT', (int) $config['db_port']);
    }
    if (!defined('DB_USER')) {
        define('DB_USER', (string) $config['db_user']);
    }
    if (!defined('DB_PASS')) {
        define('DB_PASS', (string) $config['db_pass']);
    }
    if (!defined('DB_NAME')) {
        define('DB_NAME', (string) $config['db_name']);
    }
}

function builderxConfigValue(string $key): string
{
    $config = builderxSystemConfig();
    $normalized = strtolower($key);
    $candidates = [$key, $normalized];

    foreach ($candidates as $candidate) {
        if (isset($config[$candidate]) && trim((string) $config[$candidate]) !== '') {
            return trim((string) $config[$candidate]);
        }
    }

    return '';
}

function builderxDatabaseHost(): string
{
    $host = DB_HOST;
    $port = (int) DB_PORT;

    if ($port > 0 && !str_contains($host, ':')) {
        return $host . ':' . $port;
    }

    return $host;
}

function builderxIsConfigured(): bool
{
    return DB_HOST !== '' && DB_USER !== '' && DB_NAME !== '';
}

function builderxRenderMissingConfigPage(): void
{
    http_response_code(503);
    $installerUrl = '/_installer/';
    $configPath = __DIR__ . '/config.local.php';
    $configExists = is_file($configPath);
    $message = $configExists
        ? 'The local configuration exists, but database settings are incomplete. Set BUILDERX_DB_NAME, BUILDERX_DB_USER, and BUILDERX_DB_PASS for this project, then reload.'
        : 'This project does not have phases/config.local.php yet. Run /_installer/ or create the config file from phases/config.example.php.';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>BuilderX Setup Required</title><style>body{margin:0;background:#f6f8fb;color:#111827;font-family:Arial,Helvetica,sans-serif}main{max-width:680px;margin:48px auto;padding:24px;background:#fff;border:1px solid #d8dee8;border-radius:8px}a{display:inline-block;margin-top:12px;padding:10px 14px;background:#111827;color:#fff;text-decoration:none;border-radius:6px}code{background:#eef2f7;padding:2px 6px;border-radius:4px}</style></head><body><main><h1>BuilderX Setup Required</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="' . htmlspecialchars($installerUrl, ENT_QUOTES, 'UTF-8') . '">Open Installer</a></main></body></html>';
    exit;
}
