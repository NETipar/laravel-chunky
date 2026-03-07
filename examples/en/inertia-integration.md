# Usage with Inertia.js

The `useChunkUpload` composable works independently from Inertia.js -- it uses raw `fetch()` for the chunk API calls. After the upload completes, trigger an Inertia visit to process the file server-side.

## Basic Integration

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';
import { router } from '@inertiajs/vue3';

const { upload, onComplete, progress, isUploading, error } = useChunkUpload();

onComplete((result) => {
    // All chunks uploaded & assembled on the server
    // Now tell your Laravel app about it via Inertia
    router.post('/documents', {
        upload_id: result.uploadId,
        file_name: result.fileName,
        file_size: result.fileSize,
    });
});

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) {
        upload(input.files[0]);
    }
}
</script>

<template>
    <div>
        <input type="file" @change="onFileChange" :disabled="isUploading" />

        <div v-if="isUploading">
            <progress :value="progress" max="100" />
            <span>{{ progress }}%</span>
        </div>

        <p v-if="error" class="text-red-500">{{ error }}</p>
    </div>
</template>
```

## With Inertia useForm

Combine chunk upload with other form fields:

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';
import { useForm } from '@inertiajs/vue3';

const chunky = useChunkUpload();

const form = useForm({
    title: '',
    description: '',
    upload_id: null as string | null,
    file_name: null as string | null,
});

chunky.onComplete((result) => {
    form.upload_id = result.uploadId;
    form.file_name = result.fileName;
});

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) {
        chunky.upload(input.files[0]);
    }
}

function submit() {
    form.post('/documents');
}
</script>

<template>
    <form @submit.prevent="submit">
        <div>
            <label>Title</label>
            <input v-model="form.title" type="text" />
        </div>

        <div>
            <label>Description</label>
            <textarea v-model="form.description" />
        </div>

        <div>
            <label>File</label>
            <input type="file" @change="onFileChange" :disabled="chunky.isUploading.value" />

            <div v-if="chunky.isUploading.value">
                <progress :value="chunky.progress.value" max="100" />
                <span>{{ chunky.progress.value }}%</span>
            </div>

            <span v-if="chunky.isComplete.value" class="text-green-600">
                File ready: {{ form.file_name }}
            </span>
        </div>

        <button
            type="submit"
            :disabled="form.processing || !form.upload_id"
        >
            Save Document
        </button>
    </form>
</template>
```

## With Context-based Save Callback

Instead of handling file processing in your Inertia controller, use Chunky's context save callback:

```php
// AppServiceProvider::boot()
use NETipar\Chunky\Facades\Chunky;

Chunky::context(
    'documents',
    rules: fn () => [
        'file_size' => ['max:104857600'],
        'mime_type' => ['in:application/pdf'],
    ],
    save: function ($metadata) {
        Document::create([
            'path' => $metadata->finalPath,
            'disk' => $metadata->disk,
            'file_name' => $metadata->fileName,
            'file_size' => $metadata->fileSize,
        ]);
    },
);
```

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';

// The save callback runs automatically after assembly
const { upload } = useChunkUpload({ context: 'documents' });
</script>
```

## Laravel Controller (Inertia)

```php
namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Facades\Chunky;
use NETipar\Chunky\Enums\UploadStatus;

class StoreDocumentController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'upload_id' => ['required', 'string'],
            'file_name' => ['required', 'string'],
        ]);

        // Get upload status from Chunky (returns UploadMetadata DTO)
        $status = Chunky::status($validated['upload_id']);

        if (! $status || $status->status !== UploadStatus::Completed) {
            return back()->withErrors(['upload_id' => 'Upload not completed.']);
        }

        // Move file to permanent storage
        $permanentPath = "documents/{$validated['upload_id']}/{$validated['file_name']}";
        Storage::disk($status->disk)->move($status->finalPath, $permanentPath);

        Document::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'path' => $permanentPath,
            'disk' => $status->disk,
            'file_name' => $validated['file_name'],
            'file_size' => $status->fileSize,
        ]);

        return redirect()->route('documents.index');
    }
}
```

## Why Not Use Inertia's Built-in Upload?

Inertia's `useForm().post()` sends the entire file in a single request. This works fine for small files but fails for large files:

| Feature | Inertia Upload | Chunky |
|---------|---------------|--------|
| Max file size | Server upload limit | Unlimited (chunked) |
| Resume on failure | No | Yes |
| Pause / Resume | No | Yes |
| Network interruption | Full re-upload | Resume from last chunk |
| Progress granularity | Byte-level (single req) | Chunk + byte level |
| Parallel uploads | No | Configurable concurrency |
| Checksum integrity | No | SHA-256 per chunk |
