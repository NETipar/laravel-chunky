# Használat Inertia.js-szel

A `useChunkUpload` composable függetlenül működik az Inertia.js-től -- nyers `fetch()` hívásokat használ a chunk API-hoz. A feltöltés befejezése után Inertia visit-tel dolgozhatjuk fel a fájlt szerver oldalon.

## Alap integráció

```vue
<script setup lang="ts">
import { useChunkUpload } from '@netipar/chunky-vue3';
import { router } from '@inertiajs/vue3';

const { upload, onComplete, progress, isUploading, error } = useChunkUpload();

onComplete((result) => {
    // Minden chunk feltöltve és összefűzve a szerveren
    // Most szóljunk a Laravel appnak Inertia-n keresztül
    router.post('/dokumentumok', {
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

## Inertia useForm-mal együtt

Chunk feltöltés más űrlapmezőkkel együtt:

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
    form.post('/dokumentumok');
}
</script>

<template>
    <form @submit.prevent="submit">
        <div>
            <label>Cím</label>
            <input v-model="form.title" type="text" />
        </div>

        <div>
            <label>Leírás</label>
            <textarea v-model="form.description" />
        </div>

        <div>
            <label>Fájl</label>
            <input type="file" @change="onFileChange" :disabled="chunky.isUploading.value" />

            <div v-if="chunky.isUploading.value">
                <progress :value="chunky.progress.value" max="100" />
                <span>{{ chunky.progress.value }}%</span>
            </div>

            <span v-if="chunky.isComplete.value" class="text-green-600">
                Fájl készen áll: {{ form.file_name }}
            </span>
        </div>

        <button type="submit" :disabled="form.processing || !form.upload_id">
            Dokumentum mentése
        </button>
    </form>
</template>
```

## Kontextus Save Callback-kel

Automatikus feldolgozás a feltöltés után a Chunky kontextus save callback-jével:

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

// A save callback automatikusan lefut az összefűzés után
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

        // Feltöltés státusz lekérdezése (UploadMetadata DTO-t ad vissza)
        $status = Chunky::status($validated['upload_id']);

        if (! $status || $status->status !== UploadStatus::Completed) {
            return back()->withErrors(['upload_id' => 'A feltöltés még nem fejeződött be.']);
        }

        $permanentPath = "dokumentumok/{$validated['upload_id']}/{$validated['file_name']}";
        Storage::disk($status->disk)->move($status->finalPath, $permanentPath);

        Document::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'path' => $permanentPath,
            'disk' => $status->disk,
            'file_name' => $validated['file_name'],
            'file_size' => $status->fileSize,
        ]);

        return redirect()->route('dokumentumok.index');
    }
}
```

## Miért ne használjuk az Inertia beépített feltöltését?

Az Inertia `useForm().post()` egyetlen kéréssel küldi el a teljes fájlt. Kis fájloknál ez tökéletes, de nagy fájloknál gondot okoz:

| Funkció | Inertia feltöltés | Chunky |
|---------|-------------------|--------|
| Max fájlméret | Szerver upload limit | Korlátlan (darabolva) |
| Folytatás hiba után | Nem | Igen |
| Szünet / Folytatás | Nem | Igen |
| Hálózati megszakítás | Teljes újrafeltöltés | Folytatás utolsó chunk-tól |
| Progress részletesség | Byte-szintű (1 request) | Chunk + byte szintű |
| Párhuzamos feltöltés | Nem | Konfigurálható |
| Checksum ellenőrzés | Nem | SHA-256 chunk-onként |
