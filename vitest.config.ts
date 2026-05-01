import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['packages/*/src/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'lcov', 'html'],
            include: ['packages/*/src/**/*.ts'],
            exclude: ['packages/*/src/**/*.test.ts', 'packages/*/src/index.ts'],
        },
    },
});
