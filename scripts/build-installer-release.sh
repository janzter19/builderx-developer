#!/usr/bin/env bash
set -euo pipefail

SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
OUTPUT_ROOT="${1:-${SOURCE_ROOT}/../_installer/releases}"
GIT_BIN="${GIT_BIN:-git}"

die() {
    printf 'BuilderX release error: %s\n' "$*" >&2
    exit 1
}

git_value() {
    "$GIT_BIN" -C "$SOURCE_ROOT" "$@"
}

git_value rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "${SOURCE_ROOT} is not a Git worktree."

REVISION="$(git_value rev-parse HEAD 2>/dev/null)" || die "Git source has no committed HEAD. Commit the source before building a release."
SOURCE_STATUS="$(git_value status --porcelain --untracked-files=all)"
if [[ -n "$SOURCE_STATUS" ]]; then
    die "Git source is not clean. Commit or remove source changes before building a release."
fi

command -v tar >/dev/null 2>&1 || die "tar is required."
command -v npm >/dev/null 2>&1 || die "npm is required to build the frontend release."

RELEASE_VERSION="$(git_value describe --tags --always 2>/dev/null || printf 'git-%s' "${REVISION:0:12}")"
SAFE_VERSION="$(printf '%s' "$RELEASE_VERSION" | tr -c 'A-Za-z0-9._-' '-')"
BUILD_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/builderx-release.XXXXXX")"
STAGE_ROOT="${BUILD_ROOT}/builderx"
ARCHIVE_PATH="${OUTPUT_ROOT}/builderx-${SAFE_VERSION}.tar.gz"
MANIFEST_PATH="${OUTPUT_ROOT}/builderx-${SAFE_VERSION}.manifest.json"

cleanup() {
    rm -rf "$BUILD_ROOT"
}
trap cleanup EXIT

mkdir -p "$OUTPUT_ROOT"
git_value archive --format=tar --prefix=builderx/ HEAD | tar -xf - -C "$BUILD_ROOT"

[[ -f "${STAGE_ROOT}/frontend/package-lock.json" ]] || die "The committed frontend package lock is missing."
npm ci --prefix "$STAGE_ROOT/frontend" --ignore-scripts
npm run build --prefix "$STAGE_ROOT/frontend"

# Dependencies are required to build the staged frontend, but must never ship
# inside the installer archive. The target project installs them separately.
rm -rf "${STAGE_ROOT}/frontend/node_modules"

[[ -f "${STAGE_ROOT}/frontend/dist/.vite/manifest.json" ]] || die "Frontend build did not produce a Vite manifest."
[[ -f "${STAGE_ROOT}/tools/builderx-bridge/server.mjs" ]] || die "The committed BuilderX bridge is missing."
find "$STAGE_ROOT" -type f \( -name '.env' -o -name 'config.local.php' \) -print -quit | grep -q . && die "Release contains a local configuration file."

FILE_COUNT="$(find "$STAGE_ROOT" -type f -not -path '*/node_modules/*' -not -path '*/vendor/*' | wc -l | tr -d ' ')"
CREATED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf '{\n  "schemaVersion": "builderx.release.v1",\n  "release": %s,\n  "sourceRevision": %s,\n  "createdAt": %s,\n  "fileCount": %s,\n  "frontendManifest": "frontend/dist/.vite/manifest.json",\n  "bridge": "tools/builderx-bridge/server.mjs"\n}\n' \
    "$(printf '%s' "$SAFE_VERSION" | php -r '$v=stream_get_contents(STDIN); echo json_encode($v, JSON_UNESCAPED_SLASHES);')" \
    "$(printf '%s' "$REVISION" | php -r '$v=stream_get_contents(STDIN); echo json_encode($v, JSON_UNESCAPED_SLASHES);')" \
    "$(printf '%s' "$CREATED_AT" | php -r '$v=stream_get_contents(STDIN); echo json_encode($v, JSON_UNESCAPED_SLASHES);')" \
    "$FILE_COUNT" > "$MANIFEST_PATH"

tar -czf "$ARCHIVE_PATH" -C "$BUILD_ROOT" builderx
printf 'Release archive: %s\nManifest: %s\nSource revision: %s\n' "$ARCHIVE_PATH" "$MANIFEST_PATH" "$REVISION"
