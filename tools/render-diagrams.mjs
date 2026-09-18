// Renders every .mmd in a directory to a matching .svg, using a headless
// browser because that is what mermaid needs to measure text.
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, basename } from 'node:path';
import { pathToFileURL } from 'node:url';

// ESM ignores NODE_PATH, so playwright is imported by the absolute path the
// shell wrapper resolved for us rather than by bare name.
const [dir, mermaidPath, playwrightPath] = process.argv.slice(2);
const { chromium } = await import(pathToFileURL(playwrightPath).href);
const mermaidSource = readFileSync(mermaidPath, 'utf8');
const files = readdirSync(dir).filter((f) => f.endsWith('.mmd')).sort();

if (files.length === 0) {
  console.error(`no .mmd files in ${dir}`);
  process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage();
await page.setContent('<!doctype html><html><body><div id="out"></div></body></html>');
await page.addScriptTag({ content: mermaidSource });
await page.evaluate(() => {
  window.mermaid.initialize({
    startOnLoad: false,
    theme: 'base',
    fontFamily: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    themeVariables: {
      primaryColor: '#e8f2f8',
      primaryTextColor: '#16202a',
      primaryBorderColor: '#006699',
      lineColor: '#55636f',
      fontSize: '15px',
    },
    flowchart: { htmlLabels: true, curve: 'basis', useMaxWidth: true },
    sequence: { useMaxWidth: true },
  });
});

let failed = 0;
for (const file of files) {
  const source = readFileSync(join(dir, file), 'utf8');
  const id = 'd' + basename(file, '.mmd').replace(/[^a-z0-9]/gi, '');
  try {
    const svg = await page.evaluate(
      async ([graph, renderId]) => (await window.mermaid.render(renderId, graph)).svg,
      [source, id],
    );
    writeFileSync(join(dir, basename(file, '.mmd') + '.svg'), svg);
    console.log(`  ${file} -> ${basename(file, '.mmd')}.svg  (${(svg.length / 1024).toFixed(1)} kB)`);
  } catch (error) {
    console.error(`  ${file}: ${String(error.message).split('\n')[0]}`);
    failed += 1;
  }
}

await browser.close();
if (failed > 0) {
  console.error(`${failed} diagram(s) failed to render`);
  process.exit(1);
}
console.log(`rendered ${files.length} diagrams`);
