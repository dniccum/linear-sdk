import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.ts'],
    setupFiles: ['resources/js/__tests__/setup.ts'],
    restoreMocks: true,
    unstubGlobals: true,
    coverage: {
      provider: 'v8',
      reporter: [['text', { skipFull: false }], 'html', 'lcov'],
      reportsDirectory: 'coverage',
      include: ['resources/js/**/*.ts'],
      exclude: [
        'resources/js/**/__tests__/**',
        // `types/index.ts` only declares interfaces, type aliases and `declare global`.
        // It compiles to an empty module (nothing to execute), and would otherwise show
        // up as a confusing 0/0 row in the report.
        'resources/js/types/**',
      ],
      thresholds: {
        lines: 100,
        branches: 100,
        functions: 100,
        statements: 100,
      },
    },
  },
});
