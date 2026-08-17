<?php
declare(strict_types=1);

require_once __DIR__ . '/app/foundation.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if ($requestMethod === 'POST') {
    bx_verify_csrf();
    if ((string) ($_POST['action'] ?? '') === 'reset_password') {
        if (bx_reset_password_with_token($token, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirm'] ?? ''))) {
            header('Location: ./index.php');
            exit;
        }
    }
}

$flash = bx_take_flash();
$softwareName = bx_setting('software_name', 'BuilderX');
$passwordMinimum = (int) bx_setting('password_min_length', '10');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password - <?= bx_h($softwareName) ?></title>
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
        .shell { width: min(620px, 100%); margin: 0 auto; padding: 32px 20px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 22px; }
        h1 { margin: 0 0 8px; font-size: 28px; line-height: 1.2; }
        p { color: var(--muted); line-height: 1.5; }
        label { display: block; margin: 14px 0 6px; color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
        input { width: 100%; min-height: 42px; border: 1px solid var(--line); border-radius: 6px; padding: 9px 11px; font: inherit; }
        button, .button { display: inline-flex; justify-content: center; align-items: center; min-height: 40px; margin-top: 16px; border: 0; border-radius: 6px; background: var(--accent); color: #fff; padding: 9px 14px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button.secondary { background: #334155; }
        .flash { margin-bottom: 14px; padding: 11px 13px; border-radius: 6px; border: 1px solid var(--line); background: #f8fafc; }
        .flash.success { color: var(--ok); background: #dcfce7; border-color: #bbf7d0; }
        .flash.error { color: var(--danger); background: #fee2e2; border-color: #fecaca; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
    </style>
</head>
<body>
    <main class="shell">
        <?php if ($flash): ?>
            <div class="flash <?= bx_h($flash['type']) ?>"><?= bx_h($flash['message']) ?></div>
        <?php endif; ?>

        <section class="panel">
            <h1>Reset Password</h1>
            <p>Choose a new password with at least <?= $passwordMinimum ?> characters. Recently used passwords are rejected.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= bx_h(bx_csrf_token()) ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="token" value="<?= bx_h($token) ?>">
                <label for="password">New Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="<?= $passwordMinimum ?>" required>
                <label for="password_confirm">Confirm New Password</label>
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="<?= $passwordMinimum ?>" required>
                <button type="submit">Reset Password</button>
            </form>

            <div class="actions">
                <a class="button secondary" href="./forgot-password.php">Request New Link</a>
                <a class="button secondary" href="./index.php">Back to Login</a>
            </div>
        </section>
    </main>
</body>
</html>
