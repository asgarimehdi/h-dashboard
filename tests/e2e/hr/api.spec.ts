import { test, expect, login } from '../shared/fixtures';

/**
 * HR API endpoints — verifies the parameterized queries return valid data.
 * Tests: /api/hr/stats, /api/hr/analytics/headcount-trend,
 *        /api/hr/analytics/vacancy-trend, /api/hr/analytics/staffing-ratio
 */

test.describe('HR API endpoints', () => {
  let cookies: string;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await login(page);
    const context = await page.context();
    const cookieList = await context.cookies();
    cookies = cookieList.map((c) => `${c.name}=${c.value}`).join('; ');
    await page.close();
  });

  test('GET /api/hr/stats returns valid aggregations', async ({ request }) => {
    const response = await request.get('/api/hr/stats', {
      headers: { Cookie: cookies },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.data).toHaveProperty('total_personnel');
    expect(body.data).toHaveProperty('by_unit');
    expect(body.data).toHaveProperty('by_semat');
    expect(body.data).toHaveProperty('by_tahsil');
    expect(body.data).toHaveProperty('by_estekhdam');
    expect(body.data).toHaveProperty('by_radif');
    expect(typeof body.data.total_personnel).toBe('number');
  });

  test('GET /api/hr/analytics/headcount-trend returns monthly data', async ({ request }) => {
    const response = await request.get('/api/hr/analytics/headcount-trend', {
      headers: { Cookie: cookies },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(Array.isArray(body.data)).toBeTruthy();
    if (body.data.length > 0) {
      expect(body.data[0]).toHaveProperty('month');
      expect(body.data[0]).toHaveProperty('count');
    }
  });

  test('GET /api/hr/analytics/vacancy-trend returns monthly data', async ({ request }) => {
    const response = await request.get('/api/hr/analytics/vacancy-trend', {
      headers: { Cookie: cookies },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(Array.isArray(body.data)).toBeTruthy();
    if (body.data.length > 0) {
      expect(body.data[0]).toHaveProperty('month');
      expect(body.data[0]).toHaveProperty('count');
    }
  });

  test('GET /api/hr/analytics/staffing-ratio returns aggregations', async ({ request }) => {
    const response = await request.get('/api/hr/analytics/staffing-ratio', {
      headers: { Cookie: cookies },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.data).toHaveProperty('by_unit_type');
    expect(body.data).toHaveProperty('by_semat');
    expect(typeof body.data.by_unit_type).toBe('object');
    expect(typeof body.data.by_semat).toBe('object');
  });

  test('GET /api/hr/analytics/headcount-trend respects months param', async ({ request }) => {
    const response = await request.get('/api/hr/analytics/headcount-trend?months=6', {
      headers: { Cookie: cookies },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(Array.isArray(body.data)).toBeTruthy();
  });

  test('unauthenticated request returns 401', async ({ request }) => {
    const response = await request.get('/api/hr/stats');
    expect(response.status()).toBe(401);
  });
});
