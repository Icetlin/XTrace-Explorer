import { chromium } from '@playwright/test';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

page.on('console', msg => { if (!msg.text().includes('deprecated')) console.log('[browser]', msg.text()) });
page.on('pageerror', err => console.log('[page error]', err.message));

await page.goto('http://localhost:8765/app/');
await page.waitForTimeout(2000);
await page.screenshot({ path: '/tmp/xt_01_loaded.png' });
console.log('step1: page loaded');

const tabs = await page.locator('.trace-tab').all();
console.log('tabs count:', tabs.length);
for (const t of tabs) console.log('  tab:', (await t.innerText()).trim().replace(/\n/g,' '));

if (tabs.length === 0) {
  await page.click('.tab-add');
  await page.waitForTimeout(500);
  const rows = await page.locator('.file-row').all();
  console.log('file rows:', rows.length);
  if (rows.length > 0) {
    await rows[0].click();
    await page.waitForTimeout(4000);
  }
}

await page.screenshot({ path: '/tmp/xt_02_trace.png' });

const eventRows = await page.locator('.event-row').all();
console.log('event rows:', eventRows.length);

if (eventRows.length > 0) {
  // Expand first event that has listeners
  await eventRows[0].click();
  await page.waitForTimeout(500);

  const listenerRows = await page.locator('.listener-row').all();
  console.log('listener rows:', listenerRows.length);

  if (listenerRows.length > 0) {
    // Expand first listener
    await listenerRows[0].click();
    await page.waitForTimeout(1200);
    await page.screenshot({ path: '/tmp/xt_03_listener_expanded.png' });
    console.log('step3: listener expanded');

    const callRows = await page.locator('.call-row').all();
    console.log('call rows:', callRows.length);

    if (callRows.length > 0) {
      // Ctrl+click first call row
      await callRows[0].click({ modifiers: ['Control'] });
      await page.waitForTimeout(2000);
      await page.screenshot({ path: '/tmp/xt_04_after_ctrl_click.png' });

      const previewVisible = await page.locator('.sel-preview').isVisible();
      console.log('schema preview visible:', previewVisible);

      if (previewVisible) {
        const title = await page.locator('.sel-preview__title').innerText();
        const count = await page.locator('.sel-preview__count').innerText();
        console.log('preview title:', title, '| count:', count);

        const nodes = await page.locator('.schema-row').all();
        console.log('schema nodes in preview:', nodes.length);
        for (let i = 0; i < Math.min(nodes.length, 8); i++) {
          const txt = (await nodes[i].innerText()).trim().replace(/\n/g,' ');
          const isSelected = await nodes[i].evaluate(el => el.classList.contains('schema-row--selected'));
          console.log(`  node[${i}]${isSelected?' [SELECTED]':''}:`, txt.slice(0,80));
        }

        await page.screenshot({ path: '/tmp/xt_05_preview_detail.png' });

        // Check selected row in main tree has only border (no heavy bg)
        const selectedCallRow = await page.locator('.call-row--selected').first();
        if (await selectedCallRow.isVisible()) {
          const bg = await selectedCallRow.evaluate(el => getComputedStyle(el).backgroundColor);
          console.log('selected call-row background:', bg, '(should be transparent/empty)');
        }

        // Add second selection
        if (callRows.length > 2) {
          await callRows[2].click({ modifiers: ['Control'] });
          await page.waitForTimeout(2000);
          const count2 = await page.locator('.sel-preview__count').innerText();
          console.log('after 2nd selection count:', count2);
          const nodes2 = await page.locator('.schema-row').all();
          console.log('schema nodes after 2nd select:', nodes2.length);
          await page.screenshot({ path: '/tmp/xt_06_multi_select.png' });
        }
      } else {
        console.log('ERROR: preview did not appear');
        // Check network
        const html = await page.locator('.sel-preview').count();
        console.log('sel-preview elements in DOM:', html);
      }
    }
  }
}

await browser.close();
console.log('done');
