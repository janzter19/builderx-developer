# Git Source and Installer Releases

BuilderX uses the developer Git worktree as the source of installer releases.

## Source of truth

```text
/var/www/html/developer       Git source worktree
/var/www/html/_installer      installer application and release output
```

The Git worktree must contain source, migrations, deployment templates,
documentation, bridge source, and approved bridge packages. It must not contain
live configuration, credentials, AI runtime state, database exports, uploads,
logs, queues, or backups.

## Release process

1. Make and verify the source change in the developer worktree.
2. Commit the source change.
3. Run `scripts/build-installer-release.sh`.
4. Refresh the clean installer template from the same committed revision.
5. Run the installer preflight and confirm Git source/template alignment.

The release builder uses `git archive`, so untracked files cannot silently enter
an installer release. Frontend dependencies and the production Vite build are
created in a temporary staging directory. The resulting archive contains the
compiled frontend but no `node_modules` or backend `vendor` directory.

## New computer versus new project

Moving an existing installation requires a database backup, approved runtime
data, and protected configuration. Creating a new project uses the release
archive, creates a new database and runtime user, applies the schema, seeds only
system data, and creates an empty business workspace.

Git clone alone is not an installation. The installer remains responsible for
database provisioning, web-server configuration, permissions, storage folders,
administrator setup, and the per-user VS Code/Codex bridge service.
