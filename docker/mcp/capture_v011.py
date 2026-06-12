#!/usr/bin/env python3
"""Capture screenshots for v0.11.0 release.

Walks the running app (http://app:80 inside the mcp container) and
saves PNGs to /project/docs/screenshots/.

This is a one-off because docker/mcp/readme_updater.py pins to
outdated selectors (.file-browser, .file-row) that no longer match
the rewritten App.vue. We rebuild the relevant subset here with
the live, current selectors and aggressive fallback (DOM-nuke) for
modal closing.
"""
import os
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = os.environ.get("APP_URL", "http://app:80")
FILE_ID = int(os.environ.get("FILE_ID", "34"))
OUT = Path("/project/docs/screenshots")
OUT.mkdir(parents=True, exist_ok=True)


def shot(page, name, label=""):
    time.sleep(0.5)
    p = OUT / name
    page.screenshot(path=str(p), full_page=False)
    print(f"  ✓ {name:30} {p.stat().st_size//1024:>4} KB  {label}")


def nuke_floats(page):
    """Force-close any open float modal (DOM removal as last resort)."""
    page.evaluate("""
        document.querySelectorAll('.float-modal-wrap, .summary-modal-wrap, .fb-overlay')
          .forEach(el => el.remove());
    """)
    time.sleep(0.3)


def click_in_float_ctrl(page, title_match):
    """Click a button in FloatCtrl identified by title attribute. Tries a
    normal click first, falls back to force-click if a stale modal is
    blocking."""
    sel = f'button.float-ctrl__item[title*="{title_match}"]'
    btn = page.locator(sel)
    if btn.count() == 0:
        print(f"  ! button '{title_match}' not found in float-ctrl")
        return False
    try:
        btn.first.click(timeout=2000)
    except Exception:
        print(f"  · normal click on '{title_match}' failed, force-clicking")
        nuke_floats(page)
        btn.first.click(force=True, timeout=2000)
    return True


def main():
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        ctx = browser.new_context(
            viewport={"width": 1600, "height": 1000}, device_scale_factor=1
        )
        page = ctx.new_page()

        # Clear any cached theme preference
        page.goto(BASE, wait_until="domcontentloaded")
        page.evaluate("localStorage.removeItem('xtrace-theme')")
        page.reload(wait_until="domcontentloaded")
        page.wait_for_selector(".tabs-bar", timeout=10000)
        shot(page, "01-empty.png", "app loaded, no trace open (dark)")

        # Open file picker
        page.locator("button.tab-add").click()
        page.wait_for_selector(".fb-modal", timeout=5000)
        shot(page, "02-picker.png", "file browser modal")

        # Open first ready trace
        page.locator(".fb-file").first.click()
        page.wait_for_selector(".toc-tree", timeout=10000)
        time.sleep(0.6)
        shot(page, "03-toc.png", "TOC tree — events → listeners → App calls")

        # SQL modal
        click_in_float_ctrl(page, "SQL Queries")
        page.wait_for_selector(".sql-page", timeout=5000)
        time.sleep(0.8)
        shot(page, "04-sql.png", "SQL Queries modal — chronological with N+1 + QB chains")

        # Close SQL
        nuke_floats(page)

        # AI summary
        print("[capture] open AI summary")
        click_in_float_ctrl(page, "AI summary")
        try:
            page.wait_for_selector(".summary-modal__textarea", timeout=20000)
        except Exception:
            page.wait_for_selector(".summary-modal__err", timeout=2000)
        time.sleep(0.6)
        shot(page, "05-ai-summary.png", "AI summary modal — preview/edit/copy markdown")

        # Close summary
        nuke_floats(page)

        # Open CodeView by clicking a node in TOC
        print("[capture] open CodeView")
        # Expand first event
        ev = page.locator(".toc-tree .event-row").first
        if ev.count() > 0:
            ev.click()
        time.sleep(0.3)
        # Expand first listener
        lchev = page.locator(".toc-tree .children .chevron-sm").first
        if lchev.count() > 0:
            lchev.click()
        time.sleep(0.4)
        # Click first call node
        cn = page.locator(".toc-tree [data-line-no]").first
        if cn.count() > 0:
            cn.click()
        try:
            page.wait_for_selector(".code-panel", timeout=10000)
        except Exception as e:
            print(f"  ! code panel didn't appear: {e}")
        time.sleep(0.8)
        shot(page, "06-codeview-frosted.png", "CodeView — frosted glass panel over TOC (dark)")

        # Switch to light theme
        print("[capture] switch to light theme")
        click_in_float_ctrl(page, "Switch to light")
        time.sleep(0.7)
        shot(page, "07-codeview-frosted-light.png", "CodeView — frosted glass in light theme")

        # Back to dark + close CodeView
        click_in_float_ctrl(page, "Switch to dark")
        time.sleep(0.4)
        # Close CodeView via Esc (this works — bound in CodeView.vue)
        page.keyboard.press("Escape")
        time.sleep(0.5)

        # Final overview — show float-ctrl with new button groups + ✨
        page.locator(".toc-tree").first.click()
        time.sleep(0.3)
        shot(page, "08-final.png", "Ctrl menu — 4 grouped blocks with ✨ AI summary button")

        browser.close()
    print("[capture] done")


if __name__ == "__main__":
    main()
