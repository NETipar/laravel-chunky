import type { StorageLike } from './config';

async function sha1Hex(buffer: ArrayBuffer): Promise<string> {
    const subtle = globalThis.crypto?.subtle;

    if (subtle) {
        const digest = await subtle.digest('SHA-1', buffer);

        return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    }

    // Deterministic non-crypto fallback (FNV-1a) for environments without WebCrypto.
    let hash = 0x811c9dc5;
    for (const byte of new Uint8Array(buffer)) {
        hash ^= byte;
        hash = Math.imul(hash, 0x01000193) >>> 0;
    }

    return hash.toString(16).padStart(8, '0');
}

/**
 * A stable per-file id: name + size + lastModified + a hash of the first 64 KB.
 * Enough to recognise "the same file" across page reloads for resume.
 */
export async function computeFingerprint(file: File): Promise<string> {
    const head = file.slice(0, Math.min(file.size, 65_536));
    const hash = await sha1Hex(await head.arrayBuffer());

    return `${file.name}:${file.size}:${file.lastModified}:${hash}`;
}

/**
 * Persists fingerprint -> uploadId so a reload can resume the same upload.
 */
export class FingerprintStore {
    constructor(private readonly storage: StorageLike | null) {}

    private key(fingerprint: string): string {
        return `chunky:fp:${fingerprint}`;
    }

    get(fingerprint: string): string | null {
        return this.storage?.getItem(this.key(fingerprint)) ?? null;
    }

    set(fingerprint: string, uploadId: string): void {
        this.storage?.setItem(this.key(fingerprint), uploadId);
    }

    remove(fingerprint: string): void {
        this.storage?.removeItem(this.key(fingerprint));
    }
}
