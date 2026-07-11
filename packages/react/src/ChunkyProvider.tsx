import { type ReactNode, useState } from 'react';
import { type ChunkyConfig, UploadManager, configure } from '@netipar/chunky-core';
import { ChunkyContext } from './context';

export interface ChunkyProviderProps {
    config?: ChunkyConfig;
    manager?: UploadManager;
    children: ReactNode;
}

export function ChunkyProvider({ config = {}, manager, children }: ChunkyProviderProps): ReactNode {
    // Created once; the manager owns uploads for the app's lifetime.
    const [instance] = useState(() => {
        configure(config);

        return manager ?? new UploadManager(config);
    });

    return <ChunkyContext.Provider value={instance}>{children}</ChunkyContext.Provider>;
}
