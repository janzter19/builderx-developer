---
name: database-transaction
description: Implement BuilderX database writes with ADODB transaction boundaries, parameterized SQL, complete create/update upserts, schema-safe identifiers, authorization, audit logging, direct read-back verification, and server rehydration. Use whenever a form, tab, CRUD action, or workflow creates or updates persisted data.
---

# Database Transaction

Use this skill for every BuilderX persistence change. Keep the database as the source of truth and trace the complete request, transaction, commit, and read-back path.

## Required implementation rules

- Always use the existing ADODB connection from `bx_db()` and its parameterized `Execute`, `GetRow`, `GetAll`, and `GetOne` methods. Do not introduce direct mysqli, PDO, or string-concatenated user values.
- Use ADODB transaction methods: call `BeginTrans()` before related writes, `CommitTrans()` only after all writes and verification succeed, and `RollbackTrans()` in every failure path.
- Use parameterized SQL statements and keep the syntax compatible with the configured driver. This project currently uses MySQL/MariaDB through ADODB; for a single-row create/update workflow, prefer one complete `INSERT ... ON DUPLICATE KEY UPDATE` upsert over separate fragile create/update branches.
- Treat the upsert as incomplete until both paths are handled: create a new row when the business key is absent, update the existing row when the business key already exists, preserve the stable record key on update, and set the audit action from the existing-row state.
- Check every ADODB write result for `false` immediately and capture the database error before issuing another query. Never let a silent failed `Execute()` become a misleading read-back error.
- Keep table and column identifiers fixed or strictly allow-listed. Never interpolate a user-provided table name into SQL. Apply the project `phase_builder_` prefix to Phase Builder tables.
- Make schema setup idempotent with `CREATE TABLE IF NOT EXISTS` and explicit keys, indexes, nullability, status, ownership, and timestamps.
- Validate authentication, administrator authorization, CSRF, record ownership/scope, required fields, and input lengths on the server before opening the transaction.
- Record a concise audit event inside the same transaction when the write is an auditable create or update.
- Read the saved row back after the write, compare the stable record key, business key, and every persisted field, then commit. Normalize only harmless key formatting such as surrounding whitespace/case where the identifier contract allows it. A successful HTTP response or affected-row count alone is not persistence evidence.
- After redirect or refresh, load the saved row from the server payload and map database column names to UI field names explicitly. Never let hard-coded form defaults replace a saved value because of hyphen/underscore or other naming differences.
- On failure, roll back, return a safe user-facing error, and never expose SQL, credentials, or internal connection details.

## Verification checklist

- Confirm the table and indexes exist through a safe schema read.
- Exercise both create and update using the real UI action.
- Verify the committed keys and values with a direct ADODB read-back, after the redirect, and after a full page reload.
- Confirm the UI is populated from the saved server payload, not fallback/default text.
- Test invalid CSRF, unauthorized access, missing required input, and transaction failure paths.
- Run PHP lint, focused route checks, and relevant frontend build checks.
