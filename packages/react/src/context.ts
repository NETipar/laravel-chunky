import { createContext, useContext } from 'react';
import { UploadManager, manager as globalManager } from '@netipar/chunky-core';

export const ChunkyContext = createContext<UploadManager>(globalManager);

export function useManager(): UploadManager {
    return useContext(ChunkyContext);
}
