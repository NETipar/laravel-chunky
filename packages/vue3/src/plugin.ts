import type { App, Plugin } from 'vue';
import { type ChunkyConfig, UploadManager, configure } from '@netipar/chunky-core';
import { ChunkyManagerKey } from './manager';

/**
 * `app.use(createChunky({ baseUrl, headers, ... }))` provides an app-scoped
 * UploadManager and sets the global client defaults.
 */
export function createChunky(config: ChunkyConfig = {}): Plugin {
    return {
        install(app: App): void {
            configure(config);
            app.provide(ChunkyManagerKey, new UploadManager(config));
        },
    };
}
