# BuilderX VS Code bridge 1.5.1

BuilderX is a local, user-owned bridge between the Phase Manager web page and
the visible Codex Chat session in VS Code. It uses the installed BuilderX
companion extension and the authenticated ChatGPT account already active in
VS Code.

It does not use an OpenAI API key, ChatGPT cookies, private ChatGPT endpoints,
Codex CLI workers, the Codex App Server, a database, or a background task
poller.

## Components

- `server.mjs` — loopback HTTP server on `127.0.0.1:43127`.
- `extension/extension.js` — VS Code companion that receives a short-lived URI
  handoff and invokes the installed Codex command.
- `builderx-bridge.service` — user systemd service that keeps the loopback
  server available after login.
- `extension/package.json` — installable extension manifest, version `1.5.1`.

The server and extension are deliberately separate. The server validates the
workspace and request lifecycle; the extension owns delivery into the visible
VS Code chat.

## HTTP API

All requests are local-only. The configured workspace is
`/var/www/html/developer`.

### `GET /health?workspace_root=...`

Returns readiness and active-session state:

- `code_ready` — the VS Code launcher is readable.
- `context_ready` — the configured workspace is readable and writable.
- `companion_extension_installed` and `companion_extension_version` — the
  BuilderX companion manifest was found and verified.
- `active_thread_ready` and `active_thread_id` — a VS Code Codex session for
  this workspace was detected.
- `active_thread_busy` — a request is currently running.
- `direct_text_send_ready` — the installed Codex extension exposes a supported
  direct-text send command. BuilderX will not treat the TODO-comment command
  as direct text delivery.
- `event_stream` — progress streaming is available.

Send only when all readiness conditions pass and `active_thread_busy` is false.

### `POST /handoff`

Accepts exactly:

```json
{
  "workspace_root": "/var/www/html/developer",
  "command": "Hi"
}
```

The server rejects another workspace, an empty command, commands longer than
2,000 characters, invalid JSON, and requests while the active thread is busy.
  It writes a short-lived mode `0600` request file, opens the VS Code URI
`vscode://builderx.builderx/handoff?request=<uuid>`, and waits for the extension
acknowledgement. If the installed Codex extension exposes neither a supported
direct-text command nor the explicit legacy `chatgpt.implementTodo` command,
the handoff is rejected before any visible chat request.

### `GET /events?request_id=...`

Opens one Server-Sent Events stream for the selected handoff. The bridge uses
filesystem change notifications for that selected Codex rollout; it does not
run a global worker or periodic polling loop.

Event names:

- `ready` — the stream is connected.
- `thread` — the active VS Code Codex thread was identified.
- `status` — Codex started processing.
- `assistant_message` — safe assistant output became available.
- `completed` — the final response is available.
- `failed` — the turn or stream failed.

Private reasoning events and raw tool arguments are never forwarded.

### `GET /result?request_id=...`

The backward-compatible completion endpoint. It returns `pending`,
`completed`, or `failed` and remains available for clients that do not consume
the SSE stream.

### `POST /handoff-result`

Accepts the same two-field body as `/handoff`:

```json
{
  "workspace_root": "/var/www/html/developer",
  "command": "Analyze the requested BuilderX context and write the result file."
}
```

This route is for bounded, read-only specialist results. The bridge creates a
unique mode `0600` file at
`.builderx/runtime/tasks/{request_id}/result.json` containing
`// BUILDERX_RESULT`, then appends the file-only result contract to the
visible handoff. The task must replace only that placeholder with one valid
JSON object and must not edit source code, configuration, databases, or other
files. Completion is accepted only when the task file contains a valid JSON
object; chat prose is not used as the workflow result. The response includes
`delivery.result_file`, and completed `/events` and `/result` responses include
`file_result`.

### `GET /capabilities`

Returns the bridge version, endpoint contract, event names, transport, and
security guarantees for diagnostics and compatibility checks. The current
`parallel_execution` capability reports one task channel and
`supported: false`; the Phase 3 control does not dispatch a false parallel run.
The current Phase 3 test is the supported single-chat specialist workflow:
one visible Codex Chat request contains the Coordinator and bounded
Requirements, Database, and UI/UX analyses, followed by reconciliation in
that same response. It must not be described as true parallel execution.

### `POST /restart`

Clears transient handoff acknowledgements and resets the bridge's busy-state
boundary before opening the VS Code reload URI. This makes stale
`task_started` markers from before the restart stop blocking readiness. New
Codex work created after the restart is still detected as busy. This can
interrupt an active Codex turn and must be confirmed by the caller.

## Companion extension functions

The installed extension:

- activates on startup, URI handoff, and the manual command;
- validates the request ID and active workspace;
- checks that an available Codex send command is available;
- opens the Codex sidebar when supported;
- sends the command to the visible Codex Chat session;
- writes a mode `0600` acknowledgement for the bridge server;
- shows a success or failure notification in VS Code; and
- provides `BuilderX: Send Message to Codex` from the Command Palette.

The extension does not read browser cookies, store credentials, run shell
commands, monitor unrelated workspaces, or expose the visible chat's private
reasoning.

## Installation

```bash
cd /var/www/html/developer/tools/builderx-bridge
npm run check
cd extension
npx --yes @vscode/vsce package --out ../builderx-companion-1.5.1.vsix
cd ..
code --install-extension builderx-companion-1.5.1.vsix --force
systemctl --user link /var/www/html/developer/tools/builderx-bridge/builderx-bridge.service
systemctl --user daemon-reload
systemctl --user enable --now builderx-bridge.service
```

Verify the installed version and readiness:

```bash
code --list-extensions --show-versions | rg '^builderx\.builderx@1\.3\.0$'
curl --fail --silent --show-error \
  'http://127.0.0.1:43127/capabilities' | jq .
curl --fail --silent --show-error \
  'http://127.0.0.1:43127/health?workspace_root=%2Fvar%2Fwww%2Fhtml%2Fdeveloper' | jq .
```

## Test flow

```bash
HEALTH=$(curl --fail --silent \
  'http://127.0.0.1:43127/health?workspace_root=%2Fvar%2Fwww%2Fhtml%2Fdeveloper')
echo "$HEALTH" | jq -e '
  .ok == true and
  .workspace == "/var/www/html/developer" and
  .code_ready == true and
  .context_ready == true and
  .companion_extension_installed == true and
  (.active_thread_busy == false)
'

RESPONSE=$(curl --fail --silent --request POST \
  'http://127.0.0.1:43127/handoff' \
  -H 'Content-Type: application/json' \
  --data '{"workspace_root":"/var/www/html/developer","command":"Hi"}')
echo "$RESPONSE" | jq .
REQUEST_ID=$(echo "$RESPONSE" | jq -r '.delivery.request_id')

curl --fail --silent --no-buffer \
  "http://127.0.0.1:43127/events?request_id=${REQUEST_ID}"
```

The final response is returned by the `completed` event. Clients may use
`/result` instead of `/events` when they need the original request/response
contract.

## Delivery mode

The current official Codex VS Code extension exposes `chatgpt.implementTodo`,
`chatgpt.newChat`, and `chatgpt.newCodexPanel`, but no supported direct-text
send command. BuilderX can now use `chatgpt.implementTodo` again as an explicit
legacy mode when requested. That mode creates an implementation wrapper around
the command; it is not equivalent to sending exact text into the composer.
The result-file route makes that wrapper compatible with bounded specialist
work by treating the disposable task JSON file, rather than the wrapper's chat
reply, as the authoritative result.
