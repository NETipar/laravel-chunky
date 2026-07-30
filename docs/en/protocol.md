# Laravel Chunky — Wire Protocol v1.0

> 🇭🇺 Magyar változat: [docs/hu/protocol.md](../hu/protocol.md)

> **Normative document.** This is the complete description of the v1.0 HTTP
> protocol. The backend protocol tests (T3.8) and the frontend transport code
> (T4.2) assert against this document. Source:
> [v1-implementation-plan.md](../v1-implementation-plan.md) section 3. The
> OpenAPI variant: [openapi.yaml](../openapi.yaml) — the two must match field
> by field.

## General rules

- Every request and response body is **JSON**, `Content-Type: application/json`,
  except the chunk upload (`multipart/form-data`).
- Every field name is **snake_case**.
- The default route prefix is `api/chunky` (`config chunky.routes.prefix`), the
  default middleware is `['api']`. The prefix is configurable on the server;
  the client receives the base URL, the endpoint-relative paths are fixed.
- Versioning: the protocol is **additive** within a major series — new optional
  fields and new endpoints may be added; the meaning/type of an existing field
  never changes. Clients must ignore unknown response fields.
- The server ignores unknown client-sent fields (no `422`), except where
  validation is strict (see the individual endpoints).

## Error envelope

Every non-2xx response body is **uniform**:

```json
{ "error": { "code": "upload_expired", "message": "The upload has expired." } }
```

- `error.code` — a stable, machine-readable identifier (see Error codes). The
  frontend's retry decisions are based on THIS, never on the `message` text.
- `error.message` — human-readable, localized (Laravel `__()`), safe to show
  in a UI. Not stable, not parseable.
- On `422 validation_failed` the usual Laravel `errors` map may be present
  alongside the envelope (per-field messages), but the machine path uses the
  `code`.

### Error codes

| `code` | HTTP | When |
|---|---|---|
| `validation_failed` | 422 | The request fields fail validation (missing/wrongly typed field, profile `rules()`). This includes an **oversized `file_size`** (`limits.max_file_size` / profile `maxFileSize()`) and **too many `metadata` keys** (`limits.metadata_max_keys`). |
| `profile_not_found` | 422 | The `initiate` `profile` field references an unregistered profile. |
| `unauthorized` | 403 | The profile's `authorize()` or the `Authorizer` rejected the **initiate** request, or the caller does not own the upload on the **chunk** endpoint. (Status/cancel answer non-owners with `404` — see below.) |
| `upload_not_found` | 404 | No such `upload_id`, **or** the caller does not own the upload on the status/cancel endpoint. A non-owner is deliberately indistinguishable from a non-existent upload so that which IDs exist cannot leak (anti-enumeration). |
| `batch_not_found` | 404 | No such `batch_id`, or the caller does not own the batch on the status/cancel endpoint (same anti-enumeration principle). |
| `upload_expired` | 410 | The upload has expired (`expires_at` in the past) and is not terminal — no further chunks are accepted. |
| `invalid_state` | 409 | The upload/batch is not in a state where the operation makes sense (e.g. a chunk during `assembling`/`completed`; cancel during `assembling`). |
| `chunk_index_out_of_range` | 422 | `chunk_index` < 0 or ≥ `total_chunks`. |
| `checksum_mismatch` | 422 | The supplied chunk `checksum` does not match the computed SHA-256 (integrity middleware). |
| `lock_timeout` | 503 | Too much contention on the upload's lock; the request is safe to retry. |
| `assembly_failed` | 500 | Assembly failed in **sync** mode; the upload transitioned to `failed`. |

---

## 1. `POST {prefix}/upload` — initiate an upload

Starts the lifecycle of a new chunked upload. On a fingerprint match it
returns an existing, resumable upload instead of creating a new record.

### Request (application/json)

| Field | Type | Req. | Description |
|---|---|---|---|
| `file_name` | string | ✔ | Original file name (with extension). The stored name is produced by the profile's `fileName()`. |
| `file_size` | integer | ✔ | Total file size in bytes. > 0. |
| `mime_type` | string \| null | — | Client-announced MIME type. |
| `profile` | string | — | Name of a registered profile (`config chunky.profiles`). When absent, the default/`simple` behavior applies. |
| `metadata` | object | — | Arbitrary JSON key–value data; the profile's `rules()` may validate it and the `completed()` hook receives it. At most `limits.metadata_max_keys` keys (`422 validation_failed` beyond that). |
| `fingerprint` | string \| null | — | Client-side file fingerprint (see Fingerprint resume). When `config chunky.resume.fingerprint` is enabled, resume detection is based on this. |
| `batch_id` | string \| null | — | Only meaningful through the batch-member endpoint; on a direct initiate it is normally `null`. |

### Response `201 Created` — new upload

```json
{
  "upload_id": "9b2c...uuid",
  "chunk_size": 5242880,
  "total_chunks": 20,
  "resumed": false,
  "uploaded_chunks": []
}
```

| Field | Type | Description |
|---|---|---|
| `upload_id` | string | The upload's identifier (UUID); every subsequent request uses it. |
| `chunk_size` | integer | The chunk size the server expects, in bytes. The client slices the file into pieces of EXACTLY this size. |
| `total_chunks` | integer | The number of expected chunks. |
| `resumed` | boolean | `false` for a new upload. |
| `uploaded_chunks` | integer[] | Chunk indexes already received (empty for a new upload). |

### Response `200 OK` — resumed upload (fingerprint match)

When `resume.fingerprint` is active and the server finds a live,
**non-terminal** upload with the same `fingerprint` **from the same user**, it
does NOT create a new record but returns the existing one:

```json
{
  "upload_id": "existing-uuid",
  "chunk_size": 5242880,
  "total_chunks": 20,
  "resumed": true,
  "uploaded_chunks": [0, 1, 2, 3, 4]
}
```

The client continues from the indexes NOT present in `uploaded_chunks`.

### Errors

`422 validation_failed` (including an oversized `file_size` and too many
`metadata` keys) · `422 profile_not_found` · `403 unauthorized`.

---

## 2. `POST {prefix}/upload/{uploadId}/chunks` — upload a chunk

Uploads a single chunk. The `VerifyChunkIntegrity` middleware runs first.

### Request (multipart/form-data)

| Part | Type | Req. | Description |
|---|---|---|---|
| `chunk` | file (binary) | ✔ | The chunk's bytes. |
| `chunk_index` | integer | ✔ | 0-based index. `0 ≤ index < total_chunks`. |
| `checksum` | string (hex) | — | The chunk's SHA-256 hex digest. When present, the middleware verifies it. |
| `file_checksum` | string (hex) | — | The **whole file's** SHA-256 hex digest (64 chars, always SHA-256 regardless of `integrity.algorithm`). Accepted on any chunk request — typically sent with the final one; the server persists the first non-empty value and ignores repeats. After assembly the merged bytes are verified against it. With `integrity.require_full_file = true` the completing chunk fails `422 validation_failed` unless a value was supplied. |

Header:

| Header | Req. | Description |
|---|---|---|
| `Idempotency-Key` | — | When provided, the response is cached for `idempotency.ttl` seconds and replayed **byte-exactly** on a network retry. When absent, the server derives a fallback idempotency key from the `checksum`. |

### Response `200 OK` — intermediate chunk

```json
{
  "status": "uploading",
  "chunk_index": 5,
  "uploaded_count": 6,
  "total_chunks": 20,
  "progress": 30.0
}
```

| Field | Type | Description |
|---|---|---|
| `status` | string | Always `"uploading"` for an intermediate chunk. |
| `chunk_index` | integer | The index just processed (acknowledgement). |
| `uploaded_count` | integer | Number of chunks received so far. |
| `total_chunks` | integer | Total expected chunks. |
| `progress` | number | Percentage, `0.0`–`100.0`, two-decimal precision. |

### Response `200 OK` — final chunk, **sync** assembly

When `assembly.mode = sync`, or `assembly.mode = auto` and the file size is
below `sync_threshold`. Assembly runs within the request and the result is
returned immediately:

```json
{
  "status": "completed",
  "progress": 100.0,
  "file": {
    "path": "avatars/42/9b2c.jpg",
    "size": 104857600,
    "url": "https://cdn.example.com/avatars/42/9b2c.jpg"
  },
  "payload": { "media_id": 123 }
}
```

| Field | Type | Description |
|---|---|---|
| `status` | string | `"completed"`. |
| `progress` | number | `100.0`. |
| `file` | object | The final file. `path` is disk-relative; `size` in bytes; `url` only when the disk supports a public URL (otherwise absent/`null`). The `disk` and the absolute path NEVER leak. |
| `payload` | object \| null | The return value of the profile's `completed()` hook (e.g. the id of a created domain object). `null` when the hook returns nothing. |

### Response `200 OK` — final chunk, **queue** assembly

When `assembly.mode = queue`, or `auto` and the file size is above the
threshold. Assembly is dispatched to a background job:

```json
{ "status": "assembling", "progress": 100.0 }
```

The client polls `GET {prefix}/upload/{id}` for the result (or receives it via
broadcast) until `status = completed`/`failed`.

### Idempotent replay

When the idempotency key (or the checksum fallback) hits, the server returns
the previously cached response unchanged — including the `completed` final
response above, `payload` included. No side effect runs again (events,
assemble dispatch).

### Errors

`422 validation_failed` (including a malformed `file_checksum`, and a missing
one on completion when `integrity.require_full_file` is enabled) ·
`422 chunk_index_out_of_range` · `422 checksum_mismatch` (a chunk `checksum`
rejected by the middleware, or — on the sync final chunk — the assembled file
not matching the reported `file_checksum`; the upload transitions to `failed`,
chunks are retained until cleanup for diagnosis) ·
`403 unauthorized` (the caller does not own the upload — the chunk endpoint
answers 403, not 404) · `404 upload_not_found` · `410 upload_expired` ·
`409 invalid_state` (the upload is `cancelled`/`completed`/`failed`/`assembling`) ·
`503 lock_timeout` · `500 assembly_failed` (sync mode only, when assembly
fails — the upload transitions to `failed`, chunks are retained until cleanup).

In **queue** assembly a `file_checksum` mismatch cannot surface on the chunk
response — the upload transitions to `failed` and the status endpoint (or the
broadcast) reports it.

---

## 3. `GET {prefix}/upload/{uploadId}` — status query

### Response `200 OK`

```json
{
  "upload_id": "9b2c...uuid",
  "status": "completed",
  "progress": 100.0,
  "uploaded_chunks": [0, 1, 2],
  "total_chunks": 20,
  "file_name": "video.mp4",
  "file_size": 104857600,
  "file": { "path": "avatars/42/9b2c.jpg", "size": 104857600, "url": "https://..." },
  "payload": { "media_id": 123 }
}
```

| Field | Type | Description |
|---|---|---|
| `upload_id` | string | |
| `status` | string | `pending` \| `uploading` \| `assembling` \| `completed` \| `failed` \| `cancelled`. |
| `progress` | number | `0.0`–`100.0`. |
| `uploaded_chunks` | integer[] | Received indexes. |
| `total_chunks` | integer | |
| `file_name` | string | Original file name. |
| `file_size` | integer | |
| `file` | object \| absent | Only when `completed`. Same schema as the final chunk's `file`. |
| `payload` | object \| null \| absent | Only when `completed`; the value persisted at the completed transition (the status endpoint does NOT re-run the profile hook). |

The response is the **public** projection: `disk`, `final_path` (absolute
path), and `user_id` are never included.

### Errors

`404 upload_not_found` — also `404` for a non-owner (anti-enumeration: does
not reveal that the ID exists, no `403`).

---

## 4. `DELETE {prefix}/upload/{uploadId}` — cancel an upload

### Response `200 OK`

```json
{ "status": "cancelled" }
```

Idempotent: if the upload is already `cancelled`, again
`200 { "status": "cancelled" }`.

### Errors

`404 upload_not_found` (also `404` for a non-owner, anti-enumeration) ·
`409 invalid_state` — cancel is only allowed from `pending`/`uploading`;
during `assembling`, and in the `completed`/`failed` terminal states, `409`.

---

## 5. `POST {prefix}/batch` — initiate a batch

### Request (application/json)

| Field | Type | Req. | Description |
|---|---|---|---|
| `total_files` | integer | ✔ | The number of files in the batch. > 0. |
| `profile` | string | — | Profile applied to every member upload. |
| `metadata` | object | — | Batch-level metadata. |

### Response `201 Created`

```json
{ "batch_id": "b1a2...uuid", "total_files": 3 }
```

### Errors

`422 validation_failed` · `422 profile_not_found` · `403 unauthorized`.

---

## 6. `POST {prefix}/batch/{batchId}/upload` — initiate a batch member

Like [initiate (1)](#1-post-prefixupload--initiate-an-upload), but the upload
is created bound to the given batch (`batch_id` set server-side). The request
and response schema match initiate; the response also carries `batch_id`.

### Response `201 Created`

```json
{
  "upload_id": "uuid",
  "batch_id": "b1a2...uuid",
  "chunk_size": 5242880,
  "total_chunks": 20,
  "resumed": false,
  "uploaded_chunks": []
}
```

### Errors

`404 batch_not_found` · `422 validation_failed` (including an oversized
`file_size`) · `403 unauthorized` · `409 invalid_state` (the batch is already
terminal).

---

## 7. `GET {prefix}/batch/{batchId}` — batch status

### Response `200 OK`

```json
{
  "batch_id": "b1a2...uuid",
  "status": "in_progress",
  "total_files": 3,
  "completed_count": 1,
  "failed_count": 0,
  "uploads": [
    { "upload_id": "u1", "status": "completed", "progress": 100.0 },
    { "upload_id": "u2", "status": "uploading", "progress": 40.0 },
    { "upload_id": "u3", "status": "pending", "progress": 0.0 }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `batch_id` | string | |
| `status` | string | `pending` \| `in_progress` \| `completed` \| `partially_completed` \| `cancelled`. |
| `total_files` | integer | |
| `completed_count` | integer | |
| `failed_count` | integer | |
| `uploads` | object[] | Per member: `{upload_id, status, progress}`. |

### Errors

`404 batch_not_found` — also `404` for a non-owner (anti-enumeration).

---

## 8. `DELETE {prefix}/batch/{batchId}` — cancel a batch

Cancels the batch **and every non-terminal member upload** (driver-agnostic —
through the `findByBatch` port, not by sniffing a concrete adapter).

### Response `200 OK`

```json
{ "status": "cancelled" }
```

### Errors

`404 batch_not_found` (also `404` for a non-owner, anti-enumeration) ·
`409 invalid_state` (the batch is already `completed`/`partially_completed`).

---

## 9. Direct-to-S3 transport (`direct_s3`)

Opt-in per profile (`UploadProfile::transport(): 'direct_s3'`). Chunks travel
straight to S3 as multipart parts via presigned URLs — Laravel only
orchestrates (initiate, URL issuing, complete, abort). The server transport
flow above is completely untouched; everything here is additive.

### Initiate response extension

For a direct profile the initiate response (fresh and resumed) carries an
extra `transport` object:

```json
{
  "upload_id": "9b2c...uuid",
  "chunk_size": 8388608,
  "total_chunks": 120,
  "resumed": false,
  "uploaded_chunks": [],
  "transport": {
    "mode": "direct_s3",
    "part_urls": { "0": "https://s3...&X-Amz-Signature=...", "1": "..." },
    "expires_at": "2026-07-30T12:00:00Z"
  }
}
```

| Field | Type | Description |
|---|---|---|
| `mode` | string | `"direct_s3"`. A client that does not recognize the mode must not fall back to the chunk endpoint (it answers `409`). |
| `part_urls` | object | Presigned PUT URLs keyed by 0-based chunk index — the first batch (max 100). Request more via the part-urls endpoint. |
| `expires_at` | string | When the issued URLs expire (`transports.direct_s3.url_ttl`). |
| `uploaded_parts` | object | **Resume only:** index → ETag of parts already on S3 (from ListParts), so the client can still send the full part list to complete. |

The client PUTs each part to its URL and MUST capture the `ETag` response
header (bucket CORS must include `ExposeHeaders: ETag`).

Constraints, enforced at initiate with `422 validation_failed`:
`chunk_size ≥ 5 MB` (S3 part minimum) and `total_chunks ≤ 10000`.

### `POST {prefix}/upload/{uploadId}/part-urls` — fresh presigned URLs

For expired URLs, retries, and resume top-ups.

Request: `{ "indexes": [100, 101, ...] }` — 1–100 indexes per request.

Response `200 OK`: `{ "part_urls": { "100": "https://..." }, "expires_at": "..." }`

Errors: `422 validation_failed` · `422 chunk_index_out_of_range` ·
`403 unauthorized` · `404 upload_not_found` · `410 upload_expired` ·
`409 invalid_state` (non-direct upload or terminal state).

### `POST {prefix}/upload/{uploadId}/complete` — finish the upload

Request — every part with its captured ETag:

```json
{ "parts": [ { "index": 0, "etag": "\"abc\"" }, { "index": 1, "etag": "\"def\"" } ] }
```

The server runs `CompleteMultipartUpload`, then the remote assembly pipeline:
state transition (`pending/uploading → assembling`), integrity (remote object
size vs `file_size`; S3 itself validates each part's ETag during complete —
this is the end-to-end integrity mechanism of the direct path, `file_checksum`
does not apply), the profile's `completed()` hook, terminal state + events.
The response equals the sync final-chunk response:

```json
{ "status": "completed", "progress": 100.0, "file": { "path": "...", "size": 1 }, "payload": null }
```

Idempotent: completing an already-`completed` upload replays the result.

Errors: `422 validation_failed` (missing/extra parts) ·
`422 chunk_index_out_of_range` · `403 unauthorized` · `404 upload_not_found` ·
`410 upload_expired` · `409 invalid_state` · `500 assembly_failed` (S3
complete failed or size mismatch — the upload transitions to `failed`).

### Direct-specific behavior elsewhere

- `POST .../chunks` on a direct upload → `409 invalid_state`.
- `DELETE .../{uploadId}` (cancel) also calls `AbortMultipartUpload`.
- `GET .../{uploadId}` (status) returns the tracker's last known state and
  does **not** call ListParts; the remote part list refreshes only at
  resume-initiate. The uploading client is locally accurate.
- `chunky:cleanup` aborts the remote multipart upload of expired direct
  uploads. Configure an S3 `AbortIncompleteMultipartUpload` lifecycle rule as
  a safety net.

---

## Fingerprint resume

Goal: after a reload or re-selection, the upload continues without re-sending
already uploaded chunks.

- The client sends a `fingerprint` in the `initiate` request: a stable
  identifier derived from `name + size + lastModified + the SHA-1 of the first
  chunk`.
- Server side (`resume.fingerprint = true`): initiate looks up the live,
  non-terminal upload with the same fingerprint **from the same user**
  (`UploadRepository::findByFingerprint($fp, $userId)`). On a hit: `200`,
  `resumed: true`, the existing `upload_id` and `uploaded_chunks`.
- The client keeps a `fingerprint → upload_id` map in `localStorage`; on a
  terminal response or a `404`/`410` it removes the entry.
- With `resume.fingerprint` disabled, every initiate creates a new upload
  (`resumed` is always `false`).

---

## Broadcasting (optional)

Broadcast is an **opt-in enhancement** — the full protocol works without a
queue worker and without Echo (sync mode + polling). Enable with
`broadcasting.enabled = true`.

- **Events** (11): `UploadInitiated`, `ChunkUploaded`, `ChunkUploadFailed`,
  `FileAssembled`, `UploadCompleted`, `UploadFailed`, `UploadCancelled`,
  `BatchInitiated`, `BatchCompleted`, `BatchPartiallyCompleted`,
  `BatchCancelled`.
- An event broadcasts when `broadcasting.enabled` is on **and** the event's
  class name is not on the `broadcasting.except` list. The default is
  `enabled = false`, so an old published config cannot silently turn
  everything off (no `mergeConfigFrom` landmine).
- The **default `except` list is the high-frequency events**: `ChunkUploaded`,
  `ChunkUploadFailed`, `BatchInitiated`. Turning broadcasting on therefore
  only emits the useful, rare completion events; per-chunk noise stays out
  until the developer deliberately removes them from `except`.
- **Payload — versioned**:

  ```json
  { "v": 1, "upload_id": "uuid", "status": "completed", "progress": 100.0, "...": "..." }
  ```

  The payload is `UploadRecord::toPublicArray()` (or the batch equivalent)
  plus the `"v": 1` schema version. The public projection NEVER contains
  `disk`, `final_path`, `user_id`, or an absolute path.
- **Channels**: private channels scoped to the upload/batch owner
  (`routes/channels.php`). The frontend `CompletionWatcher` reconciles
  broadcast and polling (broadcast when available, polling as the guarantee).
