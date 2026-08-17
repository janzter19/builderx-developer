# BuilderX Current Setup Backup

**Captured:** 2026-08-15  
**Workspace:** `/var/www/html/developer`  
**Purpose:** Reference snapshot of the verified BuilderX Phase Manager setup before Phase 3 work.

## 1. Product and scope boundary

BuilderX Phase Builder at `/developer/phases/` is the control plane. It is not
part of the product being built.

The target product surfaces are:

- User Portal: `http://127.0.0.1/developer/`
- Administrator Portal: `http://127.0.0.1/developer/administrator/`

The current verified production workflow is **Phase Builder · Narrative &
Cleanup** for Tab 1. Its purpose is to correct spelling and grammar while
preserving meaning, requirements, URLs, and technical details.

## 2. Current Phase 2 workflow

```text
Capture Tab 1 context
        |
        v
Read Coordinator route contract
        |
        v
Grammar Specialist
  - spelling and grammar only
  - read-only
  - writes JSON result file only
        |
        v
Database Specialist
  - validates all nine corrected sections
  - read-only approval
  - writes JSON result file only
        |
        v
PHP validation and database transaction
  - verify source context has not changed
  - copy current draft to backup table
  - read backup back and compare every field
  - upsert corrected draft using ADODB parameterized SQL
  - read corrected row back and compare every field
  - append audit entry
        |
        v
Refresh the form with the database read-back
```

The Grammar Specialist and Database Specialist are intentionally sequential:
the Database Specialist validates the Grammar Specialist result. This workflow
does not claim parallel execution.

## 3. Tab 1 data contract

The workflow requires all nine fields:

1. `product_goal`
2. `users_and_roles`
3. `main_user_journey`
4. `web_requirements`
5. `android_requirements`
6. `database_and_synchronization`
7. `security_and_permissions`
8. `validation_and_error_handling`
9. `open_questions`

The server rejects incomplete sections, changed source context, changed phase
keys, missing approvals, invalid meaning anchors, and invalid specialist JSON.

## 4. PHP persistence boundary

Relevant implementation files:

- `phases/index.php`
- `app/AI/PhaseBuilderNarrativeCleanupStore.php`

Context and save actions:

- `prepare_phase2_narrative_context`
- `prepare_phase2_database_context`
- `save_phase2_narrative_cleanup`

The save uses the existing project database helper `bx_db()`, ADODB
parameterized SQL, and an explicit transaction:

1. Verify the selected phase exists.
2. Begin transaction.
3. Lock and read the existing narrative draft.
4. Copy the existing row into `phase_builder_narrative_draft_backup`.
5. Read the backup and compare all nine fields.
6. Upsert `phase_builder_narrative_draft`.
7. Read the upserted row back and compare every corrected field.
8. Write the audit entry.
9. Commit; otherwise roll back on any failure.

No database write occurs unless the Database Specialist approval and all
server-side validations pass.

## 5. BuilderX VS Code bridge

Bridge files:

- `tools/builderx-bridge/server.mjs`
- `tools/builderx-bridge/extension/extension.js`
- `tools/builderx-bridge/builderx-bridge.service`

Current bridge settings:

- Version: `1.5.1`
- Listener: `127.0.0.1:43127`
- Workspace: `/var/www/html/developer`
- Transport: loopback HTTP with SSE progress events
- Authentication: authenticated ChatGPT account already active in VS Code
- API key: not used
- Codex CLI: not used
- Background worker or global poller: not used
- Delivery mode currently verified: `legacy-implement-todo-wrapper`

Readiness requires the workspace, VS Code launcher, companion extension,
extension activation, Codex command, active Codex session, and
`active_thread_busy === false`.

## 6. Bridge endpoints

### Ordinary handoff

`POST /handoff`

Accepts only:

```json
{
  "workspace_root": "/var/www/html/developer",
  "command": "Hi"
}
```

This is used by ordinary bridge tests and legacy flows. It returns a request
ID and exposes progress through:

- `GET /events?request_id=...`
- `GET /result?request_id=...`

### File-result handoff

`POST /handoff-result`

Accepts the same two fields as `/handoff`. The bridge creates:

```text
.builderx/runtime/tasks/{request_id}/result.json
```

with this placeholder:

```text
// BUILDERX_RESULT
```

The bridge appends a wrapper-compatible instruction telling Codex to replace
only that placeholder with one valid JSON object and not to edit source code,
configuration, databases, or other files.

The authoritative result is the parsed `file_result`, not chat prose. The
completed result is returned through SSE and `/result`, then the generated task
directory is disposed. The correlated result remains cached in the bridge for
the `/result` fallback.

## 7. Current UI behavior

The Narrative & Cleanup action:

1. Opens a confirmation dialog.
2. Shows Coordinator, Grammar Specialist, Database Specialist, and UI
   read-back progress.
3. Displays a verified workflow report.
4. Shows success only after database read-back and UI refresh.
5. Shows an error and stops before persistence for any invalid or incomplete
   result.

The bridge status modal reports readiness, workspace, extension, active thread,
transport, and test controls. A busy active thread blocks new handoffs rather
than interrupting the current Codex turn.

The modal now includes **Test single-chat specialists**. This sends one
read-only request through the existing visible Codex Chat bridge. The
Coordinator selects relevant Requirements, Database, and UI/UX roles, produces
bounded findings inside the same chat turn, and reconciles them. The workflow
explicitly does not claim independent parallel execution.

## 8. Verified evidence at capture time

The following checks passed:

- `npm run check` in `tools/builderx-bridge`
- `npm run build` in `frontend`
- PHP lint for `phases/index.php`
- PHP lint for `app/AI/PhaseBuilderNarrativeCleanupStore.php`
- Bridge health with `ready_to_send: true`
- Live `/handoff-result` test through visible VS Code Codex Chat
- Valid JSON returned through SSE and `/result`
- Disposable result task removed after completion

The live test verified the bridge transport only. The actual Phase 2 production
workflow was subsequently run and visually confirmed with all five workflow
steps complete and **Database read-back complete**.

## 9. Phase 3 boundary

Phase 3 now has an implemented single-chat orchestration mode. It is not true
parallel execution and is not claimed as such.

The current single-chat acceptance boundary is:

- one visible Codex Chat handoff;
- Coordinator selection from Requirements, Database, and UI/UX;
- bounded specialist findings in the same response;
- Coordinator reconciliation and an honest report; and
- no file edits, SQL, database changes, or child dispatch from this test.

The following true-parallel behavior remains future work and is not claimed:

- independent specialist task IDs and result channels;
- genuinely separate Codex task/session channels;
- concurrent dispatch only for independent tasks;
- wait-for-all fan-in;
- conflict detection and reconciliation;
- timeout, cancellation, retry, and partial-failure handling; and
- evidence that at least two specialists actually executed independently.

The current single active visible Codex session and `active_thread_busy` guard
are insufficient to claim true parallel specialist execution.

## 10. Recovery guidance

Before changing the bridge or Phase 2 workflow:

1. Preserve this document.
2. Do not modify the target product routes while testing BuilderX control-plane
   behavior.
3. Keep the existing `phase_builder_narrative_draft_backup` table intact.
4. Verify bridge readiness before every handoff.
5. Never send a new handoff while `active_thread_busy` is true.
6. Require database read-back before reporting a successful persistence change.
