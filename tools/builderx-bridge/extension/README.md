# BuilderX VS Code companion 1.5.1

This extension is the delivery component for the BuilderX loopback bridge. It
receives a short-lived local handoff file and sends its command through the
installed VS Code Codex extension.

## Activation and commands

- `onStartupFinished` — registers the companion after VS Code startup.
- `vscode://builderx.builderx/health?request=<uuid>` — performs a bounded live readiness probe without sending an AI request.
- `onUri` — receives `vscode://builderx.builderx/handoff?request=<uuid>`.
- `BuilderX: Send Message to Codex` — opens a manual prompt from the Command
  Palette.

## Safety boundaries

- Validates the request ID and workspace before delivery.
- Uses `chatgpt.implementTodo` in explicit legacy mode. This command rewrites
  plain text into a TODO-comment implementation wrapper; it is not direct text
  delivery.
- Reports the active workspace and whether the Codex send command is available to the bridge health endpoint.
- Writes acknowledgement files with mode `0600`.
- Does not store credentials or access browser cookies.
- Does not run Codex CLI, a background worker, or a shell command.
- Does not expose private reasoning, raw tool arguments, or UI keystrokes.

The bridge server observes the selected Codex rollout separately and exposes
safe lifecycle events through its SSE endpoint.
