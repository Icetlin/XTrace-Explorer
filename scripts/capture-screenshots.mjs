#!/usr/bin/env node
/**
 * scripts/capture-screenshots.mjs
 *
 * Снимает скриншоты UI с demo-трейса для docs/screenshots/.
 * Обязательно: запущенный app (http://localhost:8765) с mounted /traces/.
 *
 * Использует ТОЛЬКО demo-трейс:
 *   /home/ilia/dev/work/systemeio/backend/xdebug_trace/2026-06-02_13-43-40_demo_shop_checkout/trace__demo_shop_checkout.xt
 * НЕ подменяй на реальный .xt — там могут быть sensitive данные.
 *
 * Usage:
 *   APP_URL=http://localhost:8765 node scripts/capture-screenshots.mjs
 *
 * Время: ~20-40 сек на 10 скриншотов.
 *
 * Зависимости (должны быть в frontend/package.json):
 *   pnpm add -D playwright
 */
import { chromium } from '/home/ilia/dev/personal/xtrace-explorer/frontend/node_modules/playwright/index.mjs';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

const APP_URL = process.env.APP_URL || 'http://localhost:8765';
// Демо-трейс лежит в mounted-директории /traces/ (на хосте — ${TRACES_DIR} из docker-compose.yml).
// ВАЖНО: эту же директорию могут делить реальные продакшн-трейсы — мы НЕ должны их трогать.
const TRACES_DIR = process.env.TRACES_DIR
  || '/home/ilia/dev/work/systemeio/backend/xdebug_trace';
const DEMO_TRACE_REL = '2026-06-02_13-43-40_demo_shop_checkout/trace__demo_shop_checkout.xt';
const DEMO_TRACE_NAME = 'trace__demo_shop_checkout.xt';
const OUT_DIR = path.join(ROOT, 'docs', 'screenshots');

const log = (...a) => console.log('[shots]', ...a);
const err = (...a) => console.error('[shots]', ...a);

function ensureDemoTrace() {
  if (!fs.existsSync(TRACES_DIR)) {
    throw new Error(`TRACES_DIR не существует: ${TRACES_DIR}\nЗапусти docker-compose (там ${TRACES_DIR} монтируется в /traces).\nЛибо задай TRACES_DIR=... при вызове скрипта.`);
  }
  const dest = path.join(TRACES_DIR, DEMO_TRACE_REL);
  if (!fs.existsSync(dest)) {
    throw new Error(`Демо-трейс не найден: ${dest}\nСогласно xtrace-explorer skill — этот файл ЕДИНСТВЕННЫЙ допустимый источник для скриншотов.`);
  }
  log(`demo trace: ${dest}`);
  // API XTrace открывает файлы по basename, поэтому отдадим просто 'trace__demo_shop_checkout.xt'
  return dest;
}

function cleanupDemoTrace() {
  // В этой конфигурации ничего не удаляем — демо-файл живёт в общей mounted-директории,
  // а рядом лежат реальные продакшн-трейсы, которые трогать НЕЛЬЗЯ.
  log('cleanup: noop (demo trace lives in shared mounted dir)');
}

async function api(method, p, body) {
  const url = `${APP_URL}${p}`;
  const r = await fetch(url, {
    method,
    headers: body ? {'Content-Type': 'application/json'} : undefined,
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!r.ok) throw new Error(`API ${method} ${p} → ${r.status}: ${await r.text()}`);
  return r.json();
}

async function waitForFile(fileId, timeoutMs = 30000) {
  const start = Date.now();
  let lastStatus = '';
  while (Date.now() - start < timeoutMs) {
    const files = await api('GET', '/api/files');
    const f = files.find(x => x.file_id === fileId);
    if (f) {
      if (f.status === 'ready') return f;
      if (f.status === 'error') throw new Error(`parse error: ${f.errorMessage}`);
      lastStatus = f.status;
    }
    await new Promise(r => setTimeout(r, 400));
  }
  throw new Error(`parse timeout (last status: ${lastStatus})`);
}

// Reset UI state: открыть modal, theme и пр. — кликаем мимо и Esc
async function resetUi(page) {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(150);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(150);
}

async function shot(page, name) {
  const p = path.join(OUT_DIR, name);
  await page.screenshot({ path: p, fullPage: false });
  log(`✓ ${name}`);
}

async function main() {
  ensureDemoTrace();

  // 1. Открываем demo trace через REST API
  log('opening demo trace via /api/open');
  const opened = await api('POST', '/api/open', { rel_path: DEMO_TRACE_REL });
  const fileId = opened.file_id;
  log(`file_id = ${fileId}, waiting for parse…`);
  await waitForFile(fileId);
  log('parsed ✓');

  // 2. Запускаем браузер
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    viewport: { width: 1400, height: 860 },
    deviceScaleFactor: 1,
    colorScheme: 'dark',  // дефолт; переключим для light theme
  });
  const page = await ctx.newPage();
  page.on('pageerror', e => err('PAGE ERROR:', e.message));

  try {
    // 01-empty: пустое состояние, ни один файл не выбран
    // Чтобы получить пустое, нужно закрыть текущий файл. Для простоты:
    // перед скриншотом делаем localStorage.clear() и reload
    log('01-empty');
    await page.goto(APP_URL);
    await page.evaluate(() => { localStorage.clear(); });
    await page.goto(APP_URL, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await shot(page, '01-empty.png');

    // 02-picker: открыть dropdown "+" — выбираем demo trace
    log('02-picker');
    // Таб "+" — обычно класс tabs-bar__plus или похожий; fallback через поиск кнопки с "+"
    const plusBtn = page.locator('.tabs-bar__plus, .tabs-bar__add, [data-testid="open-picker"]').first();
    if (await plusBtn.count() > 0) {
      await plusBtn.click();
    } else {
      // fallback: первая кнопка с текстом "+"
      await page.locator('button:has-text("+")').first().click();
    }
    await page.waitForTimeout(500);
    await shot(page, '02-picker.png');

    // Кликаем по demo trace в picker
    // parseFileName() превращает "trace__demo_shop_checkout.xt" в label "demo / shop / checkout"
    log('selecting demo trace from picker');
    const row = page.locator('.fb-file', { hasText: 'demo / shop / checkout' }).first();
    await row.click();
    await page.waitForTimeout(1500);  // wait for ready + render

    // 03-toc: TOC view
    log('03-toc');
    await resetUi(page);
    await page.waitForTimeout(500);
    await shot(page, '03-toc.png');

    // 04-expanded: expand first event row (если collapsed)
    log('04-expanded');
    // Первая event-row в toc-tree
    const firstEvent = page.locator('.toc-tree .event-row').first();
    if (await firstEvent.count() > 0) {
      // Если она collapsed (есть группа .event-group), кликаем
      const isCollapsed = await page.locator('.toc-tree .event-row--group').count() > 0;
      if (isCollapsed) {
        await firstEvent.click();
        await page.waitForTimeout(400);
      }
    }
    // Кликаем на первую listener-row чтобы раскрыть её
    const firstListener = page.locator('.toc-tree .listener-row').first();
    if (await firstListener.count() > 0) {
      await firstListener.locator('.chevron-sm').click().catch(() => firstListener.click());
      await page.waitForTimeout(600);
    }
    await shot(page, '04-expanded.png');

    // 05-calltree: раскрыть первый call node
    log('05-calltree');
    const firstCall = page.locator('.call-node').first();
    if (await firstCall.count() > 0) {
      await firstCall.click().catch(() => {});
      await page.waitForTimeout(600);
    }
    await shot(page, '05-calltree.png');

    // 05b-calltree-deep: раскрыть глубже
    log('05b-calltree-deep');
    const deeper = page.locator('.call-node__children .call-node, .children .call-node').first();
    if (await deeper.count() > 0) {
      await deeper.click().catch(() => {});
      await page.waitForTimeout(600);
    }
    await shot(page, '05b-calltree-deep.png');

    // 06-export: правый клик на call node → контекстное меню → Export
    log('06-export');
    await page.locator('.call-node').first().click({ button: 'right' }).catch(() => {});
    await page.waitForTimeout(400);
    const exportItem = page.locator('text=/export/i').first();
    if (await exportItem.count() > 0) {
      await exportItem.click().catch(() => {});
      await page.waitForTimeout(500);
    }
    await shot(page, '06-export.png');
    await resetUi(page);

    // 07-code-view: кликнуть на call node с source → открывается frosted-glass codeview
    log('07-code-view');
    const withSource = page.locator('.call-node--with-source, .call-node[data-has-source="true"]').first();
    if (await withSource.count() > 0) {
      await withSource.click().catch(() => {});
    } else {
      // fallback: первый call-node
      await page.locator('.call-node').first().click().catch(() => {});
    }
    await page.waitForTimeout(800);
    await shot(page, '07-code-view.png');
    await resetUi(page);

    // 08-light-theme: переключаем тему
    log('08-light-theme');
    const themeBtn = page.locator('.float-ctrl__item--theme').first();
    if (await themeBtn.count() > 0) {
      await themeBtn.click();
      await page.waitForTimeout(500);
    }
    await shot(page, '08-light-theme.png');
    // Возвращаем dark
    if (await themeBtn.count() > 0) {
      await themeBtn.click();
      await page.waitForTimeout(300);
    }

    // 09-settings: открыть settings modal
    log('09-settings');
    // Обычно в FloatCtrl есть кнопка настроек, или иконка шестерёнки
    const settingsBtn = page.locator('.float-ctrl__item--settings, [data-testid="open-settings"], button[title*="Settings" i]').first();
    if (await settingsBtn.count() > 0) {
      await settingsBtn.click();
      await page.waitForTimeout(600);
    } else {
      // fallback: 4-я или 5-я кнопка в float-ctrl
      await page.locator('.float-ctrl__item').nth(3).click().catch(() => {});
      await page.waitForTimeout(600);
    }
    await shot(page, '09-settings.png');
    await resetUi(page);

    // 09b-xdebug: открыть xdebug dropdown (float-ctrl__item--xd)
    log('09b-xdebug');
    const xdBtn = page.locator('.float-ctrl__item--xd').first();
    if (await xdBtn.count() > 0) {
      await xdBtn.click();
      await page.waitForTimeout(500);
    }
    await shot(page, '09b-xdebug.png');
    await resetUi(page);

    // 10-xdebug-panel: выбрать конкретный mode чтобы панель осталась открытой
    log('10-xdebug-panel');
    if (await xdBtn.count() > 0) {
      await xdBtn.click();
      await page.waitForTimeout(400);
      const modeOpt = page.locator('.xd-opt').first();
      if (await modeOpt.count() > 0) {
        await modeOpt.click().catch(() => {});
        await page.waitForTimeout(500);
      }
    }
    await shot(page, '10-xdebug-panel.png');
    await resetUi(page);

    log('✓ all 10 screenshots saved to docs/screenshots/');
  } finally {
    await browser.close();
    cleanupDemoTrace();
  }
}

main().catch(e => { err(e); process.exit(1); });
