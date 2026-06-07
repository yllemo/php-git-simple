<?php
/**
 * Git Repo Editor — Göteborgs Stad
 * Bläddra, redigera och pusha filer till GitHub/GitLab via API-nyckel.
 */

require_once __DIR__ . '/GitRepoClient.php';

// -------------------------------------------------------------------------
// Session & konfiguration
// -------------------------------------------------------------------------
session_start();

$error   = '';
$success = '';
$client  = null;

// -------------------------------------------------------------------------
// Hjälpfunktioner
// -------------------------------------------------------------------------

function sanitize(string $val): string
{
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function getClient(): ?GitRepoClient
{
    if (empty($_SESSION['cfg'])) return null;
    $cfg = $_SESSION['cfg'];
    try {
        return new GitRepoClient(
            $cfg['provider'],
            $cfg['token'],
            $cfg['owner'],
            $cfg['repo'],
            $cfg['branch'],
            $cfg['gitlab_url'] ?? 'https://gitlab.com'
        );
    } catch (Throwable $e) {
        return null;
    }
}

// -------------------------------------------------------------------------
// AJAX-anrop — hanteras före HTML-output
// -------------------------------------------------------------------------
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Spara konfiguration
    if ($action === 'connect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $_SESSION['cfg'] = [
            'provider'   => $data['provider']   ?? 'github',
            'token'      => $data['token']       ?? '',
            'owner'      => $data['owner']       ?? '',
            'repo'       => $data['repo']        ?? '',
            'branch'     => $data['branch']      ?? 'main',
            'gitlab_url' => $data['gitlab_url']  ?? 'https://gitlab.com',
        ];
        try {
            $c    = getClient();
            $info = $c->getRepoInfo();
            jsonResponse(['ok' => true, 'repo' => $info['full_name'] ?? $info['path_with_namespace'] ?? '']);
        } catch (Throwable $e) {
            unset($_SESSION['cfg']);
            jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // Koppla från
    if ($action === 'disconnect') {
        session_destroy();
        jsonResponse(['ok' => true]);
    }

    // Kräv inloggad session för övriga anrop
    $c = getClient();
    if (!$c) jsonResponse(['ok' => false, 'error' => 'Ingen aktiv session.']);

    // Lista filer
    if ($action === 'list') {
        $path = $_GET['path'] ?? '';
        try {
            $files = $c->listFiles($path);
            jsonResponse(['ok' => true, 'files' => $files, 'path' => $path]);
        } catch (Throwable $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // Hämta fil
    if ($action === 'get') {
        $path = $_GET['path'] ?? '';
        try {
            $file = $c->getFile($path);
            jsonResponse(['ok' => true, 'file' => $file]);
        } catch (Throwable $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // Spara/pusha fil
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data    = json_decode(file_get_contents('php://input'), true);
        $path    = $data['path']    ?? '';
        $content = $data['content'] ?? '';
        $message = $data['message'] ?? 'Uppdaterad via Git Repo Editor';
        $sha     = $data['sha']     ?? null;
        try {
            $result = $c->putFile($path, $content, $message, $sha);
            jsonResponse(['ok' => true, 'result' => $result]);
        } catch (Throwable $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // Ta bort fil
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data    = json_decode(file_get_contents('php://input'), true);
        $path    = $data['path']    ?? '';
        $message = $data['message'] ?? 'Borttagen via Git Repo Editor';
        $sha     = $data['sha']     ?? '';
        try {
            $c->deleteFile($path, $message, $sha);
            jsonResponse(['ok' => true]);
        } catch (Throwable $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // Lista branches
    if ($action === 'branches') {
        try {
            $branches = $c->listBranches();
            jsonResponse(['ok' => true, 'branches' => $branches]);
        } catch (Throwable $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // Byt branch
    if ($action === 'set_branch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $_SESSION['cfg']['branch'] = $data['branch'] ?? 'main';
        jsonResponse(['ok' => true]);
    }

    jsonResponse(['ok' => false, 'error' => 'Okänd åtgärd.']);
}

// -------------------------------------------------------------------------
// Kontrollera session för initial sidladdning
// -------------------------------------------------------------------------
$isConnected = !empty($_SESSION['cfg']);
$cfg         = $_SESSION['cfg'] ?? [];
?>
<!DOCTYPE html>
<html lang="sv" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Git Repo Editor — Göteborgs Stad</title>
<style>
/* ============================================================
   CSS-VARIABLER — Göteborgs Stad Design System
   ============================================================ */
:root {
  --gs-blue:       #0077bc;
  --bg-color:      #FFFFFE;
  --bg-footer:     #F5F5F5;
  --bg-nav:        #F4F9FC;
  --bg-info:       #F2F9F9;
  --text-color:    #333333;
  --text-secondary:#6E6E6E;
  --link-color:    #005799;
  --border-color:  #979797;
  --success:       #5a8b3b;
  --warning:       #f2a900;
  --error:         #d24723;
  --info:          #008391;
  --radius:        4px;
}

[data-theme="dark"] {
  --bg-color:      #1F1F1F;
  --bg-footer:     #121212;
  --bg-nav:        #141414;
  --bg-info:       #282828;
  --text-color:    #FFFFFF;
  --text-secondary:#E3E8E9;
  --link-color:    #479EF5;
  --border-color:  #666666;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Goteborg', Arial, Helvetica, sans-serif;
  font-size: 16px;
  line-height: 1.5;
  color: var(--text-color);
  background: var(--bg-color);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ============================================================ HEADER */
header {
  background: var(--gs-blue);
  color: #fff;
  padding: 0 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 56px;
  flex-shrink: 0;
}

header h1 {
  font-size: 18px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #fff;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.provider-badge {
  font-size: 12px;
  background: rgba(255,255,255,0.2);
  border-radius: 12px;
  padding: 2px 10px;
  color: #fff;
}

/* ============================================================ LAYOUT */
.app {
  display: flex;
  flex: 1;
  overflow: hidden;
  height: calc(100vh - 56px);
}

/* ============================================================ SIDEBAR */
.sidebar {
  width: 280px;
  min-width: 220px;
  background: var(--bg-nav);
  border-right: 1px solid var(--border-color);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.sidebar-header {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border-color);
  background: var(--bg-nav);
}

.sidebar-header h2 {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 6px;
}

.branch-selector {
  display: flex;
  gap: 6px;
  align-items: center;
}

.branch-selector select {
  flex: 1;
  padding: 4px 8px;
  border: 1px solid var(--border-color);
  border-radius: var(--radius);
  background: var(--bg-color);
  color: var(--text-color);
  font-size: 13px;
}

.breadcrumb {
  padding: 8px 16px;
  font-size: 12px;
  color: var(--text-secondary);
  background: var(--bg-info);
  border-bottom: 1px solid var(--border-color);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.breadcrumb span { cursor: pointer; color: var(--link-color); }
.breadcrumb span:hover { text-decoration: underline; }

.file-list {
  flex: 1;
  overflow-y: auto;
  padding: 8px 0;
}

.file-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 16px;
  cursor: pointer;
  font-size: 14px;
  border-left: 3px solid transparent;
  transition: background 0.15s;
}

.file-item:hover { background: rgba(0,119,188,0.08); }
.file-item.active {
  background: rgba(0,119,188,0.12);
  border-left-color: var(--gs-blue);
  font-weight: 600;
}

.file-item .icon {
  width: 18px;
  text-align: center;
  font-size: 15px;
  flex-shrink: 0;
}

.file-item .fname {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar-actions {
  padding: 12px 16px;
  border-top: 1px solid var(--border-color);
}

/* ============================================================ EDITOR */
.editor-pane {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.editor-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  background: var(--bg-nav);
  border-bottom: 1px solid var(--border-color);
  flex-wrap: wrap;
}

.editor-toolbar .file-path {
  flex: 1;
  font-size: 13px;
  color: var(--text-secondary);
  font-family: 'Courier New', monospace;
}

.commit-area {
  display: flex;
  gap: 8px;
  align-items: center;
  width: 100%;
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px solid var(--border-color);
}

.commit-area input {
  flex: 1;
  padding: 6px 10px;
  border: 1px solid var(--border-color);
  border-radius: var(--radius);
  background: var(--bg-color);
  color: var(--text-color);
  font-size: 13px;
}

textarea#editor {
  flex: 1;
  width: 100%;
  border: none;
  outline: none;
  resize: none;
  padding: 16px 20px;
  font-family: 'Courier New', Courier, monospace;
  font-size: 14px;
  line-height: 1.6;
  background: var(--bg-color);
  color: var(--text-color);
  tab-size: 2;
}

/* ============================================================ WELCOME / CONNECT */
.welcome {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
}

.connect-card {
  background: var(--bg-nav);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 32px;
  width: 100%;
  max-width: 480px;
}

.connect-card h2 {
  font-size: 20px;
  color: var(--gs-blue);
  margin-bottom: 20px;
}

.form-group {
  margin-bottom: 14px;
}

.form-group label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-secondary);
  margin-bottom: 4px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.form-group input,
.form-group select {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border-color);
  border-radius: var(--radius);
  background: var(--bg-color);
  color: var(--text-color);
  font-size: 14px;
}

.form-group input:focus,
.form-group select:focus {
  border-color: var(--gs-blue);
  outline: none;
}

.gitlab-url-row { display: none; }

/* ============================================================ KNAPPAR */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border-radius: var(--radius);
  border: none;
  cursor: pointer;
  font-size: 14px;
  font-family: inherit;
  font-weight: 600;
  transition: opacity 0.15s, background 0.15s;
  white-space: nowrap;
}

.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.btn-primary {
  background: var(--gs-blue);
  color: #fff;
}
.btn-primary:hover:not(:disabled) { background: #005799; }

.btn-secondary {
  background: var(--bg-color);
  color: var(--text-color);
  border: 1px solid var(--border-color);
}
.btn-secondary:hover:not(:disabled) { background: var(--bg-info); }

.btn-danger {
  background: var(--error);
  color: #fff;
}
.btn-danger:hover:not(:disabled) { opacity: 0.85; }

.btn-success {
  background: var(--success);
  color: #fff;
}
.btn-success:hover:not(:disabled) { opacity: 0.85; }

.btn-sm { padding: 4px 10px; font-size: 13px; }

/* ============================================================ TOAST */
#toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.toast-item {
  padding: 10px 18px;
  border-radius: var(--radius);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  box-shadow: 0 2px 12px rgba(0,0,0,0.2);
  animation: slidein 0.2s ease;
}

@keyframes slidein {
  from { transform: translateX(60px); opacity: 0; }
  to   { transform: translateX(0);   opacity: 1; }
}

.toast-success { background: var(--success); }
.toast-error   { background: var(--error); }
.toast-info    { background: var(--info); }

/* ============================================================ SPINNER */
.spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255,255,255,0.4);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* ============================================================ MODAL */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 900;
  align-items: center;
  justify-content: center;
}

.modal-overlay.open { display: flex; }

.modal {
  background: var(--bg-color);
  border-radius: 8px;
  padding: 28px 32px;
  max-width: 400px;
  width: 90%;
  box-shadow: 0 8px 32px rgba(0,0,0,0.25);
}

.modal h3 { margin-bottom: 12px; color: var(--text-color); }
.modal p  { font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

/* ============================================================ EMPTY */
.empty-editor {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: var(--text-secondary);
  gap: 12px;
}

.empty-editor .big-icon { font-size: 48px; opacity: 0.4; }

/* ============================================================ TOGGLE */
.theme-toggle {
  background: none;
  border: none;
  color: #fff;
  cursor: pointer;
  font-size: 18px;
  padding: 4px 8px;
  border-radius: var(--radius);
  transition: background 0.15s;
}
.theme-toggle:hover { background: rgba(255,255,255,0.15); }

/* ============================================================ SCROLLBAR */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }

/* ============================================================ RESPONSIVE */
@media (max-width: 700px) {
  .sidebar { width: 220px; min-width: 180px; }
}
</style>
</head>
<body>

<header>
  <h1>Git Repo Editor</h1>
  <div class="header-right">
    <span id="providerBadge" class="provider-badge" style="display:none"></span>
    <button class="theme-toggle" onclick="toggleTheme()" title="Byt tema">☀</button>
    <button id="btnDisconnect" class="btn btn-secondary btn-sm" style="display:none" onclick="disconnect()">Koppla från</button>
  </div>
</header>

<div class="app" id="app">

  <!-- ========== ANSLUTNINGSVY ========== -->
  <div class="welcome" id="connectView">
    <div class="connect-card">
      <h2>🔑 Anslut till repo</h2>

      <div class="form-group">
        <label>Provider</label>
        <select id="cfgProvider" onchange="onProviderChange()">
          <option value="github">GitHub</option>
          <option value="gitlab">GitLab</option>
        </select>
      </div>

      <div class="form-group gitlab-url-row" id="gitlabUrlRow">
        <label>GitLab-URL <small style="font-weight:400">(lämna tom för gitlab.com)</small></label>
        <input type="url" id="cfgGitlabUrl" placeholder="https://gitlab.example.com">
      </div>

      <div class="form-group">
        <label>API-nyckel / Access Token</label>
        <input type="password" id="cfgToken" placeholder="ghp_… eller glpat-…" autocomplete="off">
      </div>

      <div class="form-group">
        <label>Ägare / Namespace</label>
        <input type="text" id="cfgOwner" placeholder="organisation eller användare">
      </div>

      <div class="form-group">
        <label>Repo-namn</label>
        <input type="text" id="cfgRepo" placeholder="mitt-repo">
      </div>

      <div class="form-group">
        <label>Branch <small style="font-weight:400">(default: main)</small></label>
        <input type="text" id="cfgBranch" value="main">
      </div>

      <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:8px"
              onclick="connect()" id="btnConnect">
        Anslut
      </button>

      <p style="margin-top:16px;font-size:12px;color:var(--text-secondary);line-height:1.6">
        Nyckeln sparas bara i serverns PHP-session och skickas aldrig till klienten.
        Läs README.md för instruktioner om vilka behörigheter som krävs.
      </p>
    </div>
  </div>

  <!-- ========== REPO-VY ========== -->
  <div class="sidebar" id="sidebarView" style="display:none">
    <div class="sidebar-header">
      <h2>Filer</h2>
      <div class="branch-selector">
        <select id="branchSelect" onchange="changeBranch(this.value)"></select>
      </div>
    </div>
    <div class="breadcrumb" id="breadcrumb">
      <span onclick="navigateTo('')">🏠 rot</span>
    </div>
    <div class="file-list" id="fileList">
      <div style="padding:24px;text-align:center;color:var(--text-secondary)">Laddar…</div>
    </div>
    <div class="sidebar-actions">
      <button class="btn btn-secondary btn-sm" style="width:100%;justify-content:center"
              onclick="newFilePrompt()">➕ Ny fil</button>
    </div>
  </div>

  <div class="editor-pane" id="editorView" style="display:none">
    <div class="editor-toolbar" id="editorToolbar">
      <div class="file-path" id="currentFilePath">Välj en fil…</div>
      <button class="btn btn-secondary btn-sm" onclick="refreshFile()">🔄</button>
      <button class="btn btn-danger btn-sm" id="btnDelete" onclick="confirmDelete()" style="display:none">🗑 Ta bort</button>
      <div class="commit-area">
        <input type="text" id="commitMsg" placeholder="Commit-meddelande…" value="Uppdaterad via Git Repo Editor">
        <button class="btn btn-success" id="btnSave" onclick="saveFile()">⬆ Push</button>
      </div>
    </div>
    <div class="empty-editor" id="emptyEditor">
      <div class="big-icon">📄</div>
      <span>Välj en fil i sidopanelen för att börja redigera</span>
    </div>
    <textarea id="editor" style="display:none" spellcheck="false"
              oninput="markDirty()"></textarea>
  </div>

</div>

<!-- ========== BEKRÄFTELSEDIALOG ========== -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <h3>Ta bort fil</h3>
    <p id="deleteModalMsg">Är du säker på att du vill ta bort filen? Åtgärden skapar en ny commit.</p>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModal('deleteModal')">Avbryt</button>
      <button class="btn btn-danger" onclick="doDelete()">Ta bort</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
// ============================================================
// TILLSTÅNDSVARIABLER
// ============================================================
let currentPath   = '';
let currentFile   = null;  // { path, sha, content }
let isDirty       = false;
let isConnected   = <?= $isConnected ? 'true' : 'false' ?>;
const savedCfg    = <?= json_encode(['provider' => $cfg['provider'] ?? 'github', 'branch' => $cfg['branch'] ?? 'main']) ?>;

// ============================================================
// TEMA
// ============================================================
function toggleTheme() {
  const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t);
  localStorage.setItem('theme', t);
}

document.addEventListener('DOMContentLoaded', () => {
  const t = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);

  if (isConnected) {
    showRepoView();
    updateProviderBadge(savedCfg.provider);
    loadBranches(savedCfg.branch);
    loadFiles('');
  }
});

// ============================================================
// ANSLUTNING
// ============================================================
function onProviderChange() {
  const p = document.getElementById('cfgProvider').value;
  document.getElementById('gitlabUrlRow').style.display = p === 'gitlab' ? '' : 'none';
}

async function connect() {
  const btn = document.getElementById('btnConnect');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Ansluter…';

  const payload = {
    provider:   document.getElementById('cfgProvider').value,
    token:      document.getElementById('cfgToken').value,
    owner:      document.getElementById('cfgOwner').value,
    repo:       document.getElementById('cfgRepo').value,
    branch:     document.getElementById('cfgBranch').value || 'main',
    gitlab_url: document.getElementById('cfgGitlabUrl').value || 'https://gitlab.com',
  };

  try {
    const res  = await api('connect', 'POST', payload);
    if (!res.ok) throw new Error(res.error);
    isConnected = true;
    updateProviderBadge(payload.provider);
    showRepoView();
    await loadBranches(payload.branch);
    await loadFiles('');
    toast('Ansluten till ' + res.repo, 'success');
  } catch (e) {
    toast('Anslutning misslyckades: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = 'Anslut';
  }
}

async function disconnect() {
  if (isDirty && !confirm('Du har osparade ändringar. Koppla från ändå?')) return;
  await api('disconnect');
  location.reload();
}

// ============================================================
// VYHANTERING
// ============================================================
function showRepoView() {
  document.getElementById('connectView').style.display  = 'none';
  document.getElementById('sidebarView').style.display  = '';
  document.getElementById('editorView').style.display   = '';
  document.getElementById('btnDisconnect').style.display = '';
}

function updateProviderBadge(provider) {
  const badge = document.getElementById('providerBadge');
  badge.textContent = provider === 'github' ? '🐙 GitHub' : '🦊 GitLab';
  badge.style.display = '';
}

// ============================================================
// FILER
// ============================================================
async function loadFiles(path) {
  currentPath = path;
  updateBreadcrumb(path);
  const list = document.getElementById('fileList');
  list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-secondary)">Laddar…</div>';

  try {
    const res = await api('list&path=' + encodeURIComponent(path));
    if (!res.ok) throw new Error(res.error);

    const files = res.files;
    files.sort((a, b) => {
      if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
      return a.name.localeCompare(b.name);
    });

    if (files.length === 0) {
      list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-secondary)">Tom mapp</div>';
      return;
    }

    list.innerHTML = '';
    if (path) {
      // Tillbaka-knapp
      const back = document.createElement('div');
      back.className = 'file-item';
      back.innerHTML = '<span class="icon">⬆</span><span class="fname">..</span>';
      back.onclick = () => {
        const parent = path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '';
        loadFiles(parent);
      };
      list.appendChild(back);
    }

    files.forEach(f => {
      const item = document.createElement('div');
      item.className = 'file-item' + (currentFile?.path === f.path ? ' active' : '');
      item.dataset.path = f.path;
      item.dataset.type = f.type;
      item.innerHTML = `<span class="icon">${f.type === 'dir' ? '📁' : fileIcon(f.name)}</span>
                        <span class="fname">${escHtml(f.name)}</span>`;
      item.onclick = () => {
        if (f.type === 'dir') {
          loadFiles(f.path);
        } else {
          openFile(f.path, item);
        }
      };
      list.appendChild(item);
    });
  } catch (e) {
    list.innerHTML = `<div style="padding:24px;color:var(--error)">Fel: ${escHtml(e.message)}</div>`;
  }
}

async function openFile(path, itemEl) {
  if (isDirty && !confirm('Du har osparade ändringar. Öppna ändå?')) return;

  // Markera aktiv
  document.querySelectorAll('.file-item.active').forEach(el => el.classList.remove('active'));
  if (itemEl) itemEl.classList.add('active');

  document.getElementById('emptyEditor').style.display = 'none';
  document.getElementById('editor').style.display      = 'none';
  document.getElementById('currentFilePath').textContent = '⏳ Hämtar ' + path + '…';
  document.getElementById('btnDelete').style.display   = 'none';
  document.getElementById('btnSave').disabled = true;

  try {
    const res = await api('get&path=' + encodeURIComponent(path));
    if (!res.ok) throw new Error(res.error);

    currentFile = { path: res.file.path, sha: res.file.sha, content: res.file.content };
    isDirty     = false;

    const editor = document.getElementById('editor');
    editor.value = res.file.content;
    editor.style.display = '';
    document.getElementById('currentFilePath').textContent = path;
    document.getElementById('btnDelete').style.display   = '';
    document.getElementById('btnSave').disabled = false;
  } catch (e) {
    toast('Kunde inte öppna fil: ' + e.message, 'error');
    document.getElementById('currentFilePath').textContent = 'Välj en fil…';
    document.getElementById('emptyEditor').style.display = '';
  }
}

async function refreshFile() {
  if (!currentFile) return;
  if (isDirty && !confirm('Du har osparade ändringar. Uppdatera ändå?')) return;
  const activeEl = document.querySelector('.file-item.active');
  await openFile(currentFile.path, activeEl);
  toast('Fil uppdaterad', 'info');
}

async function saveFile() {
  if (!currentFile) return;
  const content = document.getElementById('editor').value;
  const message = document.getElementById('commitMsg').value || 'Uppdaterad via Git Repo Editor';
  const btn     = document.getElementById('btnSave');

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Pushar…';

  try {
    const res = await api('save', 'POST', {
      path: currentFile.path,
      content,
      message,
      sha: currentFile.sha,
    });
    if (!res.ok) throw new Error(res.error);

    // Uppdatera SHA om GitHub returnerar det
    if (res.result?.content?.sha) currentFile.sha = res.result.content.sha;
    if (res.result?.file?.blob_id) currentFile.sha = res.result.file.blob_id;

    isDirty = false;
    toast('✅ Fil pushad!', 'success');
  } catch (e) {
    toast('Push misslyckades: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '⬆ Push';
  }
}

function markDirty() {
  isDirty = true;
}

// ============================================================
// NY FIL
// ============================================================
function newFilePrompt() {
  const name = prompt('Filnamn (inkl. sökväg från aktuell mapp):');
  if (!name) return;
  const path = currentPath ? currentPath + '/' + name : name;
  currentFile = { path, sha: null, content: '' };
  isDirty = true;
  document.getElementById('emptyEditor').style.display = 'none';
  const editor = document.getElementById('editor');
  editor.value = '';
  editor.style.display = '';
  document.getElementById('currentFilePath').textContent = path + ' (ny)';
  document.getElementById('btnDelete').style.display = 'none';
  document.getElementById('btnSave').disabled = false;
  editor.focus();
}

// ============================================================
// RADERING
// ============================================================
function confirmDelete() {
  if (!currentFile) return;
  document.getElementById('deleteModalMsg').textContent =
    'Ta bort "' + currentFile.path + '"? Åtgärden skapar en ny commit.';
  document.getElementById('deleteModal').classList.add('open');
}

async function doDelete() {
  closeModal('deleteModal');
  const message = document.getElementById('commitMsg').value || 'Borttagen via Git Repo Editor';
  try {
    const res = await api('delete', 'POST', { path: currentFile.path, message, sha: currentFile.sha });
    if (!res.ok) throw new Error(res.error);
    currentFile = null;
    isDirty     = false;
    document.getElementById('editor').style.display      = 'none';
    document.getElementById('emptyEditor').style.display = '';
    document.getElementById('currentFilePath').textContent = 'Välj en fil…';
    document.getElementById('btnDelete').style.display = 'none';
    await loadFiles(currentPath);
    toast('🗑 Fil borttagen', 'success');
  } catch (e) {
    toast('Radering misslyckades: ' + e.message, 'error');
  }
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// ============================================================
// BRANCHES
// ============================================================
async function loadBranches(activeBranch) {
  try {
    const res = await api('branches');
    if (!res.ok) throw new Error(res.error);
    const sel = document.getElementById('branchSelect');
    sel.innerHTML = '';
    res.branches.forEach(b => {
      const opt = document.createElement('option');
      opt.value = b;
      opt.textContent = b;
      if (b === activeBranch) opt.selected = true;
      sel.appendChild(opt);
    });
  } catch (e) {
    // Tysta branches-fel — inte kritiskt
  }
}

async function changeBranch(branch) {
  if (isDirty && !confirm('Du har osparade ändringar. Byt branch ändå?')) {
    // Återställ select
    const sel = document.getElementById('branchSelect');
    sel.value = savedCfg.branch;
    return;
  }
  try {
    await api('set_branch', 'POST', { branch });
    currentFile = null;
    isDirty     = false;
    document.getElementById('editor').style.display      = 'none';
    document.getElementById('emptyEditor').style.display = '';
    document.getElementById('currentFilePath').textContent = 'Välj en fil…';
    await loadFiles('');
    toast('Branch: ' + branch, 'info');
  } catch (e) {
    toast('Fel vid byte av branch: ' + e.message, 'error');
  }
}

// ============================================================
// BRÖDSMULOR
// ============================================================
function navigateTo(path) {
  loadFiles(path);
}

function updateBreadcrumb(path) {
  const bc = document.getElementById('breadcrumb');
  if (!path) {
    bc.innerHTML = '<span onclick="navigateTo(\'\')">🏠 rot</span>';
    return;
  }
  const parts = path.split('/');
  let html = '<span onclick="navigateTo(\'\')">🏠 rot</span>';
  let built = '';
  parts.forEach((p, i) => {
    built += (built ? '/' : '') + p;
    const bp = built;
    if (i < parts.length - 1) {
      html += ` / <span onclick="navigateTo('${bp}')">${escHtml(p)}</span>`;
    } else {
      html += ` / <span>${escHtml(p)}</span>`;
    }
  });
  bc.innerHTML = html;
}

// ============================================================
// API-HJÄLP
// ============================================================
async function api(action, method = 'GET', body = null) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json' },
  };
  if (body && method !== 'GET') opts.body = JSON.stringify(body);

  const url = window.location.pathname + '?action=' + action;
  const res = await fetch(url, opts);
  return res.json();
}

// ============================================================
// TOASTS
// ============================================================
function toast(msg, type = 'info') {
  const container = document.getElementById('toast');
  const el = document.createElement('div');
  el.className = 'toast-item toast-' + type;
  el.textContent = msg;
  container.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

// ============================================================
// HJÄLPFUNKTIONER
// ============================================================
function escHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fileIcon(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  const map = {
    md: '📝', markdown: '📝',
    php: '🐘', js: '🟨', ts: '🔷',
    html: '🌐', htm: '🌐', css: '🎨',
    json: '🔧', yaml: '🔧', yml: '🔧',
    xml: '📋', svg: '🖼', png: '🖼', jpg: '🖼', jpeg: '🖼', gif: '🖼',
    sh: '⚙', bash: '⚙', py: '🐍', rb: '💎',
    txt: '📄', log: '📋', csv: '📊',
    pdf: '📕', zip: '📦', tar: '📦', gz: '📦',
  };
  return map[ext] || '📄';
}

// Varning vid osparade ändringar
window.addEventListener('beforeunload', e => {
  if (isDirty) {
    e.preventDefault();
    e.returnValue = '';
  }
});
</script>
</body>
</html>
