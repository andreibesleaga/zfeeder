/**
 * tools/check-preview.mjs — look at what tools/preview-templates.php produced.
 *
 *   php tools/preview-templates.php
 *   NODE_PATH=/home/andrei/work/AI/kaiban-distributed/node_modules \
 *     node tools/check-preview.mjs
 *
 * Set ZF_PREVIEW_BASE to a URL (e.g. http://127.0.0.1:8899/build/preview) to load
 * the pages over HTTP instead of file://; external <use> references into the icon
 * sprite only resolve over HTTP, so that is the honest way to shoot the admin.
 *
 * For every preview page, at 320 / 768 / 1280 / 1920 and in both colour schemes:
 * assert the document does not scroll horizontally, capture a screenshot into
 * build/preview/shots/, and report the computed background so a theme that fails
 * to switch is visible in the log rather than only in a picture.
 */
import { readdir, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';

// ESM ignores NODE_PATH, so resolve playwright through a CJS require rooted at
// whichever node_modules the caller pointed us at.
const roots = (process.env.NODE_PATH || '').split(path.delimiter).filter(Boolean);
let chromium;
for (const base of [...roots, path.resolve('node_modules')]) {
  try {
    chromium = createRequire(path.join(base, 'noop.js'))('playwright').chromium;
    break;
  } catch { /* try the next root */ }
}
if (!chromium) {
  console.error('playwright not resolvable; set NODE_PATH to a node_modules containing it');
  process.exit(2);
}

const previewDir = path.resolve('build/preview');
const shotDir = path.join(previewDir, 'shots');
const widths = [320, 768, 1280, 1920];
const schemes = ['light', 'dark'];

await mkdir(shotDir, { recursive: true });
const pages = (await readdir(previewDir)).filter((f) => f.endsWith('.html') && f !== 'index.html').sort();

const browser = await chromium.launch();
const failures = [];
let checks = 0;

for (const scheme of schemes) {
  const context = await browser.newContext({ colorScheme: scheme, deviceScaleFactor: 1 });
  const page = await context.newPage();
  for (const file of pages) {
    const name = file.replace(/\.html$/, '');
    for (const width of widths) {
      await page.setViewportSize({ width, height: 900 });
      const base = process.env.ZF_PREVIEW_BASE;
      const url = base ? `${base.replace(/\/$/, '')}/${file}` : 'file://' + path.join(previewDir, file);
      await page.goto(url, { waitUntil: base ? 'networkidle' : 'load' });
      const probe = await page.evaluate(() => {
        const zf = document.querySelector('.zf');
        const cs = zf ? getComputedStyle(zf) : null;
        return {
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
          bodyScroll: document.body.scrollWidth,
          bg: cs ? cs.backgroundColor : 'none',
          fg: cs ? cs.color : 'none',
          zfCount: document.querySelectorAll('.zf').length,
          widest: (() => {
            let worst = { sel: '', w: 0 };
            for (const el of document.querySelectorAll('.zf *')) {
              const r = el.getBoundingClientRect();
              if (r.right > worst.w) worst = { sel: el.className || el.tagName, w: Math.round(r.right) };
            }
            return worst;
          })(),
        };
      });
      checks++;
      if (probe.scrollWidth > probe.innerWidth) {
        failures.push(
          `${name} @${width} ${scheme}: scrollWidth ${probe.scrollWidth} > innerWidth ${probe.innerWidth}` +
            ` (widest: ${probe.widest.sel} right=${probe.widest.w})`,
        );
      }
      await page.screenshot({
        path: path.join(shotDir, `${name}-${width}-${scheme}.png`),
        fullPage: width === 768 || width === 320,
      });
      if (width === 1280) {
        console.log(`${name.padEnd(13)} ${scheme.padEnd(5)} bg=${probe.bg} fg=${probe.fg} zf=${probe.zfCount}`);
      }
    }
  }
  await context.close();
}

await browser.close();

console.log(`\n${checks} viewport checks, ${pages.length} pages, ${widths.length} widths x ${schemes.length} schemes`);
if (failures.length) {
  console.log(`\nHORIZONTAL OVERFLOW (${failures.length}):`);
  for (const f of failures) console.log('  ' + f);
  process.exit(1);
}
console.log('no horizontal overflow anywhere');
