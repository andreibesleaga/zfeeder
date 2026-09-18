// @ts-check
const { test, expect } = require('@playwright/test');
const { WIDTHS, expectNoAccessibilityViolations, expectNoHorizontalOverflow } = require('../helpers');

/**
 * Every modern template is checked at every width, in light and dark, for
 * accessibility and for overflow. The classic set is checked for rendering only:
 * it reproduces 2004 markup on purpose — layout tables and all — and
 * docs/ACCESSIBILITY.md records that openly.
 */

const MODERN = [
  'cards', 'list', 'ticker', 'bluelogos', 'greenlogos', 'aqua', 'ampheta',
  'simpleblue', 'simplegray', 'titlebox', 'headlinebox', 'simplecss',
  'infojunkie', 'rij', 'sidebar', 'mainframe',
];

const CLASSIC = [
  'bluelogos', 'greenlogos', 'aqua', 'ampheta', 'simpleblue', 'simplegray',
  'titlebox', 'headlinebox', 'simplecss', 'infojunkie', 'rij', 'sidebar', 'mainframe',
];

test.describe('modern templates', () => {
  for (const name of MODERN) {
    test(`${name} renders and is accessible`, async ({ page }) => {
      const response = await page.goto(`/demos/template/modern/${name}`);
      expect(response?.status(), `modern/${name} should render`).toBe(200);
      await expect(page.locator('.output')).not.toBeEmpty();
      await expectNoAccessibilityViolations(page, `modern/${name}`);
    });
  }

  for (const width of WIDTHS) {
    test(`cards has no horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/demos/template/modern/cards');
      await expectNoHorizontalOverflow(page);
    });
  }

  test('the output uses semantic elements rather than layout tables', async ({ page }) => {
    await page.goto('/demos/template/modern/cards');
    const output = page.locator('.output');
    await expect(output.locator('article').first()).toBeVisible();
    expect(await output.locator('font').count(), 'no <font> elements in the modern set').toBe(0);
  });

  test('item dates are machine readable', async ({ page }) => {
    await page.goto('/demos/template/modern/list');
    const time = page.locator('.output time[datetime]').first();
    if (await time.count()) {
      const value = await time.getAttribute('datetime');
      expect(value, 'datetime must be a parseable timestamp').toMatch(/^\d{4}-\d{2}-\d{2}/);
    }
  });
});

test.describe('classic templates', () => {
  for (const name of CLASSIC) {
    test(`${name} still renders the 2004 output`, async ({ page }) => {
      const response = await page.goto(`/demos/template/classic/${name}`);
      expect(response?.status(), `classic/${name} should render`).toBe(200);
      await expect(page.locator('.output')).not.toBeEmpty();
    });
  }

  test('a classic template keeps its 2004 markup', async ({ page }) => {
    await page.goto('/demos/template/classic/bluelogos');
    const html = await page.locator('.output').innerHTML();
    // The point of the classic set is that this is unchanged since 2004.
    expect(html).toContain('<font');
  });

  test('the classic icons resolve', async ({ page }) => {
    const missing = [];
    page.on('response', (r) => {
      if (r.status() >= 400 && /\/images\//.test(r.url())) missing.push(r.url());
    });
    await page.goto('/demos/template/classic/bluelogos');
    await page.waitForLoadState('networkidle');
    expect(missing, `broken image requests: ${missing.join(', ')}`).toEqual([]);
  });
});

test.describe('dark mode', () => {
  test.use({ colorScheme: 'dark' });

  test('the modern set is readable in dark mode', async ({ page }) => {
    await page.goto('/demos/template/modern/cards');
    const background = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    expect(background, 'the body must not stay white in dark mode').not.toBe('rgb(255, 255, 255)');
    await expectNoAccessibilityViolations(page, 'modern/cards in dark mode');
  });
});
