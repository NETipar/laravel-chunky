import { build } from 'esbuild';

const shared = {
    bundle: true,
    platform: 'browser',
    sourcemap: 'linked',
};

// Main entrypoint.
await build({
    ...shared,
    entryPoints: ['src/index.ts'],
    format: 'esm',
    outfile: 'dist/index.js',
});

await build({
    ...shared,
    entryPoints: ['src/index.ts'],
    format: 'cjs',
    outfile: 'dist/index.cjs',
});

// Tools subpath for advanced consumers (RetryPolicy, EventEmitter).
// Marked @internal in code; pinning the package version is recommended
// when importing from this surface.
await build({
    ...shared,
    entryPoints: ['src/tools.ts'],
    format: 'esm',
    outfile: 'dist/tools.js',
});

await build({
    ...shared,
    entryPoints: ['src/tools.ts'],
    format: 'cjs',
    outfile: 'dist/tools.cjs',
});
