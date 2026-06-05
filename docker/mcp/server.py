"""XTrace Explorer MCP server — SSE transport, port 8766."""

import asyncio
import json
import os
import subprocess
import time
from typing import Any

import httpx
from mcp.server.fastmcp import FastMCP
from starlette.requests import Request
from starlette.responses import JSONResponse

APP_URL = os.environ.get("APP_URL", "http://app")
COMPOSE_FILE = os.environ.get("COMPOSE_FILE", "/compose/compose.yaml")
APP_CONTAINER = os.environ.get("APP_CONTAINER", "xtrace-explorer-app-1")

# Path to playwright venv on the host (used by update_readme)
PLAYWRIGHT_PYTHON = os.environ.get("PLAYWRIGHT_PYTHON", "/tmp/pw_venv/bin/python3")
# Absolute path to the project root on the host
PROJECT_DIR = os.environ.get("PROJECT_DIR", "/home/ilia/dev/personal/xtrace-explorer")
# Path to readme_updater.py on the host (alongside server.py in the project)
README_UPDATER = os.environ.get(
    "README_UPDATER",
    os.path.join(PROJECT_DIR, "docker/mcp/readme_updater.py"),
)

mcp = FastMCP("xtrace-explorer", port=8766, host="0.0.0.0")

# connection tracking
_connections: int = 0
_last_tool_call: float = 0.0   # epoch seconds of last tool invocation
_total_tool_calls: int = 0

# screenshot capture state — written line-by-line so get_screenshot_log can tail it
SCREENSHOT_LOG = "/tmp/screenshot_capture.log"
_capture_running: bool = False


# ── helpers ──────────────────────────────────────────────────────────────────

def _docker_exec(cmd: list[str], timeout: int = 30) -> str:
    result = subprocess.run(
        ["docker", "exec", APP_CONTAINER] + cmd,
        capture_output=True,
        text=True,
        timeout=timeout,
    )
    return (result.stdout + result.stderr).strip()


async def _api(method: str, path: str, **kwargs) -> Any:
    async with httpx.AsyncClient(base_url=APP_URL, timeout=30) as client:
        resp = await client.request(method, path, **kwargs)
        resp.raise_for_status()
        return resp.json()


# Path to demo trace generator script — inside the container it's mounted at /app/
DEMO_TRACE_GENERATOR = "/app/generate_demo_trace.py"

# ── Group 1: environment ──────────────────────────────────────────────────────

@mcp.tool()
def restart_worker() -> str:
    """Restart the Messenger async worker inside the app container (use after changing TraceParser/TraceIndex)."""
    out = _docker_exec(["supervisorctl", "restart", "messenger"])
    return f"supervisorctl restart messenger:\n{out}"


@mcp.tool()
def run_tests(filter: str = "") -> str:
    """Run PHPUnit tests inside the app container. Optional `filter` narrows to a test name or class."""
    cmd = ["php", "vendor/bin/phpunit", "--colors=never"]
    if filter:
        cmd += ["--filter", filter]
    out = _docker_exec(cmd, timeout=120)
    return out


@mcp.tool()
async def reparse(file_id: int, wait: bool = True, timeout_sec: int = 120) -> str:
    """
    Trigger reparse for a trace file and optionally wait until status=ready.
    Returns final status or a timeout message.
    """
    await _api("POST", f"/api/reparse/{file_id}")
    if not wait:
        return f"Reparse triggered for file {file_id}."

    deadline = time.time() + timeout_sec
    while time.time() < deadline:
        status_data = await _api("GET", f"/api/status/{file_id}")
        status = status_data.get("status", "unknown")
        progress = status_data.get("progress", 0)
        if status == "ready":
            return f"File {file_id} is ready."
        if status == "error":
            msg = status_data.get("errorMessage", "unknown error")
            return f"Parse failed: {msg}"
        await asyncio.sleep(2)
    return f"Timeout after {timeout_sec}s. Last status: {status}"


# ── Group 2: trace analysis ───────────────────────────────────────────────────

@mcp.tool()
async def get_files() -> str:
    """List all trace files in the database (id, name, status)."""
    files = await _api("GET", "/api/files")
    rows = [f"{f['file_id']:>4}  {f['status']:<10}  {f['name']}" for f in files]
    return "\n".join(rows) if rows else "No files."


@mcp.tool()
async def get_toc(file_id: int) -> str:
    """
    Return the Table of Contents for a trace file: dispatched events with their listeners.
    Each entry has event name, line_no, depth, and listener list.
    """
    toc = await _api("GET", f"/api/toc/{file_id}")
    if not toc:
        return "Empty TOC. Is the file parsed?"
    out = []
    for entry in toc:
        out.append(f"[line {entry.get('line_no')}] EVENT: {entry.get('event')}")
        for lst in entry.get("listeners", []):
            out.append(f"    listener  line={lst.get('line_no')} depth={lst.get('depth')}  {lst.get('class')}")
    return "\n".join(out)


@mcp.tool()
async def get_children(file_id: int, line_no: int, depth: int, raw: bool = False, max_items: int = 50) -> str:
    """
    Return child calls at line_no/depth inside a trace file.
    raw=True disables noise filter (shows all calls including framework internals).
    max_items caps output to avoid flooding context (default 50).
    """
    params = {"line_no": line_no, "depth": depth, "raw": 1 if raw else 0}
    resp = await _api("GET", f"/api/children/{file_id}", params=params)
    children = resp["children"] if isinstance(resp, dict) else resp
    if not children:
        return "No children."
    out = []
    for i, c in enumerate(children[:max_items]):
        ret = f"  ⇒ {c['return']}" if c.get("return") else ""
        args = ", ".join(c.get("args", []))
        out.append(f"[{c['line_no']}] depth={c['depth']}  {c['sig']}({args}){ret}  @ {c.get('file','')}")
    if len(children) > max_items:
        out.append(f"... {len(children) - max_items} more (increase max_items or use raw=False to filter)")
    return "\n".join(out)


@mcp.tool()
async def get_meta(file_id: int) -> str:
    """Return metadata for a trace file: total lines, request/response info."""
    meta = await _api("GET", f"/api/meta/{file_id}")
    return json.dumps(meta, indent=2, ensure_ascii=False)


@mcp.tool()
async def search_trace(file_id: int, query: str) -> str:
    """Search for calls matching a signature fragment inside a trace file."""
    results = await _api("GET", f"/api/search/{file_id}", params={"q": query})
    if not results:
        return f"No results for '{query}'."
    out = []
    for r in results:
        out.append(f"[line {r.get('line_no')}] depth={r.get('depth')}  {r.get('sig')}")
    return "\n".join(out)


@mcp.tool()
async def get_sql(file_id: int) -> str:
    """
    Return all SQL queries executed in a trace file.
    Shows query number, line_no, SQL, params, and which TOC event triggered it.
    Groups duplicate queries and flags N+1 patterns.
    """
    queries = await _api("GET", f"/api/sql/{file_id}")
    if not queries:
        return "No SQL queries found (reparse if file was parsed before this feature was added)."

    # Count duplicates by normalized SQL (strip aliases like u0_, p1_ for grouping)
    import re as _re
    def normalize(sql):
        if not sql: return ""
        s = _re.sub(r'\b[a-z]\d+_', '', sql)
        s = _re.sub(r'\s+', ' ', s).strip()
        return s[:120]

    counts: dict[str, int] = {}
    for q in queries:
        k = normalize(q.get("sql"))
        counts[k] = counts.get(k, 0) + 1

    out = [f"Total queries: {len(queries)}", ""]
    dupes = {k: v for k, v in counts.items() if v > 1}
    if dupes:
        out.append(f"⚠ N+1 candidates ({len(dupes)} distinct SQLs executed multiple times):")
        for sql_norm, cnt in sorted(dupes.items(), key=lambda x: -x[1])[:10]:
            out.append(f"  ×{cnt}  {sql_norm[:100]}")
        out.append("")

    out.append("— All queries —")
    for q in queries:
        sql = (q.get("sql") or "?")[:200]
        params = q.get("params") or []
        params_str = f"  params={params}" if params and params != ["…"] else ""
        toc = q.get("toc") or ""
        dupe_mark = f" ×{counts[normalize(q.get('sql'))]}" if counts.get(normalize(q.get("sql")), 1) > 1 else ""
        out.append(f"[{q['n']:>3}] line={q['line_no']} depth={q['depth']}{dupe_mark}  {sql}{params_str}")
        if toc:
            out.append(f"       ↳ {toc}")
    return "\n".join(out)


@mcp.tool()
async def get_schema(file_id: int, items: list[dict]) -> str:
    """
    Fetch the call schema (aggregated tree) for selected items.
    items: list of {line_no, depth} dicts matching toc listeners or children entries.
    """
    result = await _api("POST", f"/api/schema/{file_id}", json=items)
    return json.dumps(result, indent=2, ensure_ascii=False)


# ── Group 3: logs ─────────────────────────────────────────────────────────────

@mcp.tool()
def get_logs(lines: int = 100) -> str:
    """Return the last N lines of the app container logs (includes messenger worker output and PHP errors)."""
    result = subprocess.run(
        ["docker", "logs", "--tail", str(lines), APP_CONTAINER],
        capture_output=True,
        text=True,
        timeout=15,
    )
    out = result.stdout + result.stderr
    return out.strip() if out.strip() else "No logs."


@mcp.tool()
def get_worker_logs(lines: int = 50) -> str:
    """Return recent messenger worker stdout (parse errors, message processing). Alias for get_logs focused on worker output."""
    raw = get_logs(lines=lines * 3)
    relevant = [ln for ln in raw.splitlines() if any(kw in ln for kw in ["messenger", "Parse", "ERROR", "Exception", "Warning", "TraceParser", "TraceIndex", "async"])]
    if not relevant:
        return raw[-2000:]
    return "\n".join(relevant[-lines:])


# ── Group 4: parser validation ────────────────────────────────────────────────

@mcp.tool()
async def validate_toc(file_id: int) -> str:
    """
    Validate TOC structure for signs of a broken parser:
    - events with no listeners
    - duplicate line_no entries
    - line_no=0 or None
    - listeners with no class name
    Returns 'OK' or a list of anomalies found.
    """
    toc = await _api("GET", f"/api/toc/{file_id}")
    if not toc:
        return "WARN: TOC is empty — file may not be parsed yet."

    problems = []
    seen_lines: dict[int, str] = {}

    for i, entry in enumerate(toc):
        event = entry.get("event") or f"<unnamed #{i}>"
        line_no = entry.get("line_no")
        listeners = entry.get("listeners", [])

        if not line_no:
            problems.append(f"event '{event}': line_no is missing or 0")

        if line_no in seen_lines:
            problems.append(f"event '{event}' at line {line_no}: duplicate line_no (same as '{seen_lines[line_no]}')")
        elif line_no:
            seen_lines[line_no] = event

        if not listeners:
            problems.append(f"event '{event}' at line {line_no}: no listeners")

        for lst in listeners:
            if not lst.get("class"):
                problems.append(f"event '{event}': listener at line {lst.get('line_no')} has no class name")
            if not lst.get("line_no"):
                problems.append(f"event '{event}': listener '{lst.get('class')}' has no line_no")

    if not problems:
        return f"OK — {len(toc)} events, TOC looks valid."
    return "ANOMALIES FOUND:\n" + "\n".join(f"  - {p}" for p in problems)


# ── Group 5: demo trace + README updater ─────────────────────────────────────

@mcp.tool()
async def generate_demo_trace(app_url: str = "http://localhost:8765") -> str:
    """
    Generate a synthetic Xdebug trace file (fictional Acme\\Shop Symfony app)
    and open it in XTrace Explorer so it can be used for screenshots and demos.

    The trace contains:
      - kernel.request  (5 listeners: ValidateRequest, Session, Router, MaintenanceMode, AuthToken)
      - kernel.controller (2 listeners: Router, RateLimiting)
      - kernel.response  (3 listeners: ResponseListener, CORS, JwtRefresh)
      - kernel.terminate  (2 listeners: OrderConfirmation, Metrics)
      - realistic call trees with Doctrine, Stripe gateway, Messenger dispatch

    No real project data is included.
    Returns the file_id that was assigned to the demo trace.
    """
    log: list[str] = []

    # Step 1: fetch settings
    log.append("[1/6] Fetching app settings...")
    try:
        settings = await _api("GET", "/api/settings")
    except Exception as e:
        log.append(f"  FAIL: {e}")
        return "\n".join(log)
    log.append(f"  OK: traces_dir={settings.get('traces_dir')!r}")

    demo_filename = "trace__demo_shop_checkout.xt"

    # Step 2: resolve host TRACES_DIR
    log.append("[2/6] Resolving host TRACES_DIR...")
    host_traces_dir = os.environ.get("TRACES_DIR_HOST", "")
    if not host_traces_dir:
        try:
            with open(COMPOSE_FILE) as f:
                for line in f:
                    if "TRACES_DIR=" in line and not line.strip().startswith("#"):
                        host_traces_dir = line.strip().split("TRACES_DIR=", 1)[1].strip()
                        break
        except Exception as e:
            log.append(f"  WARNING: could not read compose file: {e}")

    if not host_traces_dir:
        log.append("  FAIL: TRACES_DIR_HOST not set and not found in compose file")
        return "\n".join(log)
    log.append(f"  OK: host_traces_dir={host_traces_dir!r}")

    # Step 3: generate the .xt file
    out_path = os.path.join(host_traces_dir, demo_filename)
    log.append(f"[3/6] Running generator: python3 {DEMO_TRACE_GENERATOR} --out {out_path}")
    gen_result = subprocess.run(
        ["python3", DEMO_TRACE_GENERATOR, "--out", out_path],
        capture_output=True, text=True, timeout=15,
    )
    if gen_result.returncode != 0:
        log.append(f"  FAIL (exit {gen_result.returncode}):")
        log.append(f"  stderr: {gen_result.stderr.strip()}")
        log.append(f"  stdout: {gen_result.stdout.strip()}")
        return "\n".join(log)
    log.append(f"  OK: {gen_result.stdout.strip()}")

    # Step 4: open/register in the app
    log.append(f"[4/6] Opening trace in app: POST /api/open rel_path={demo_filename!r}")
    try:
        open_resp = await _api("POST", "/api/open", json={"rel_path": demo_filename})
    except Exception as e:
        log.append(f"  FAIL: {e}")
        return "\n".join(log)
    file_id = open_resp.get("file_id")
    if not file_id:
        log.append(f"  FAIL: no file_id in response: {open_resp}")
        return "\n".join(log)
    log.append(f"  OK: file_id={file_id}")

    # Step 5: reparse
    log.append(f"[5/6] Reparsing file_id={file_id}...")
    try:
        await _api("POST", f"/api/reparse/{file_id}")
        log.append("  OK")
    except Exception as e:
        log.append(f"  WARNING: reparse request failed: {e}")

    # Step 6: wait for ready
    log.append("[6/6] Waiting for parse to complete (up to 60s)...")
    for attempt in range(30):
        try:
            status = await _api("GET", f"/api/status/{file_id}")
        except Exception as e:
            log.append(f"  WARNING attempt {attempt+1}: status check failed: {e}")
            await asyncio.sleep(2)
            continue
        s = status.get("status")
        log.append(f"  attempt {attempt+1}: status={s!r}")
        if s == "ready":
            break
        if s == "error":
            log.append(f"  FAIL: parse error: {status.get('error')}")
            return "\n".join(log)
        await asyncio.sleep(2)
    else:
        log.append("  FAIL: timed out waiting for ready")
        return "\n".join(log)

    toc = await _api("GET", f"/api/toc/{file_id}")
    events = [e["event"] for e in toc]
    log.append(f"\nDone — file_id={file_id}, events: {', '.join(events)}")
    return "\n".join(log)

@mcp.tool()
def capture_ui_screenshots(
    app_url: str = "http://localhost:8765",
    file_id: int = 0,
) -> str:
    """
    Capture live screenshots and animated GIFs of the running XTrace Explorer UI.

    Launches the capture in the background and returns immediately.
    Follow progress with get_screenshot_log(). When it shows "[capture] done",
    the result JSON is on the "[capture] result:" line — pass screenshot paths
    to write_readme().

    Steps:
      1. Launch headless Chromium (Playwright)
      2. Navigate: empty → file picker → TOC → expanded event → call tree → deep dive → export → settings
      3. Build animated GIFs: demo-drilldown.gif, demo-deep-dive.gif
      4. Save everything to docs/screenshots/

    Parameters:
      app_url   URL of the running app (default: http://localhost:8765)
      file_id   Trace file id to use for demos (0 = auto-detect first ready file)
    """
    global _capture_running
    import shutil

    if _capture_running:
        return "Already running — check progress with get_screenshot_log()"

    lines: list[str] = []
    lines.append(f"PLAYWRIGHT_PYTHON={PLAYWRIGHT_PYTHON!r}")
    lines.append(f"README_UPDATER={README_UPDATER!r}")
    lines.append(f"PROJECT_DIR={PROJECT_DIR!r}")

    if not shutil.which(PLAYWRIGHT_PYTHON) and not os.path.isfile(PLAYWRIGHT_PYTHON):
        lines.append(f"FAIL: Playwright Python not found at {PLAYWRIGHT_PYTHON!r}")
        return "\n".join(lines)
    if not os.path.isfile(README_UPDATER):
        lines.append(f"FAIL: readme_updater.py not found at {README_UPDATER!r}")
        return "\n".join(lines)

    cmd = [PLAYWRIGHT_PYTHON, README_UPDATER, "--app-url", app_url, "--project-dir", PROJECT_DIR]
    if file_id:
        cmd += ["--file-id", str(file_id)]

    # Open log file and launch process detached — Popen returns immediately
    log_f = open(SCREENSHOT_LOG, "w", buffering=1)
    log_f.write(f"[capture] started: {' '.join(cmd)}\n")
    log_f.flush()

    proc = subprocess.Popen(
        cmd,
        stdout=log_f,
        stderr=log_f,
        close_fds=True,
    )

    import threading
    def _wait():
        global _capture_running
        rc = proc.wait()
        log_f.write(f"[capture] done (exit {rc})\n")
        log_f.flush()
        log_f.close()
        _capture_running = False
    _capture_running = True
    threading.Thread(target=_wait, daemon=True).start()

    lines.append(f"Started (pid {proc.pid}). Log: {SCREENSHOT_LOG}")
    lines.append("Use get_screenshot_log() to follow progress.")
    return "\n".join(lines)


@mcp.tool()
def get_screenshot_log(lines: int = 50) -> str:
    """
    Return the last N lines of the screenshot capture log.
    Use after capture_ui_screenshots() to follow progress.
    When you see '[capture] done', check the '[capture] result:' line for the JSON summary.
    """
    try:
        with open(SCREENSHOT_LOG) as f:
            all_lines = f.readlines()
        tail = all_lines[-lines:]
        status = "RUNNING" if _capture_running else "idle"
        return f"[status: {status}]\n" + "".join(tail)
    except FileNotFoundError:
        return "No capture log yet. Call capture_ui_screenshots() first."


@mcp.tool()
def write_readme(readme_text: str) -> str:
    """
    Write a README.md to the project root.

    Call this after capture_ui_screenshots() — compose the README yourself
    (using the screenshot paths from that call) and pass the full Markdown text here.

    Parameters:
      readme_text  Complete Markdown content for README.md
    """
    readme_path = os.path.join(PROJECT_DIR, "README.md")
    try:
        with open(readme_path, "w") as f:
            f.write(readme_text)
        return f"README.md written ({len(readme_text)} chars) → {readme_path}"
    except Exception as e:
        return f"Failed to write README: {e}"


# ── scaffold ─────────────────────────────────────────────────────────────────

_SCAFFOLD_MCP_TOOL = '''\
# ── Group: {group} ───────────────────────────────────────────────────────────

@mcp.tool()
async def {name}(file_id: int) -> str:
    """TODO: describe what {name} does."""
    result = await _api("GET", f"/api/TODO/{{file_id}}")
    return json.dumps(result, indent=2, ensure_ascii=False)
'''

_SCAFFOLD_MCP_TOOL_DOCKER = '''\
# ── Group: {group} ───────────────────────────────────────────────────────────

@mcp.tool()
def {name}() -> str:
    """TODO: describe what {name} does."""
    return _docker_exec(["php", "/app/bin/console", "TODO:command"])
'''

_SCAFFOLD_VUE_COMPONENT = '''\
<template>
  <div class="{css}">
    <!-- TODO -->
  </div>
</template>

<script setup>
import {{ ref, computed }} from 'vue'
import {{ useTraceStore }} from '../stores/trace'

const store = useTraceStore()
const props = defineProps({{
  fileId: {{ type: Number, required: true }},
}})
</script>

<style scoped>
.{css} {{
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px;
  color: rgba(160, 185, 215, 0.85);
  background: rgba(255, 255, 255, 0.022);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
}}
</style>
'''

_SCAFFOLD_SETTINGS_SECTION = '''\
<!-- Add to sections[] in <script setup>: -->
{{ id: '{id}', icon: '{icon}', label: '{label}' }},

<!-- Template block (inside <div class="settings-content">): -->
<template v-if="activeSection === '{id}'">
  <div class="section-title">{label}</div>
  <div class="section-desc">
    TODO: describe this section.
  </div>

  <!-- TODO: content -->

  <transition name="toast">
    <div v-if="toast" class="toast" :class="'toast--' + toast.type">{{{{ toast.msg }}}}</div>
  </transition>
</template>
'''

_SCAFFOLD_PHP_ENDPOINT = '''\
    #[Route('/{route}/{{id}}', methods: ['{method}'])]
    public function {name}(int $id, Request $request): JsonResponse
    {{
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile) {{
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }}

        $tracePath = $this->tracesDir . '/' . $id;
        // TODO: implement using $this->traceIndex or $tracePath

        return $this->json([
            'file_id' => $traceFile->getId(),
            // TODO: result fields
        ]);
    }}
'''

_FILE_HINTS = {
    'mcp_tool':           'docker/mcp/server.py  (paste before the /status endpoint)',
    'mcp_tool_docker':    'docker/mcp/server.py  (paste before the /status endpoint)',
    'vue_component':      'frontend/src/components/{name}.vue  (create new file)',
    'vue_settings_section': 'frontend/src/components/SettingsPage.vue',
    'php_endpoint':       'symfony/src/Controller/TraceController.php',
}

_REBUILD_HINTS = {
    'mcp_tool':           'docker compose up -d --build mcp',
    'mcp_tool_docker':    'docker compose up -d --build mcp',
    'vue_component':      'cd frontend && npm run build',
    'vue_settings_section': 'cd frontend && npm run build',
    'php_endpoint':       '(none — PHP files are volume-mounted, changes apply immediately)',
}


@mcp.tool()
def scaffold(
    type: str,
    name: str,
    group: str = "",
    label: str = "",
    icon: str = "◈",
    route: str = "",
    method: str = "GET",
) -> str:
    """
    Return a ready-to-paste code scaffold for a new feature in xtrace-explorer.
    All paths are relative to the project root (where docker-compose.yml lives).

    type:
      mcp_tool              — @mcp.tool() calling _api() (HTTP to app container)
      mcp_tool_docker       — @mcp.tool() calling _docker_exec()
      vue_component         — Vue3 SFC with store + props skeleton
      vue_settings_section  — new tab in SettingsPage (nav entry + template block)
      php_endpoint          — new method in TraceController

    name:   snake_case for mcp/php, PascalCase for Vue SFC, kebab-case for settings tab id
    group:  section comment label in server.py (mcp types only)
    label:  human-readable title shown in UI (vue_settings_section)
    icon:   single char for sidebar nav (vue_settings_section)
    route:  URL slug without braces, e.g. "my-feature" → /api/my-feature/{id}  (php_endpoint)
    method: GET | POST | DELETE  (php_endpoint)
    """
    css = name.replace('_', '-').lower()
    id_ = css

    bodies = {
        'mcp_tool':             _SCAFFOLD_MCP_TOOL.format(name=name, group=group or 'New'),
        'mcp_tool_docker':      _SCAFFOLD_MCP_TOOL_DOCKER.format(name=name, group=group or 'New'),
        'vue_component':        _SCAFFOLD_VUE_COMPONENT.format(name=name, css=css),
        'vue_settings_section': _SCAFFOLD_SETTINGS_SECTION.format(id=id_, label=label or name, icon=icon),
        'php_endpoint':         _SCAFFOLD_PHP_ENDPOINT.format(name=name, route=route or css, method=method.upper()),
    }

    if type not in bodies:
        return f"Unknown type '{type}'. Available: {', '.join(bodies)}"

    file_hint = _FILE_HINTS[type].format(name=name)
    rebuild   = _REBUILD_HINTS[type]

    return "\n".join([
        f"=== scaffold: {type} / {name} ===",
        "",
        bodies[type].rstrip(),
        "",
        f"── file to edit ──",
        file_hint,
        "",
        f"── rebuild ──",
        rebuild,
    ])


# ── /status HTTP endpoint ─────────────────────────────────────────────────────

@mcp.custom_route("/capture-start", methods=["POST"])
async def http_capture_start(request: Request) -> JSONResponse:
    """Start screenshot capture in background. Returns 202 immediately."""
    global _capture_running
    import shutil
    if _capture_running:
        return JSONResponse({"status": "already_running", "log": SCREENSHOT_LOG}, status_code=409)
    if not shutil.which(PLAYWRIGHT_PYTHON) and not os.path.isfile(PLAYWRIGHT_PYTHON):
        return JSONResponse({"error": f"PLAYWRIGHT_PYTHON not found: {PLAYWRIGHT_PYTHON}"}, status_code=500)
    if not os.path.isfile(README_UPDATER):
        return JSONResponse({"error": f"README_UPDATER not found: {README_UPDATER}"}, status_code=500)

    body = {}
    try:
        body = await request.json()
    except Exception:
        pass
    app_url = body.get("app_url", APP_URL)
    file_id = body.get("file_id", 0)

    cmd = [PLAYWRIGHT_PYTHON, README_UPDATER, "--app-url", app_url, "--project-dir", PROJECT_DIR]
    if file_id:
        cmd += ["--file-id", str(file_id)]

    log_f = open(SCREENSHOT_LOG, "w", buffering=1)
    log_f.write(f"[capture] started: {' '.join(cmd)}\n")
    log_f.flush()
    proc = subprocess.Popen(cmd, stdout=log_f, stderr=log_f, close_fds=True)
    _capture_running = True

    import threading
    def _wait():
        global _capture_running
        rc = proc.wait()
        log_f.write(f"[capture] done (exit {rc})\n")
        log_f.flush()
        log_f.close()
        _capture_running = False
    threading.Thread(target=_wait, daemon=True).start()

    return JSONResponse({"status": "started", "pid": proc.pid, "log": SCREENSHOT_LOG}, status_code=202)


@mcp.custom_route("/capture-log", methods=["GET"])
async def http_capture_log(request: Request) -> JSONResponse:
    """Return current screenshot capture log and running status."""
    try:
        with open(SCREENSHOT_LOG) as f:
            content = f.read()
    except FileNotFoundError:
        content = ""
    return JSONResponse({
        "running": _capture_running,
        "log": content,
    }, headers={"Access-Control-Allow-Origin": "*"})


@mcp.custom_route("/status", methods=["GET"])
async def http_status(request: Request) -> JSONResponse:
    """Return MCP server status: active SSE connections, last tool call time, total calls."""
    global _connections, _last_tool_call, _total_tool_calls
    now = time.time()
    last_seen_ago = round(now - _last_tool_call) if _last_tool_call else None
    return JSONResponse({
        "ok": True,
        "connections": _connections,
        "last_tool_call_ago": last_seen_ago,
        "total_tool_calls": _total_tool_calls,
    }, headers={"Access-Control-Allow-Origin": "*"})


# ── SSE connection tracking middleware ────────────────────────────────────────

class TrackingMiddleware:
    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        global _connections, _last_tool_call, _total_tool_calls
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        path = scope.get("path", "")

        if path.startswith("/sse"):
            _connections += 1
            try:
                await self.app(scope, receive, send)
            finally:
                _connections -= 1
        elif path.startswith("/messages/") and scope.get("method") == "POST":
            _last_tool_call = time.time()
            _total_tool_calls += 1
            await self.app(scope, receive, send)
        else:
            await self.app(scope, receive, send)


# ── entrypoint ────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn
    # Try streamable HTTP (mcp >= 1.2) — no SSE race condition on init.
    # Fall back to sse_app for older clients.
    if hasattr(mcp, "streamable_http_app"):
        app = mcp.streamable_http_app()
    else:
        app = mcp.sse_app()
    app = TrackingMiddleware(app)
    uvicorn.run(app, host="0.0.0.0", port=8766)
