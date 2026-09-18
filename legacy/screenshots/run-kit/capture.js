const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8090';
const OUT = process.argv[2];
const SITE = process.argv[3];
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const shot = async (name, url, opts = {}) => {
    await page.goto(url, { waitUntil: 'load', timeout: 180000 });
    await page.waitForTimeout(600);
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: opts.full !== false });
    console.log('shot', name);
  };
  // usage / integration demos
  await shot('usage-01-demo-default-bluelogos', `${BASE}/demo.php`, { full: false });
  await shot('usage-01b-demo-default-bluelogos-fullpage', `${BASE}/demo.php`);
  await shot('usage-02-demo-css-template', `${BASE}/demo_css.php`, { full: false });
  await shot('usage-03-demo-multiple-columns', `${BASE}/demo_multiple.php`, { full: false });
  await shot('usage-04-demo-positions-layers', `${BASE}/demo_positions.php`, { full: false });
  await shot('usage-05-demo-categories-infojunkie', `${BASE}/demo_categories.php?zfcategory=news`);
  await shot('usage-06-demo-frames-aggregator', `${BASE}/demo_frames.php`, { full: false });
  await page.goto(`${BASE}/demo_frames.php`); await page.waitForTimeout(800);
  await page.frameLocator('iframe[name=sidebar]').locator('select').first().selectOption({ label: 'TECHNOLOGY' }).catch(async () => { await page.frameLocator('iframe[name=sidebar]').locator('select').first().selectOption('technology').catch(()=>{}); });
  await page.waitForTimeout(4000);
  await page.screenshot({ path: `${OUT}/usage-06b-demo-frames-aggregator-technology.png` }); console.log('shot usage-06b');
  await shot('usage-07-demo-rij-template', `${BASE}/demo_rij.php`, { full: false });
  for (const t of ['bluelogos','greenlogos','aqua','ampheta','simpleblue','simplegray','titlebox','headlinebox','simplecss','infojunkie']) {
    await shot(`template-${t}`, `${BASE}/demo_template.php?zftemplate=${t}&zfcategory=zfeeder`, { full: false });
  }
  await shot('usage-08-wap-wml-output-menu', `${BASE}/wap_source.php`);
  await shot('usage-09-wap-wml-output-category', `${BASE}/wap_source.php?q=zfcategory%3Dnews`);
  await shot('usage-10-wap-wml-output-feed', `${BASE}/wap_source.php?q=zfcategory%3Dnews%26zfposition%3Dp1`);
  await shot('docs-readme', `${BASE}/readme.html`, { full: false });
  // admin backend
  await shot('admin-01-login', `${BASE}/newsfeeds/admin.php`);
  await page.fill('input[name=admin_user]', 'admin');
  await page.fill('input[name=admin_pass]', 'demo2004');
  await page.click('input[name=submit_login]');
  await page.waitForLoadState('load');
  await page.screenshot({ path: `${OUT}/admin-02-main.png`, fullPage: true }); console.log('shot admin-02-main');
  await shot('admin-03-add-new-feed', `${BASE}/newsfeeds/admin.php?zfaction=addnew`);
  // autodiscovery from a site URL
  let ok = false;
  for (const site of ['https://lwn.net/', 'https://www.phoronix.com/', 'https://arstechnica.com/', 'https://www.theregister.com/']) {
    await page.goto(`${BASE}/newsfeeds/admin.php?zfaction=addnew`);
    await page.fill('input[name=siteurl]', site);
    await page.click('input[name=submitsiteurl]');
    await page.waitForLoadState('load', { timeout: 180000 });
    const html = await page.content();
    if (html.includes('add to subscription list')) { ok = true; console.log('autodiscovery ok for', site); break; }
    console.log('autodiscovery failed for', site);
  }
  await page.screenshot({ path: `${OUT}/admin-04-add-new-autodiscovery-result.png`, fullPage: true });
  // direct RSS URL
  await page.goto(`${BASE}/newsfeeds/admin.php?zfaction=addnew`);
  await page.fill('input[name=feedurl]', 'https://feeds.bbci.co.uk/news/science_and_environment/rss.xml');
  await page.click('input[name=submitfeedurl]');
  await page.waitForLoadState('load', { timeout: 180000 });
  await page.screenshot({ path: `${OUT}/admin-05-add-new-feed-preview-form.png`, fullPage: true }); console.log('shot admin-05');
  // subscribe it into the news category
  if ((await page.content()).includes('add to subscription list')) {
    await page.selectOption('select[name=zfcategory]', 'news').catch(() => {});
    await page.fill('input[name=showednews]', '3');
    await page.click('input[name=subscribe]');
    await page.waitForLoadState('load', { timeout: 180000 });
    await page.screenshot({ path: `${OUT}/admin-06-add-new-subscribed.png`, fullPage: true }); console.log('shot admin-06');
  }
  await shot('admin-07-subscriptions-default-category', `${BASE}/newsfeeds/admin.php?zfaction=subscriptions`);
  await page.selectOption('select[name=zfcategory]', 'news');
  await page.click('input[name=changecateg]');
  await page.waitForLoadState('load', { timeout: 180000 });
  await page.screenshot({ path: `${OUT}/admin-08-subscriptions-news-category.png`, fullPage: true }); console.log('shot admin-08');
  await shot('admin-09-config', `${BASE}/newsfeeds/admin.php?zfaction=config`);
  await shot('admin-10-import-feed-list', `${BASE}/newsfeeds/admin.php?zfaction=importlist`);
  await shot('admin-11-updates', `${BASE}/newsfeeds/admin.php?zfaction=updates`, { full: false });
  // original 2004 project website, from the Wayback copy
  await shot('site-2004-home', `file://${SITE}/index.html`);
  await shot('site-2004-screenshots-page', `file://${SITE}/shots.html`);
  await shot('site-2004-references', `file://${SITE}/references.html`);
  await shot('site-2004-support', `file://${SITE}/support.html`);
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
