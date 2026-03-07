# Livewire Integráció

A Chunky tartalmaz egy beépített Livewire komponenst, ami Alpine.js-t használ a háttérben. Nem szükséges npm csomag -- minden benne van a Composer csomagban.

## Követelmény

- Livewire 3+
- Alpine.js (a Livewire-rel érkezik)

## Alap használat

```blade
<livewire:chunky-upload />
```

Ez egy teljes feltöltési UI-t renderel:
- Drag & drop / kattintás dropzone
- Progress bar százalékkal
- Szünet / Folytatás / Megszakítás gombok
- Hiba kijelzés újrapróbálkozás gombbal
- Befejezési üzenet

## Kontextussal

Szerver oldali validációs szabályok alkalmazása:

```blade
<livewire:chunky-upload context="profile_avatar" />
```

## Egyedi UI slot-tal

A default UI felülírása, de a feltöltési logika megtartása:

```blade
<livewire:chunky-upload context="documents">
    <div
        x-data="chunkUpload({ context: 'documents' })"
        class="border-2 border-dashed rounded-lg p-8"
    >
        <div x-show="!isUploading && !isComplete">
            <label class="cursor-pointer">
                <input type="file" class="hidden" x-on:change="handleFileInput($event)" />
                <span>Kattints vagy húzd ide a dokumentumot</span>
            </label>
        </div>

        <div x-show="isUploading" class="space-y-2">
            <div class="flex justify-between text-sm">
                <span x-text="currentFile?.name"></span>
                <span x-text="Math.round(progress) + '%'"></span>
            </div>
            <div class="h-2 bg-gray-200 rounded">
                <div class="h-2 bg-blue-500 rounded" :style="'width: ' + progress + '%'"></div>
            </div>
            <div class="flex gap-2">
                <button x-on:click="isPaused ? resume() : pause()" x-text="isPaused ? 'Folytatás' : 'Szünet'"></button>
                <button x-on:click="cancel()">Megszakítás</button>
            </div>
        </div>

        <div x-show="isComplete" class="text-green-600">Feltöltés kész!</div>

        <div x-show="error" class="text-red-600">
            <span x-text="error"></span>
            <button x-on:click="retry()">Újrapróbálás</button>
        </div>
    </div>
</livewire:chunky-upload>
```

## Feltöltés befejezése utáni feldolgozás

### Szülő Livewire komponensben

Figyeld a `chunky-upload-completed` eseményt:

```php
namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;

class DocumentManager extends Component
{
    public array $uploads = [];

    #[On('chunky-upload-completed')]
    public function handleUpload(array $data): void
    {
        // $data tartalma:
        // - uploadId: string
        // - fileName: string
        // - fileSize: int
        // - finalPath: string
        // - disk: string

        $this->uploads[] = [
            'name' => $data['fileName'],
            'path' => $data['finalPath'],
        ];

        Storage::disk($data['disk'])->move(
            $data['finalPath'],
            "documents/{$data['fileName']}",
        );
    }

    public function render()
    {
        return view('livewire.document-manager');
    }
}
```

```blade
{{-- livewire/document-manager.blade.php --}}
<div>
    <livewire:chunky-upload context="documents" />

    <ul>
        @foreach($uploads as $upload)
            <li>{{ $upload['name'] }}</li>
        @endforeach
    </ul>
</div>
```

### Kontextus Save Callback-kel

Automatikus feldolgozás (pl. Spatie Media Library):

```php
// AppServiceProvider::boot()
use NETipar\Chunky\Facades\Chunky;

Chunky::context('profile_avatar', save: function ($metadata) {
    $user = auth()->user();
    $user->addMediaFromDisk($metadata->finalPath, $metadata->disk)
        ->toMediaCollection('avatar');
});
```

```blade
{{-- A save callback automatikusan lefut az összefűzés után --}}
<livewire:chunky-upload context="profile_avatar" />
```

## Hogyan működik?

A Livewire komponens hídként működik:

1. **Chunk feltöltések** közvetlenül a Chunky API endpoint-okra mennek (`fetch()` az Alpine.js-ben) -- NEM mennek át a Livewire wire protokollon
2. **Befejezési értesítés** Alpine.js `$dispatch` -> Livewire `$wire` -on keresztül megy, ami meghívja a `completeUpload()`-ot a szerveren
3. A Livewire komponens ellenőrzi a feltöltés státuszát és kiküldi a `chunky-upload-completed` eseményt a szülőnek

Ez azt jelenti:
- Nincs Livewire upload limit (a chunk-ok megkerülik a Livewire-t)
- A progress frissítés valós idejű (Alpine.js, nincs szerver körutazás)
- Szerver oldali ellenőrzés mégis megtörténik a befejezéskor
