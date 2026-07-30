/**
 * Minimal incremental SHA-256 (FIPS 180-4), dependency-free. WebCrypto's
 * subtle.digest is not incremental — a multi-GB file cannot be buffered — so
 * the Uploader hashes the file in slices through this class instead.
 */

// Int32Array wraps the unsigned FIPS constants to int32 on assignment.
// prettier-ignore
const K = new Int32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

export class Sha256 {
    // prettier-ignore
    private readonly state = new Int32Array([
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
        0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
    ]);

    private readonly block = new Uint8Array(64);

    private readonly w = new Int32Array(64);

    private blockLength = 0;

    private bytesHashed = 0;

    private finished = false;

    update(data: Uint8Array): this {
        if (this.finished) {
            throw new Error('Sha256 cannot be updated after digest().');
        }

        this.bytesHashed += data.length;
        let pos = 0;

        if (this.blockLength > 0) {
            while (this.blockLength < 64 && pos < data.length) {
                this.block[this.blockLength++] = data[pos++];
            }

            if (this.blockLength === 64) {
                this.processBlock(this.block, 0);
                this.blockLength = 0;
            }
        }

        while (data.length - pos >= 64) {
            this.processBlock(data, pos);
            pos += 64;
        }

        while (pos < data.length) {
            this.block[this.blockLength++] = data[pos++];
        }

        return this;
    }

    digestHex(): string {
        if (!this.finished) {
            const bitLenHi = Math.floor(this.bytesHashed / 0x20000000);
            const bitLenLo = (this.bytesHashed % 0x20000000) * 8;

            this.block[this.blockLength++] = 0x80;

            if (this.blockLength > 56) {
                this.block.fill(0, this.blockLength, 64);
                this.processBlock(this.block, 0);
                this.blockLength = 0;
            }

            this.block.fill(0, this.blockLength, 56);
            new DataView(this.block.buffer).setUint32(56, bitLenHi);
            new DataView(this.block.buffer).setUint32(60, bitLenLo);
            this.processBlock(this.block, 0);
            this.finished = true;
        }

        let hex = '';
        for (let i = 0; i < 8; i++) {
            hex += (this.state[i] >>> 0).toString(16).padStart(8, '0');
        }

        return hex;
    }

    private processBlock(data: Uint8Array, offset: number): void {
        const { state, w } = this;

        for (let i = 0; i < 16; i++) {
            const o = offset + i * 4;
            w[i] = (data[o] << 24) | (data[o + 1] << 16) | (data[o + 2] << 8) | data[o + 3];
        }

        for (let i = 16; i < 64; i++) {
            const x = w[i - 15];
            const y = w[i - 2];
            const s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
            const s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
        }

        let a = state[0];
        let b = state[1];
        let c = state[2];
        let d = state[3];
        let e = state[4];
        let f = state[5];
        let g = state[6];
        let h = state[7];

        for (let i = 0; i < 64; i++) {
            const s1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
            const ch = (e & f) ^ (~e & g);
            const t1 = (h + s1 + ch + K[i] + w[i]) | 0;
            const s0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
            const maj = (a & b) ^ (a & c) ^ (b & c);
            const t2 = (s0 + maj) | 0;

            h = g;
            g = f;
            f = e;
            e = (d + t1) | 0;
            d = c;
            c = b;
            b = a;
            a = (t1 + t2) | 0;
        }

        state[0] = (state[0] + a) | 0;
        state[1] = (state[1] + b) | 0;
        state[2] = (state[2] + c) | 0;
        state[3] = (state[3] + d) | 0;
        state[4] = (state[4] + e) | 0;
        state[5] = (state[5] + f) | 0;
        state[6] = (state[6] + g) | 0;
        state[7] = (state[7] + h) | 0;
    }
}

const SLICE_SIZE = 64 * 1024 * 1024;

/**
 * Hashes a file sequentially in 64 MB slices on the main thread — the slice
 * reads are async, so the event loop stays responsive without a worker.
 */
export async function sha256File(file: Blob, sliceSize: number = SLICE_SIZE): Promise<string> {
    const hasher = new Sha256();

    for (let offset = 0; offset < file.size; offset += sliceSize) {
        const buffer = await file.slice(offset, Math.min(offset + sliceSize, file.size)).arrayBuffer();
        hasher.update(new Uint8Array(buffer));
    }

    return hasher.digestHex();
}
