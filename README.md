# XTrace Explorer

> A browser-based viewer for Xdebug function trace files (`.xt`). Navigate millions of function calls, drill into event listeners, search by class name, and annotate interesting lines — all without loading the full trace into memory.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4.svg)
![Vue](https://img.shields.io/badge/Vue-3-42b883.svg)
![Docker](https://img.shields.io/badge/docker-compose-2496ED.svg)

---

## What problem does it solve

Xdebug can record every function call in a PHP request to a `.xt` file. A single request can produce **3–10 million lines** and hundreds of megabytes. Opening that in a text editor is painful.

XTrace Explorer parses the file in the background, builds a line index for fast random access, extracts the Symfony event dispatch tree, and lets you navigate it lazily in the browser — expanding only what you need.

---

## Screenshots

**Empty state — click `+` to open a trace:**

![Empty state](docs/screenshots/01-empty.png)

**File browser — lists all `.xt` files from your configured trace directory:**

![File picker](docs/screenshots/02-picker.png)

**TOC view — all dispatched Symfony events for the request, grouped by source (SF / APP / JWT / 2FA...):**

![TOC view](docs/screenshots/03-toc.png)

**Expand an event — see its listeners grouped by source:**

![Expanded event](docs/screenshots/04-expanded.png)

**Drill into a listener — lazy-loaded call tree with arguments, return values, and file:line:**

![Call tree](docs/screenshots/05-calltree.png)

**`Ctrl+Click` events or listeners to select them, then export as Markdown — copy to clipboard or download as `.md`:**

![Export](docs/screenshots/06-export.png)

---

## Features

- **Multi-tab** — open several traces side by side, sessions persist across page reloads
- **Event TOC** — shows every `TraceableEventDispatcher->dispatch` call, grouped by source framework (SF / APP / JWT / 2FA / etc.)
- **Lazy call tree** — children are fetched on demand; only the nodes you expand are loaded
- **Arguments & return values** — parsed from xdebug format: objects simplified to `ClassName {…}`, strings truncated, JWTs replaced with `<JWT>`
- **Noise filter** — hides Symfony/Doctrine plumbing (Container, ServiceLocator, Stopwatch, etc.) by default; toggle with "show all calls"
- **Full-text search** — search by class/method name across the entire trace
- **Ctrl+Click export** — select any events/listeners with `Ctrl+Click`, then copy as Markdown or download `.md` — useful for sharing findings or writing postmortems
- **Favourites** — bookmark traces for quick access
- **Request info bar** — shows method, URI, host, IP, content-type, cookies count at a glance
- **Session persistence** — open tabs and scroll positions are restored on reload

---

## Requirements

- Docker + Docker Compose
- An Xdebug trace directory (files generated with `xdebug.mode=trace`)

---

## Quick start

```bash
git clone https://github.com/Icetlin/XTrace-Explorer.git
cd XTrace-Explorer
```

Edit `docker-compose.yml` — point the traces volume at your `.xt` files:

```yaml
volumes:
  - /path/to/your/xdebug/traces:/traces:ro
```

Then start:

```bash
docker compose up -d
```

Open **http://localhost:8765**, click **`+`**, pick a `.xt` file.

First open triggers background parsing (can take 10–60 s for large files). Status is shown in the tab. Parsed index is cached — subsequent opens are instant.

---

## Generating traces with Xdebug

Add to your `php.ini` / `xdebug.ini`:

```ini
xdebug.mode=trace
xdebug.start_with_request=trigger
xdebug.trace_output_dir=/path/to/traces
xdebug.trace_format=0
```

Then trigger a trace with the `XDEBUG_TRIGGER` cookie or query param:

```bash
curl -b "XDEBUG_TRIGGER=1" https://your-app.local/api/some/endpoint
```

The resulting `trace__*.xt` file will appear in the file browser automatically.

---

## Architecture

```
xtrace-explorer/
├── Dockerfile                  # PHP 8.3-fpm + nginx + supervisord, all in one image
├── docker-compose.yml
├── symfony/                    # Symfony 7 backend
│   └── src/
│       ├── Controller/TraceController.php   # REST API
│       ├── Service/TraceParser.php          # Parses .xt → toc.json + line_index.json
│       ├── Service/TraceIndex.php           # getChildren, search (random-access via index)
│       ├── Entity/TraceFile.php
│       ├── Entity/Annotation.php
│       └── MessageHandler/ParseTraceHandler.php  # Symfony Messenger async worker
└── frontend/                   # Vue 3 + Vite + Pinia
    └── src/
        ├── App.vue
        ├── stores/trace.js
        └── components/
            ├── TocTree.vue     # Event → Listener tree
            └── CallNode.vue    # Recursive call node (lazy)
```

**How parsing works:**

1. On first open, a `ParseTraceMessage` is dispatched to a Symfony Messenger worker
2. `TraceParser` scans the file once, building:
   - `line_index.json` — byte offset every 500 lines (enables `fseek` to any line in O(1))
   - `toc.json` — the event dispatch tree (event name → listeners), extracted by tracking `TraceableEventDispatcher->dispatch` calls on a stack (handles nested events correctly)
   - `meta.json` — total line count + request/response info
3. `TraceIndex::getChildren` uses the line index to seek directly to any call and read its immediate children, applying a noise filter

**API endpoints:**

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/api/browse` | list `.xt` files from traces dir |
| `POST` | `/api/open` | open a file, start parsing |
| `GET` | `/api/status/{id}` | parsing status + progress |
| `GET` | `/api/toc/{id}` | event dispatch tree |
| `GET` | `/api/children/{id}?line_no=N&depth=D&raw=0` | children of a call node |
| `GET` | `/api/search/{id}?q=...` | search by class/method name |
| `GET/POST/DELETE` | `/api/annotations/{id}` | per-line annotations |
| `GET` | `/api/annotations/{id}/export` | export annotations as Markdown |
| `POST` | `/api/reparse/{id}` | force re-parse (after upgrading) |

---

## Development

```bash
# Backend (runs on :8765)
docker compose up -d

# Frontend dev server with HMR
cd frontend
npm install
npm run dev   # proxies API to localhost:8765

# After changing TraceParser — re-parse a file
curl -X POST http://localhost:8765/api/reparse/<file_id>

# After changing frontend — rebuild
cd frontend && npm run build
```

---

## License

MIT — see [LICENSE](LICENSE).
