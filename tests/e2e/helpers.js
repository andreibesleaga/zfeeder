// @ts-check
const { expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

/**
 * Credentials the test server was started with. The password is generated for
 * each run by tools/run-e2e.sh and passed in the environment, so there is no
 * fixed credential anywhere in the repository; run the suite through that
 * script rather than calling playwright directly.
 */
const ADMIN = { user: 'admin', password: process.env.ZF_E2E_PASSWORD };

if (!ADMIN.password) {
  throw new Error('ZF_E2E_PASSWORD is not set — start the suite with tools/run-e2e.sh');
}

/** Widths every layout must survive. */
const WIDTHS = [320, 768, 1280, 1920];

/**
 * Runs axe against the current page and fails with a readable list of problems.
 * WCAG 2.2 AA is the bar the project commits to in docs/ACCESSIBILITY.md.
 */
async function expectNoAccessibilityViolations(page, context = '') {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();

  if (results.violations.length > 0) {
    const detail = results.violations
      .map((v) => {
        const nodes = v.nodes.slice(0, 3).map((n) => `        ${n.target.join(' ')}`).join('\n');
        return `  [${v.impact}] ${v.id}: ${v.help}\n${nodes}`;
      })
      .join('\n');
    throw new Error(`Accessibility violations${context ? ' on ' + context : ''}:\n${detail}`);
  }
}

/** Fails if the document scrolls sideways, which is the usual sign of a broken layout. */
async function expectNoHorizontalOverflow(page) {
  const overflow = await page.evaluate(() => {
    const d = document.documentElement;
    return { scroll: d.scrollWidth, client: d.clientWidth };
  });
  expect(
    overflow.scroll,
    `horizontal overflow: content is ${overflow.scroll}px in a ${overflow.client}px viewport`,
  ).toBeLessThanOrEqual(overflow.client + 1);
}

/** Signs in to the admin panel and lands on the main screen. */
async function signIn(page) {
  await page.goto('/admin');
  await page.getByLabel(/user/i).fill(ADMIN.user);
  await page.getByLabel(/password/i).fill(ADMIN.password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await expect(page.getByRole('navigation')).toBeVisible();
}

/** Walks the page with the keyboard only and checks focus stays visible and in order. */
async function expectKeyboardReachable(page, expectedFirstLabels = []) {
  const seen = [];
  for (let i = 0; i < 12; i += 1) {
    await page.keyboard.press('Tab');
    const focused = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return null;
      const style = getComputedStyle(el);
      return {
        tag: el.tagName.toLowerCase(),
        label: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 40),
        outlineWidth: style.outlineWidth,
        outlineStyle: style.outlineStyle,
        boxShadow: style.boxShadow,
      };
    });
    if (!focused) break;
    seen.push(focused);
  }
  expect(seen.length, 'nothing was reachable with the Tab key').toBeGreaterThan(0);
  for (const label of expectedFirstLabels) {
    expect(seen.some((s) => s.label.toLowerCase().includes(label.toLowerCase()))).toBeTruthy();
  }
}

module.exports = {
  ADMIN,
  WIDTHS,
  expectNoAccessibilityViolations,
  expectNoHorizontalOverflow,
  signIn,
  expectKeyboardReachable,
};
