<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../adodb/adodb.inc.php';

builderxDefineDatabaseConstants();
if (!builderxIsConfigured()) {
    http_response_code(404);
    exit;
}

const NOTE_ATTACHMENT_DIR = __DIR__ . '/../storage/phase-note-attachments';

function noteAttachmentDb(): ADOConnection
{
    static $db = null;
    if ($db instanceof ADOConnection) {
        return $db;
    }

    $db = ADONewConnection(DB_DRIVER);
    $db->Connect(builderxDatabaseHost(), DB_USER, DB_PASS, DB_NAME);
    $db->SetFetchMode(ADODB_FETCH_ASSOC);
    $db->Execute("SET NAMES 'utf8mb4'");
    $db->debug = false;

    return $db;
}

$attachmentKey = (string) ($_GET['attachment_key'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/i', $attachmentKey)) {
    http_response_code(404);
    exit;
}

$attachment = noteAttachmentDb()->GetRow(
    "SELECT * FROM builder_phase_task_note_attachment WHERE attachment_key = ? AND attachment_status = 'ACTIVE' LIMIT 1",
    [$attachmentKey]
) ?: [];

$path = (string) ($attachment['storage_path'] ?? '');
$baseDir = realpath(NOTE_ATTACHMENT_DIR);
$realPath = $path !== '' ? realpath($path) : false;

if (!$attachment || !$baseDir || !$realPath || !str_starts_with($realPath, $baseDir) || !is_file($realPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . (string) $attachment['mime_type']);
header('Content-Length: ' . (string) filesize($realPath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($realPath);
