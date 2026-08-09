// Phase UI verification: log into the panel, visit every operator page,
// capture console errors, page errors, failed requests and a screenshot.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE || 'http://127.0.0.1:8123';
const OUT = process.env.OUT || 'ui-audit/after';
const EMAIL = process.env.EMAIL || 'admin@sipeta.test';
const PASSWORD = process.env.PASSWORD || 'password';

const PAGES = [
  ['dashboard', '/admin'],
  ['kk-list', '/admin/kartu-keluargas'],
  ['kk-create', '/admin/kartu-keluargas/create'],
  ['penduduk-list', '/admin/penduduks'],
  ['penduduk-create', '/admin/penduduks/create'],
  ['backup', '/admin/backup'],
  ['settings', '/admin/settings'],
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const report = [];

  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[type=email]', EMAIL);
  await page.fill('input[type=password]', PASSWORD);
  await Promise.all([
    page.waitForURL('**/admin**', { timeout: 20000 }).catch(() => {}),
    page.click('button[type=submit]'),
  ]);
  await page.waitForTimeout(1500);
  const loggedIn = !page.url().includes('/login');
  console.log('LOGIN ' + (loggedIn ? 'OK' : 'FAILED -> ' + page.url()));
  if (!loggedIn) {
    fs.writeFileSync(`${OUT}/login-fail.html`, await page.content());
    await browser.close();
    process.exit(2);
  }

  for (const [name, path] of PAGES) {
    const p = await ctx.newPage();
    const errs = [], failed = [];
    p.on('console', m => { if (m.type() === 'error') errs.push(m.text().slice(0, 300)); });
    p.on('pageerror', e => errs.push('PAGEERROR: ' + String(e).slice(0, 300)));
    p.on('requestfailed', r => failed.push(`${r.url().slice(0, 120)} ${r.failure()?.errorText}`));

    let status = 0;
    try {
      const resp = await p.goto(BASE + path, { waitUntil: 'networkidle', timeout: 45000 });
      status = resp ? resp.status() : 0;
      await p.waitForTimeout(2500); // let lazy Livewire widgets settle
    } catch (e) {
      errs.push('NAV: ' + String(e).slice(0, 200));
    }

    const body = await p.content();
    const sqlErr = /SQLSTATE|Unknown column|QueryException/i.test(body);
    const laravelErr = /Whoops|Internal Server Error|Symfony\\Component\\ErrorHandler/i.test(body);

    // measure horizontal overflow
    const overflow = await p.evaluate(() => {
      const d = document.documentElement;
      return { scrollW: d.scrollWidth, clientW: d.clientWidth, scrollH: d.scrollHeight, clientH: d.clientHeight };
    }).catch(() => null);

    await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: true }).catch(() => {});
    report.push({ name, path, status, errs, failed, sqlErr, laravelErr, overflow });
    console.log(
      `${name.padEnd(16)} HTTP=${status} console=${errs.length} failedReq=${failed.length} ` +
      `SQLerr=${sqlErr} laravelErr=${laravelErr} ` +
      `overflowX=${overflow ? overflow.scrollW > overflow.clientW + 2 : '?'} ` +
      `pageH=${overflow ? overflow.scrollH : '?'}`
    );
    if (errs.length) errs.slice(0, 4).forEach(e => console.log('    ERR: ' + e));
    await p.close();
  }

  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  await browser.close();
})();
