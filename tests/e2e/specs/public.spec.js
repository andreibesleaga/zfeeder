// @ts-check
const { test, expect } = require('@playwright/test');
const {
  WIDTHS,
  expectNoAccessibilityViolations,
  expectNoHorizontalOverflow,
} = require('../helpers');

test.describe('the demonstration site', () => {
  test('the front page explains what zFeeder is and links to the demos', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('zFeeder');
    await expect(page.getByRole('link', { name: /one line in any page/i })).toBeVisible();
    await expect(page.getByRole('link', { name: /administration panel/i })).toBeVisible();
    await expectNoAccessibilityViolations(page, 'the front page');
  });

  test('the front page has a skip link that moves focus to the content', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    const skip = page.getByRole('link', { name: /skip to content/i });
    await expect(skip).toBeFocused();
    await skip.press('Enter');
    await expect(page.locator('#main')).toBeVisible();
  });

  for (const slug of ['one-line', 'css', 'multiple', 'positions', 'categories', 'aggregator']) {
    test(`the ${slug} demonstration renders feed content in the modern set`, async ({ page }) => {
      const response = await page.goto(`/demos/${slug}?set=modern`);
      expect(response?.status(), `/demos/${slug} should be 200`).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      // Fixtures are seeded into the cache, so real headlines must appear.
      await expect(page.locator('main')).toContainText(/Tech Wire|Science Desk|DevChannel|zFeeder|Modern Atom/);
      await expectNoAccessibilityViolations(page, `/demos/${slug} (modern)`);
    });

    test(`the ${slug} demonstration also renders in the classic set`, async ({ page }) => {
      // The classic set reproduces 2004 markup byte for byte - layout tables,
      // <font> elements and the original colours - so it is checked for
      // rendering only. Its accessibility limitations are deliberate and are
      // recorded in docs/ACCESSIBILITY.md; `modern` is the accessible set.
      const response = await page.goto(`/demos/${slug}?set=classic`);
      expect(response?.status(), `/demos/${slug} should be 200`).toBe(200);
      await expect(page.locator('main')).toContainText(/Tech Wire|Science Desk|DevChannel|zFeeder|Modern Atom/);
    });
  }

  test('an unknown demonstration is a 404, not a crash', async ({ page }) => {
    const response = await page.goto('/demos/does-not-exist');
    expect(response?.status()).toBe(404);
    await expect(page.locator('body')).toContainText(/not found/i);
  });

  test('a path traversal attempt is refused', async ({ request }) => {
    for (const path of ['/demos/template/classic/..%2F..%2Fconfig', '/api/opml/..%2F..%2Fetc%2Fpasswd']) {
      const response = await request.get(path, { maxRedirects: 0 });
      expect(response.status(), `${path} must not succeed`).toBeGreaterThanOrEqual(400);
      expect(await response.text()).not.toContain('root:');
    }
  });
});

test.describe('the embedding endpoints', () => {
  test('/embed returns an HTML fragment, not a whole document', async ({ request }) => {
    const response = await request.get('/embed');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/html');
    const body = await response.text();
    expect(body).not.toContain('<!doctype html');
    expect(body.length).toBeGreaterThan(50);
  });

  test('/api/feeds returns the parsed data', async ({ request }) => {
    const response = await request.get('/api/feeds');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body).toHaveProperty('category');
    expect(Array.isArray(body.channels)).toBeTruthy();
    if (body.channels.length > 0) {
      expect(body.channels[0]).toHaveProperty('title');
      expect(Array.isArray(body.channels[0].items)).toBeTruthy();
    }
  });

  test('/embed carries no cross-origin header for an origin that is not allowed', async ({ request }) => {
    const response = await request.get('/embed', { headers: { Origin: 'https://evil.example' } });
    expect(response.headers()['access-control-allow-origin']).toBeUndefined();
  });

  test('/healthz reports the version and the storage backend', async ({ request }) => {
    const response = await request.get('/healthz');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.status).toBe('ok');
    expect(body.version).toMatch(/^2\./);
    expect(['flat', 'sqlite']).toContain(body.storage);
  });

  test('/refresh without the key is refused', async ({ request }) => {
    const response = await request.get('/refresh');
    expect(response.status()).toBe(403);
  });
});

test.describe('security headers', () => {
  test('the public pages carry the hardening headers', async ({ request }) => {
    const response = await request.get('/');
    const headers = response.headers();
    expect(headers['content-security-policy']).toContain("default-src 'self'");
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['referrer-policy']).toBeTruthy();
    expect(headers['permissions-policy']).toBeTruthy();
  });
});

test.describe('layout holds at every width', () => {
  for (const width of WIDTHS) {
    test(`the front page does not scroll sideways at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await expectNoHorizontalOverflow(page);
    });
  }
});
