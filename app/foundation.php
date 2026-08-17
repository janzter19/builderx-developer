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

require_once __DIR__ . '/../phases/config.php';
require_once __DIR__ . '/../adodb/adodb.inc.php';
require_once __DIR__ . '/AI/AiTaskStore.php';
require_once __DIR__ . '/AI/CommunicationMessageStore.php';
require_once __DIR__ . '/AI/AiTaskResultReconciler.php';
require_once __DIR__ . '/AI/PhaseBuilderNarrativeCleanupStore.php';
require_once __DIR__ . '/AI/AiSpecialistRegistry.php';
require_once __DIR__ . '/AI/ApprovalStore.php';
require_once __DIR__ . '/AI/MemoryStore.php';
require_once __DIR__ . '/AI/CoordinatorRouter.php';

builderxDefineDatabaseConstants();
if (!builderxIsConfigured()) {
    builderxRenderMissingConfigPage();
}

define('BUILDERX_DB_DRIVER', DB_DRIVER);
define('BUILDERX_DB_HOST', builderxDatabaseHost());
define('BUILDERX_DB_USER', DB_USER);
define('BUILDERX_DB_PASS', DB_PASS);
define('BUILDERX_DB_NAME', DB_NAME);

function bx_db(): ADOConnection
{
    static $db = null;
    if ($db instanceof ADOConnection) {
        return $db;
    }

    $db = ADONewConnection(BUILDERX_DB_DRIVER);
    $db->Connect(BUILDERX_DB_HOST, BUILDERX_DB_USER, BUILDERX_DB_PASS, BUILDERX_DB_NAME);
    $db->SetFetchMode(ADODB_FETCH_ASSOC);
    $db->Execute("SET NAMES 'utf8mb4'");
    $db->debug = false;

    return $db;
}

function bx_run_bridge_database_test(): array
{
    $db = bx_db();
    $table = 'phase_builder_codex_bridge_test';
    $transactionStarted = false;
    $assertExecute = static function ($result, string $operation) use ($db): void {
        if ($result !== false) {
            return;
        }

        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    };

    try {
        $db->BeginTrans();
        $transactionStarted = true;

        $assertExecute($db->Execute(
            'CREATE TABLE IF NOT EXISTS phase_builder_codex_bridge_test (
                test_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                test_code VARCHAR(40) NOT NULL,
                test_label VARCHAR(120) NOT NULL,
                test_value VARCHAR(120) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ), 'Bridge test table creation');

        $insertedIds = [];
        $expectedRows = [];
        for ($index = 1; $index <= 10; $index++) {
            $code = sprintf('TEST-%02d', $index);
            $label = sprintf('Bridge Test %02d', $index);
            $value = sprintf('Value %02d', $index);
            $assertExecute($db->Execute(
                'INSERT INTO phase_builder_codex_bridge_test (test_code, test_label, test_value) VALUES (?, ?, ?)',
                [$code, $label, $value]
            ), 'Bridge test row insertion');

            $testId = (int) $db->Insert_ID();
            if ($testId < 1) {
                throw new RuntimeException('Bridge test row insertion did not return a valid test_id.');
            }
            $insertedIds[] = $testId;
            $expectedRows[$testId] = [
                'test_code' => $code,
                'test_label' => $label,
                'test_value' => $value,
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($insertedIds), '?'));
        $insertedRows = $db->GetAll(
            "SELECT test_id, test_code, test_label, test_value, created_at FROM {$table} WHERE test_id IN ({$placeholders}) ORDER BY test_id",
            $insertedIds
        );
        if (!is_array($insertedRows)) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Bridge test read-back failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
        if (count($insertedRows) !== 10) {
            throw new RuntimeException('Bridge test read-back returned ' . count($insertedRows) . ' rows; expected 10.');
        }

        foreach ($insertedRows as $row) {
            $testId = (int) ($row['test_id'] ?? 0);
            $expected = $expectedRows[$testId] ?? null;
            if ($expected === null
                || (string) ($row['test_code'] ?? '') !== $expected['test_code']
                || (string) ($row['test_label'] ?? '') !== $expected['test_label']
                || (string) ($row['test_value'] ?? '') !== $expected['test_value']
                || trim((string) ($row['created_at'] ?? '')) === '') {
                throw new RuntimeException('Bridge test read-back validation failed for test_id ' . $testId . '.');
            }
        }

        $structure = $db->GetAll(
            'SELECT COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, COLUMN_KEY AS column_key, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [BUILDERX_DB_NAME, $table]
        );
        if (!is_array($structure)) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Bridge test table structure read failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }

        $committed = $db->CommitTrans();
        $transactionStarted = false;
        if ($committed === false) {
            throw new RuntimeException('Bridge test transaction commit failed.');
        }

        return [
            'table' => $table,
            'structure' => $structure,
            'inserted_rows' => $insertedRows,
            'count' => count($insertedRows),
            'transaction' => 'committed',
        ];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bx_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function bx_csrf_token(): string
{
    if (empty($_SESSION['builderx_csrf'])) {
        $_SESSION['builderx_csrf'] = bin2hex(random_bytes(24));
    }

    return $_SESSION['builderx_csrf'];
}

function bx_verify_csrf(): void
{
    $token = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals(bx_csrf_token(), $token)) {
        bx_flash('Invalid request token.', 'error');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? './'));
        exit;
    }
}

function bx_flash(string $message, string $type = 'info', ?string $details = null): void
{
    $_SESSION['builderx_flash'] = ['message' => $message, 'type' => $type];
    if ($details !== null && trim($details) !== '') {
        $_SESSION['builderx_flash']['details'] = substr(trim($details), 0, 4000);
    }
}

function bx_take_flash(): ?array
{
    $flash = $_SESSION['builderx_flash'] ?? null;
    unset($_SESSION['builderx_flash']);

    return $flash;
}

function bx_password_hash(string $password): string
{
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_hash($password, $algo);
}

function bx_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function bx_user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255);
}

function bx_add_column_if_missing(string $table, string $column, string $definition): void
{
    $exists = bx_db()->GetOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, $table, $column]
    );

    if ((int) $exists === 0) {
        bx_db()->Execute("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function bx_add_index_if_missing(string $table, string $index, string $definition): void
{
    $exists = bx_db()->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [BUILDERX_DB_NAME, $table, $index]
    );

    if ((int) $exists === 0) {
        bx_db()->Execute("ALTER TABLE {$table} ADD {$definition}");
    }
}

function bx_add_unique_index_if_missing(string $table, string $column, string $index, string $definition): void
{
    $db = bx_db();
    $namedExists = $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0',
        [BUILDERX_DB_NAME, $table, $index]
    );
    if ((int) $namedExists > 0) {
        return;
    }

    $columnIsUnique = $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0',
        [BUILDERX_DB_NAME, $table, $column]
    );
    if ((int) $columnIsUnique === 0) {
        $db->Execute("ALTER TABLE {$table} ADD {$definition}");
    }
}

function bx_phase_builder_current_draft_key(): string
{
    return trim((string) bx_db()->GetOne(
        'SELECT draft_key FROM phase_builder_narrative_draft ORDER BY updated_at DESC, x_id DESC LIMIT 1'
    ));
}

function bx_backup_phase_builder_narrative_draft(): void
{
    $db = bx_db();
    $missingRows = $db->GetAll(
        'SELECT source.x_id FROM phase_builder_narrative_draft source LEFT JOIN phase_builder_narrative_draft_backup backup ON backup.x_id = source.x_id WHERE backup.x_id IS NULL ORDER BY source.x_id'
    );
    if (!is_array($missingRows) || $missingRows === []) {
        return;
    }

    $db->BeginTrans();
    $transactionStarted = true;
    try {
        $copied = $db->Execute('INSERT IGNORE INTO phase_builder_narrative_draft_backup SELECT * FROM phase_builder_narrative_draft');
        if ($copied === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Tab 1 backup copy failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }

        $fields = [
            'x_id',
            'draft_key',
            'phase_key',
            'product_goal',
            'users_and_roles',
            'main_user_journey',
            'web_requirements',
            'android_requirements',
            'database_and_synchronization',
            'security_and_permissions',
            'validation_and_error_handling',
            'open_questions',
            'created_by_user_key',
            'updated_by_user_key',
            'created_at',
            'updated_at',
        ];
        $selectFields = implode(', ', $fields);
        foreach ($missingRows as $missingRow) {
            $rowKey = (int) ($missingRow['x_id'] ?? 0);
            if ($rowKey < 1) {
                throw new RuntimeException('Tab 1 backup copy returned an invalid source key.');
            }

            $source = $db->GetRow("SELECT {$selectFields} FROM phase_builder_narrative_draft WHERE x_id = ? LIMIT 1", [$rowKey]);
            $backup = $db->GetRow("SELECT {$selectFields} FROM phase_builder_narrative_draft_backup WHERE x_id = ? LIMIT 1", [$rowKey]);
            if (!is_array($source) || !is_array($backup)) {
                throw new RuntimeException('Tab 1 backup read-back returned no matching row.');
            }
            foreach ($fields as $field) {
                if ((string) ($source[$field] ?? '') !== (string) ($backup[$field] ?? '')) {
                    throw new RuntimeException('Tab 1 backup read-back mismatch for ' . $field . '.');
                }
            }
        }

        $db->CommitTrans();
        $transactionStarted = false;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_schema(): void
{
    $db = bx_db();

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_system_setting (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key CHAR(36) NOT NULL UNIQUE,
            setting_name VARCHAR(120) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
            is_secret TINYINT(1) NOT NULL DEFAULT 0,
            setting_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    bx_add_column_if_missing('builder_system_setting', 'is_secret', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER setting_group');

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL UNIQUE,
            user_login VARCHAR(80) NOT NULL UNIQUE,
            user_password_hash VARCHAR(255) NOT NULL,
            user_name VARCHAR(160) NOT NULL,
            user_email VARCHAR(190) NOT NULL UNIQUE,
            user_status ENUM('DRAFT','ACTIVE','INACTIVE','LOCKED','DELETED') NOT NULL DEFAULT 'DRAFT',
            user_failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
            user_password_changed_at TIMESTAMP NULL,
            user_password_expires_at TIMESTAMP NULL,
            user_email_verified_at TIMESTAMP NULL,
            user_two_factor_required TINYINT(1) NOT NULL DEFAULT 0,
            user_recovery_codes_enabled TINYINT(1) NOT NULL DEFAULT 0,
            user_last_login_at TIMESTAMP NULL,
            server_timestamp TIMESTAMP NULL,
            user_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_created_by_key CHAR(36) NULL,
            user_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            user_updated_by_key CHAR(36) NULL,
            user_deleted_at TIMESTAMP NULL,
            user_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_user_status (user_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    bx_add_column_if_missing('builder_user', 'user_password_expires_at', 'TIMESTAMP NULL AFTER user_password_changed_at');
    bx_add_column_if_missing('builder_user', 'user_email_verified_at', 'TIMESTAMP NULL AFTER user_password_expires_at');
    bx_add_column_if_missing('builder_user', 'user_two_factor_required', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER user_email_verified_at');
    bx_add_column_if_missing('builder_user', 'user_recovery_codes_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER user_two_factor_required');

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_group (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_key CHAR(36) NOT NULL UNIQUE,
            group_name VARCHAR(120) NOT NULL UNIQUE,
            group_description TEXT NULL,
            group_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_role (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role_key CHAR(36) NOT NULL UNIQUE,
            role_name VARCHAR(120) NOT NULL UNIQUE,
            role_description TEXT NULL,
            role_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_permission (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            permission_key CHAR(36) NOT NULL UNIQUE,
            permission_code VARCHAR(120) NOT NULL UNIQUE,
            permission_name VARCHAR(160) NOT NULL,
            permission_scope VARCHAR(60) NOT NULL,
            permission_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_group (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL,
            group_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_user_group (user_key, group_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_role (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL,
            role_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_user_role (user_key, role_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_role_permission (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role_key CHAR(36) NOT NULL,
            permission_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_role_permission (role_key, permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_branch (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_key CHAR(36) NOT NULL UNIQUE,
            branch_name VARCHAR(160) NOT NULL,
            branch_code VARCHAR(40) NOT NULL UNIQUE,
            branch_status ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED','DELETED') NOT NULL DEFAULT 'ACTIVE',
            branch_address TEXT NULL,
            branch_contact TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_branch_status (branch_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_project (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_key CHAR(36) NOT NULL UNIQUE,
            branch_key CHAR(36) NOT NULL,
            project_name VARCHAR(160) NOT NULL,
            project_code VARCHAR(40) NOT NULL UNIQUE,
            project_status ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED','DELETED') NOT NULL DEFAULT 'ACTIVE',
            project_description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_project_branch (branch_key),
            INDEX idx_builder_project_status (project_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_branch (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL,
            branch_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_user_branch (user_key, branch_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_project (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL,
            project_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_user_project (user_key, project_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_session (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NOT NULL,
            session_token_hash CHAR(64) NOT NULL,
            ip_address VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            session_status ENUM('ACTIVE','REVOKED','EXPIRED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            INDEX idx_builder_user_session_user (user_key),
            INDEX idx_builder_user_session_status (session_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_password_reset (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reset_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NOT NULL,
            reset_token_hash CHAR(64) NOT NULL UNIQUE,
            reset_status ENUM('PENDING','USED','EXPIRED','REVOKED') NOT NULL DEFAULT 'PENDING',
            requested_ip VARCHAR(80) NULL,
            requested_user_agent VARCHAR(255) NULL,
            used_ip VARCHAR(80) NULL,
            used_user_agent VARCHAR(255) NULL,
            email_delivery_status ENUM('PENDING','QUEUED','SENT','FAILED','PLACEHOLDER') NOT NULL DEFAULT 'PLACEHOLDER',
            email_verification_required TINYINT(1) NOT NULL DEFAULT 1,
            two_factor_required TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            INDEX idx_builder_password_reset_user (user_key),
            INDEX idx_builder_password_reset_status (reset_status),
            INDEX idx_builder_password_reset_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_password_history (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            history_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            changed_by_key CHAR(36) NULL,
            change_reason VARCHAR(120) NOT NULL DEFAULT 'password-reset',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_builder_password_history_user (user_key),
            INDEX idx_builder_password_history_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_login_history (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            login_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NULL,
            user_login VARCHAR(120) NULL,
            login_status ENUM('SUCCESS','FAILED','LOCKED') NOT NULL,
            ip_address VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            failure_reason VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_builder_login_user (user_key),
            INDEX idx_builder_login_status (login_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_audit_log (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            audit_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NULL,
            action VARCHAR(80) NOT NULL,
            module VARCHAR(80) NOT NULL,
            record_key CHAR(36) NULL,
            previous_values LONGTEXT NULL,
            new_values LONGTEXT NULL,
            ip_address VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            reason TEXT NULL,
            branch_key CHAR(36) NULL,
            project_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_builder_audit_user (user_key),
            INDEX idx_builder_audit_action (action),
            INDEX idx_builder_audit_module (module),
            INDEX idx_builder_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_family_member (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_key CHAR(36) NOT NULL UNIQUE,
            owner_user_key CHAR(36) NOT NULL,
            first_name VARCHAR(80) NOT NULL,
            middle_name VARCHAR(80) NULL,
            last_name VARCHAR(80) NOT NULL,
            suffix VARCHAR(40) NULL,
            birth_date DATE NULL,
            relationship_to_user VARCHAR(80) NOT NULL,
            contact_email VARCHAR(190) NULL,
            contact_phone VARCHAR(40) NULL,
            consent_privacy TINYINT(1) NOT NULL DEFAULT 0,
            consent_contact TINYINT(1) NOT NULL DEFAULT 0,
            member_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            member_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            member_created_by_key CHAR(36) NULL,
            member_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            member_updated_by_key CHAR(36) NULL,
            member_deleted_at TIMESTAMP NULL,
            member_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_family_member_owner (owner_user_key),
            INDEX idx_builder_family_member_status (member_status),
            INDEX idx_builder_family_member_lookup (owner_user_key, last_name, first_name, birth_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_family_member_vehicle (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_key CHAR(36) NOT NULL UNIQUE,
            member_key CHAR(36) NOT NULL,
            owner_user_key CHAR(36) NOT NULL,
            plate_number VARCHAR(40) NOT NULL,
            make VARCHAR(80) NULL,
            model VARCHAR(80) NULL,
            model_year SMALLINT UNSIGNED NULL,
            color VARCHAR(60) NULL,
            ownership_type VARCHAR(80) NOT NULL,
            registration_status VARCHAR(80) NULL,
            vehicle_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            vehicle_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            vehicle_created_by_key CHAR(36) NULL,
            vehicle_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            vehicle_updated_by_key CHAR(36) NULL,
            vehicle_deleted_at TIMESTAMP NULL,
            vehicle_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_family_vehicle_member (member_key),
            INDEX idx_builder_family_vehicle_owner (owner_user_key),
            INDEX idx_builder_family_vehicle_plate (owner_user_key, plate_number),
            INDEX idx_builder_family_vehicle_status (vehicle_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_family_member_education (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            education_key CHAR(36) NOT NULL UNIQUE,
            member_key CHAR(36) NOT NULL,
            owner_user_key CHAR(36) NOT NULL,
            education_level VARCHAR(80) NOT NULL,
            institution_name VARCHAR(190) NOT NULL,
            program_name VARCHAR(190) NULL,
            date_started DATE NULL,
            date_completed DATE NULL,
            completion_status VARCHAR(80) NOT NULL,
            education_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            education_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            education_created_by_key CHAR(36) NULL,
            education_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            education_updated_by_key CHAR(36) NULL,
            education_deleted_at TIMESTAMP NULL,
            education_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_family_education_member (member_key),
            INDEX idx_builder_family_education_owner (owner_user_key),
            INDEX idx_builder_family_education_lookup (owner_user_key, education_level, institution_name),
            INDEX idx_builder_family_education_status (education_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_ai_task (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_key VARCHAR(128) NOT NULL UNIQUE,
            correlation_id VARCHAR(128) NOT NULL,
            parent_task_key VARCHAR(128) NULL,
            action VARCHAR(80) NOT NULL,
            stage ENUM('Think','Design','Build','Validate','Document','Preserve') NOT NULL,
            specialist VARCHAR(80) NOT NULL,
            task_status ENUM('queued','running','awaiting_approval','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
            input_json LONGTEXT NOT NULL,
            output_json LONGTEXT NULL,
            error_json LONGTEXT NULL,
            permissions_json TEXT NOT NULL,
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_by_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_ai_task_correlation (correlation_id),
            INDEX idx_builder_ai_task_status (task_status),
            INDEX idx_builder_ai_task_owner (created_by_key),
            INDEX idx_builder_ai_task_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_ai_specialist (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            specialist_key VARCHAR(128) NOT NULL UNIQUE,
            specialist_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
            specialist_name VARCHAR(120) NOT NULL,
            purpose TEXT NOT NULL,
            stages_json TEXT NOT NULL,
            skills_json TEXT NOT NULL,
            allowed_tools_json TEXT NOT NULL,
            write_scope ENUM('none','communication_only','build_allowlist','phase_manager_approval') NOT NULL DEFAULT 'none',
            rag_scopes_json TEXT NOT NULL,
            specialist_status ENUM('pending_approval','active','inactive','retired') NOT NULL DEFAULT 'pending_approval',
            review_status ENUM('unreviewed','approved','rejected','needs_revision') NOT NULL DEFAULT 'unreviewed',
            approval_reference VARCHAR(128) NULL,
            is_temporary TINYINT(1) NOT NULL DEFAULT 0,
            owner_user_key CHAR(36) NULL,
            evidence_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_ai_specialist_status (specialist_status),
            INDEX idx_builder_ai_specialist_review (review_status),
            INDEX idx_builder_ai_specialist_owner (owner_user_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_ai_approval (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            approval_key VARCHAR(128) NOT NULL UNIQUE,
            operation ENUM('delete','move','database','backup','audit') NOT NULL,
            target VARCHAR(1024) NOT NULL,
            target_hash VARCHAR(128) NOT NULL,
            actor_user_key CHAR(36) NULL,
            approval_status ENUM('pending','approved','consumed','expired','rejected') NOT NULL DEFAULT 'pending',
            approved_by_key CHAR(36) NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            consumed_at TIMESTAMP NULL,
            INDEX idx_builder_ai_approval_status (approval_status),
            INDEX idx_builder_ai_approval_expiry (expires_at),
            INDEX idx_builder_ai_approval_actor (actor_user_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute('ALTER TABLE builder_ai_approval MODIFY COLUMN expires_at DATETIME NOT NULL');

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_ai_memory (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            memory_key VARCHAR(128) NOT NULL UNIQUE,
            memory_version INT UNSIGNED NOT NULL DEFAULT 1,
            title VARCHAR(240) NOT NULL,
            content LONGTEXT NOT NULL,
            memory_type ENUM('brand_rule','decision','instruction','example','task_result','reference') NOT NULL,
            retrieval_types_json TEXT NOT NULL,
            tags_json TEXT NOT NULL,
            metadata_json LONGTEXT NOT NULL,
            source_reference VARCHAR(512) NULL,
            parent_memory_key VARCHAR(128) NULL,
            memory_status ENUM('pending_approval','approved','archived','rejected') NOT NULL DEFAULT 'pending_approval',
            review_status ENUM('unreviewed','approved','rejected','needs_revision') NOT NULL DEFAULT 'unreviewed',
            vault_path VARCHAR(512) NULL,
            owner_user_key CHAR(36) NULL,
            approved_by_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_ai_memory_status (memory_status),
            INDEX idx_builder_ai_memory_type (memory_type),
            INDEX idx_builder_ai_memory_parent (parent_memory_key),
            INDEX idx_builder_ai_memory_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_narrative_draft (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            draft_key CHAR(36) NOT NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            product_goal LONGTEXT NOT NULL,
            users_and_roles LONGTEXT NOT NULL,
            main_user_journey LONGTEXT NOT NULL,
            web_requirements LONGTEXT NOT NULL,
            android_requirements LONGTEXT NOT NULL,
            database_and_synchronization LONGTEXT NOT NULL,
            security_and_permissions LONGTEXT NOT NULL,
            validation_and_error_handling LONGTEXT NOT NULL,
            open_questions LONGTEXT NOT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_narrative_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $backupTableCreated = $db->Execute('CREATE TABLE IF NOT EXISTS phase_builder_narrative_draft_backup LIKE phase_builder_narrative_draft');
    if ($backupTableCreated === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Tab 1 backup table setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    foreach (['phase_builder_narrative_draft', 'phase_builder_narrative_draft_backup'] as $narrativeTable) {
        bx_add_column_if_missing($narrativeTable, 'draft_key', 'CHAR(36) NULL');
        $legacyDraftRows = $db->GetAll(
            "SELECT x_id FROM {$narrativeTable} WHERE draft_key IS NULL OR draft_key = '' ORDER BY x_id"
        );
        foreach ($legacyDraftRows as $legacyDraftRow) {
            $backfilledDraftKey = bx_uuid();
            $backfilled = $db->Execute(
                "UPDATE {$narrativeTable} SET draft_key = ? WHERE x_id = ?",
                [$backfilledDraftKey, (int) $legacyDraftRow['x_id']]
            );
            if ($backfilled === false) {
                $databaseError = trim((string) $db->ErrorMsg());
                throw new RuntimeException('Builder draft identity backfill failed for ' . $narrativeTable . ($databaseError !== '' ? ': ' . $databaseError : '.'));
            }
        }
        $draftKeyRequired = $db->Execute("ALTER TABLE {$narrativeTable} MODIFY draft_key CHAR(36) NOT NULL");
        if ($draftKeyRequired === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Builder draft identity schema update failed for ' . $narrativeTable . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
        $phaseKeyNullable = (string) $db->GetOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, $narrativeTable, 'phase_key']
        );
        if ($phaseKeyNullable === 'NO') {
            $madeNullable = $db->Execute("ALTER TABLE {$narrativeTable} MODIFY phase_key CHAR(36) NULL");
            if ($madeNullable === false) {
                $databaseError = trim((string) $db->ErrorMsg());
                throw new RuntimeException('Tab 1 standalone draft schema update failed for ' . $narrativeTable . ($databaseError !== '' ? ': ' . $databaseError : '.'));
            }
        }
        $normalizedEmptyKeys = $db->Execute("UPDATE {$narrativeTable} SET phase_key = NULL WHERE phase_key = ''");
        if ($normalizedEmptyKeys === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Tab 1 standalone draft key normalization failed for ' . $narrativeTable . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
        bx_add_unique_index_if_missing($narrativeTable, 'draft_key', "uq_{$narrativeTable}_draft_key", "UNIQUE KEY uq_{$narrativeTable}_draft_key (draft_key)");
    }

    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_requirements_analysis (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            analysis_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            source_narrative_hash CHAR(64) NOT NULL,
            analysis_json LONGTEXT NOT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_requirements_updated (updated_at),
            INDEX idx_phase_builder_requirements_source (source_narrative_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_system_architecture (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            architecture_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            source_requirements_hash CHAR(64) NOT NULL,
            architecture_json LONGTEXT NOT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_architecture_updated (updated_at),
            INDEX idx_phase_builder_architecture_source (source_requirements_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_ui_ux_design (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ui_ux_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            source_architecture_hash CHAR(64) NOT NULL,
            ui_ux_json LONGTEXT NOT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_ui_ux_updated (updated_at),
            INDEX idx_phase_builder_ui_ux_source (source_architecture_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_execution_roadmap (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            roadmap_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            source_architecture_hash CHAR(64) NOT NULL,
            roadmap_json LONGTEXT NOT NULL,
            progress_json LONGTEXT NOT NULL,
            stages_json LONGTEXT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_roadmap_updated (updated_at),
            INDEX idx_phase_builder_roadmap_source (source_architecture_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_todo_chat_messages (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NOT NULL,
            phase_id VARCHAR(160) NOT NULL,
            task_id VARCHAR(200) NOT NULL,
            subtask_id VARCHAR(200) NOT NULL,
            todo_id VARCHAR(200) NOT NULL,
            sender VARCHAR(20) NOT NULL DEFAULT 'user',
            message_text TEXT NOT NULL,
            message_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            edited_at TIMESTAMP NULL,
            deleted_at TIMESTAMP NULL,
            created_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_todo_chat_scope (draft_key, todo_id, message_status),
            INDEX idx_phase_builder_todo_chat_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_todo_chat_attachments (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attachment_key CHAR(36) NOT NULL UNIQUE,
            message_key CHAR(36) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            byte_size INT UNSIGNED NOT NULL,
            data_url LONGTEXT NOT NULL,
            attachment_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_todo_chat_attachment_message (message_key, attachment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_todo_chat_consolidations (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            consolidation_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NOT NULL,
            phase_id VARCHAR(160) NOT NULL,
            task_id VARCHAR(200) NOT NULL,
            subtask_id VARCHAR(200) NOT NULL,
            todo_id VARCHAR(200) NOT NULL,
            context_json LONGTEXT NOT NULL,
            ai_result_json LONGTEXT NULL,
            approval_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            created_by_user_key CHAR(36) NULL,
            approved_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            INDEX idx_phase_builder_todo_consolidation_scope (draft_key, todo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_todo_execution_logs (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            execution_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NOT NULL,
            phase_id VARCHAR(160) NOT NULL,
            task_id VARCHAR(200) NOT NULL,
            subtask_id VARCHAR(200) NOT NULL,
            todo_id VARCHAR(200) NOT NULL,
            context_json LONGTEXT NOT NULL,
            result_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'RUNNING',
            rollback_status VARCHAR(20) NOT NULL DEFAULT 'NOT_REQUESTED',
            rollback_result_json LONGTEXT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            rolled_back_at TIMESTAMP NULL,
            INDEX idx_phase_builder_todo_exec_scope (draft_key, todo_id, created_at),
            INDEX idx_phase_builder_todo_exec_status (status, rollback_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach (['phase_builder_requirements_analysis', 'phase_builder_system_architecture', 'phase_builder_ui_ux_design', 'phase_builder_execution_roadmap'] as $artifactTable) {
        bx_add_column_if_missing($artifactTable, 'draft_key', 'CHAR(36) NULL');
        $phaseKeyNullable = (string) $db->GetOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, $artifactTable, 'phase_key']
        );
        if ($phaseKeyNullable === 'NO') {
            $db->Execute("ALTER TABLE {$artifactTable} MODIFY phase_key CHAR(36) NULL");
        }
        $db->Execute("UPDATE {$artifactTable} artifact INNER JOIN phase_builder_narrative_draft draft ON draft.phase_key = artifact.phase_key SET artifact.draft_key = draft.draft_key WHERE artifact.draft_key IS NULL AND artifact.phase_key IS NOT NULL");
        bx_add_unique_index_if_missing($artifactTable, 'draft_key', "uq_{$artifactTable}_draft_key", "UNIQUE KEY uq_{$artifactTable}_draft_key (draft_key)");
    }
    bx_add_column_if_missing('phase_builder_execution_roadmap', 'stages_json', 'LONGTEXT NULL');
    bx_backup_phase_builder_narrative_draft();

    bx_seed_foundation();
}

function bx_seed_foundation(): void
{
    $settings = [
        ['software_name', 'BuilderX', 'general'],
        ['software_description', 'Dynamic Enterprise Form, Workflow, Reporting, and Accounting Builder', 'general'],
        ['version', '0.1.0-foundation', 'general'],
        ['default_time_zone', 'Asia/Manila', 'localization'],
        ['default_language', 'en', 'localization'],
        ['default_currency', 'PHP', 'localization'],
        ['session_timeout_minutes', '120', 'security'],
        ['password_min_length', '10', 'security'],
        ['password_reset_token_minutes', '30', 'security'],
        ['password_history_count', '3', 'security'],
        ['password_expiration_days', '90', 'security'],
        ['account_recovery_email_delivery', 'placeholder', 'security'],
        ['account_recovery_2fa_policy', 'optional-planned', 'security'],
        ['codex_chat_id', builderxConfigValue('codex_chat_id'), 'ai'],
    ];

    foreach ($settings as $setting) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_system_setting WHERE setting_name = ?', [$setting[0]]) === 0) {
            bx_db()->Execute(
                'INSERT INTO builder_system_setting (setting_key, setting_name, setting_value, setting_group) VALUES (?, ?, ?, ?)',
                [bx_uuid(), $setting[0], $setting[1], $setting[2]]
            );
        }
    }

    foreach ([
        ['Administrators', 'Full system administration group.'],
        ['Encoders', 'Data entry group.'],
        ['Auditors', 'Read-only audit and report review group.'],
    ] as $group) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_group WHERE group_name = ?', [$group[0]]) === 0) {
            bx_db()->Execute('INSERT INTO builder_group (group_key, group_name, group_description) VALUES (?, ?, ?)', [bx_uuid(), $group[0], $group[1]]);
        }
    }

    foreach ([
        ['Administrator', 'Full system administration role.'],
        ['Branch Manager', 'Branch-level management role.'],
        ['Project User', 'Project-level application user role.'],
        ['Auditor', 'Audit and report review role.'],
    ] as $role) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_role WHERE role_name = ?', [$role[0]]) === 0) {
            bx_db()->Execute('INSERT INTO builder_role (role_key, role_name, role_description) VALUES (?, ?, ?)', [bx_uuid(), $role[0], $role[1]]);
        }
    }

    foreach ([
        ['system.manage', 'Manage System', 'system'],
        ['settings.manage', 'Manage Settings', 'system'],
        ['audit.view', 'View Audit Logs', 'system'],
        ['users.manage', 'Manage Users', 'system'],
        ['permissions.manage', 'Manage Permissions', 'system'],
        ['branches.manage', 'Manage Branches', 'branch'],
        ['projects.manage', 'Manage Projects', 'project'],
        ['forms.manage', 'Manage Forms', 'form'],
        ['records.view', 'View Records', 'record'],
        ['records.create', 'Create Records', 'record'],
        ['records.update', 'Update Records', 'record'],
        ['records.delete', 'Soft Delete Records', 'record'],
        ['records.restore', 'Restore Records', 'record'],
        ['reports.manage', 'Manage Reports', 'report'],
        ['family_members.report', 'View Family Member Reports', 'report'],
        ['exports.run', 'Run Exports', 'action'],
    ] as $permission) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_permission WHERE permission_code = ?', [$permission[0]]) === 0) {
            bx_db()->Execute(
                'INSERT INTO builder_permission (permission_key, permission_code, permission_name, permission_scope) VALUES (?, ?, ?, ?)',
                [bx_uuid(), $permission[0], $permission[1], $permission[2]]
            );
        }
    }

    if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_branch WHERE branch_code = ?', ['HO']) === 0) {
        bx_db()->Execute(
            'INSERT INTO builder_branch (branch_key, branch_name, branch_code, branch_address, branch_contact) VALUES (?, ?, ?, ?, ?)',
            [bx_uuid(), 'Head Office', 'HO', 'Default head office branch.', '']
        );
    }

    if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_project WHERE project_code = ?', ['CORE']) === 0) {
        $branchKey = (string) bx_db()->GetOne('SELECT branch_key FROM builder_branch WHERE branch_code = ?', ['HO']);
        bx_db()->Execute(
            'INSERT INTO builder_project (project_key, branch_key, project_name, project_code, project_description) VALUES (?, ?, ?, ?, ?)',
            [bx_uuid(), $branchKey, 'Core Platform', 'CORE', 'Default project for BuilderX foundation modules.']
        );
    }

    $adminRole = (string) bx_db()->GetOne('SELECT role_key FROM builder_role WHERE role_name = ?', ['Administrator']);
    $permissions = bx_db()->GetAll('SELECT permission_key FROM builder_permission');
    foreach ($permissions as $permission) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_role_permission WHERE role_key = ? AND permission_key = ?', [$adminRole, $permission['permission_key']]) === 0) {
            bx_db()->Execute('INSERT INTO builder_role_permission (role_key, permission_key) VALUES (?, ?)', [$adminRole, $permission['permission_key']]);
        }
    }

    (new \BuilderX\AI\AiSpecialistRegistry())->ensureSystemSpecialists();
}

function bx_setting(string $name, ?string $default = null): ?string
{
    $value = bx_db()->GetOne(
        "SELECT setting_value FROM builder_system_setting WHERE setting_name = ? AND setting_status = 'ACTIVE'",
        [$name]
    );

    return $value === false || $value === null ? $default : (string) $value;
}

function bx_audit(string $action, string $module, ?string $recordKey = null, array $newValues = [], ?string $reason = null): void
{
    bx_db()->Execute(
        'INSERT INTO builder_audit_log (audit_key, user_key, action, module, record_key, new_values, ip_address, user_agent, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            bx_uuid(),
            $_SESSION['builderx_user_key'] ?? null,
            $action,
            $module,
            $recordKey,
            $newValues ? json_encode($newValues) : null,
            bx_client_ip(),
            bx_user_agent(),
            $reason,
        ]
    );
}

function bx_user_has_permission(?array $user, string $permissionCode): bool
{
    if (!$user || $permissionCode === '') {
        return false;
    }

    return (int) bx_db()->GetOne(
        "SELECT COUNT(*)
        FROM builder_user_role ur
        JOIN builder_role r ON r.role_key = ur.role_key AND r.role_status = 'ACTIVE'
        JOIN builder_role_permission rp ON rp.role_key = r.role_key
        JOIN builder_permission p ON p.permission_key = rp.permission_key AND p.permission_status = 'ACTIVE'
        WHERE ur.user_key = ? AND p.permission_code = ?",
        [$user['user_key'], $permissionCode]
    ) > 0;
}

function bx_mask_email(?string $email): string
{
    $email = trim((string) $email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $prefix = substr($local, 0, 1);

    return $prefix . '***@' . $domain;
}

function bx_mask_phone(?string $phone): string
{
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if (strlen($digits) <= 4) {
        return '***';
    }

    return '***' . substr($digits, -4);
}

function bx_count(string $table, string $where = '1=1'): int
{
    return (int) bx_db()->GetOne("SELECT COUNT(*) FROM {$table} WHERE {$where}");
}

function bx_current_user(): ?array
{
    if (empty($_SESSION['builderx_user_key'])) {
        return null;
    }

    $user = bx_db()->GetRow(
        "SELECT * FROM builder_user WHERE user_key = ? AND user_status = 'ACTIVE'",
        [$_SESSION['builderx_user_key']]
    );

    return $user ?: null;
}

function bx_is_admin(array $user): bool
{
    return (int) bx_db()->GetOne(
        "SELECT COUNT(*)
        FROM builder_user_role ur
        JOIN builder_role r ON r.role_key = ur.role_key
        WHERE ur.user_key = ? AND r.role_name = 'Administrator' AND r.role_status = 'ACTIVE'",
        [$user['user_key']]
    ) > 0;
}

function bx_login(string $login, string $password): bool
{
    $user = bx_db()->GetRow(
        "SELECT * FROM builder_user WHERE (user_login = ? OR user_email = ?) AND user_status IN ('ACTIVE','LOCKED')",
        [$login, $login]
    );

    if (!$user) {
        bx_login_history(null, $login, 'FAILED', 'User not found.');
        return false;
    }

    if ($user['user_status'] === 'LOCKED') {
        bx_login_history($user['user_key'], $login, 'LOCKED', 'Account is locked.');
        return false;
    }

    if (!password_verify($password, $user['user_password_hash'])) {
        $failed = (int) $user['user_failed_login_count'] + 1;
        $status = $failed >= 5 ? 'LOCKED' : 'ACTIVE';
        bx_db()->Execute('UPDATE builder_user SET user_failed_login_count = ?, user_status = ? WHERE user_key = ?', [$failed, $status, $user['user_key']]);
        bx_login_history($user['user_key'], $login, $status === 'LOCKED' ? 'LOCKED' : 'FAILED', 'Invalid password.');
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['builderx_user_key'] = $user['user_key'];
    $_SESSION['builderx_user_name'] = $user['user_name'];
    $_SESSION['builderx_session_key'] = bx_uuid();

    bx_db()->Execute('UPDATE builder_user SET user_failed_login_count = 0, user_last_login_at = CURRENT_TIMESTAMP WHERE user_key = ?', [$user['user_key']]);
    bx_db()->Execute(
        'INSERT INTO builder_user_session (session_key, user_key, session_token_hash, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        [$_SESSION['builderx_session_key'], $user['user_key'], hash('sha256', session_id()), bx_client_ip(), bx_user_agent(), (int) bx_setting('session_timeout_minutes', '120')]
    );
    bx_login_history($user['user_key'], $login, 'SUCCESS', null);
    bx_audit('LOGIN', 'authentication', $user['user_key']);

    return true;
}

function bx_login_history(?string $userKey, string $login, string $status, ?string $reason): void
{
    bx_db()->Execute(
        'INSERT INTO builder_user_login_history (login_key, user_key, user_login, login_status, ip_address, user_agent, failure_reason) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [bx_uuid(), $userKey, $login, $status, bx_client_ip(), bx_user_agent(), $reason]
    );
}

function bx_recovery_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function bx_request_password_reset(string $login): ?string
{
    $identity = trim($login);
    if ($identity === '') {
        bx_flash('Enter your username or email address.', 'error');
        return null;
    }

    $user = bx_db()->GetRow(
        "SELECT * FROM builder_user WHERE (user_login = ? OR user_email = ?) AND user_status IN ('ACTIVE','LOCKED')",
        [$identity, $identity]
    );

    if (!$user) {
        bx_audit('PASSWORD_RESET_REQUEST_UNKNOWN', 'authentication', null, ['identity' => $identity], 'Password reset requested for an unknown account.');
        bx_flash('If the account exists, a recovery link is ready for email delivery.', 'success');
        return null;
    }

    bx_db()->Execute(
        "UPDATE builder_user_password_reset
        SET reset_status = 'REVOKED'
        WHERE user_key = ? AND reset_status = 'PENDING'",
        [$user['user_key']]
    );

    $token = bin2hex(random_bytes(32));
    $tokenMinutes = max(5, (int) bx_setting('password_reset_token_minutes', '30'));
    bx_db()->Execute(
        "INSERT INTO builder_user_password_reset (
            reset_key,
            user_key,
            reset_token_hash,
            requested_ip,
            requested_user_agent,
            email_delivery_status,
            email_verification_required,
            two_factor_required,
            expires_at
        ) VALUES (?, ?, ?, ?, ?, 'PLACEHOLDER', ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
        [
            bx_uuid(),
            $user['user_key'],
            bx_recovery_token_hash($token),
            bx_client_ip(),
            bx_user_agent(),
            empty($user['user_email_verified_at']) ? 1 : 0,
            (int) $user['user_two_factor_required'] === 1 ? 1 : 0,
            $tokenMinutes,
        ]
    );

    bx_audit('PASSWORD_RESET_REQUEST', 'authentication', $user['user_key'], [
        'user_login' => $user['user_login'],
        'email_delivery_status' => 'PLACEHOLDER',
        'expires_in_minutes' => $tokenMinutes,
    ], 'Password reset link generated.');
    bx_flash('If the account exists, a recovery link is ready for email delivery.', 'success');

    return $token;
}

function bx_password_was_recently_used(string $userKey, string $password): bool
{
    $historyCount = max(0, (int) bx_setting('password_history_count', '3'));
    if ($historyCount === 0) {
        return false;
    }

    $rows = bx_db()->GetAll(
        'SELECT password_hash FROM builder_user_password_history WHERE user_key = ? ORDER BY created_at DESC, x_id DESC LIMIT ' . $historyCount,
        [$userKey]
    );

    foreach ($rows as $row) {
        if (password_verify($password, (string) $row['password_hash'])) {
            return true;
        }
    }

    $currentHash = (string) bx_db()->GetOne('SELECT user_password_hash FROM builder_user WHERE user_key = ?', [$userKey]);
    return $currentHash !== '' && password_verify($password, $currentHash);
}

function bx_remember_password_history(string $userKey, string $passwordHash, string $reason): void
{
    bx_db()->Execute(
        'INSERT INTO builder_user_password_history (history_key, user_key, password_hash, changed_by_key, change_reason) VALUES (?, ?, ?, ?, ?)',
        [bx_uuid(), $userKey, $passwordHash, $_SESSION['builderx_user_key'] ?? null, $reason]
    );
}

function bx_reset_password_with_token(string $token, string $password, string $passwordConfirm): bool
{
    $token = trim($token);
    if ($token === '') {
        bx_flash('Password reset token is required.', 'error');
        return false;
    }

    if (strlen($password) < (int) bx_setting('password_min_length', '10')) {
        bx_flash('Password is shorter than the configured minimum length.', 'error');
        return false;
    }

    if ($password !== $passwordConfirm) {
        bx_flash('Password confirmation does not match.', 'error');
        return false;
    }

    $reset = bx_db()->GetRow(
        "SELECT r.*, u.user_login, u.user_status
        FROM builder_user_password_reset r
        JOIN builder_user u ON u.user_key = r.user_key
        WHERE r.reset_token_hash = ?",
        [bx_recovery_token_hash($token)]
    );

    if (!$reset || $reset['reset_status'] !== 'PENDING') {
        bx_flash('Password reset link is invalid or already used.', 'error');
        return false;
    }

    if (strtotime((string) $reset['expires_at']) < time()) {
        bx_db()->Execute(
            "UPDATE builder_user_password_reset SET reset_status = 'EXPIRED' WHERE reset_key = ?",
            [$reset['reset_key']]
        );
        bx_audit('PASSWORD_RESET_EXPIRED', 'authentication', $reset['user_key'], ['user_login' => $reset['user_login']], 'Expired password reset link used.');
        bx_flash('Password reset link has expired. Request a new recovery link.', 'error');
        return false;
    }

    if (bx_password_was_recently_used((string) $reset['user_key'], $password)) {
        bx_audit('PASSWORD_RESET_REJECTED', 'authentication', $reset['user_key'], ['reason' => 'password-history'], 'Password reset rejected by history policy.');
        bx_flash('Choose a password that was not used recently.', 'error');
        return false;
    }

    $passwordHash = bx_password_hash($password);
    $expirationDays = max(0, (int) bx_setting('password_expiration_days', '90'));
    $expiresSql = $expirationDays > 0 ? 'DATE_ADD(NOW(), INTERVAL ' . $expirationDays . ' DAY)' : 'NULL';

    bx_db()->Execute(
        "UPDATE builder_user
        SET user_password_hash = ?,
            user_password_changed_at = CURRENT_TIMESTAMP,
            user_password_expires_at = {$expiresSql},
            user_failed_login_count = 0,
            user_status = CASE WHEN user_status = 'LOCKED' THEN 'ACTIVE' ELSE user_status END
        WHERE user_key = ?",
        [$passwordHash, $reset['user_key']]
    );
    bx_remember_password_history((string) $reset['user_key'], $passwordHash, 'account-recovery');

    bx_db()->Execute(
        "UPDATE builder_user_password_reset
        SET reset_status = 'USED',
            used_ip = ?,
            used_user_agent = ?,
            used_at = CURRENT_TIMESTAMP
        WHERE reset_key = ?",
        [bx_client_ip(), bx_user_agent(), $reset['reset_key']]
    );
    bx_db()->Execute(
        "UPDATE builder_user_password_reset SET reset_status = 'REVOKED' WHERE user_key = ? AND reset_status = 'PENDING'",
        [$reset['user_key']]
    );

    bx_audit('PASSWORD_RESET_COMPLETE', 'authentication', $reset['user_key'], [
        'user_login' => $reset['user_login'],
        'password_expires_in_days' => $expirationDays,
    ], 'Password reset completed through account recovery.');
    bx_flash('Password reset complete. You can sign in with the new password.', 'success');

    return true;
}

function bx_logout(): void
{
    if (!empty($_SESSION['builderx_session_key'])) {
        bx_db()->Execute(
            "UPDATE builder_user_session SET session_status = 'REVOKED', revoked_at = CURRENT_TIMESTAMP WHERE session_key = ?",
            [$_SESSION['builderx_session_key']]
        );
    }

    bx_audit('LOGOUT', 'authentication', $_SESSION['builderx_user_key'] ?? null);
    unset($_SESSION['builderx_user_key'], $_SESSION['builderx_user_name'], $_SESSION['builderx_session_key']);
}

function bx_create_initial_admin(array $input): bool
{
    if (bx_count('builder_user') > 0) {
        bx_flash('Initial administrator already exists.', 'error');
        return false;
    }

    if (strlen($input['password']) < (int) bx_setting('password_min_length', '10')) {
        bx_flash('Password is shorter than the configured minimum length.', 'error');
        return false;
    }

    if ($input['password'] !== $input['password_confirm']) {
        bx_flash('Password confirmation does not match.', 'error');
        return false;
    }

    $userKey = bx_uuid();
    bx_db()->Execute(
        "INSERT INTO builder_user (user_key, user_login, user_password_hash, user_name, user_email, user_status, user_password_changed_at)
        VALUES (?, ?, ?, ?, ?, 'ACTIVE', CURRENT_TIMESTAMP)",
        [$userKey, $input['login'], bx_password_hash($input['password']), $input['name'], $input['email']]
    );

    foreach (['Administrator'] as $roleName) {
        $roleKey = (string) bx_db()->GetOne('SELECT role_key FROM builder_role WHERE role_name = ?', [$roleName]);
        bx_db()->Execute('INSERT IGNORE INTO builder_user_role (user_key, role_key) VALUES (?, ?)', [$userKey, $roleKey]);
    }

    $groupKey = (string) bx_db()->GetOne('SELECT group_key FROM builder_group WHERE group_name = ?', ['Administrators']);
    bx_db()->Execute('INSERT IGNORE INTO builder_user_group (user_key, group_key) VALUES (?, ?)', [$userKey, $groupKey]);

    $branchKey = (string) bx_db()->GetOne('SELECT branch_key FROM builder_branch WHERE branch_code = ?', ['HO']);
    $projectKey = (string) bx_db()->GetOne('SELECT project_key FROM builder_project WHERE project_code = ?', ['CORE']);
    bx_db()->Execute('INSERT IGNORE INTO builder_user_branch (user_key, branch_key) VALUES (?, ?)', [$userKey, $branchKey]);
    bx_db()->Execute('INSERT IGNORE INTO builder_user_project (user_key, project_key) VALUES (?, ?)', [$userKey, $projectKey]);

    bx_audit('CREATE', 'builder_user', $userKey, ['user_login' => $input['login'], 'role' => 'Administrator'], 'Initial administrator created.');
    bx_flash('Initial administrator created. You can now sign in.', 'success');

    return true;
}

bx_schema();
