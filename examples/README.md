# Examples cookbook

Copy-paste recipes for common scenarios. For the basic setup, start with the
[main README](../README.md); this file collects the deeper patterns.

- [Surviving SPA navigation](#surviving-spa-navigation)
- [Pause / resume / cancel](#pause--resume--cancel)
- [A global upload tray](#a-global-upload-tray)
- [Reacting to events (backend)](#reacting-to-events-backend)
- [Batch uploads](#batch-uploads)
- [Direct core usage (no manager)](#direct-core-usage-no-manager)
- [Custom authorization](#custom-authorization)

## Surviving SPA navigation

The whole point of the `UploadManager`: an upload started on one page keeps
running after you navigate away, because the manager owns it — the component
only binds to it.

```ts
import { manager } from '@netipar/chunky-core';

// Page A — start an upload, then navigate away. Nothing cancels it.
manager.upload(bigFile, { profile: 'video' });

// Page B (or a global layout) — reflect everything still in flight.
manager.subscribe((uploads) => renderTray(uploads));
console.log(manager.uploads(), manager.totalProgress());

// Warn only on a real page unload (browser close / refresh), not SPA nav:
manager.installUnloadGuard();
```

In a component the rule is: **unmount only unsubscribes.**

```ts
// vue
const { start, state } = useUpload();      // onScopeDispose unsubscribes; the upload lives on
// react
const { start, state } = useUpload();      // useSyncExternalStore unsubscribes on unmount
```

## Pause / resume / cancel

```ts
const uploader = manager.upload(file, { profile: 'video' });

pauseButton.onclick = () => uploader.pause();    // stops launching new chunks; in-flight finish
resumeButton.onclick = () => uploader.resume();
cancelButton.onclick = () => uploader.cancel();  // aborts + deletes server-side; state -> 'cancelled'

uploader.subscribe((s) => {
    // s.status: 'uploading' | 'paused' | 'assembling' | 'completed' | 'failed' | 'cancelled'
    progress.value = s.progress;
    eta.textContent = s.etaSeconds != null ? `${s.etaSeconds}s left` : '';
});
```

Resume across a page reload is automatic when `resume.fingerprint` is on: pick
the same file again and Chunky continues from the chunks the server already has.

## A global upload tray

Vue, using the headless `UploadTray`:

```vue
<script setup lang="ts">
import { UploadTray } from '@netipar/chunky-vue3';
</script>

<template>
  <UploadTray v-slot="{ state }">
    <div class="tray-row">
      <span>{{ state.file.name }}</span>
      <progress :value="state.progress" max="100" />
      <span>{{ state.status }}</span>
    </div>
  </UploadTray>
</template>
```

React, using `useUploads`:

```tsx
import { useUploads } from '@netipar/chunky-react';

function Tray() {
    const { uploads, totalProgress } = useUploads();
    return (
        <div>
            <div>Total: {totalProgress}%</div>
            {uploads.map((u) => <div key={u.id}>{u.getState().file.name} — {u.getState().progress}%</div>)}
        </div>
    );
}
```

## Reacting to events (backend)

```php
use Illuminate\Support\Facades\Event;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\BatchCompleted;

Event::listen(UploadCompleted::class, function (UploadCompleted $event) {
    logger()->info('upload done', ['id' => $event->upload->uploadId, 'path' => $event->upload->finalPath]);
});

Event::listen(BatchCompleted::class, function (BatchCompleted $event) {
    // notify the user their gallery finished
});
```

Prefer the profile's `completed()` hook for the primary side effect (it runs
before the upload is marked complete and its return value reaches the client);
use events for secondary reactions.

## Batch uploads

```ts
const batch = manager.batch(files, { profile: 'gallery', concurrency: 3 });

batch.subscribe((s) => header.textContent = `${s.completed}/${s.total} (${s.failed} failed)`);

const result = await batch.upload();
// result.status: 'completed' | 'partially_completed' | 'cancelled'
// result.results: per-file UploadResult[]
```

## Direct core usage (no manager)

When you want to own the lifecycle yourself (e.g. a one-off upload you fully
control), skip the manager:

```ts
import { Uploader, configure } from '@netipar/chunky-core';

configure({ baseUrl: '/api/chunky' });

const uploader = new Uploader(file, { profile: 'avatar' }, /* config */ undefined as never);
// ...or resolveConfig() from the config module for full control.
const result = await uploader.upload();
```

For most apps, `manager.upload()` is the better default — it tracks the upload
and keeps it alive across navigation.

## Custom authorization

```php
use Illuminate\Contracts\Auth\Authenticatable;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\UploadRecord;

class TeamAuthorizer implements Authorizer
{
    public function owns(?Authenticatable $user, UploadRecord $upload): bool
    {
        return $user !== null && $this->sameTeam($user, $upload->userId);
    }

    public function ownsBatch(?Authenticatable $user, BatchRecord $batch): bool
    {
        return $user !== null && $this->sameTeam($user, $batch->userId);
    }

    private function sameTeam(Authenticatable $user, ?string $ownerId): bool { /* ... */ }
}

// config/chunky.php
'authorization' => ['authorizer' => \App\Chunky\TeamAuthorizer::class],
```
