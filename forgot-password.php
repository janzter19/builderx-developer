<?php
declare(strict_types=1);

require_once __DIR__ . '/app/foundation.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$recoveryToken = null;
$showRecoveryToken = (string) getenv('BUILDERX_SHOW_RESET_LINK') === '1';

if ($requestMethod === 'POST') {
    bx_verify_csrf();
    if ((string) ($_POST['action'] ?? '') === 'request_password_reset') {
        $recoveryToken = bx_request_password_reset((string) ($_POST['login'] ?? ''));
    }
}

$flash = bx_take_flash();
$softwareName = bx_setting('software_name', 'BuilderX');
$tokenMinutes = (int) bx_setting('password_reset_token_minutes', '30');
$passwordHistory = (int) bx_setting('password_history_count', '3');
$passwordExpiration = (int) bx_setting('password_expiration_days', '90');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password - <?= bx_h($softwareName) ?></title>
    <style>
        :root {
            --ink: #1e293b;
            --muted: #64748b;
            --line: #d8dee9;
            --panel: #ffffff;
            --bg: #f6f8fb;
            --accent: #0f766e;
            --danger: #b91c1c;
            --ok: #15803d;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font-family: Arial, Helvetica, sans-serif; letter-spacing: 0; }
        .shell { width: min(900px, 100%); margin: 0 auto; padding: 32px 20px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 22px; }
        .grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start; }
        h1 { margin: 0 0 8px; font-size: 28px; line-height: 1.2; }
        h2 { margin: 0 0 10px; font-size: 16px; }
        p, li { color: var(--muted); line-height: 1.5; }
        label { display: block; margin: 14px 0 6px; color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
        input { width: 100%; min-height: 42px; border: 1px solid var(--line); border-radius: 6px; padding: 9px 11px; font: inherit; }
        button, .button { display: inline-flex; justify-content: center; align-items: center; min-height: 40px; margin-top: 16px; border: 0; border-radius: 6px; background: var(--accent); color: #fff; padding: 9px 14px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button.secondary { background: #334155; }
        .flash { margin-bottom: 14px; padding: 11px 13px; border-radius: 6px; border: 1px solid var(--line); background: #f8fafc; }
        .flash.success { color: var(--ok); background: #dcfce7; border-color: #bbf7d0; }
        .flash.error { color: var(--danger); background: #fee2e2; border-color: #fecaca; }
        .notice { margin-top: 16px; padding: 12px; border: 1px solid #bfdbfe; border-radius: 8px; background: #eff6ff; }
        .notice code { display: block; margin-top: 8px; overflow-wrap: anywhere; color: #1d4ed8; }
        ul { margin: 0; padding-left: 18px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        @media (max-width: 760px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <main class="shell">
        <?php if ($flash): ?>
            <div class="flash <?= bx_h($flash['type']) ?>"><?= bx_h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="grid">
            <section class="panel">
                <h1>Recover Account</h1>
                <p>Enter your username or email. The response is intentionally generic so account existence is not exposed.</p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= bx_h(bx_csrf_token()) ?>">
                    <input type="hidden" name="action" value="request_password_reset">
                    <label for="login">Username or Email</label>
                    <input id="login" name="login" autocomplete="username" required>
                    <button type="submit">Prepare Recovery Link</button>
                </form>

                <?php if ($recoveryToken && $showRecoveryToken): ?>
                    <div class="notice">
                        <strong>Local reset-link validation</strong>
                        <p>The mail service is not connected in this phase. Use this generated reset link for local validation.</p>
                        <code><?= bx_h('./reset-password.php?token=' . rawurlencode($recoveryToken)) ?></code>
                    </div>
                <?php elseif ($recoveryToken): ?>
                    <div class="notice">
                        <strong>Recovery link prepared</strong>
                        <p>The reset link was generated. Configure mail delivery before using account recovery in production, or reset the user password from Administrator Users.</p>
                    </div>
                <?php endif; ?>

                <div class="actions">
                    <a class="button secondary" href="./index.php">Back to Login</a>
                </div>
            </section>

            <aside class="panel">
                <h2>Recovery Requirements</h2>
                <ul>
                    <li>Reset tokens expire after <?= $tokenMinutes ?> minutes.</li>
                    <li>The last <?= $passwordHistory ?> password<?= $passwordHistory === 1 ? '' : 's' ?> cannot be reused.</li>
                    <li>New passwords expire after <?= $passwordExpiration ?> days.</li>
                    <li>Email verification is tracked and currently enforced as a placeholder flag.</li>
                    <li>2FA and recovery codes are planned flags for later authentication phases.</li>
                    <li>Recovery requests and completion events are written to the audit log.</li>
                </ul>
            </aside>
        </div>
    </main>
</body>
</html>
