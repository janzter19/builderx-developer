#!/usr/bin/env node

import { spawn } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { access, chmod, mkdir, open, readFile, readdir, realpath, rm, stat, unlink, writeFile } from 'node:fs/promises'
import { constants as fsConstants } from 'node:fs'
import { watch } from 'node:fs'
import http from 'node:http'
import os from 'node:os'
import path from 'node:path'

const host = '127.0.0.1'
const port = Number.parseInt(process.env.BUILDERX_BRIDGE_PORT || '43127', 10)
const workspaceRoot = path.resolve(process.env.BUILDERX_WORKSPACE_ROOT || '/var/www/html/developer')
const codeExecutable = process.env.BUILDERX_CODE_EXECUTABLE || '/usr/bin/code'
const codexHome = process.env.CODEX_HOME || path.join(os.homedir(), '.codex')
const sessionDirectory = path.join(codexHome, 'sessions')
const extensionDirectory = path.join(os.homedir(), '.vscode', 'extensions')
const requestDirectory = path.join(os.tmpdir(), 'builderx-bridge')
const resultTaskDirectory = path.join(workspaceRoot, '.builderx', 'runtime', 'tasks')
const resultPlaceholder = '// BUILDERX_RESULT'
const maxBodyBytes = 256 * 1024
const bridgeVersion = '1.5.1'
const handoffResults = new Map()
let sessionBusyResetAt = 0

const allowedHosts = new Set(['localhost', '127.0.0.1'])

async function readable(filePath) {
  try {
    await access(filePath, fsConstants.R_OK)
    return true
  } catch {
    return false
  }
}

async function writable(directory) {
  try {
    await access(directory, fsConstants.R_OK | fsConstants.W_OK | fsConstants.X_OK)
    return true
  } catch {
    return false
  }
}

async function canonical(filePath) {
  try {
    return await realpath(filePath)
  } catch {
    return path.resolve(filePath)
  }
}

async function workspaceMatches(candidate) {
  if (!candidate) return true
  return (await canonical(candidate)) === (await canonical(workspaceRoot))
}

function originFor(request) {
  const raw = request.headers.origin
  if (!raw) return ''
  try {
    const origin = new URL(raw)
    if (!['http:', 'https:'].includes(origin.protocol) || !allowedHosts.has(origin.hostname)) return ''
    return `${origin.protocol}//${origin.host}`
  } catch {
    return ''
  }
}

function originAllowed(request) {
  return !request.headers.origin || originFor(request) !== ''
}

function applyHeaders(request, response) {
  const origin = originFor(request)
  if (origin) {
    response.setHeader('Access-Control-Allow-Origin', origin)
    response.setHeader('Vary', 'Origin')
  }
  response.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
  response.setHeader('Access-Control-Allow-Headers', 'Content-Type')
  response.setHeader('Access-Control-Allow-Private-Network', 'true')
  response.setHeader('Cache-Control', 'no-store')
}

function sendJson(request, response, status, payload) {
  applyHeaders(request, response)
  response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' })
  response.end(JSON.stringify(payload))
}

async function readJson(request) {
  const chunks = []
  let received = 0
  for await (const chunk of request) {
    received += chunk.length
    if (received > maxBodyBytes) throw new Error('BuilderX bridge request is too large.')
    chunks.push(chunk)
  }
  const text = Buffer.concat(chunks).toString('utf8')
  if (!text) return {}
  try {
    return JSON.parse(text)
  } catch {
    throw new Error('BuilderX bridge received invalid JSON.')
  }
}

async function listFiles(directory) {
  const files = []
  async function visit(current) {
    let entries
    try {
      entries = await readdir(current, { withFileTypes: true })
    } catch {
      return
    }
    for (const entry of entries) {
      const next = path.join(current, entry.name)
      if (entry.isDirectory()) await visit(next)
      else if (entry.isFile() && entry.name.endsWith('.jsonl')) files.push(next)
    }
  }
  await visit(directory)
  return files
}

async function listDirectories(directory) {
  const directories = [directory]
  let entries
  try {
    entries = await readdir(directory, { withFileTypes: true })
  } catch {
    return directories
  }
  for (const entry of entries) {
    if (entry.isDirectory()) directories.push(...await listDirectories(path.join(directory, entry.name)))
  }
  return directories
}

function decodeJsonString(value) {
  try {
    return JSON.parse(`"${value}"`)
  } catch {
    return value
  }
}

async function latestTurnState(filePath, fileSize) {
  const start = Math.max(0, fileSize - 1024 * 1024)
  const file = await open(filePath, 'r')
  try {
    const buffer = Buffer.alloc(fileSize - start)
    await file.read(buffer, 0, buffer.length, start)
    const text = buffer.toString('utf8')
    const markers = [
      ['task_started', '"type":"task_started"'],
      ['task_complete', '"type":"task_complete"'],
      ['turn_aborted', '"type":"turn_aborted"'],
    ]
    let latest = { state: '', index: -1 }
    for (const [state, marker] of markers) {
      const index = text.lastIndexOf(marker)
      if (index > latest.index) latest = { state, index }
    }
    return latest.state
  } finally {
    await file.close()
  }
}

async function discoverActiveSession(options = {}) {
  const after = Number(options.after || 0)
  const exclude = String(options.exclude || '')
  const expectedWorkspace = await canonical(workspaceRoot)
  const candidates = []
  for (const filePath of await listFiles(sessionDirectory)) {
    if (filePath === exclude) continue
    let fileStat
    try {
      fileStat = await stat(filePath)
      if (fileStat.mtimeMs < after) continue
      const file = await open(filePath, 'r')
      const headSize = Math.min(64 * 1024, fileStat.size)
      const buffer = Buffer.alloc(headSize)
      await file.read(buffer, 0, buffer.length, 0)
      await file.close()
      const head = buffer.toString('utf8')
      const sessionId = head.match(/"(?:session_id|id)":"([0-9a-f-]{36})"/)?.[1] || ''
      const rawCwd = head.match(/"cwd":"((?:\\.|[^"\\])*)"/)?.[1] || ''
      const source = head.match(/"(?:originator|source)":"([^"]+)"/)?.[1] || ''
      if (!sessionId || !['codex_vscode', 'vscode'].includes(source)) continue
      if ((await canonical(decodeJsonString(rawCwd))) !== expectedWorkspace) continue
      candidates.push({
        sessionId,
        filePath,
        fileSize: fileStat.size,
        modifiedAt: fileStat.mtimeMs,
        busy: (await latestTurnState(filePath, fileStat.size)) === 'task_started'
          && (sessionBusyResetAt === 0 || fileStat.mtimeMs >= sessionBusyResetAt),
      })
    } catch {
      // Ignore incomplete or inaccessible rollout files.
    }
  }
  candidates.sort((left, right) => right.modifiedAt - left.modifiedAt)
  return candidates[0] || null
}

async function installedExtensionMetadata() {
  try {
    const entries = await readdir(extensionDirectory)
    const installed = []
    for (const entry of entries.filter((candidate) => candidate.startsWith('builderx.builderx-'))) {
      try {
        const packageJson = JSON.parse(await readFile(path.join(extensionDirectory, entry, 'package.json'), 'utf8'))
        if (packageJson.name === 'builderx' && packageJson.publisher === 'builderx') {
          installed.push({ name: packageJson.name, publisher: packageJson.publisher, version: packageJson.version || '' })
        }
      } catch {
        // Ignore stale or incomplete extension directories.
      }
    }
    installed.sort((left, right) => right.version.localeCompare(left.version, undefined, { numeric: true }))
    return installed[0] || null
  } catch {
    return null
  }
}

async function readHandoffSessionResult(filePath, startOffset) {
  let fileStat
  try {
    fileStat = await stat(filePath)
  } catch {
    return { status: 'pending', processing: false }
  }
  if (fileStat.size <= startOffset) return { status: 'pending', processing: false }

  const start = Math.max(startOffset, fileStat.size - 8 * 1024 * 1024)
  const file = await open(filePath, 'r')
  try {
    const buffer = Buffer.alloc(fileStat.size - start)
    await file.read(buffer, 0, buffer.length, start)
    const lines = buffer.toString('utf8').split('\n')
    let processing = false
    let completed = ''
    let aborted = false
    let latestAssistant = ''
    for (const line of lines) {
      if (!line.trim()) continue
      let event
      try {
        event = JSON.parse(line)
      } catch {
        continue
      }
      if (event.type === 'event_msg' && event.payload?.type === 'task_started') processing = true
      if (event.type === 'event_msg' && event.payload?.type === 'turn_aborted') {
        aborted = true
        processing = false
      }
      if (event.type === 'event_msg' && event.payload?.type === 'task_complete') {
        processing = false
        completed = String(event.payload.last_agent_message || event.payload.message || '').trim()
      }
      if (event.type === 'response_item' && event.payload?.type === 'message' && event.payload.role === 'assistant') {
        const text = Array.isArray(event.payload.content)
          ? event.payload.content.filter((item) => item?.type === 'output_text').map((item) => String(item.text || '')).join('\n').trim()
          : ''
        if (text) latestAssistant = text
      }
    }
    if (aborted) return { status: 'failed', message: 'The Codex turn was interrupted before BuilderX received a reply.' }
    if (completed || (!processing && latestAssistant && lines.length > 1)) {
      return { status: 'completed', result: completed || latestAssistant }
    }
    return { status: 'pending', processing }
  } finally {
    await file.close()
  }
}

async function readResultFile(handoff) {
  if (!handoff.resultFilePath) return null
  let lastError = ''
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      const raw = (await readFile(handoff.resultFilePath, 'utf8')).trim()
      if (!raw) throw new Error('The BuilderX result file is empty.')
      if (raw === resultPlaceholder) throw new Error('The BuilderX result file still contains its placeholder.')
      const parsed = JSON.parse(raw)
      if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') throw new Error('The BuilderX result file must contain one JSON object.')
      return {
        status: 'completed',
        result: JSON.stringify(parsed),
        file_result: parsed,
        result_file: handoff.resultFileRelativePath,
      }
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error)
      if (attempt < 2) await new Promise((resolve) => setTimeout(resolve, 250))
    }
  }
  return { status: 'failed', message: `${lastError} The workflow stopped before accepting the result.` }
}

function assistantTextFromEvent(event) {
  if (event?.type !== 'response_item' || event.payload?.type !== 'message' || event.payload.role !== 'assistant') return ''
  if (!Array.isArray(event.payload.content)) return ''
  return event.payload.content
    .filter((item) => item?.type === 'output_text')
    .map((item) => String(item.text || ''))
    .join('\n')
    .trim()
}

function messageTextFromEvent(event) {
  if (event?.type === 'event_msg' && event.payload?.type === 'user_message') {
    return String(event.payload.message || '')
  }
  if (event?.type !== 'response_item' || event.payload?.type !== 'message' || event.payload.role !== 'user') return ''
  if (!Array.isArray(event.payload.content)) return ''
  return event.payload.content
    .filter((item) => item?.type === 'input_text')
    .map((item) => String(item.text || ''))
    .join('\n')
    .trim()
}

async function readHandoffSessionEvents(filePath, startOffset, command, createdAt, alreadyStarted) {
  let fileStat
  try {
    fileStat = await stat(filePath)
  } catch {
    return { events: [], nextOffset: startOffset, terminal: null, started: alreadyStarted }
  }
  if (fileStat.size <= startOffset) return { events: [], nextOffset: startOffset, terminal: null, started: alreadyStarted }

  const file = await open(filePath, 'r')
  try {
    const buffer = Buffer.alloc(fileStat.size - startOffset)
    await file.read(buffer, 0, buffer.length, startOffset)
    const text = buffer.toString('utf8')
    const completeEnd = text.lastIndexOf('\n')
    if (completeEnd < 0) return { events: [], nextOffset: startOffset, terminal: null, started: alreadyStarted }

    const events = []
    let terminal = null
    let latestAssistant = ''
    let started = alreadyStarted
    const normalizedCommand = command.toLowerCase()
    for (const line of text.slice(0, completeEnd).split('\n')) {
      if (!line.trim()) continue
      let event
      try {
        event = JSON.parse(line)
      } catch {
        continue
      }
      const eventTime = Date.parse(String(event.timestamp || ''))
      if (Number.isFinite(eventTime) && eventTime < createdAt) continue
      const payloadType = event.type === 'event_msg' ? event.payload?.type : ''
      const userText = messageTextFromEvent(event).toLowerCase()
      if (!started && normalizedCommand && userText.includes(normalizedCommand)) {
        started = true
        events.push({ event: 'status', data: { status: 'running', message: 'Codex Chat received the request.' } })
      }
      if (payloadType === 'task_started') {
        started = true
        events.push({ event: 'status', data: { status: 'running', message: 'Codex Chat started processing the request.' } })
      } else if (started && payloadType === 'turn_aborted') {
        terminal = { event: 'failed', data: { status: 'failed', message: 'The Codex turn was interrupted before BuilderX received a reply.' } }
      } else if (started && payloadType === 'task_complete') {
        const result = String(event.payload?.last_agent_message || event.payload?.message || latestAssistant || '').trim()
        terminal = { event: 'completed', data: { status: 'completed', result } }
      }

      const assistantText = assistantTextFromEvent(event)
      if (started && assistantText && assistantText !== latestAssistant) {
        latestAssistant = assistantText
        events.push({ event: 'assistant_message', data: { status: 'running', text: assistantText } })
      }
    }
    return { events, nextOffset: startOffset + completeEnd + 1, terminal, started }
  } finally {
    await file.close()
  }
}

function writeSse(response, event, data) {
  response.write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`)
}

async function streamHandoffEvents(request, response, requestId) {
  const handoff = handoffResults.get(requestId)
  if (!handoff) throw new Error('The BuilderX handoff result is unavailable or has expired.')

  applyHeaders(request, response)
  response.setHeader('Content-Type', 'text/event-stream; charset=utf-8')
  response.setHeader('Connection', 'keep-alive')
  response.setHeader('X-Accel-Buffering', 'no')
  response.writeHead(200)

  let closed = false
  let reading = false
  let dirty = false
  let filePath = handoff.filePath || ''
  let offset = handoff.startOffset
  let started = false
  let lastAssistant = ''
  const watchers = new Map()

  const cleanup = () => {
    if (closed) return
    closed = true
    for (const watcher of watchers.values()) watcher.close()
    watchers.clear()
    if (!response.writableEnded) response.end()
  }
  const send = (event, data) => {
    if (!closed && !response.writableEnded) writeSse(response, event, data)
  }
  const processEvents = async () => {
    if (closed) return
    if (reading) {
      dirty = true
      return
    }
    reading = true
    try {
      do {
        dirty = false
        const active = await discoverActiveSession({ after: handoff.createdAt, exclude: handoff.filePath })
        if (active && active.filePath !== filePath) {
          filePath = active.filePath
          offset = 0
          send('thread', { thread_id: active.sessionId, message: 'Connected to the active VS Code Codex Chat session.' })
        }
        if (!filePath) continue
        const batch = await readHandoffSessionEvents(filePath, offset, handoff.command || '', handoff.createdAt, started)
        offset = batch.nextOffset
        started = batch.started
        for (const item of batch.events) {
          if (item.event === 'assistant_message') {
            if (item.data.text === lastAssistant) continue
            lastAssistant = item.data.text
          }
          send(item.event, item.data)
        }
        if (batch.terminal) {
          const terminal = batch.terminal.event === 'failed'
            ? { status: 'failed', message: batch.terminal.data.message || 'The Codex turn was interrupted before BuilderX received a result.' }
            : handoff.resultFilePath
              ? await readResultFile(handoff)
              : { status: 'completed', result: batch.terminal.data.result }
          handoff.result = terminal
          if (terminal.status === 'completed') send('completed', terminal)
          else send('failed', terminal)
          await disposeResultTask(handoff)
          cleanup()
          break
        }
      } while (dirty && !closed)
    } catch (error) {
      send('failed', { status: 'failed', message: error instanceof Error ? error.message : 'BuilderX could not read the Codex progress stream.' })
      cleanup()
    } finally {
      reading = false
    }
  }

  send('ready', { request_id: requestId, status: 'submitted', message: 'BuilderX is listening for Codex Chat progress.' })
  request.once('close', cleanup)
  response.once('close', cleanup)
  const attachWatchers = async () => {
    for (const directory of await listDirectories(sessionDirectory)) {
      if (watchers.has(directory)) continue
      try {
        const watcher = watch(directory, { persistent: false }, () => {
          void processEvents()
          void attachWatchers()
        })
        watchers.set(directory, watcher)
      } catch {
        // A session directory may disappear during cleanup or rotation.
      }
    }
  }
  await attachWatchers()
  if (!watchers.size) {
    send('failed', { status: 'failed', message: 'BuilderX could not watch the Codex session directories.' })
    cleanup()
    return
  }
  await processEvents()
}

async function readTrackedResult(handoff) {
  if (handoff.result) return handoff.result
  if (handoff.filePath) {
    const result = await readHandoffSessionResult(handoff.filePath, handoff.startOffset)
    if (result.status !== 'pending') return handoff.resultFilePath && result.status === 'completed' ? await readResultFile(handoff) : result
  }
  const active = await discoverActiveSession()
  if (active && active.modifiedAt >= handoff.createdAt && active.filePath !== handoff.filePath) {
    handoff.filePath = active.filePath
    handoff.startOffset = 0
    const result = await readHandoffSessionResult(active.filePath, 0)
    return handoff.resultFilePath && result.status === 'completed' ? await readResultFile(handoff) : result
  }
  return { status: 'pending', processing: active?.busy === true }
}

async function disposeResultTask(handoff) {
  if (!handoff.resultFilePath) return
  await rm(path.dirname(handoff.resultFilePath), { recursive: true, force: true }).catch(() => {})
  handoff.resultFilePath = ''
}

async function readAcknowledgement(requestId) {
  const acknowledgementPath = path.join(requestDirectory, `${requestId}.ack.json`)
  try {
    const acknowledgement = JSON.parse(await readFile(acknowledgementPath, 'utf8'))
    await unlink(acknowledgementPath).catch(() => {})
    return acknowledgement?.request_id === requestId ? acknowledgement : null
  } catch {
    return null
  }
}

async function waitForAcknowledgement(requestId) {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    const acknowledgement = await readAcknowledgement(requestId)
    if (acknowledgement) return acknowledgement
    await new Promise((resolve) => setTimeout(resolve, 250))
  }
  throw new Error('The BuilderX VS Code extension did not acknowledge the handoff. Reload VS Code and try again.')
}

async function probeExtension() {
  const requestId = randomUUID()
  const healthAckPath = path.join(requestDirectory, `${requestId}.ack.json`)
  await mkdir(requestDirectory, { recursive: true, mode: 0o700 })
  try {
    await launchUri(`vscode://builderx.builderx/health?request=${encodeURIComponent(requestId)}`)
    for (let attempt = 0; attempt < 32; attempt += 1) {
      const acknowledgement = await readAcknowledgement(requestId)
      if (acknowledgement) return acknowledgement
      await new Promise((resolve) => setTimeout(resolve, 250))
    }
    return { state: 'error', message: 'The BuilderX extension did not answer its readiness probe.' }
  } catch (error) {
    return { state: 'error', message: error instanceof Error ? error.message : String(error) }
  } finally {
    await unlink(healthAckPath).catch(() => {})
  }
}

function launchUri(uri) {
  return new Promise((resolve, reject) => {
    const child = spawn(codeExecutable, ['--reuse-window', '--open-url', uri], {
      cwd: workspaceRoot,
      env: process.env,
      stdio: ['ignore', 'ignore', 'pipe'],
    })
    let stderr = ''
    child.stderr.on('data', (chunk) => { stderr += chunk.toString() })
    child.once('error', reject)
    child.once('exit', (code) => {
      if (code === 0) resolve()
      else reject(new Error(stderr.trim() || `VS Code rejected the BuilderX URI with exit code ${code}.`))
    })
  })
}

async function requestPath(requestId) {
  await mkdir(requestDirectory, { recursive: true, mode: 0o700 })
  const target = path.join(requestDirectory, `${requestId}.json`)
  await writeFile(target, '', { mode: 0o600 })
  await chmod(target, 0o600)
  return target
}

async function createResultTask(requestId) {
  const taskDirectory = path.join(resultTaskDirectory, requestId)
  const resultPath = path.join(taskDirectory, 'result.json')
  await mkdir(taskDirectory, { recursive: true, mode: 0o700 })
  await writeFile(resultPath, resultPlaceholder, { mode: 0o600 })
  await chmod(resultPath, 0o600)
  return {
    absolutePath: resultPath,
    relativePath: path.relative(workspaceRoot, resultPath).split(path.sep).join('/'),
  }
}

function resultFileCommand(command, resultFileRelativePath) {
  return [
    'Implement this TODO by analyzing the referenced project files.',
    '',
    'Do not modify any project file, source code, configuration, or database.',
    '',
    `Replace only the comment \`${resultPlaceholder}\` in:`,
    resultFileRelativePath,
    '',
    'Replace it with exactly one valid JSON object required by the task. The JSON file is the required implementation result; do not return the result object in chat.',
    'Do not edit anything else.',
    '',
    'BuilderX task:',
    command,
  ].join('\n')
}

async function validateHandoff(payload) {
  const unsupportedFields = Object.keys(payload || {}).filter((field) => !['workspace_root', 'command'].includes(field))
  if (unsupportedFields.length > 0) throw new Error('BuilderX /handoff accepts only workspace_root and command.')
  const requestedWorkspace = String(payload.workspace_root || '').trim()
  if (!(await workspaceMatches(requestedWorkspace))) throw new Error('The BuilderX request targets a different workspace.')
  if (!(await writable(workspaceRoot))) throw new Error('The BuilderX workspace is not readable and writable by the VS Code user.')
  const command = String(payload.command || '').trim()
  if (!command || command.length > 2000) throw new Error('BuilderX requires a non-empty message of 2,000 characters or fewer.')
  return { command }
}

async function handleHandoff(payload, options = {}) {
  const validated = await validateHandoff(payload)
  const currentSession = await discoverActiveSession()
  if (currentSession?.busy) throw new Error('The active Codex thread is busy. BuilderX will not interrupt or overwrite it.')

  const requestId = randomUUID()
  const createdAt = Date.now()
  const filePath = currentSession?.filePath || ''
  const startOffset = currentSession?.fileSize || 0
  const handoffFile = await requestPath(requestId)
  const resultTask = options.resultFile ? await createResultTask(requestId) : null
  const deliveredCommand = resultTask ? resultFileCommand(validated.command, resultTask.relativePath) : validated.command
  await writeFile(handoffFile, JSON.stringify({
    request_id: requestId,
    workspace_root: workspaceRoot,
    command: deliveredCommand,
  }), { mode: 0o600 })
  await chmod(handoffFile, 0o600)

  try {
    await launchUri(`vscode://builderx.builderx/handoff?request=${encodeURIComponent(requestId)}`)
    const acknowledgement = await waitForAcknowledgement(requestId)
    if (acknowledgement.state === 'error') throw new Error(acknowledgement.message || 'The BuilderX VS Code extension rejected the handoff.')
    handoffResults.set(requestId, {
      requestId,
      filePath,
      startOffset,
      createdAt,
      command: deliveredCommand,
      resultFilePath: resultTask?.absolutePath || '',
      resultFileRelativePath: resultTask?.relativePath || '',
    })
    return {
      request_id: requestId,
      acknowledged: true,
      state: 'submitted',
      thread_id: acknowledgement.thread_id || currentSession?.sessionId || '',
      result_file: resultTask?.relativePath || '',
    }
  } finally {
    await unlink(handoffFile).catch(() => {})
  }
}

async function health() {
  const [activeSession, extension, codeReady, workspaceReady] = await Promise.all([
    discoverActiveSession(),
    installedExtensionMetadata(),
    readable(codeExecutable),
    writable(workspaceRoot),
  ])
  const extensionProbe = extension && codeReady && workspaceReady
    ? await probeExtension()
    : { state: 'error', message: 'The bridge, workspace, or installed extension is not available.' }
  const extensionWorkspaceReady = extensionProbe.state === 'ready'
    && extensionProbe.extension_active === true
    && await workspaceMatches(extensionProbe.workspace)
  const extensionActive = extensionWorkspaceReady
  const directTextSendReady = extensionProbe.state === 'ready' && extensionProbe.direct_text_send_ready === true
  const legacyWrapperAvailable = extensionProbe.state === 'ready' && extensionProbe.legacy_wrapper_available === true
  const codexCommandReady = extensionProbe.state === 'ready' && extensionProbe.command_ready === true
  const readyToSend = codeReady
    && workspaceReady
    && Boolean(extension)
    && extensionActive
    && codexCommandReady
    && Boolean(activeSession)
    && activeSession.busy === false
  return {
    ok: true,
    bridge: 'BuilderX',
    name: 'BuilderX',
    target: 'vscode-visible-codex-task',
    workspace: workspaceRoot,
    port,
    code_ready: codeReady,
    context_ready: workspaceReady,
    active_thread_ready: Boolean(activeSession),
    active_thread_id: activeSession?.sessionId || '',
    active_thread_busy: activeSession?.busy || false,
    companion_extension_installed: Boolean(extension),
    companion_extension_version: extension?.version || '',
    builderx_extension_active: extensionActive,
    codex_command_ready: codexCommandReady,
    direct_text_send_ready: directTextSendReady,
    legacy_wrapper_available: legacyWrapperAvailable,
    delivery_mode: extensionProbe.delivery_mode || (directTextSendReady ? 'direct-text' : legacyWrapperAvailable ? 'legacy-implement-todo-wrapper' : 'unavailable'),
    extension_workspace_ready: extensionWorkspaceReady,
    extension_probe_state: extensionProbe.state || 'error',
    extension_probe_message: extensionProbe.message || '',
    orchestration_extension_installed: Boolean(extension),
    codex_runtime: 'OpenAI Codex VS Code extension',
    event_stream: true,
    ready_to_send: readyToSend,
  }
}

function capabilities() {
  return {
    ok: true,
    bridge: 'BuilderX',
  version: bridgeVersion,
    transport: 'loopback-http',
    workspace: workspaceRoot,
    endpoints: {
      health: 'GET /health?workspace_root=...',
      handoff: 'POST /handoff { workspace_root, command }',
      handoff_result: 'POST /handoff-result { workspace_root, command }',
      events: 'GET /events?request_id=... (SSE)',
      result: 'GET /result?request_id=...',
      restart: 'POST /restart',
      capabilities: 'GET /capabilities',
    },
    parallel_execution: {
      supported: false,
      task_channels: 1,
      reason: 'The installed VS Code Codex extension currently exposes one active visible thread and no separate task-channel dispatch contract.',
    },
    event_types: ['ready', 'thread', 'status', 'assistant_message', 'completed', 'failed'],
    guarantees: [
      'localhost-only binding',
      'workspace matching',
      'active-thread busy protection',
      'short-lived permission-protected handoff files',
      'no API key or CLI worker',
      'readiness requires a live BuilderX extension probe and Codex send command check',
    ],
  }
}

async function restartVsCode() {
  sessionBusyResetAt = Date.now()
  handoffResults.clear()
  let clearedMarkers = 0
  try {
    for (const entry of await readdir(requestDirectory, { withFileTypes: true })) {
      if (!entry.isFile() || !/\.(?:json|ack\.json)$/.test(entry.name)) continue
      await unlink(path.join(requestDirectory, entry.name)).catch(() => {})
      clearedMarkers += 1
    }
  } catch {
    // The temporary request directory may not exist yet.
  }
  await launchUri('vscode://command/workbench.action.reloadWindow')
  return { uri_opened: true, busy_marker_reset: true, cleared_markers: clearedMarkers }
}

const server = http.createServer(async (request, response) => {
  if (!originAllowed(request)) {
    sendJson(request, response, 403, { ok: false, message: 'BuilderX only accepts requests from localhost.' })
    return
  }
  if (request.method === 'OPTIONS') {
    applyHeaders(request, response)
    response.writeHead(204)
    response.end()
    return
  }

  const requestUrl = new URL(request.url || '/', `http://${host}:${port}`)
  try {
    if (request.method === 'GET' && ['/health', '/keepalive'].includes(requestUrl.pathname)) {
      if (!(await workspaceMatches(requestUrl.searchParams.get('workspace_root') || ''))) {
        sendJson(request, response, 409, { ok: false, message: 'BuilderX is serving a different workspace.' })
        return
      }
      sendJson(request, response, 200, await health())
      return
    }
    if (request.method === 'POST' && requestUrl.pathname === '/handoff') {
      const delivery = await handleHandoff(await readJson(request))
      sendJson(request, response, 200, { ok: true, bridge: 'BuilderX', target: 'vscode-visible-codex-task', delivery })
      return
    }
    if (request.method === 'POST' && requestUrl.pathname === '/handoff-result') {
      const delivery = await handleHandoff(await readJson(request), { resultFile: true })
      sendJson(request, response, 200, { ok: true, bridge: 'BuilderX', target: 'vscode-visible-codex-task', delivery })
      return
    }
    if (request.method === 'POST' && requestUrl.pathname === '/restart') {
      const restart = await restartVsCode()
      sendJson(request, response, 200, { ok: true, bridge: 'BuilderX', message: 'VS Code window reload requested.', restart })
      return
    }
    if (request.method === 'GET' && requestUrl.pathname === '/events') {
      const requestId = requestUrl.searchParams.get('request_id') || ''
      if (!/^[0-9a-f-]{36}$/.test(requestId)) throw new Error('The BuilderX request ID is invalid.')
      await streamHandoffEvents(request, response, requestId)
      return
    }
    if (request.method === 'GET' && requestUrl.pathname === '/result') {
      const requestId = requestUrl.searchParams.get('request_id') || ''
      if (!/^[0-9a-f-]{36}$/.test(requestId)) throw new Error('The BuilderX request ID is invalid.')
      const handoff = handoffResults.get(requestId)
      if (!handoff) throw new Error('The BuilderX handoff result is unavailable or has expired.')
      const result = await readTrackedResult(handoff)
      if (result.status !== 'pending') {
        handoff.result = result
        await disposeResultTask(handoff)
      }
      sendJson(request, response, 200, { ok: true, request_id: requestId, ...(handoff.result || result) })
      return
    }
    if (request.method === 'GET' && requestUrl.pathname === '/capabilities') {
      sendJson(request, response, 200, capabilities())
      return
    }
    sendJson(request, response, 404, { ok: false, message: 'BuilderX route not found.' })
  } catch (error) {
    const message = error instanceof Error ? error.message : 'BuilderX bridge request failed.'
    const status = /busy|different workspace/.test(message)
      ? 409
      : /accepts only|requires|invalid JSON|too large/.test(message) ? 400 : 500
    sendJson(request, response, status, { ok: false, bridge: 'BuilderX', message })
  }
})

server.on('error', (error) => {
  process.stderr.write(`BuilderX bridge failed: ${error instanceof Error ? error.message : String(error)}\n`)
  process.exitCode = 1
})

server.listen(port, host, () => {
  process.stdout.write(`BuilderX bridge listening on http://${host}:${port}\n`)
  process.stdout.write(`BuilderX workspace: ${workspaceRoot}\n`)
})

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.close(() => process.exit(0)))
}
