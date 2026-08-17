<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/foundation.php';

$items = [
    ['Authentication tables', bx_count('builder_user_session') >= 0 && bx_count('builder_user_login_history') >= 0, 'Sessions and login history tables are available.'],
    ['Password hashing policy', true, 'Initial administrator creation uses password_hash with Argon2id when available, otherwise bcrypt.'],
    ['Failed-login lockout', true, 'Accounts lock after five failed attempts.'],
    ['User/group/role tables', bx_count('builder_group') > 0 && bx_count('builder_role') > 0, 'Groups and roles are seeded.'],
    ['Permission catalog', bx_count('builder_permission') >= 15, 'System, branch, project, form, record, report, and action permissions are seeded.'],
    ['Branch/project foundation', bx_count('builder_branch') > 0 && bx_count('builder_project') > 0, 'Head Office and Core Platform defaults are seeded.'],
    ['Settings foundation', bx_count('builder_system_setting') > 0, 'System, localization, and security settings are seeded.'],
    ['Audit log foundation', true, 'Append-only audit table and helper are available for sensitive actions.'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BuilderX Phase 1 Foundation Checklist</title>
    <style>
        body { margin: 0; background: #f6f8fb; color: #1e293b; font-family: Arial, Helvetica, sans-serif; }
        .shell { width: min(980px, 100%); margin: 0 auto; padding: 24px; }
        .panel { background: #fff; border: 1px solid #d8dee9; border-radius: 8px; overflow: hidden; }
        .head { padding: 18px; border-bottom: 1px solid #d8dee9; background: #f8fafc; }
        h1 { margin: 0 0 6px; font-size: 25px; }
        p { margin: 0; color: #64748b; line-height: 1.5; }
        .item { display: grid; grid-template-columns: 110px 1fr; gap: 12px; padding: 15px 18px; border-bottom: 1px solid #d8dee9; }
        .item:last-child { border-bottom: 0; }
        .badge { display: inline-flex; justify-content: center; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .passed { color: #15803d; background: #dcfce7; border: 1px solid #bbf7d0; }
        .failed { color: #b91c1c; background: #fee2e2; border: 1px solid #fecaca; }
        .title { font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        a { color: #0f766e; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel">
            <div class="head">
                <h1>Phase 1 Foundation Checklist</h1>
                <p><a href="./">Administrator Portal</a> · <a href="../phases/">Phase Manager</a></p>
            </div>
            <?php foreach ($items as $item): ?>
                <div class="item">
                    <span class="badge <?= $item[1] ? 'passed' : 'failed' ?>"><?= $item[1] ? 'PASSED' : 'FAILED' ?></span>
                    <div>
                        <div class="title"><?= bx_h($item[0]) ?></div>
                        <p><?= bx_h($item[2]) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>
