import { build } from 'esbuild';

const shared = {
    entryPoints: ['src/index.ts'],
    bundle: true,
    platform: 'browser',
    sourcemap: 'linked',
    external: ['@netipar/chunky-core'],
};

await build({
    ...shared,
    format: 'esm',
    outfile: 'dist/index.js',
});

await build({
    ...shared,
    format: 'cjs',
    outfile: 'dist/index.cjs',
});
