import { defineConfig } from 'vitest/config';

// Deliberately separate from `vite.config.js`: Vitest reads this file INSTEAD
// of the app's Vite config, so the Laravel plugin and Tailwind are not loaded
// for unit tests. Tests live in `tests/js/` (outside `resources/js/`) so they
// stay out of the `tsc` ratchet and the `js-typecheck` include (#1453).
export default defineConfig({
  test: {
    include: ['tests/js/**/*.test.js'],
    environment: 'node',
  },
});
