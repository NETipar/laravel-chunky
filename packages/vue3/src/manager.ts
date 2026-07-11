import { inject, type InjectionKey } from 'vue';
import { UploadManager, manager as globalManager } from '@netipar/chunky-core';

export const ChunkyManagerKey: InjectionKey<UploadManager> = Symbol('chunky-manager');

/**
 * Resolves the app-provided UploadManager, falling back to the module-level
 * singleton. The manager owns uploads, so they outlive any component.
 */
export function useManager(): UploadManager {
    return inject(ChunkyManagerKey, globalManager);
}
