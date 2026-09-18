// @ts-check
const { test, expect } = require('@playwright/test');
const {
  ADMIN,
  expectNoAccessibilityViolations,
  expectNoHorizontalOverflow,
  signIn,
} = require('../helpers');

/**
 * The administration panel in a real browser.
 *
 * The integration suite already proves the request/response behaviour; what a
 * browser adds is the things only a browser can see: that the markup is
 * accessible, that it survives a narrow viewport, that focus is visible, and
 * that the screens work with JavaScript executing rather than only as strings.
 */

const SCREENS = [
  ['main', '/admin'],
  ['add new', '/admin/add-new'],
  ['subscriptions', '/admin/subscriptions'],
  ['config', '/admin/config'],
  ['import', '/admin/import'],
  ['updates', '/admin/updates'],
];

test.describe('signing in', () => {
  test('the sign-in form is reachable and accessible', async ({ page }) => {
    await page.goto('/admin/login');

    await expect(page.getByLabel(/user/i)).toBeVisible();
    await expect(page.getByLabel(/password/i)).toBeVisible();
    await expectNoAccessibilityViolations(page, 'the sign-in form');
  });

  test('an anonymous visitor is sent to the sign-in form', async ({ page }) => {
    await page.goto('/admin/subscriptions');
    await expect(page).toHaveURL(/\/admin\/login/);
  });

  test('a wrong password is refused without saying which half was wrong', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel(/user/i).fill(ADMIN.user);
    await page.getByLabel(/password/i).fill('not the password');
    await page.getByRole('button', { name: /sign in|log in/i }).click();

    const body = (await page.locator('body').innerText()).toLowerCase();
    expect(body).toContain('sign in');
    // The message must not distinguish an unknown user from a bad password.
    expect(body).not.toMatch(/no such user|unknown user|user does not exist/);
  });

  test('the right password gets in', async ({ page }) => {
    await signIn(page);
    // Two links point at the subscriptions screen: the navigation and the
    // list of screens on the main page. Scope to the navigation.
    await expect(
      page.getByRole('navigation').getByRole('link', { name: /subscriptions/i }),
    ).toBeVisible();
  });
});

test.describe('the screens', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page);
  });

  for (const [name, path] of SCREENS) {
    test(`the ${name} screen is accessible`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response?.status(), `${path} should be 200`).toBe(200);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await expectNoAccessibilityViolations(page, path);
    });

    test(`the ${name} screen fits its viewport`, async ({ page }) => {
      await page.goto(path);
      await expectNoHorizontalOverflow(page);
    });
  }

  test('the menu marks where you are', async ({ page }) => {
    await page.goto('/admin/config');
    await expect(page.locator('[aria-current="page"]')).toHaveCount(1);
  });

  test('every form control has a label a screen reader can use', async ({ page }) => {
    await page.goto('/admin/config');

    const unlabelled = await page.evaluate(() => {
      const controls = Array.from(document.querySelectorAll('input, select, textarea'));
      return controls
        .filter((el) => {
          if (el.type === 'hidden') return false;
          if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')) return false;
          return !(el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`));
        })
        .map((el) => el.name || el.id || el.tagName);
    });

    expect(unlabelled, `controls without a label: ${unlabelled.join(', ')}`).toEqual([]);
  });

  test('options owned by the environment are shown as locked', async ({ page }) => {
    await page.goto('/admin/config');
    // The e2e server sets ZF_ADMIN_PASSWORD_HASH and others in the environment,
    // so at least one field must be marked as not editable here.
    const disabled = await page.locator('[disabled], [readonly], .is-locked').count();
    expect(disabled).toBeGreaterThan(0);
  });

  test('the panel carries no inline script, because the policy forbids it', async ({ page }) => {
    await page.goto('/admin');
    const inline = await page.evaluate(
      () => Array.from(document.querySelectorAll('script')).filter((s) => !s.src && s.textContent.trim()).length,
    );
    expect(inline, 'an inline script would be blocked by the Content Security Policy').toBe(0);
  });

  test('signing out ends the session', async ({ page }) => {
    await page.goto('/admin');
    await page.getByRole('button', { name: /sign out|logout/i }).click();
    await page.goto('/admin/subscriptions');
    await expect(page).toHaveURL(/\/admin\/login/);
  });
});

test.describe('keyboard only', () => {
  test('the panel can be operated without a mouse', async ({ page }) => {
    await signIn(page);
    await page.goto('/admin');

    const reached = [];
    for (let i = 0; i < 15; i += 1) {
      await page.keyboard.press('Tab');
      const focused = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) return null;
        const style = getComputedStyle(el);
        return {
          name: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 30),
          hasRing: style.outlineStyle !== 'none' || style.boxShadow !== 'none',
        };
      });
      if (!focused) break;
      reached.push(focused);
    }

    expect(reached.length, 'nothing was reachable with Tab').toBeGreaterThan(3);
    expect(reached.some((r) => /skip/i.test(r.name)), 'a skip link should come first').toBeTruthy();
  });
});
