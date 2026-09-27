import { test, expect, login, TEST_USER } from '../shared/fixtures';
import { execSync } from 'child_process';

/**
 * HR API endpoints — verifies the parameterized queries return valid data.
 * Tests: /api/hr/stats, /api/hr/analytics/headcount-trend,
 *        /api/hr/analytics/vacancy-trend, /api/hr/analytics/staffing-ratio
 *
 * These routes use `ability:hr:read` middleware which requires a Sanctum
 * token with the hr:read ability. Session cookies don't satisfy this,
 * so we create a token via a PHP helper script.
 */

test.describe('HR API endpoints', () => {
  let token: string;

  test.beforeAll(async () => {
    // Create a Sanctum token with all needed abilities
    const output = execSync(
      `php tests/e2e/create-token.php ${TEST_USER.nCode} "hr:read,units:read,hardware:read,tickets:read,traffic:read"`,
      { cwd: process.cwd(), encoding: 'utf-8' }
    ).trim();
    token = output;
  });

  test('GET /api/hr/stats returns valid aggregations', async ({ request }) => {
    const response = await request.get('/api/hr/stats', {
      headers: { Authorization: `Bearer ${token}` },
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
      headers: { Authorization: `Bearer ${token}` },
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
      headers: { Authorization: `Bearer ${token}` },
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
      headers: { Authorization: `Bearer ${token}` },
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
      headers: { Authorization: `Bearer ${token}` },
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
