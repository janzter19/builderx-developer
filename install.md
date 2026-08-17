# BuilderX Product Installation

This document defines the initial installation and permission model for the
BuilderX product and its hybrid file-operation architecture.

## Git source and installer release boundary

`/var/www/html/developer` is the canonical Git worktree. The installer must
consume a clean committed revision, not an uncommitted workspace or runtime
folder. Before refreshing the installer template, commit source changes and
run:

```bash
git -C /var/www/html/developer status --short --untracked-files=all
/var/www/html/developer/scripts/build-installer-release.sh
```

The release builder archives the committed Git revision, installs frontend
dependencies only in a temporary staging directory, builds `frontend/dist`,
validates the current `tools/builderx-bridge`, and writes a release manifest.
It rejects uncommitted source and local configuration files. Runtime data,
database credentials, AI keys, logs, uploads, and backups are never source
release inputs.

The external installer records the Git revision in
`builderx-source-manifest.json` and refuses to create a project when the clean
template does not match the current committed source. Use the local
`Update Installer Template` action only after the Git worktree is clean.

## Architecture

```text
Phase Manager browser
  |
  v
VS Code BuilderX bridge
  |
  +-- POST /handoff and GET /result --> visible VS Code Codex Chat
```

The VS Code BuilderX bridge handles the explicit browser-to-chat handoff. The
separate MCP Server handles low-risk file access directly. The Phase Manager
owns approval-required operations and all database, backup, and audit
behavior.

## Handler policy

| Operation | Handler | Rule |
|---|---|---|
| List/search files | MCP Server | Only inside configured allowlisted roots |
| Read files | MCP Server | Reject traversal, symlink escapes, secrets, and oversized files |
| Create/update files | MCP Server | Atomic writes using the shared group; preserve `www-data` ownership |
| Delete/move sensitive files | Phase Manager | Require explicit approval before execution |
| Database changes | Phase Manager | Require authorization, validation, transaction, and audit entry |
| Backups | Phase Manager | MCP cannot invoke or delete backups |
| Audit logs | Phase Manager | Append-only from the MCP boundary; MCP cannot modify or delete them |

The MCP Server must not expose arbitrary shell execution, unrestricted paths,
database credentials, backup access, or audit-log write access. The Phase
Manager browser send-request is handled separately by the VS Code BuilderX
bridge described below.

## AI runtime boundary

Phase Builder sends explicit requests through the installed VS Code BuilderX
bridge at `http://127.0.0.1:43127`. The bridge delivers the request to the
visible VS Code Codex Chat session using the authenticated ChatGPT account.
Before sending, the application checks the workspace, runtime, companion
extension, and `active_thread_busy` state. It sends `POST /handoff` with only
`workspace_root` and `command`, then may consume the correlated `/events` SSE
stream or read the backward-compatible `/result` response.
Bounded specialist work uses `POST /handoff-result` with the same two fields;
the bridge creates `.builderx/runtime/tasks/{request_id}/result.json` with a
`// BUILDERX_RESULT` placeholder and accepts only a valid JSON object written
to that task file. The chat response is not parsed as the specialist result.
It does not use the Codex App Server, `codex exec`, an OpenAI API key, a
browser token, or a background worker.

## VS Code BuilderX bridge installation

The installed BuilderX bridge is version `1.5.1` and is the only supported AI
communication path for Phase Builder. It must run against the project
workspace `/var/www/html/developer`.

Build and install the companion extension from the extension manifest, not the
bridge server root:

```bash
cd /var/www/html/developer/tools/builderx-bridge
npm run check
cd extension
npx --yes @vscode/vsce package --out ../builderx-companion-1.1.0.vsix
code --install-extension ../builderx-companion-1.1.0.vsix --force
```

Restart the VS Code window through the BuilderX bridge and verify readiness:

```bash
curl --fail --silent --show-error --request POST \
  http://127.0.0.1:43127/restart | jq .

curl --fail --silent --show-error \
  'http://127.0.0.1:43127/health?workspace_root=%2Fvar%2Fwww%2Fhtml%2Fdeveloper' \
  | jq -e '
    .ok == true and
    .workspace == "/var/www/html/developer" and
    .code_ready == true and
    .context_ready == true and
    .companion_extension_installed == true and
    .active_thread_busy == false
  '

curl --fail --silent --show-error \
  'http://127.0.0.1:43127/capabilities' | jq .
```

Only send a request when the readiness check returns true. Do not interrupt an
active Codex Chat turn. The web button uses the same health → handoff → event
stream/result sequence and does not create a second worker or polling service.
The event stream reports safe lifecycle and assistant-output events only; it
does not expose private reasoning or literal UI keystrokes.

## Prerequisites

- Linux host with `www-data` available for the web application.
- PHP 8.2 or later, the required PHP extensions, and a supported database.
- A dedicated MCP service account.
- A local Phase Manager service account. Use `www-data` when the service must
  create files owned by `www-data`.
- `setfacl` installed if default ACL inheritance is required.
- The MCP Server and File Service bound to a Unix socket or loopback address;
  neither service should be publicly exposed.

Do not place passwords, API keys, or private tokens in this file or in shell
history.

## Installation variables

Set these values for the target host before running the commands below:

```bash
PRODUCT_ROOT="/var/www/html/developer"
SHARED_GROUP="builderx"
MCP_USER="<mcp-service-user>"
PHASE_MANAGER_USER="www-data"
PHASE_FILES_ROOT="$PRODUCT_ROOT/storage/phase-manager-files"
BACKUP_ROOT="$PRODUCT_ROOT/storage/backups"
AUDIT_ROOT="$PRODUCT_ROOT/storage/audit"
```

The MCP user must be an existing dedicated service account. Do not substitute a
general-purpose login account unless the deployment policy explicitly allows
it.

## Shared group and directories

Create the shared group and add both services to it:

```bash
sudo groupadd --system "$SHARED_GROUP" 2>/dev/null || true
sudo usermod -aG "$SHARED_GROUP" www-data
sudo usermod -aG "$SHARED_GROUP" "$MCP_USER"
sudo usermod -aG "$SHARED_GROUP" "$PHASE_MANAGER_USER"
```

Create the MCP-allowlisted file root. The setgid bit (`2` in `2770`) keeps new
entries in the `builderx` group.

```bash
sudo install -d -o www-data -g "$SHARED_GROUP" -m 2770 "$PHASE_FILES_ROOT"
sudo setfacl -m u:www-data:rwx,g:"$SHARED_GROUP":rwx,o::--- "$PHASE_FILES_ROOT"
sudo setfacl -d -m u::rwx,g::rwx,o::--- "$PHASE_FILES_ROOT"
```

Required modes:

```text
Directories: www-data:builderx 2770
Files:       www-data:builderx 0660
Service umask: 0007
```

For an existing dedicated Phase Manager file root, repair only that root:

```bash
sudo chown -R www-data:"$SHARED_GROUP" "$PHASE_FILES_ROOT"
sudo find "$PHASE_FILES_ROOT" -type d -exec chmod 2770 {} +
sudo find "$PHASE_FILES_ROOT" -type f -exec chmod 0660 {} +
```

Do not run recursive ownership or permission changes against the whole product
document root.

## Ownership requirement

Group membership controls access; it does not make the creator the `www-data`
owner. If the MCP process runs as a user other than `www-data`, its create and
update requests must be executed by the File Service running as `www-data`, or
by a narrowly scoped writer with equivalent ownership guarantees.

The installation is incomplete if a normal create/update operation produces a
file owned by an unintended account.

## Backup and audit storage

Backups and audit logs are Phase Manager-owned resources and must not be part of
the MCP allowlist. Prefer separate permissions from the shared file root:

```bash
sudo install -d -o www-data -g www-data -m 0700 "$BACKUP_ROOT" "$AUDIT_ROOT"
```

If the Phase Manager runs as a separate account, grant that account access
through a dedicated group or ACL without adding the directories to MCP's
read/write roots.

## Service configuration requirements

Configure both services with:

- `umask=0007`.
- A fixed allowlist containing only `PHASE_FILES_ROOT` for ordinary MCP file
  operations.
- No public network listener; use a Unix socket or `127.0.0.1`.
- Request size and timeout limits.
- Structured logs that record actor, operation, normalized path, result, and
  request ID without recording file contents or secrets.
- Atomic write behavior using a temporary file in the same directory followed
  by a rename.

The MCP Server must validate and normalize paths before sending them to the
File Service. The File Service must repeat the validation; authorization must
not depend on the UI or MCP client alone.

## Approval workflow

Delete, move, database, backup, and audit operations must use a Phase Manager
approval record containing:

- actor and authenticated session;
- exact operation and normalized target;
- expected current file hash or database version;
- approval creation time and short expiration time;
- one-time approval identifier;
- result and audit reference.

The File Service must reject expired, reused, mismatched, or already-consumed
approvals. Recheck the target immediately before execution to prevent a
time-of-check/time-of-use replacement.

## Verification checklist

Run these checks after installation:

```bash
id www-data
id "$MCP_USER"
stat -c '%U:%G %a %n' "$PHASE_FILES_ROOT"
getfacl "$PHASE_FILES_ROOT"
```

Then verify the complete path:

1. MCP can list and read an allowlisted file.
2. MCP can create and update a test file.
3. The resulting file is owned by `www-data:builderx` with mode `0660`.
4. MCP rejects `../` traversal and symlink escapes.
5. MCP rejects access to backups and audit logs.
6. Delete and move requests require Phase Manager approval.
7. Database changes cannot be initiated by MCP directly.
8. Backup and audit events are persisted by Phase Manager and survive restart.
9. A failed write leaves the original file unchanged.

Remove any test files after verification and record the validation result in the
Phase Manager audit trail.

## Completion criteria

Installation is complete only when:

- the shared group and service identities are verified;
- directory and file ownership read back correctly;
- the MCP allowlist is active;
- the approval workflow rejects missing, expired, and reused approvals;
- database, backup, and audit operations are unavailable through direct MCP
  access; and
- the full create/update/read-back path succeeds after a service restart.

## Installation checklist and execution log

This section is updated as setup work is executed. Planned commands remain
unchecked until their result has been verified.

### Communication directory

- [x] Create `/var/www/html/developer/storage/codex-communication/`.
- [x] Create `inbox/`, `outbox/`, `processed/`, `failed/`, and `locks/`.
- [x] Add the VS Code BuilderX bridge user and `www-data` to `builderx`.
- [x] Apply `www-data:builderx` ownership.
- [x] Apply directory mode `2770` and verify a created message file at mode
  `0660`.
- [x] Add Apache 2.4 denial rules for the communication directory through
  `storage/codex-communication/.htaccess`; live HTTP verification remains
  pending until Apache is successfully configured and reloaded.
- [ ] Verify the VS Code BuilderX bridge can receive a handoff message.
- [ ] Verify BuilderX can write and read back a message.

### Pre-install diagnostics completed

- [x] Confirmed the current VS Code OS user is `janzter`.
- [x] Confirmed the `builderx` group is not currently present.
- [x] Found `/var/lib/builderx-codex`, currently owned by `www-data:www-data`
  with mode `750`; the VS Code bridge user cannot traverse it.
- [x] Confirmed non-interactive administrative access is unavailable; no
  password was stored or used.
- [x] At the initial pre-install check, no communication directory or service
  permissions had been changed.
- [x] Created rollback snapshot:
  `/home/janzter/builderx-rollback-20260814T020727Z.tar.gz`.
- [x] Snapshot SHA-256:
  `578877d66f697dab6dc845fd159f79163f9ede0e0b0d9eb858d49c19a72ea1c4`.
- [x] Snapshot mode and ownership: `janzter:janzter 0600`.
- [x] Snapshot excludes generated `frontend/node_modules`, `frontend/dist`,
  runtime `storage`, and live `phases/config.local.php` credentials.
- [x] Added `contracts/ai-task-v1.schema.json` for task lifecycle, specialist,
  stage, input, output, and permission contracts.
- [x] Added `contracts/communication-message-v1.schema.json` for versioned
  BuilderX-to-bridge message envelopes and delivery states.
- [x] Validated both contract files with Node.js JSON parsing.
- [x] Earlier pre-setup permission read-back found `builderx` and
  `storage/codex-communication` absent; this was resolved by the later host
  setup below.
- [x] Re-verified host setup: `builderx` contains `janzter` and `www-data`;
  all five communication directories are `www-data:builderx 2770`.
- [x] Re-verified ACLs: `janzter` and `www-data` have `rwx`, group inheritance
  is enabled, and other users have no access.
- [x] Re-verified the current VS Code bridge process can read and traverse every
  communication directory.
- [x] Added `app/AI/CommunicationMessageStore.php` with fixed-folder routing,
  path and symlink checks, size limits, checksums, duplicate protection, and
  atomic publication.
- [x] PHP-linted `app/AI/CommunicationMessageStore.php` successfully.
- [x] Ran a communication-store health check, outbox write, checksum-verified
  read-back, and file-mode check; the test file was removed afterward.
- [x] Added the isolated `builder_ai_task` persistence schema to the
  foundation bootstrap with ownership, lifecycle status, JSON payloads,
  timestamps, and lookup indexes.
- [x] Added `app/AI/AiTaskStore.php` with contract validation, user-scoped
  read-back, lifecycle transition rules, and audit metadata that excludes task
  text and model output.
- [x] PHP-linted the AI task store and validated the task contract JSON.
- [x] Verified the AI task lifecycle: create, read back, transition to
  `running`, transition to `completed`, read back the result, then removed the
  uniquely generated test task and matching test audit entries.
- [x] Database read-back confirmed `builder_ai_task` exists with 18 columns and
  zero remaining test rows.
- [x] Added the authenticated `create_ai_task` endpoint and `ai_task_status`
  read-back endpoint to the product root.
- [x] Added the BuilderX portal AI Rephrase Assistant with immediate task ID
  acknowledgement, 1.5-second polling, and component-only result updates.
- [x] Added `app/AI/AiTaskResultReconciler.php` so a verified Codex result is
  persisted before the status endpoint returns `completed`, then moved to the
  processed or failed communication folder.
- [x] Ran an authenticated endpoint smoke test: task creation returned an
  opaque ID, the status endpoint read it back as `queued`, and the correlated
  communication message read back with the matching task ID; the test records and
  message were removed afterward.
- [x] Ran a synthetic Codex-result read-back test: a correlated result
  completed the task, the message moved to `processed`, and test data was
  removed afterward. Actual VS Code BuilderX bridge delivery was verified
  separately through the loopback handoff endpoint.
- [x] Added the versioned `builder_ai_specialist` registry and
  `app/AI/AiSpecialistRegistry.php`; proposals default to
  `pending_approval`, and activation requires an approval reference.
- [x] Verified specialist proposal, approval, stage/skill matching, and
  cleanup with a synthetic registry test.
- [x] Ran `npm run build` successfully after the UI integration.
- [x] Final verification: PHP lint passed for all new task, reconciler,
  specialist, communication, foundation, and root-route files; both AI tables
  exist with zero remaining test rows; communication folders contain no test
  JSON files and retain `www-data:builderx 2770` directory permissions.
- [x] Corrected a local PHP syntax typo in one read-only table verification
  command and reran the check successfully; no product data was affected.
- [x] Added `contracts/file-service-v1.schema.json`, the allowlisted
  `app/AI/FileService.php`, and the local JSON-line transport at
  `bin/builderx-file-service.php`.
- [x] Verified file-service list, protected-config rejection, and unsupported
  delete rejection; no test project file was created.
- [x] Added communication message claim, release, and expiry cleanup support;
  expired messages route to `failed` and claimed messages route through
  `locks` before release.
- [x] Verified communication claim, expiry routing, release routing, and
  cleanup with temporary messages.
- [x] Added deployment references:
  `deploy/systemd/builderx-file-service.service.example` and
  `deploy/nginx/deny-builderx-sensitive.conf.example`.
- [x] Added the MCP JSON-RPC stdio adapter at
  `app/AI/McpFileServer.php` and `bin/builderx-mcp-server.php`; it supports
  initialization compatibility, notifications, `tools/list`, `tools/call`,
  and only the five allowlisted file tools.
- [x] Preserved the original JSON-line File Service executable for existing
  integrations while adding MCP as a separate entry point.
- [x] MCP smoke test passed: initialization, five-tool discovery, allowed
  file read, and protected communication-path rejection.
- [x] Legacy File Service smoke test still passed after the MCP adapter was
  added.
- [x] Registered `builderx_file_service` in the local MCP configuration used by
  the VS Code BuilderX bridge
  as a local STDIO server with `BUILDERX_FILE_ROOT` scoped to this project and
  `default_tools_approval_mode = "writes"`.
- [x] Verified the registration through the local MCP configuration;
  the server is enabled with all five expected tools.
- [x] Verified live launch after the VS Code restart: two
  `builderx-mcp-server.php` processes are running under `janzter`, matching the
  active VS Code bridge and IDE hosts.
- [x] Local comparison read of `contracts/file-service-v1.schema.json`
  returned the expected BuilderX File Service schema; this was a direct local
  read, not a UI-originated MCP call.
- [x] Direct MCP negative test for `storage/codex-communication/.htaccess`
  returned JSON-RPC error `-32602` with the path rejected by the allowlist.
- [x] Added the permission-preserving `FileServiceGateway` and Unix-socket
  client boundary; MCP can use a `www-data:builderx` socket without weakening
  the File Service writer identity check.
- [x] Added deployment examples for the socket-activated File Service:
  `deploy/systemd/builderx-file-service.socket.example` and
  `deploy/systemd/builderx-file-service@.service.example`.
- [x] Verified current direct-read mode still reads an approved contract and
  rejects a create request from the VS Code bridge identity before any file is
  created; the write response is intentionally generic (`-32603`).
- [ ] Install and enable the socket-activated service with administrator
  privileges, then add `BUILDERX_FILE_SERVICE_SOCKET` to the local MCP entry
  used by the VS Code BuilderX bridge. The local MCP config intentionally
  remains in direct-read mode until the socket exists; this avoids pointing
  the live MCP server at an unavailable endpoint.
- [x] Installed the socket unit at
  `/etc/systemd/system/builderx-file-service.socket`; the matching service
  unit and activation remain pending.
- [x] Installed the matching service unit at
  `/etc/systemd/system/builderx-file-service@.service`; systemd daemon reload
  and socket activation remain pending.
- [x] Reloaded systemd with `sudo systemctl daemon-reload`; socket activation
  remains pending.
- [x] Enabled the socket; it is `active` and `listening`, but verification
  found `/run/builderx` was created as `root:root 0770`, preventing the
  `builderx` group from traversing it. Added
  `deploy/systemd/builderx-file-service.tmpfiles.example` to persist the
  required `www-data:builderx 2770` runtime directory ownership.
- [ ] Install the tmpfiles rule, recreate `/run/builderx`, and verify socket
  access as the VS Code bridge user before switching MCP to socket mode.
- [x] Installed `/etc/tmpfiles.d/builderx-file-service.conf`; applying the
  rule and verifying runtime ownership remain pending.
- [x] Applied the tmpfiles rule: `/run/builderx` is now
  `www-data:builderx 2770` and the socket unit is `active`. The current
  VS Code session still lacks `builderx` in its supplementary groups, so a
  logout/login or full VS Code restart is required before socket access can be
  verified.
- [x] Socket read test exposed a service launch failure; diagnosis found the
  executable mode was `0750` with group `janzter`, so `www-data` could not
  execute it. The executable needs `builderx` group ownership before retrying.
- [x] Corrected both service executables to `janzter:builderx 0750` and
  verified a real MCP read through the socket-backed `www-data` File Service.
- [x] Enabled `BUILDERX_FILE_SERVICE_SOCKET=/run/builderx/builderx-file-service.sock`
  in the local MCP configuration; a full VS Code restart from a session with
  the `builderx` group is required before live bridge calls use the socket.
- [x] Normalized socket-backed File Service validation errors so protected
  paths retain the safe `-32602` MCP rejection used by direct mode.
- [x] Added session recovery for the rephrase task ID so polling can resume
  after a page reload; final live VS Code BuilderX bridge delivery was
  verified through the loopback handoff endpoint.
- [x] Final Coordinator smoke test: authenticated rephrase creation routed to
  the approved `rephrase` specialist, returned `queued`, and published a
  correlated inbox request message; the task and message were removed afterward.
- [x] Added `contracts/approval-v1.schema.json`, the
  `builder_ai_approval` table, and `app/AI/ApprovalStore.php` for
  target-specific, hash-checked, expiring, one-time approvals.
- [x] Approval lifecycle verification passed: pending → approved → consumed;
  replay was rejected and test rows were removed.
- [x] Corrected database timestamp behavior by storing approval expiry as
  immutable `DATETIME`; the first lifecycle test exposed and was cleaned up
  after fixing the driver/schema interaction.
- [x] Added `contracts/memory-v1.schema.json`, the `builder_ai_memory` table,
  and `app/AI/MemoryStore.php` for approval-gated AI-Memory and Obsidian
  Markdown export.
- [x] Added bounded keyword, metadata, and hybrid retrieval with an explicit
  `vector_used: false` result until an embedding provider is approved.
- [x] Verified memory proposal, approval, Obsidian export, hybrid/metadata
  search, and test cleanup; the empty vault remains at
  `storage/ai-memory/obsidian/` for approved project memory.
- [x] Added `app/AI/CoordinatorRouter.php`; task routing now selects only an
  approved active specialist or returns a registration-required proposal.
- [x] Registered the system Coordinator and Rephrase specialists with
  approved default scopes and verified grammar routing selects `rephrase`.
- [x] Confirmed Apache2 is installed (`Apache/2.4.52`) and added
  `deploy/apache/builderx-sensitive-files.conf.example` for VirtualHost-level
  denial of communication, AI-Memory, backup, audit, and protected config
  paths.
- [x] Added active `.htaccess` denial rules under
  `storage/codex-communication/` and `storage/ai-memory/` with directory
  listing disabled.
- [x] Reloaded Apache2 after the successful privileged configuration check;
  `systemctl is-active apache2` returned `active` and the service state is
  `running`.
- [x] Privileged `sudo apache2ctl configtest` returned `Syntax OK`; Apache
  reported only the standard missing-global-`ServerName` warning.
- [x] Confirmed the referenced snake-oil certificate exists and confirmed
  `/usr/sbin/make-ssl-cert` is available for the administrator to repair the
  local default SSL configuration.
- [ ] After privileged deployment, verify the new Apache rules, ownership, and
  modes on the live folders. The unprivileged preparation left the new sample
  and `.htaccess` files owned by the VS Code bridge user; production deployment must
  apply the shared `www-data:builderx` ownership policy.

## Build phases toward the target goal

This is the implementation checklist for reaching the secure BuilderX-to-VS
Code BuilderX bridge communication target. Do not mark a phase complete from
code presence
alone; complete its acceptance check and record the result here.

### Specialist-agent model

The framework uses multiple role-based AI specialists coordinated by one Phase
Manager. Specialists may share the same AI runtime, but each specialist has a
separate scope, input contract, output contract, and permission boundary.

| Stage | Specialist roles |
|---|---|
| Think | Requirements, Architecture |
| Design | UI/UX, Database, Solution Design |
| Build | Frontend, Backend |
| Validate | Testing, Security, Accessibility |
| Document | Documentation, Handoff |
| Preserve | Persistence, Maintenance, Rollback |

Coordination requirements:

- [ ] Define the Phase Manager coordinator and routing rules.
- [x] Implement the first Coordinator routing rule: approved active registry
  match or explicit registration-required response.
- [ ] Define every specialist role, responsibility, and allowed stage.
- [ ] Define the input and output schema for specialist handoffs.
- [ ] Include specialist name, stage, task ID, status, files affected, tests,
  risks, and recommended next action in every result.
- [ ] Route handoffs through the communication directory using correlation IDs.
- [ ] Keep specialists read-only by default.
- [ ] Permit file mutations only through the approved Build path.
- [ ] Require Phase Manager approval for sensitive file, database, backup, and
  audit operations.
- [ ] Prevent one specialist from silently changing another specialist's scope.
- [ ] Preserve specialist outputs and decisions for continuity and rollback.

Coordinator AI requirements:

- [ ] Define the Coordinator AI role and operating instructions.
- [ ] Define task-packet fields: task ID, stage, specialist, objective, inputs,
  allowed paths, write permission, expected output, and constraints.
- [ ] Define result fields: status, result, files changed, tests, risks, and
  next action.
- [ ] Implement stage routing and dependency rules.
- [ ] Support parallel fan-out for independent specialist tasks and fan-in for
  result reconciliation.
- [ ] Add conflict detection when specialists return incompatible results.
- [ ] Add retry, escalation, cancellation, and blocked-task states.
- [ ] Apply token-efficient context selection instead of sending the whole
  project to every specialist.
- [ ] Require validation before advancing to the next stage.
- [ ] Route approval-required work to the Phase Manager instead of executing it
  directly.

Acceptance: the Coordinator AI can receive one goal, produce scoped task
packets, route them to multiple specialists, reconcile their structured
results, and advance or stop based on validation and approval status.

Acceptance: the coordinator can route one task through the appropriate
specialists, collect structured results, enforce stage order, and reject an
unauthorized write or out-of-scope operation.

### Phase 0 — Freeze the target contract

- [ ] Confirm the project root and communication root.
- [ ] Confirm the MCP service user, Phase Manager service user, and `builderx`
  group membership.
- [ ] Confirm the transport: Unix socket or loopback-only connection.
- [ ] Confirm the allowed MCP operations: list, search, read, create, update,
  and health.
- [ ] Confirm that delete, move, database, backup, and audit operations remain
  Phase Manager-controlled.

Acceptance: the target paths, identities, transport, and operation boundary are
written in this file with no unresolved ownership decision.

### Phase 1 — Prepare host identities and permissions

- [x] Create the `builderx` group if it does not exist.
- [x] Add the MCP and Phase Manager service accounts to the group.
- [ ] Apply the service umask `0007`.
- [ ] Reload or restart the web/PHP service so its `www-data` process receives
  the updated supplementary group membership.
- [ ] Verify that the web server cannot expose communication files directly.

Acceptance: `id`, `getent group`, and permission read-back show the intended
identities and no unintended public access.

### Phase 2 — Create the communication directory

- [x] Create `storage/codex-communication/`.
- [x] Create `inbox/`, `outbox/`, `processed/`, `failed/`, and `locks/`.
- [x] Apply `www-data:builderx` ownership.
- [x] Apply directory mode `2770` and verify a created message file at mode
  `0660`.
- [x] Configure default ACL inheritance where required.
- [x] Verify the VS Code BuilderX bridge can receive a handoff through
  `POST /handoff`.
- [x] Verify BuilderX can read the correlated response through `GET /result`.

Acceptance: a test request completes BuilderX → VS Code BuilderX bridge →
visible VS Code Codex Chat → correlated result read-back without changing
ownership unexpectedly.

### Phase 3 — Define the message contract

- [ ] Define the JSON envelope: message ID, sender, recipient, type, created
  time, payload, checksum, and status.
- [ ] Define message types and schema versions.
- [ ] Define filename and correlation-ID rules.
- [ ] Define maximum message size and retention behavior.
- [ ] Define atomic-write and lock-file behavior.
- [ ] Define invalid, duplicate, expired, and partially written message errors.

Acceptance: valid messages are accepted, malformed messages are rejected, and
duplicate message IDs cannot be processed twice.

### Phase 4 — Build the Phase Manager File Service

- [ ] Implement health/readiness reporting.
- [ ] Implement canonical path validation and allowlisted roots.
- [ ] Reject traversal, symlink escapes, unsupported paths, and oversized files.
- [ ] Implement list/search/read.
- [ ] Implement atomic create/update with ownership and mode verification.
- [ ] Implement structured errors and request IDs.
- [ ] Add operation logging without file contents or secrets.

Acceptance: the service passes allowed-path, denied-path, symlink, size-limit,
atomic-write, and read-back tests.

### Phase 5 — Build the BuilderX MCP Server boundary

- [ ] Expose only the approved ordinary file tools.
- [ ] Connect MCP to the local File Service.
- [ ] Keep the MCP listener local-only.
- [ ] Pass request IDs and structured errors through unchanged.
- [ ] Ensure MCP has no direct database credentials.
- [ ] Ensure MCP has no delete, move, backup, audit-write, or arbitrary-shell
  tool.

Acceptance: a local MCP call through the VS Code BuilderX bridge can complete
list/read/create/update and every non-approved operation is rejected before
execution.

### Phase 6 — Connect the VS Code BuilderX bridge

- [x] Verify the local MCP connection used by the VS Code BuilderX bridge.
- [x] Verify the browser send path uses the bridge health check, then
  `POST /handoff`, then the matching `GET /result` request.
- [x] Verify bounded specialist results use `POST /handoff-result` and the
  unique `.builderx/runtime/tasks/{request_id}/result.json` read-back file.
- [x] Expose the dedicated `builderx_ai_tasks_next`,
  `builderx_ai_task_claim`, and `builderx_ai_task_complete` tools through the
  local MCP connection. Generic file tools cannot access the communication
  directory.
- [x] Send the test command `Hi` through the connected VS Code BuilderX bridge
  to the visible Codex Chat session.
- [x] Read the correlated response through the bridge result path.
- [ ] Handle restart and reconnect without losing messages.
- [ ] Verify message ownership and permissions after bridge writes.

Acceptance: the complete live path works after restarting the MCP connection
and the VS Code BuilderX bridge.

### Phase 7 — Add Phase Manager approvals

- [ ] Define approval records for delete and move operations.
- [ ] Bind each approval to the exact target, operation, current hash/version,
  actor, expiry, and one-time approval ID.
- [ ] Reject missing, expired, reused, mismatched, or unauthorized approvals.
- [ ] Recheck the target immediately before execution.
- [ ] Record the result in the audit trail.

Acceptance: sensitive file operations cannot execute without a valid,
single-use approval.

### Phase 8 — Add approved database operations

- [ ] Keep database credentials inside the Phase Manager boundary.
- [ ] Validate authorized operation type and target.
- [ ] Execute changes transactionally.
- [ ] Support rollback or failure-safe behavior.
- [ ] Record before/after metadata and the approval reference.

Acceptance: MCP cannot modify the database directly, and approved changes have
transaction and audit evidence.

### Phase 9 — Add backup and audit ownership

- [ ] Store backups outside the MCP allowlist.
- [ ] Make audit records append-only from the MCP boundary.
- [ ] Restrict backup and audit permissions to Phase Manager services.
- [ ] Define retention, rotation, and recovery behavior.
- [ ] Verify backup and audit data survives service restart.

Acceptance: MCP cannot create, delete, modify, or read protected backup/audit
data unless the Phase Manager explicitly exposes a safe result.

### Phase 10 — End-to-end verification

- [ ] Verify the complete message round trip.
- [ ] Verify persistence after process and VS Code/BuilderX bridge restart.
- [ ] Verify path traversal, symlink, size, malformed-message, and replay
  rejection.
- [ ] Verify ownership and modes on every created file.
- [ ] Verify sensitive operations require approval.
- [ ] Verify database, backup, and audit boundaries.
- [ ] Run PHP lint, service tests, route checks, and focused security tests.
- [ ] Record actual commands, outputs, and remaining risks in this file.

Acceptance: the target goal is demonstrated with read-back evidence rather than
only a healthy process or HTTP response.

### Phase 11 — Operational hardening

- [ ] Add service startup and restart configuration.
- [ ] Add health monitoring and actionable failure logs.
- [ ] Add log rotation without exposing message contents or secrets.
- [ ] Document recovery, cleanup, and rollback procedures.
- [ ] Review the final allowlist and Linux permissions after deployment.

Acceptance: the communication path can be operated, recovered, and audited by
an administrator without granting unrestricted filesystem or database access.

### Phase 12 — Specialist directory and Coordinator Memory interface

- [x] Build the initial Specialist Directory interface listing registered
  specialists, status, stages, and skills; full filters and metadata columns
  remain pending.
- [ ] Extend the directory listing with purpose, tools, permission scope,
  version, owner, last update, and stage/skill/status filters.
- [ ] Add search and filters by stage, skill, status, project, and permission
  scope.
- [x] Add Coordinator Memory Chat support for proposing a new specialist or
  approved-knowledge update.
- [ ] Show the proposed memory/RAG update before saving it.
- [x] Require administrator approval for each persistent memory update.
- [x] Store memory source, project metadata, review status, version, and
  approval state; confidence, sensitivity, and rollback fields remain pending.
- [x] Make approved memory available to the implemented keyword/metadata/hybrid
  retrieval path immediately; a separate vector index remains pending.
- [ ] Preserve previous versions and support restore or archive.
- [ ] Record who requested, approved, changed, or rejected each update.
- [ ] Prevent Coordinator chat from granting new tools or permissions without
  the specialist-registration approval workflow.

Example Coordinator chat request:

```text
Add this approved UI/UX rule to the UI/UX specialist memory:
Standard BuilderX buttons must use border-radius: 0 unless an explicit
exception is approved.
```

The interface should convert the request into a reviewable memory record,
display the affected scope, then save and index it only after approval.

Acceptance: an administrator can view the specialist list, use Coordinator
chat to propose a UI/UX rule, approve it, retrieve it through RAG in a later
UI task, and restore the previous rule version.

## Final target checklist

This is the consolidated checklist for the complete target. All items are
pending unless marked in the execution log above.

### Obsidian, AI-Memory, and RAG layers

Obsidian is the human-readable knowledge vault. AI-Memory manages what the
assistant is allowed to remember, for how long, and with what confidence. RAG
retrieves approved knowledge for a specific task. These layers must not be
treated as one unrestricted memory store.

Recommended vault areas:

```text
00-Inbox/       unreviewed captures and incoming results
10-Projects/    project notes and active work
20-Decisions/   approved architecture and product decisions
30-Reference/   source material and external references
40-Memory/      approved reusable AI memory
90-Archive/     retired material
```

Recommended note properties:

```yaml
type: decision | requirement | task | result | reference | memory
project: builderx
stage: Think | Design | Build | Validate | Document | Preserve
source: user | specialist | phase-manager | external
confidence: 0.0
sensitivity: public | internal | restricted
created: YYYY-MM-DD
updated: YYYY-MM-DD
review_after: YYYY-MM-DD
```

Memory tiers:

- [ ] Working memory: current task context and temporary specialist inputs.
- [ ] Episodic memory: completed tasks, decisions, results, and evidence.
- [ ] Semantic memory: approved facts, requirements, and architecture rules.
- [ ] Procedural memory: workflows, policies, prompts, and validation rules.
- [ ] Archive memory: expired or superseded content retained for history only.

RAG types to support progressively:

- [x] Keyword retrieval for exact terms, names, and error messages.
- [ ] Semantic/vector retrieval for conceptually similar notes.
- [x] Hybrid retrieval combining keyword scoring with approved memory metadata.
- [x] Metadata-filtered retrieval by memory type and tag; project, stage,
  source, sensitivity, and date filters remain pending.
- [ ] Hierarchical retrieval using note, section, and parent-project context.
- [ ] Graph retrieval using Obsidian links, backlinks, and related decisions.
- [ ] Temporal retrieval for current, historical, and superseded knowledge.
- [ ] Structured retrieval for task, phase, approval, and database records.
- [ ] Reranking and citation checks before context reaches a specialist.

Recommended implementation order:

1. Markdown notes with properties and stable IDs.
2. Full-text and metadata-filtered search.
3. Hybrid keyword plus vector retrieval.
4. Parent/child chunking and reranking.
5. Obsidian link graph retrieval.
6. Temporal and structured retrieval.
7. Agentic multi-step retrieval only after the earlier layers are reliable.

Knowledge-flow requirements:

- [ ] Ingest only approved vault folders and BuilderX records.
- [ ] Preserve the original note path, section, hash, and source in every
  indexed chunk.
- [ ] Re-index changed notes and remove deleted or revoked content.
- [ ] Filter restricted content before retrieval, not after generation.
- [ ] Return citations or source-note links with specialist results.
- [ ] Require review before promoting working or episodic memory into approved
  semantic or procedural memory.
- [ ] Prevent the AI from silently changing canonical Obsidian notes.
- [ ] Use the communication directory as the handoff boundary; do not expose
  the Obsidian vault directly to the public web server.
- [ ] Use Obsidian URI actions only for controlled desktop navigation or note
  creation after user authorization.
- [ ] Back up the vault and preserve version history before automated writes.

Acceptance: a specialist can retrieve relevant, permitted notes with source
links and metadata, while restricted, expired, deleted, or unapproved memory
is excluded from the context.

### Foundation and rollback

- [ ] Define the final target, scope, non-goals, and completion criteria.
- [x] Create and hash a rollback snapshot outside the active product.
- [ ] Inventory legacy AI runtime, orchestration, bridge, UI, persistence,
  configuration, installer, logs, and stored context.
- [ ] Disable legacy AI execution before removing its files or data.
- [ ] Verify ordinary BuilderX behavior after the legacy runtime is disabled.
- [ ] Remove confirmed legacy AI artifacts from the active product.
- [ ] Retain the rollback snapshot until final acceptance.

### Six-stage AI framework

- [ ] Implement the `Think` stage for requirements and architecture.
- [ ] Implement the `Design` stage for UI/UX, database, and solution design.
- [ ] Implement the `Build` stage for frontend and backend work.
- [ ] Implement the `Validate` stage for testing, security, and accessibility.
- [ ] Implement the `Document` stage for documentation and handoff.
- [ ] Implement the `Preserve` stage for persistence, maintenance, and rollback.
- [ ] Define valid stage transitions and blocked/approval states.

### Coordinator AI and specialists

- [ ] Implement one Coordinator AI as the command and routing layer.
- [ ] Define Requirements, Architecture, UI/UX, Database, Solution Design,
  Frontend, Backend, Security, Testing, Accessibility, Documentation, and
  Preservation specialist roles.
- [x] Define task packets and structured specialist results in
  `contracts/ai-task-v1.schema.json`.
- [ ] Support parallel execution for independent tasks.
- [ ] Reconcile specialist results and detect conflicts.
- [ ] Implement retry, escalation, cancellation, timeout, and blocked states.
- [ ] Enforce token-efficient context selection.
- [ ] Keep specialists read-only by default.
- [ ] Route all mutations through the approved Build and Phase Manager paths.
- [ ] Create a versioned specialist registry with purpose, stages, skills,
  tools, permissions, input schema, and output schema.
- [x] Create the persisted specialist registry foundation with pending
  proposals, approved activation, stage/skill matching, RAG scopes, and
  temporary-specialist metadata.
- [x] Let the Coordinator match the rephrase task to an approved registered
  specialist; broader on-demand routing remains to be expanded.
- [ ] If no suitable specialist exists, create a specialist-registration
  proposal instead of silently inventing permissions.
- [ ] Require Phase Manager approval before registering new tools or write
  access for a specialist.
- [ ] Assign approved skills and RAG scopes when a specialist is registered.
- [ ] Support temporary specialists that can be retired after a task.
- [ ] Preserve useful specialist definitions with version, owner, evidence,
  and review status.
- [ ] Prevent any Coordinator or specialist from granting itself permissions.

Example on-demand specialist registration:

```json
{
  "specialist": "accessibility",
  "purpose": "Review UI accessibility",
  "stages": ["Validate"],
  "skills": ["wcag-review", "keyboard-navigation"],
  "allowed_tools": ["read_files"],
  "write_scope": "none",
  "status": "pending-approval"
}
```

### BuilderX-to-VS Code BuilderX bridge communication

- [x] Create `storage/codex-communication/` with `inbox/`, `outbox/`,
  `processed/`, `failed/`, and `locks/`.
- [x] Configure `www-data:builderx` ownership, directory mode `2770`, and
  verify message file mode `0660`.
- [x] Block direct HTTP access to the communication directory; live requests
  to `/developer/storage/codex-communication/` and
  `/developer/storage/ai-memory/` returned `403` after the Apache reload.
- [x] Define the versioned JSON message envelope and correlation IDs in
  `contracts/communication-message-v1.schema.json`.
- [x] Implement atomic writes, duplicate protection, claiming, expiry, and
  cleanup in `app/AI/CommunicationMessageStore.php`.
- [x] Align task direction with the VS Code BuilderX bridge handoff: BuilderX
  sends the command through `POST /handoff`, and the correlated response is
  read through `GET /result` for Phase Manager reconciliation.
- [x] Add the safe one-time migration utility
  `bin/builderx-communication-migrate.php` for queued requests created before
  the direction correction. It defaults to a dry run and requires `--apply`.
- [x] Synthetic corrected-direction round trip passed: an `inbox/` request was
  claimed, a correlated `outbox/` result was read back, reconciled to a
  completed task, and all temporary records were removed.
- [x] Verify BuilderX-to-bridge delivery and bridge-to-BuilderX read-back with
  the visible VS Code Codex Chat session; the final `Hi` handoff returned a
  correlated result through the loopback bridge.

### MCP and File Service

- [x] Implement a local-only JSON-line File Service transport and a separate
  MCP JSON-RPC stdio adapter; service-manager registration remains pending.
- [x] Implement list, search, read, create, and update operations in the File
  Service boundary.
- [x] Enforce path allowlists, canonical paths, symlink protection, size
  limits, and structured errors.
- [x] Require the `www-data` service identity for File Service writes and use
  atomic publication with mode `0660`.
- [x] Prevent the local transport from exposing arbitrary shell, database,
  backup, audit, delete, or sensitive move operations.
- [x] Register the MCP executable with the local MCP configuration used by the
  VS Code BuilderX bridge and verify its parsed entry, including the three
  dedicated AI task tools.
- [x] Verify the MCP server exposes eight tools: five allowlisted file tools
  and the three dedicated BuilderX task tools.
- [ ] Verify live tool discovery and a read-only BuilderX file read from the
  connected MCP server through the visible VS Code Codex Chat session;
  process launch is confirmed, but a UI-originated tool call has not yet been
  recorded.

### AI task completion and partial UI refresh

- [x] Build the first persisted task-record service and lifecycle contract.
- [x] Create a persisted task record when the AI Rephrase Assistant is used.
- [x] Return an opaque `task_id` immediately.
- [x] Implement task states in the persistence service: `queued`, `running`, `awaiting_approval`,
  `completed`, `failed`, and `cancelled`.
- [x] Persist the final AI result before notifying the web client when a
  verified result message is available.
- [x] Expose an authorized task-status endpoint.
- [x] Implement initial 1.5-second polling from the web page.
- [x] Refresh only the affected component when the task reaches `completed`.
- [ ] Preserve the result after page reload.
- [ ] Add Server-Sent Events later if real-time updates are needed; do not add
  WebSockets until there is a demonstrated requirement.

### Brand consistency and UI/UX governance

- [x] Define the first approved brand-rule contract shape for color,
  typography, spacing, shapes, buttons, forms, navigation, status states, and
  responsive behavior through the memory schema and Obsidian export path.
- [ ] Define the complete BuilderX brand rules for color, typography, spacing, shapes,
  buttons, forms, navigation, status states, and responsive behavior.
- [ ] Store approved brand rules and examples in the Obsidian knowledge vault
  and approved AI-Memory.
- [ ] Tag each rule by project, component, stage, source, sensitivity, and
  review status.
- [ ] Retrieve relevant brand rules through RAG for every UI/UX task.
- [ ] Enforce critical rules through shared design tokens and reusable UI
  primitives, not prompts alone.
- [ ] Require the UI/UX specialist to identify applicable brand rules before
  proposing a design.
- [ ] Require the Build specialist to use shared components and tokens.
- [ ] Add a UI/UX validator that checks generated screens against approved
  brand rules.
- [ ] Require explicit approval for documented exceptions.
- [ ] Preserve each rule, implementation reference, validation result, and
  approval decision for future retrieval.

Example acceptance rule:

```yaml
id: brand.button.shape
scope: current-project
rule: Standard buttons use border-radius: 0
enforcement: shared-button-token-and-validator
exceptions: explicit-approval-required
status: approved
```

Acceptance: a future UI task retrieves the approved brand rule, uses the shared
design system, and fails validation when it creates an unapproved inconsistent
button or component.

### Approval, database, backup, and audit boundaries

- [x] Implement the approval record foundation for delete, move, database,
  backup, and audit operations.
- [ ] Require Phase Manager approval for delete and move operations at each
  future execution endpoint.
- [ ] Bind approvals to actor, exact target, operation, current hash/version,
  expiry, and one-time approval ID.
- [ ] Keep database credentials inside the Phase Manager boundary.
- [ ] Execute approved database changes transactionally.
- [ ] Keep backups and audit logs outside the MCP allowlist.
- [ ] Make audit records append-only from the MCP boundary.
- [ ] Verify unauthorized operations are rejected and recorded safely.

### Final validation and preservation

- [ ] Run the complete User → Coordinator → Specialist → Phase Manager →
  BuilderX/VS Code bridge → Web UI path.
- [ ] Verify task completion, partial refresh, persistence, and restart recovery.
- [ ] Verify permissions, path traversal, symlink, replay, malformed-message,
  and failure-path rejection.
- [ ] Run focused application, service, security, and accessibility tests.
- [ ] Record commands, outputs, evidence, risks, and rollback references in
  this file.
- [ ] Document operations, recovery, cleanup, and maintenance ownership.
- [ ] Obtain final approval before deleting the rollback snapshot or any
  remaining stored legacy AI data.

## Recommended MVP order

1. Rollback snapshot and scope inventory.
2. Communication directory and message contract.
3. One Coordinator AI plus the Requirements specialist.
4. One complete task round trip with persisted read-back.
5. The AI rephrase action with task status and partial UI refresh.
6. Build specialist with controlled file mutation.
7. Validate specialist and security negative tests.
8. Add the remaining specialists and approval-controlled operations.
