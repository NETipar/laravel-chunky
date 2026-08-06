import { describe, expect, it } from 'vitest';
import { memoryStorage } from './__tests__/support';
import { FingerprintStore, computeFingerprint } from './fingerprint';

describe('computeFingerprint', () => {
    it('is stable for identical files', async () => {
        const a = new File(['hello world'], 'a.bin', { lastModified: 1000 });
        const b = new File(['hello world'], 'a.bin', { lastModified: 1000 });

        expect(await computeFingerprint(a)).toBe(await computeFingerprint(b));
    });

    it('differs when the content differs', async () => {
        const a = new File(['hello world'], 'a.bin', { lastModified: 1000 });
        const b = new File(['HELLO WORLD'], 'a.bin', { lastModified: 1000 });

        expect(await computeFingerprint(a)).not.toBe(await computeFingerprint(b));
    });
});

describe('FingerprintStore', () => {
    it('round-trips fingerprint to upload id', () => {
        const store = new FingerprintStore(memoryStorage());

        store.set('fp-1', 'u-1');
        expect(store.get('fp-1')).toBe('u-1');

        store.remove('fp-1');
        expect(store.get('fp-1')).toBeNull();
    });

    it('is a no-op without storage', () => {
        const store = new FingerprintStore(null);

        store.set('fp-1', 'u-1');
        expect(store.get('fp-1')).toBeNull();
    });
});
