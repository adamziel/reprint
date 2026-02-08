import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['tests/import-*.test.js'],
        testTimeout: 60000,
        hookTimeout: 300000,
        pool: 'forks',
        fileParallelism: true,
        globalSetup: './lib/global-setup.js',
    },
});
