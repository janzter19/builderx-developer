# BuilderX Installation Runbook for Codex

This is an execution runbook for installing BuilderX on a new Linux computer.
Codex must follow the phases in order, verify each phase, and stop when a
required dependency, credential, or approved installer package is unavailable.

There are two different operations:

- Source setup: clone and prepare the canonical BuilderX developer source.
- New project setup: use the separate _installer application to create a
  project folder, database, tables, administrator, and empty business workspace.

Git clone alone is not a complete application installation.

## Safety rules

Codex must:

1. Never print, commit, or send passwords, API keys, database credentials,
   browser tokens, or private SSH keys.
2. Ask before deleting, overwriting, moving, or resetting an existing project,
   database, or configuration file.
3. Never run destructive commands such as git reset --hard, rm -rf,
   DROP DATABASE, or equivalent without approval for the exact target.
4. Keep live configuration and runtime data outside Git.
5. Use /var/www/html/developer unless the user chooses another path and the
   bridge service is updated too.
6. Report verification output instead of claiming success from source inspection.

## Required decisions

Before making changes, Codex must confirm:

- fresh computer or existing-installation migration;
- new project or existing-project restoration;
- public URL/path;
- database administrator credentials, entered locally rather than in chat;
- first BuilderX administrator details, entered locally;
- GitHub authentication and repository access.

For a new project, the target folder must be new unless the user explicitly
approves a migration or recovery procedure.

## Phase 1: Inspect prerequisites

Run read-only checks:

~~~bash
uname -a
command -v git node npm php apache2 mysql jq code || true
git --version
node --version
npm --version
php --version
php -m | sort
systemctl is-active apache2 2>/dev/null || true
systemctl is-active mysql 2>/dev/null || true
~~~

Required baseline:

- Linux with Apache or another supported web server;
- PHP 8.2 or later with mysqli, openssl, json, and session;
- MySQL or a supported compatible database;
- Node.js, npm, Git, and jq;
- VS Code with the authenticated Codex/ChatGPT extension for bridge use.

Typical Ubuntu prerequisites are:

~~~bash
sudo apt update
sudo apt install -y git nodejs npm apache2 mysql-server \
  php php-cli php-mysql php-curl php-mbstring php-xml php-zip jq gh
~~~

Install only missing packages and re-run the checks.

## Phase 2: Clone the canonical source

~~~bash
gh auth status || gh auth login
test ! -e /var/www/html/developer
sudo install -d -o "$(id -un)" -g "$(id -gn)" /var/www/html/developer
git clone https://github.com/janzter19/builderx-developer.git \
  /var/www/html/developer
cd /var/www/html/developer
git status --short --branch
git log -1 --oneline
~~~

The worktree must be clean. Do not create phases/config.local.php, .env,
AI runtime files, uploads, logs, or database exports inside the Git source.

## Phase 3: Build the frontend

~~~bash
cd /var/www/html/developer
npm ci --prefix frontend
npm run build --prefix frontend
test -f frontend/dist/index.html
test -f frontend/dist/.vite/manifest.json
~~~

Do not run npm audit fix automatically; it may change dependencies and source
state. Report audit warnings separately.

## Phase 4: Configure the web application

Use the deployment examples in:

- deployment/apache/
- deployment/nginx/
- deployment/php/
- install.md

The web server must read application source files and write only approved
runtime folders. Do not recursively change the entire document root to
www-data without checking existing ownership first.

~~~bash
test -d /var/www/html/developer/storage
test -d /var/www/html/developer/phases
test -f /var/www/html/developer/index.php
stat -c '%U:%G %a %n' /var/www/html/developer \
  /var/www/html/developer/storage
~~~

## Phase 4A: Apply folder permissions

Use a shared group for the web application and the local BuilderX bridge. Do
not recursively change ownership or permissions on the whole document root.

~~~bash
APP_ROOT=/var/www/html/developer
WEB_USER=www-data
SHARED_GROUP=builderx

sudo groupadd --system "$SHARED_GROUP" 2>/dev/null || true
sudo usermod -aG "$SHARED_GROUP" "$WEB_USER"
sudo usermod -aG "$SHARED_GROUP" "$(id -un)"
~~~

After adding the current user to the group, start a new login session or fully
restart VS Code so the new group is active.

For a fresh installation, create only the application runtime folders that
need web-server writes:

~~~bash
RUNTIME_DIRS=(
  storage/logs
  storage/exports
  storage/imports
  storage/queue
  storage/reports
  storage/uploads
  storage/synchronization
  storage/phase-note-attachments
  storage/ai-jobs
  storage/ai-memory
  storage/ai-operations
  storage/codex-communication
  storage/coordinator-context
  storage/sharingan-context
)

for relative_path in "${RUNTIME_DIRS[@]}"; do
  sudo install -d -o "$WEB_USER" -g "$SHARED_GROUP" -m 2770 \
    "$APP_ROOT/$relative_path"
done
~~~

Backups and audit logs are more restricted and must not be part of the normal
MCP file allowlist:

~~~bash
APP_ROOT=/var/www/html/developer
WEB_USER=www-data
sudo install -d -o "$WEB_USER" -g "$WEB_USER" -m 0700 \
  "$APP_ROOT/storage/backups" "$APP_ROOT/storage/audit"
~~~

Repair an existing approved runtime folder narrowly, not the entire product:

~~~bash
APP_ROOT=/var/www/html/developer
WEB_USER=www-data
SHARED_GROUP=builderx
RUNTIME_PATH="$APP_ROOT/storage/phase-note-attachments"
sudo chown -R "$WEB_USER:$SHARED_GROUP" "$RUNTIME_PATH"
sudo find "$RUNTIME_PATH" -type d -exec chmod 2770 {} +
sudo find "$RUNTIME_PATH" -type f -exec chmod 0660 {} +
~~~

Local configuration must not be committed or publicly readable:

~~~bash
APP_ROOT=/var/www/html/developer
WEB_USER=www-data
sudo chown "$WEB_USER:$WEB_USER" "$APP_ROOT/phases/config.local.php"
sudo chmod 0640 "$APP_ROOT/phases/config.local.php"
~~~

Apply the same owner and mode to the installer local configuration when the
separate installer is present:

~~~bash
sudo chown www-data:www-data /var/www/html/_installer/config.local.php
sudo chmod 0640 /var/www/html/_installer/config.local.php
~~~

Verify the permission boundary:

~~~bash
APP_ROOT=/var/www/html/developer
WEB_USER=www-data
id "$WEB_USER"
id "$(id -un)"
stat -c '%U:%G %a %n' \
  "$APP_ROOT" \
  "$APP_ROOT/storage" \
  "$APP_ROOT/storage/logs" \
  "$APP_ROOT/storage/phase-note-attachments" \
  "$APP_ROOT/storage/backups" \
  "$APP_ROOT/storage/audit" \
  "$APP_ROOT/phases/config.local.php"
~~~

Expected policy:

- source files: readable by the web server, but not writable by it;
- normal runtime directories: www-data:builderx, mode 2770;
- normal runtime files: www-data:builderx, mode 0660;
- backups and audit: www-data:www-data, mode 0700;
- local configuration: www-data:www-data, mode 0640;
- storage, configuration, communication, and AI-memory paths denied from
  public directory browsing and direct download.

## Phase 5: Locate and configure the installer

The current Git repository contains the developer source. The installer is a
separate application at:

~~~text
/var/www/html/_installer
~~~

If that folder is missing, Codex must stop and ask for the approved installer
package or installer repository. It must not use an old or unknown template.

When the installer is available:

1. Copy config.example.php to the protected local configuration file.
2. Set database host, port, administrator username, and password locally.
3. Set source_project to /var/www/html/developer.
4. Protect the file from public download.
5. Open http://host/_installer/.
6. Run installer preflight.
7. Choose Refresh Clean Template.

Preflight must pass:

- Git source/template alignment;
- clean template;
- MySQL administrator account;
- PHP runtime and folder permissions.

## Phase 6: Create a new project

Use this phase only for a new project:

1. Open http://host/_installer/.
2. Enter a new project name and unused target folder.
3. Enter the database name or explicitly choose existing-database mode.
4. Enter the first BuilderX administrator details locally.
5. Submit once.
6. Confirm the folder, database, administrator, frontend, and system records.
7. Confirm the business workspace is empty except for required system data.

The installer handles database provisioning, schema/tables, project
configuration, runtime directories, permissions, administrator setup, and
initial Phase Manager records.

## Phase 7: Restore an existing project

Do not clone Git over an existing live project. A migration separately requires:

- a verified database backup and restore;
- approved uploads, AI memory, and other runtime data;
- protected phases/config.local.php;
- source-revision compatibility verification;
- a rollback plan.

Git is for source. It is not a database backup or a replacement for runtime
data.

## Phase 8: Install the VS Code/Codex bridge

~~~bash
cd /var/www/html/developer/tools/builderx-bridge
npm run check
cd extension
npx --yes @vscode/vsce package \
  --out ../builderx-companion-1.5.1.vsix
code --install-extension ../builderx-companion-1.5.1.vsix --force
cd ..
systemctl --user link builderx-bridge.service
systemctl --user daemon-reload
systemctl --user enable --now builderx-bridge.service
~~~

If node is not at /usr/local/bin/node, update the service ExecStart path to
the result of command -v node.

Authenticate the Codex/ChatGPT VS Code extension in the visible VS Code
session. The bridge does not use an OpenAI API key or browser cookies.

## Phase 9: Verify

~~~bash
cd /var/www/html/developer
git status --short --branch
php -l index.php
php -l phases/index.php
npm run build --prefix frontend

curl --fail --silent --show-error \
  'http://127.0.0.1:43127/health?workspace_root=%2Fvar%2Fwww%2Fhtml%2Fdeveloper' \
  | jq -e '
    .ok == true and
    .code_ready == true and
    .context_ready == true and
    .companion_extension_installed == true and
    .active_thread_busy == false
  '
~~~

For a new project, also verify:

~~~bash
test -f /var/www/html/<project-folder>/phases/config.local.php
test -f /var/www/html/<project-folder>/frontend/dist/index.html
stat -c '%U:%G %a %n' /var/www/html/<project-folder>/storage
~~~

Installation is complete only when the browser loads the project, the
database-backed Phase Manager loads, runtime writes use the intended owner and
group, and the bridge health check returns ready.

## Completion report

Codex must report:

- repository and source commit;
- application path and public URL;
- frontend build result;
- database/project result without credentials;
- runtime ownership and permission result;
- bridge version and health result;
- warnings and remaining manual actions.

Never include passwords, API keys, session tokens, or private configuration
contents in the report.
