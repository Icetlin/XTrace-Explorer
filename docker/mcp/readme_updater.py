#!/usr/bin/env python3
"""
readme_updater.py — captures live screenshots and animated GIFs of the running
XTrace Explorer UI, then writes README.md using content provided via --readme-text.

Usage (capture only, return JSON summary):
  /tmp/pw_venv/bin/python3 readme_updater.py \
      --app-url http://localhost:8765 \
      --project-dir /path/to/xtrace-explorer

Usage (capture + write README supplied externally):
  /tmp/pw_venv/bin/python3 readme_updater.py \
      --project-dir /path/to/xtrace-explorer \
      --readme-file /tmp/readme.md
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path

from PIL import Image
from playwright.sync_api import sync_playwright

# ── CLI args ──────────────────────────────────────────────────────────────────

def parse_args():
    p = argparse.ArgumentParser()
    p.add_argument("--app-url", default="http://localhost:8765")
    p.add_argument("--project-dir", required=True)
    p.add_argument("--file-id", type=int, default=None,
                   help="Trace file id to use for demos (auto-detected if omitted)")
    p.add_argument("--out-readme", default=None,
                   help="Path to write README (default: project-dir/README.md)")
    p.add_argument("--screenshots-dir", default=None,
                   help="Where to save screenshots/gifs (default: project-dir/docs/screenshots)")
    p.add_argument("--readme-file", default=None,
                   help="Path to a pre-generated README to write to --out-readme")
    p.add_argument("--dry-run", action="store_true",
                   help="Capture screenshots/GIFs but skip writing README")
    return p.parse_args()


# ── Screenshot helpers ────────────────────────────────────────────────────────

def wait_and_screenshot(page, path: str, wait_ms: int = 600) -> str:
    page.wait_for_load_state("networkidle", timeout=10000)
    time.sleep(wait_ms / 1000)
    page.screenshot(path=path, full_page=False)
    return path


def frames_to_gif(frames: list[str], out_path: str, duration: int = 1000, loop: int = 0):
    """Convert PNG frame paths to an optimised animated GIF via Pillow."""
    images = [Image.open(f).convert("RGBA") for f in frames]
    w, h = images[0].size
    nw, nh = w // 2, h // 2
    palettised = [
        img.resize((nw, nh), Image.LANCZOS).convert("P", palette=Image.ADAPTIVE, colors=128)
        for img in images
    ]
    palettised[0].save(
        out_path,
        save_all=True,
        append_images=palettised[1:],
        duration=duration,
        loop=loop,
        optimize=True,
    )


# ── Screenshot scenarios ──────────────────────────────────────────────────────

def capture_all(page, base_url: str, file_id: int, ss_dir: Path) -> dict[str, str]:
    """
    Navigate through all key UI states and capture screenshots.
    Uses verified CSS class names from the real DOM.
    Returns dict: name → absolute file path.
    """
    shots = {}

    # 1. Empty state — clear localStorage so no stale tabs are restored
    page.goto(base_url, wait_until="networkidle")
    page.evaluate("() => localStorage.clear()")
    page.reload(wait_until="networkidle")
    time.sleep(0.5)
    shots["01_empty"] = wait_and_screenshot(page, str(ss_dir / "01-empty.png"))

    # 2. File picker
    page.locator("button.tab-add").click()
    time.sleep(0.5)
    page.wait_for_selector(".file-browser", timeout=5000)
    shots["02_picker"] = wait_and_screenshot(page, str(ss_dir / "02-picker.png"))

    # 3. Open the demo trace file — find .file-row containing "demo_shop"
    try:
        demo_row = page.locator(".file-row").filter(has_text="demo_shop")
        if demo_row.count() == 0:
            # fallback: first row
            demo_row = page.locator(".file-row").first
        else:
            demo_row = demo_row.first
        demo_row.click()
        time.sleep(1.2)
        page.wait_for_load_state("networkidle")
        page.wait_for_selector(".toc-tree", timeout=10000)
        shots["03_toc"] = wait_and_screenshot(page, str(ss_dir / "03-toc.png"))
    except Exception as e:
        print(f"[readme-updater] skip 03_toc: {e}", flush=True)

    # 4. Expand first event with listeners
    try:
        page.locator(".event-row.has-listeners").first.click()
        time.sleep(0.5)
        shots["04_expanded"] = wait_and_screenshot(page, str(ss_dir / "04-expanded.png"))
    except Exception as e:
        print(f"[readme-updater] skip 04_expanded: {e}", flush=True)

    # 5. Expand first listener → lazy call tree
    try:
        page.locator(".listener-row").first.click()
        time.sleep(1.0)
        page.wait_for_load_state("networkidle")
        page.wait_for_selector(".call-node", timeout=8000)
        shots["05_calltree"] = wait_and_screenshot(page, str(ss_dir / "05-calltree.png"))
    except Exception as e:
        print(f"[readme-updater] skip 05_calltree: {e}", flush=True)

    # 6. Expand a non-leaf call-row deeper
    try:
        page.locator(".call-row:not(.call-row--leaf)").first.click()
        time.sleep(0.6)
        page.wait_for_load_state("networkidle")
        shots["05b_calltree_deep"] = wait_and_screenshot(page, str(ss_dir / "05b-calltree-deep.png"))
    except Exception as e:
        print(f"[readme-updater] skip 05b_calltree_deep: {e}", flush=True)

    # 7. Source view — appears inside expanded call-nodes when source path is configured
    try:
        for _ in range(4):
            expandable = page.locator(".call-row:not(.call-row--leaf)").all()
            if not expandable:
                break
            expandable[0].click()
            time.sleep(0.5)
            page.wait_for_load_state("networkidle")
            if page.locator(".source-view").count() > 0:
                break
        if page.locator(".source-view").count() > 0:
            shots["07_source_view"] = wait_and_screenshot(page, str(ss_dir / "07-source-view.png"))
        else:
            print("[readme-updater] skip 07_source_view: no source-view appeared", flush=True)
    except Exception as e:
        print(f"[readme-updater] skip 07_source_view: {e}", flush=True)

    # 8. Export panel — Ctrl+Click event rows to build selection
    try:
        event_rows = page.locator(".event-row.has-listeners").all()
        for row in event_rows[:3]:
            row.click(modifiers=["Control"])
            time.sleep(0.15)
        time.sleep(0.4)
        if page.locator(".export-panel").count() > 0:
            shots["06_export"] = wait_and_screenshot(page, str(ss_dir / "06-export.png"))
        else:
            print("[readme-updater] skip 06_export: export-panel not visible", flush=True)
        clear_btn = page.locator(".export-clear")
        if clear_btn.count() > 0:
            clear_btn.first.click()
        time.sleep(0.2)
    except Exception as e:
        print(f"[readme-updater] skip 06_export: {e}", flush=True)

    # 9. Settings → General
    try:
        page.locator("button.nav-btn").click()
        time.sleep(0.5)
        page.wait_for_selector(".settings-page", timeout=5000)
        shots["09_settings"] = wait_and_screenshot(page, str(ss_dir / "09-settings.png"))
        # Xdebug tab
        page.locator(".nav-item").filter(has_text="Xdebug").click()
        time.sleep(0.4)
        shots["09b_xdebug"] = wait_and_screenshot(page, str(ss_dir / "09b-xdebug.png"))
        page.locator("button.nav-btn").click()
        time.sleep(0.3)
    except Exception as e:
        print(f"[readme-updater] skip 09_settings: {e}", flush=True)

    return shots


# ── GIF builder ───────────────────────────────────────────────────────────────

def build_gifs(shots: dict[str, str], ss_dir: Path) -> dict[str, str]:
    gifs = {}

    drill_keys = ["01_empty", "02_picker", "03_toc", "04_expanded", "05_calltree"]
    drill_frames = [shots[k] for k in drill_keys if k in shots]
    if len(drill_frames) >= 3:
        out = str(ss_dir / "demo-drilldown.gif")
        frames_to_gif(drill_frames, out, duration=1000)
        gifs["demo-drilldown"] = out
        print(f"[readme-updater] GIF: {out} ({len(drill_frames)} frames)", flush=True)

    deep_keys = ["05_calltree", "05b_calltree_deep", "07_source_view"]
    deep_frames = [shots[k] for k in deep_keys if k in shots]
    if len(deep_frames) >= 2:
        out = str(ss_dir / "demo-deep-dive.gif")
        frames_to_gif(deep_frames, out, duration=1100)
        gifs["demo-deep-dive"] = out
        print(f"[readme-updater] GIF: {out} ({len(deep_frames)} frames)", flush=True)

    return gifs


# ── .env loader ───────────────────────────────────────────────────────────────

def _load_dotenv(project_dir: Path):
    env_file = project_dir / ".env"
    if not env_file.exists():
        return
    for line in env_file.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        key = key.strip()
        val = val.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = val


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    args = parse_args()
    project_dir = Path(args.project_dir).resolve()
    ss_dir = Path(args.screenshots_dir) if args.screenshots_dir else project_dir / "docs" / "screenshots"
    ss_dir.mkdir(parents=True, exist_ok=True)
    out_readme = Path(args.out_readme) if args.out_readme else project_dir / "README.md"

    _load_dotenv(project_dir)

    print(f"[readme-updater] app: {args.app_url}", flush=True)
    print(f"[readme-updater] screenshots → {ss_dir}", flush=True)

    # Auto-detect a ready file_id
    file_id = args.file_id
    if not file_id:
        import httpx
        files = httpx.get(f"{args.app_url}/api/files", timeout=10).json()
        ready = [f for f in files if f.get("status") == "ready"]
        if ready:
            file_id = ready[0]["file_id"]
            print(f"[readme-updater] using file_id={file_id} ({ready[0]['name'][:60]})", flush=True)

    # Capture screenshots
    print("[readme-updater] launching browser...", flush=True)
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        page = browser.new_page(viewport={"width": 1400, "height": 860})
        print("[readme-updater] capturing screenshots...", flush=True)
        shots = capture_all(page, args.app_url, file_id, ss_dir)
        print(f"[readme-updater] captured: {list(shots.keys())}", flush=True)
        browser.close()

    # Build GIFs
    print("[readme-updater] building GIFs...", flush=True)
    gifs = build_gifs(shots, ss_dir)

    result = {
        "screenshots": {k: str(Path(v).name) for k, v in shots.items()},
        "gifs": {k: str(Path(v).name) for k, v in gifs.items()},
        "screenshots_dir": str(ss_dir),
        "readme_path": str(out_readme),
    }

    # If a pre-generated README is supplied, write it now
    if args.readme_file and not args.dry_run:
        readme_text = Path(args.readme_file).read_text()
        out_readme.write_text(readme_text)
        result["readme_written"] = str(out_readme)
        result["readme_length"] = len(readme_text)
        print(f"[readme-updater] README written → {out_readme}", flush=True)

    print(json.dumps(result))


if __name__ == "__main__":
    main()
