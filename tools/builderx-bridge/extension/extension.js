const vscode = require('vscode')
const crypto = require('node:crypto')
const fs = require('node:fs/promises')
const os = require('node:os')
const path = require('node:path')

const requestDirectory = path.join(os.tmpdir(), 'builderx-bridge')
const extensionVersion = '1.5.1'

function validRequestId(value) {
  return /^[0-9a-f-]{36}$/.test(value)
}

function currentWorkspace() {
  return vscode.workspace.workspaceFolders?.[0]?.uri.fsPath || process.env.BUILDERX_WORKSPACE_ROOT || ''
}

async function writeSecure(filePath, content) {
  await fs.writeFile(filePath, content, { mode: 0o600 })
  await fs.chmod(filePath, 0o600)
}

async function writeAcknowledgement(requestId, state, message = '', extra = {}) {
  const acknowledgementPath = path.join(requestDirectory, `${requestId}.ack.json`)
  await fs.mkdir(requestDirectory, { recursive: true, mode: 0o700 })
  await writeSecure(acknowledgementPath, JSON.stringify({ request_id: requestId, state, message, ...extra }))
}

async function dispatchRequest(requestId) {
  if (!validRequestId(requestId)) throw new Error('BuilderX received an invalid request ID.')
  const requestPath = path.join(requestDirectory, `${requestId}.json`)
  let payload
  try {
    payload = JSON.parse(await fs.readFile(requestPath, 'utf8'))
  } catch {
    throw new Error('BuilderX could not read the handoff request file.')
  }

  const root = currentWorkspace()
  if (!root) throw new Error('Open the BuilderX project folder in VS Code before sending a request.')
  if (payload.workspace_root && path.resolve(payload.workspace_root) !== path.resolve(root)) {
    throw new Error('BuilderX is open in a different workspace than the handoff request.')
  }

  const command = String(payload.command || '').trim()
  if (!command) throw new Error('BuilderX did not receive a message to send.')
  const commands = await vscode.commands.getCommands(true)
  if (!commands.includes('chatgpt.implementTodo')) {
    throw new Error('The OpenAI Codex VS Code extension is not active.')
  }
  if (commands.includes('chatgpt.openSidebar')) await vscode.commands.executeCommand('chatgpt.openSidebar')
  await vscode.commands.executeCommand('chatgpt.implementTodo', {
    cwd: root,
    fileName: 'BuilderX request.txt',
    line: 1,
    comment: command,
  })
  await writeAcknowledgement(requestId, 'submitted', 'Codex received the request through chatgpt.implementTodo; the visible prompt includes the implementation wrapper.', { workspace: root, delivery_mode: 'legacy-implement-todo-wrapper' })
  void vscode.window.showInformationMessage('BuilderX sent the request to the visible Codex Chat using the implementation-wrapper delivery mode.')
}

async function handleRequest(requestId) {
  try {
    await dispatchRequest(requestId)
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    await writeAcknowledgement(requestId, 'error', message).catch(() => {})
    void vscode.window.showErrorMessage(`BuilderX could not send the Codex request: ${message}`)
  }
}

async function handleHealthRequest(requestId) {
  try {
    if (!validRequestId(requestId)) throw new Error('BuilderX received an invalid readiness probe ID.')
    const root = currentWorkspace()
    if (!root) throw new Error('Open the BuilderX project folder in VS Code before checking readiness.')
    const commands = await vscode.commands.getCommands(true)
    const directTextSendReady = commands.some((command) => ['chatgpt.sendMessage', 'chatgpt.sendText', 'chatgpt.sendPrompt'].includes(command))
    const legacyWrapperAvailable = commands.includes('chatgpt.implementTodo')
    const senderReady = directTextSendReady || legacyWrapperAvailable
    const message = directTextSendReady
      ? 'A supported direct-text Codex Chat command is available.'
      : legacyWrapperAvailable
        ? 'Using chatgpt.implementTodo; the visible prompt includes the implementation wrapper.'
        : 'The Codex send command is not active.'
    await writeAcknowledgement(requestId, senderReady ? 'ready' : 'error', message, {
      workspace: root,
      extension_active: true,
      command_ready: senderReady,
      direct_text_send_ready: directTextSendReady,
      legacy_wrapper_available: legacyWrapperAvailable,
      delivery_mode: directTextSendReady ? 'direct-text' : legacyWrapperAvailable ? 'legacy-implement-todo-wrapper' : 'unavailable',
      extension_version: extensionVersion,
    })
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    await writeAcknowledgement(requestId, 'error', message, {
      extension_active: false,
      command_ready: false,
      extension_version: extensionVersion,
    }).catch(() => {})
  }
}

async function sendManualPrompt() {
  const prompt = await vscode.window.showInputBox({
    title: 'BuilderX → Codex Chat',
    prompt: 'Message to send to the visible Codex Chat',
    ignoreFocusOut: true,
  })
  if (!prompt?.trim()) return
  const requestId = crypto.randomUUID()
  await fs.mkdir(requestDirectory, { recursive: true, mode: 0o700 })
  const root = currentWorkspace()
  await writeSecure(path.join(requestDirectory, `${requestId}.json`), JSON.stringify({
    request_id: requestId,
    workspace_root: root,
    command: prompt.trim().slice(0, 2000),
  }))
  await handleRequest(requestId)
}

function activate(context) {
  context.subscriptions.push(
    vscode.commands.registerCommand('builderx.sendToCodex', sendManualPrompt),
    vscode.window.registerUriHandler({
      handleUri: async (uri) => {
        const requestId = new URLSearchParams(uri.query).get('request') || ''
        if (uri.path === '/health') {
          await handleHealthRequest(requestId)
          return
        }
        if (uri.path === '/handoff') await handleRequest(requestId)
      },
    }),
  )
}

function deactivate() {}

module.exports = { activate, deactivate }
