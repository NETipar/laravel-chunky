# Alpine.js Usage

The `@netipar/chunky-alpine` package provides an Alpine.js data component for chunk uploads. Use it standalone or with Livewire.

## Installation

### Standalone (without Livewire)

```bash
npm install @netipar/chunky-alpine
```

Register the plugin before starting Alpine:

```js
import { registerChunkUpload } from '@netipar/chunky-alpine';
import Alpine from 'alpinejs';

registerChunkUpload(Alpine);
Alpine.start();
```

### With Livewire

No installation needed. The Livewire component (`<livewire:chunky-upload />`) uses Alpine.js internally and handles registration automatically. See the [Livewire example](livewire.md).

## Basic Upload

```html
<div x-data="chunkUpload()">
    <input type="file" x-on:change="handleFileInput($event)" :disabled="isUploading" />

    <template x-if="isUploading">
        <div>
            <progress :value="progress" max="100"></progress>
            <span x-text="Math.round(progress) + '%'"></span>
        </div>
    </template>

    <template x-if="isComplete">
        <p class="text-green-600">Upload complete!</p>
    </template>

    <template x-if="error">
        <p class="text-red-600" x-text="error"></p>
    </template>
</div>
```

## With All Controls

```html
<div x-data="chunkUpload({ maxConcurrent: 3 })">
    <template x-if="!isUploading && !isComplete">
        <label class="block cursor-pointer border-2 border-dashed rounded-lg p-8 text-center">
            <input type="file" class="hidden" x-on:change="handleFileInput($event)" />
            <p>Click to select a file</p>
        </label>
    </template>

    <template x-if="isUploading || isPaused">
        <div class="space-y-2">
            <div class="flex justify-between text-sm">
                <span x-text="currentFile?.name"></span>
            </div>

            <div class="h-3 bg-gray-200 rounded-full overflow-hidden">
                <div class="h-full bg-indigo-500 rounded-full transition-all"
                     :style="'width: ' + progress + '%'"></div>
            </div>

            <div class="flex justify-between text-sm text-gray-500">
                <span x-text="uploadedChunks + ' / ' + totalChunks + ' chunks'"></span>
                <span x-text="Math.round(progress) + '%'"></span>
            </div>

            <div class="flex gap-2">
                <button x-on:click="isPaused ? resume() : pause()"
                        x-text="isPaused ? 'Resume' : 'Pause'"
                        class="rounded bg-yellow-500 px-4 py-2 text-white"></button>
                <button x-on:click="cancel()"
                        class="rounded bg-red-500 px-4 py-2 text-white">Cancel</button>
            </div>
        </div>
    </template>

    <template x-if="isComplete">
        <div class="rounded bg-green-100 p-4 text-green-800">Upload complete!</div>
    </template>

    <template x-if="error">
        <div class="rounded bg-red-100 p-4 text-red-800">
            <p x-text="error"></p>
            <button x-on:click="retry()" class="mt-2 rounded bg-red-500 px-4 py-2 text-white">Retry</button>
        </div>
    </template>
</div>
```

## With Context

```html
<div x-data="chunkUpload({ context: 'profile_avatar' })">
    <input type="file" accept="image/*" x-on:change="handleFileInput($event)" />

    <template x-if="isUploading">
        <progress :value="progress" max="100"></progress>
    </template>

    <template x-if="isComplete">
        <p>Avatar uploaded!</p>
    </template>
</div>
```

## Listening for Events

The Alpine component dispatches custom DOM events:

```html
<div
    x-data="chunkUpload()"
    x-on:chunky:complete.window="console.log('Upload done:', $event.detail.uploadId)"
    x-on:chunky:error.window="console.error('Upload failed:', $event.detail.message)"
>
    <input type="file" x-on:change="handleFileInput($event)" />
</div>
```

## Available Options

Pass options to `chunkUpload()`:

```html
<div x-data="chunkUpload({
    chunkSize: 2097152,
    maxConcurrent: 5,
    autoRetry: true,
    maxRetries: 3,
    context: 'documents',
    headers: { 'X-Custom': 'value' },
    withCredentials: true,
})">
```

## Available State & Methods

| Property | Type | Description |
|----------|------|-------------|
| `progress` | `number` | 0-100 percentage |
| `isUploading` | `boolean` | Upload in progress |
| `isPaused` | `boolean` | Upload paused |
| `isComplete` | `boolean` | Upload finished |
| `error` | `string \| null` | Error message |
| `uploadId` | `string \| null` | Server-assigned upload ID |
| `uploadedChunks` | `number` | Chunks uploaded so far |
| `totalChunks` | `number` | Total chunks |
| `currentFile` | `File \| null` | Current file |

| Method | Description |
|--------|-------------|
| `upload(file, metadata?)` | Start upload |
| `handleFileInput(event)` | Handle `<input>` change event |
| `pause()` | Pause upload |
| `resume()` | Resume upload |
| `cancel()` | Cancel upload |
| `retry()` | Retry failed upload |
