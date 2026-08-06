import { describe, expect, it } from 'vitest';
import { Sha256, sha256File } from './sha256';

function hashOf(input: string): string {
    return new Sha256().update(new TextEncoder().encode(input)).digestHex();
}

describe('Sha256', () => {
    it('matches the FIPS 180-4 test vectors', () => {
        expect(hashOf('')).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
        expect(hashOf('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
        expect(hashOf('abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq'))
            .toBe('248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1');
    });

    it('hashes a one-million-a message correctly', () => {
        const hasher = new Sha256();
        const chunk = new Uint8Array(10_000).fill(0x61);
        for (let i = 0; i < 100; i++) {
            hasher.update(chunk);
        }

        expect(hasher.digestHex()).toBe('cdc76e5c9914fb9281a1c7e284d73e67f1809a48a497200e046d39ccc7112cd0');
    });

    it('is invariant to update slicing across block boundaries', () => {
        const bytes = new TextEncoder().encode('x'.repeat(150));
        const whole = new Sha256().update(bytes).digestHex();

        const sliced = new Sha256();
        sliced.update(bytes.subarray(0, 1));
        sliced.update(bytes.subarray(1, 63));
        sliced.update(bytes.subarray(63, 65));
        sliced.update(bytes.subarray(65, 128));
        sliced.update(bytes.subarray(128));

        expect(sliced.digestHex()).toBe(whole);
    });

    it('agrees with WebCrypto on a small input', async () => {
        const bytes = new TextEncoder().encode('chunky-webcrypto-parity');
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        const expected = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');

        expect(new Sha256().update(bytes).digestHex()).toBe(expected);
    });

    it('rejects updates after digest', () => {
        const hasher = new Sha256().update(new Uint8Array([1]));
        hasher.digestHex();

        expect(() => hasher.update(new Uint8Array([2]))).toThrow('after digest');
    });
});

describe('sha256File', () => {
    it('hashes a file in slices to the same digest', async () => {
        const content = 'A'.repeat(1000);
        const file = new File([content], 'file.bin');

        expect(await sha256File(file, 64)).toBe(hashOf(content));
    });

    it('hashes an empty file', async () => {
        expect(await sha256File(new File([], 'empty.bin')))
            .toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
    });
});
