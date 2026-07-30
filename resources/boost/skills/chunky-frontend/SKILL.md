---
name: chunky-frontend
description: >
  Wire the Chunky upload clients into a frontend: @netipar/chunky-core for any
  stack, plus the Vue 3, React, Alpine.js wrappers and the Livewire component.
  Covers configuration, upload state, pause/resume/cancel, batches,
  navigation-surviving uploads, and error handling.
license: MIT
metadata:
  author: NETipar
---

# Chunky — Frontend Clients

Use this skill when connecting a browser frontend to a Laravel backend running
`netipar/laravel-chunky`. Backend setup (profiles, config, events) is the
`chunky-development` skill.

## Primary Goal

Start uploads through the module-level manager with the right profile, render
from subscribed state snapshots, and let the engine own the upload lifecycle —
resume, retries, and completion polling are built in.

## Core Model (all frameworks)

`@netipar/chunky-core` is the engine; the wrappers are thin adapters over it.

- `configure({...})` sets global defaults (base URL, headers).
- `manager` (a module-level `UploadManager`) owns every upload. **Uploads
  survive SPA navigation**: unmounting a component only unsubscribes — it
  never cancels the upload.
- `manager.upload(file, { profile })` returns an `Uploader`;
  `uploader.subscribe(cb)` streams immutable state snapshots
  (`status`, `progress`, `uploadedChunks`, `totalChunks`, `bytesPerSecond`,
  `etaSeconds`, `file`, `result`, `error`).
- `await uploader.upload()` resolves with the final result in **every**
  assembly mode — when the server queues assembly, the client polls
  automatically.
- Reloading the page and re-selecting the same file resumes the previous
  upload via its fingerprint instead of re-sending bytes.

## Workflow

### 1. Install and configure once

```bash
npm install @netipar/chunky-core        # or @netipar/chunky-vue3 / -react / -alpine
```

```ts
import { configure } from '@netipar/chunky-core';

configure({
    baseUrl: '/api/chunky',
    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')!.getAttribute('content')! },
});
```

Vue 3: `app.use(createChunky({...}))`. React: wrap in
`<ChunkyProvider config={{...}}>`. Alpine:
`Alpine.plugin((A) => registerChunky(A, {...}))`. Each accepts the same config
and replaces the `configure()` call.

### 2. Start uploads with a profile

```ts
import { manager } from '@netipar/chunky-core';

const uploader = manager.upload(file, { profile: 'avatar' });
uploader.subscribe((state) => render(state.status, state.progress, state.etaSeconds));

const result = await uploader.upload();
// result.file?.url, result.payload — payload comes from the profile's completed() hook
```

Controls: `uploader.pause()`, `uploader.resume()`, `await uploader.cancel()`.
Warn on real page unload while uploads run: `manager.installUnloadGuard()`
(SPA navigation is unaffected).

End-to-end verification: `manager.upload(file, { profile: 'avatar',
fileChecksum: true })` hashes the whole file (SHA-256, embedded incremental
hasher — no dependency) in parallel with the upload and sends it as
`file_checksum` on the final chunk; the server verifies the assembled result
against it (mismatch → `checksum_mismatch` error, upload `failed`). Off by
default.

### 3. Framework wrappers

Vue 3 (`@netipar/chunky-vue3`):

```vue
<script setup lang="ts">
import { useUpload } from '@netipar/chunky-vue3';

const { start, state } = useUpload();
const onPick = (e: Event) => start((e.target as HTMLInputElement).files![0], { profile: 'avatar' });
</script>

<template>
  <input type="file" @change="onPick" />
  <progress v-if="state" :value="state.progress" max="100" />
</template>
```

`useUploads()` lists all active uploads (global tray); `useBatch()` for
batches; `UploadTray` and `ChunkDropzone` are headless, styleable components.

React (`@netipar/chunky-react`): same hook names — `useUpload()`,
`useUploads()`, `useBatch()` — inside `<ChunkyProvider>`.

Alpine (`@netipar/chunky-alpine`):

```html
<div x-data="chunkyUpload({ profile: 'avatar' })">
    <input type="file" @change="onFileChange($event)" />
    <template x-if="state"><progress :value="state.progress" max="100"></progress></template>
</div>
```

Livewire: `composer require livewire/livewire`, register the Alpine plugin as
above, then `<livewire:chunky-upload profile="avatar" />`.

### 4. Batches

```ts
const batch = manager.batch([file1, file2, file3], { profile: 'gallery', concurrency: 3 });
batch.subscribe((s) => render(`${s.completed}/${s.total}`));
const result = await batch.upload(); // status: 'completed' | 'partially_completed' | 'cancelled'
```

Failed members do not block the rest; the batch ends `partially_completed`.

### 5. Handle errors by code

Failures surface as a typed `ChunkyError` with a stable `.code`
(`validation_failed`, `unauthorized`, `upload_not_found`, `upload_expired`,
`invalid_state`, `checksum_mismatch`, `lock_timeout`, `assembly_failed`, …).
Branch on the code, never the message. Transient chunk failures are retried
automatically before an error is surfaced.

### 6. Real-time completion (optional)

Polling is built in; Echo is an enhancement, not a requirement. When the
backend enables broadcasting, subscribe with plain Laravel Echo — private
channels `chunky.upload.{uploadId}` / `chunky.batch.{batchId}`, event names
`.upload.completed`, `.batch.completed`, `.batch.partially_completed`
(payloads are versioned: `{"v":1,…}`).

## References

- `@netipar/chunky-core` exports: `manager`, `UploadManager`, `Uploader`,
  `Batch`, `CompletionWatcher`, `configure`, `computeFingerprint`,
  `ChunkyError`, and the `UploadState` / `UploadResult` / `UploadOptions` /
  `BatchOptions` types.
- Wrapper packages: `@netipar/chunky-vue3` (Vue 3.4+), `@netipar/chunky-react`
  (React 18+), `@netipar/chunky-alpine` (Alpine 3+). Each depends on core.
- Wire protocol and error table: `docs/en/protocol.md` in the Composer
  package (Hungarian: `docs/hu/protocol.md`).
- Usage recipes (tray, pause/resume, navigation survival): `examples/README.md`
  in the Composer package.

## Examples

- Global upload tray that survives navigation: mount `useUploads()` (or
  `manager` subscriptions) in a layout component; page components only call
  `manager.upload()` and never hold the uploader beyond starting it.
- Avatar form: `const result = await manager.upload(file, { profile: 'avatar' }).upload()`,
  then read `result.payload.media_id` created by the backend profile's
  `completed()` hook.

## Anti-patterns

- Do not cancel uploads on component unmount — unsubscribe only; the manager
  owns the upload lifecycle.
- Do not hand-roll status polling after the final chunk —
  `await uploader.upload()` already resolves across sync and queued assembly.
- Do not chunk files or call the HTTP endpoints manually from application
  code; `manager.upload()` handles chunking, concurrency, retries, and resume.
- Do not build UI state from ad-hoc flags; render from the subscribed
  snapshot (`state.status`, `state.progress`) so resume and re-attach work.
- Do not skip the CSRF header when using the `web` middleware group — pass it
  in `configure()` / `createChunky()` / `ChunkyProvider`.
