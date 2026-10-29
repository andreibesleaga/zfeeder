/**
 * tools/check-admin.mjs — look at the real admin panel, both skins.
 *
 * Signs in, walks the six screens at two widths in both colour schemes,
 * asserts the page never scrolls sideways, screenshots everything into
 * build/preview/admin-shots/, and runs axe-core on each screen.
 *
 *   ZF_ADMIN_MODERN=http://127.0.0.1:8190 ZF_ADMIN_CLASSIC=http://127.0.0.1:8191 \
 *   ZF_AXE=/path/to/axe.min.js \
 *   NODE_PATH=/home/andrei/work/AI/kaiban-distributed/node_modules \
 *   node tools/check-admin.mjs
 */
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';

const roots = (process.env.NODE_PATH || '').split(path.delimiter).filter(Boolean);
let chromium;
for (const base of [...roots, path.resolve('node_modules')]) {
  try {
    chromium = createRequire(path.join(base, 'noop.js'))('playwright').chromium;
    break;
  } catch { /* next */ }
}
if (!chromium) {
  console.error('playwright not resolvable; set NODE_PATH');
  process.exit(2);
}

const skins = [
  ['modern', process.env.ZF_ADMIN_MODERN || 'http://127.0.0.1:8190'],
  ['classic', process.env.ZF_ADMIN_CLASSIC || 'http://127.0.0.1:8191'],
];
const screens = [
  ['main', '/admin'],
  ['subscriptions', '/admin/subscriptions'],
  ['config', '/admin/config'],
  ['add-new', '/admin/add-new'],
  ['import', '/admin/import'],
  ['updates', '/admin/updates'],
];
const widths = [1280, 375];
const schemes = ['light', 'dark'];
const user = process.env.ZF_ADMIN_USER || 'admin';
const pass = process.env.ZF_ADMIN_PASS || 'demo2026zfeeder';
const axeSource = process.env.ZF_AXE ? await readFile(process.env.ZF_AXE, 'utf8') : null;

const shotDir = path.resolve('build/preview/admin-shots');
await mkdir(shotDir, { recursive: true });

const overflow = [];
const violations = [];
const unstyled = [];
const browser = await chromium.launch();

for (const [skin, base] of skins) {
  for (const scheme of schemes) {
    const context = await browser.newContext({ colorScheme: scheme, viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    // The admin sets `script-src 'self' 'nonce-…'`, so an inline <script> is
    // refused - as it should be. Serve axe from a same-origin URL instead,
    // intercepted here so the application never has to ship a test tool.
    if (axeSource) {
      await page.route('**/__axe.js', (route) =>
        route.fulfill({ status: 200, contentType: 'application/javascript', body: axeSource }),
      );
    }

    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('#admin_user', user);
    await page.fill('#admin_pass', pass);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')]);
    if (!(await page.locator('.zf-nav-list').count())) {
      console.error(`${skin}/${scheme}: sign-in failed (no nav after login)`);
      process.exitCode = 1;
      await context.close();
      continue;
    }

    for (const [name, urlPath] of screens) {
      for (const width of widths) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(base + urlPath, { waitUntil: 'networkidle' });

        const probe = await page.evaluate(() => {
          const body = document.body;
          const cs = getComputedStyle(body);
          const link = document.querySelector('.zf-nav-link');
          const btn = document.querySelector('.zf-button');
          const srOnly = document.querySelector('.zf-sr-only');
          const rect = (el) => (el ? el.getBoundingClientRect() : null);
          const r = rect(btn);
          const sr = rect(srOnly);
          return {
            scrollWidth: document.documentElement.scrollWidth,
            innerWidth: window.innerWidth,
            bodyBg: cs.backgroundColor,
            bodyFont: cs.fontFamily.split(',')[0],
            navStyled: link ? getComputedStyle(link).textDecorationLine !== 'underline' || getComputedStyle(link).minHeight !== 'auto' : true,
            buttonHeight: r ? Math.round(r.height) : null,
            buttonMinHeight: btn ? getComputedStyle(btn).minHeight : null,
            srOnlyHidden: sr ? Math.round(sr.width) <= 2 : null,
            smallTargets: [...document.querySelectorAll('button, .zf-button, .zf-nav-link, a.zf-skip')]
              .filter((el) => {
                const b = el.getBoundingClientRect();
                return b.width > 0 && b.height > 0 && b.height < 44;
              })
              .map((el) => (el.className || el.tagName) + ':' + Math.round(el.getBoundingClientRect().height)),
          };
        });

        if (probe.scrollWidth > probe.innerWidth) {
          overflow.push(`${skin}/${scheme} ${name} @${width}: ${probe.scrollWidth} > ${probe.innerWidth}`);
        }
        // A page with no stylesheet applied keeps the UA's transparent body and
        // unsized buttons. (The visually-hidden labels are *deliberately* shown
        // in the narrow table layout, so they cannot be used as the probe.)
        if (probe.bodyBg === 'rgba(0, 0, 0, 0)' || probe.buttonMinHeight !== '44px') {
          unstyled.push(`${skin}/${scheme} ${name} @${width}: bg=${probe.bodyBg} button min-height=${probe.buttonMinHeight}`);
        }
        if (width >= 768 && probe.srOnlyHidden === false) {
          unstyled.push(`${skin}/${scheme} ${name} @${width}: .zf-sr-only is visible in the wide layout`);
        }
        if (width === 1280 && probe.smallTargets.length) {
          console.log(`  small targets on ${skin}/${scheme} ${name}: ${probe.smallTargets.join(', ')}`);
        }

        await page.screenshot({
          path: path.join(shotDir, `${skin}-${name}-${width}-${scheme}.png`),
          fullPage: width === 375,
        });

        if (axeSource && width === 1280) {
          await page.addScriptTag({ url: '/__axe.js' });
          const result = await page.evaluate(async () =>
            await window.axe.run(document, {
              runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] },
            }),
          );
          const list = result.violations.map(
            (v) => `${v.id} (${v.impact}, ${v.nodes.length}): ${v.nodes[0].target.join(' ')} — ${v.help}`,
          );
          console.log(
            `axe ${skin}/${scheme} ${name.padEnd(14)} ${list.length === 0 ? '0 violations' : list.length + ' VIOLATIONS'}`,
          );
          for (const line of list) {
            console.log('    ' + line);
            violations.push(`${skin}/${scheme} ${name}: ${line}`);
          }
        }
      }
    }
    await context.close();
  }
}

await browser.close();

console.log(`\nscreens: ${screens.length} x ${widths.length} widths x ${schemes.length} schemes x ${skins.length} skins`);
if (unstyled.length) {
  console.log(`UNSTYLED (${unstyled.length}):`);
  unstyled.forEach((u) => console.log('  ' + u));
}
if (overflow.length) {
  console.log(`HORIZONTAL OVERFLOW (${overflow.length}):`);
  overflow.forEach((o) => console.log('  ' + o));
}
console.log(`axe violations total: ${violations.length}`);
if (overflow.length || unstyled.length || violations.length) process.exitCode = 1;
else console.log('no overflow, styles applied, axe clean');
