# Laravel Chunky v1.0 — Végrehajtási terv (agent-playbook)

> Ez a dokumentum a [v1-rewrite-plan.md](v1-rewrite-plan.md) architektúra-terv
> **végrehajtható** változata: egy fejlesztő agent (Opus 4.8) ebből a dokumentumból,
> további kérdések nélkül, fázisról fázisra végig tudja vinni az újraírást.
> Minden döntés meghozva, minden publikus API-szignatúra rögzítve.
> Kétség esetén ez a dokumentum az igazság forrása; amit nem rögzít, arra a
> 0.x viselkedése a minta (lásd 1.4 — hogyan olvasd a régi kódot).

---

## 1. Munkarend (KÖTELEZŐ, olvasd el először)

### 1.1 Környezet és branch

- Munkakönyvtár: a repo gyökere. Branch: **`v1`** (`git checkout -b v1 main`).
- A `main` érintetlen marad (0.x karbantartás). MINDEN v1 munka a `v1` branchen.
- A `v1` branchen az újraírás **tiszta lappal indul**: az 1. fázis elején törlöd
  a `src/`, `tests/Feature`, `tests/Unit`, `database/migrations` tartalmát és a
  `packages/*/src`, `packages/*/types` tartalmát. **Megmarad** a repo-váz:
  `composer.json` (módosítva), `pint.json`, `phpstan.neon` (baseline sor törölve,
  level: max), `phpunit.xml`, `pnpm-workspace.yaml`, `vitest.config.ts`, CI
  workflow-k, `lang/`, `art/`, `LICENSE.md`.

### 1.2 Minőségi kapuk — minden fázis végén MIND zöld

```bash
composer ci                    # = pint --test + phpstan (level max) + pest
pnpm -r test                   # vitest minden workspace-ben
pnpm -r exec tsc --noEmit      # strict typecheck
npx publint packages/core && npx @arethetypeswrong/cli --pack packages/core   # 4. fázistól
```

Szabályok:
- **PHPStan level max, baseline TILOS.** Ha baseline-ra lenne szükség, a kód rossz — javítsd a kódot.
- Teszt kikapcsolása/skippelése a kapu átviteléhez TILOS.
- Maximum **2 önjavítási kör** egy bukó kapun; ha utána is bukik, állj meg és
  jelentsd a konkrét hibát — ne lazíts a kapun.
- Fázis végén commit (a repo commit-konvenciói szerint, magyar vagy angol
  üzenet a meglévő stílusban), a commit üzenetben a fázis számával.

### 1.3 Kódstílus

A `~/.claude/laravel-php-guidelines.md` szabályai érvényesek. Kiemelten:
- Early return, `else` kerülése, happy path utoljára.
- Typed property-k, constructor property promotion, `readonly` DTO-k.
- Docblock csak ott, ahol a típusrendszer kevés (generics: `array<int, string>`).
- Kommentet csak ott írj, ahol a kód nem tudja elmondani a *megkötést*
  (versenyhelyzet-indoklás, crash-window magyarázat) — a 0.x kódbázis
  komment-sűrűsége a minta, ez a package erőssége volt.
- TypeScript: strict, `noUncheckedIndexedAccess`, publikus API-n explicit típusok.

### 1.4 A régi kód mint referencia

A 0.x kód a `main` branchen érhető el: `git show main:src/... `. NE másold
vakon, de az alábbi helyeken a 0.x **viselkedése normatív** (bug-fixek évei
vannak bennük) — olvasd el, mielőtt az adott részt implementálod:

| Téma | Régi fájl |
|---|---|
| Assembly biztonság (staging file, disk-space preflight, méret-check, path-traversal guard) | `src/Handlers/DefaultChunkHandler.php` |
| Idempotency-key + checksum fallback | `src/Http/Controllers/UploadChunkController.php` |
| Claim CAS + stale takeover szemantika | `src/Trackers/DatabaseTracker.php:120-171`, `src/Jobs/AssembleFileJob.php` |
| Batch-finalizálás (event lock-on kívül) | `src/Trackers/DatabaseBatchTracker.php:100-156` |
| Broadcast payload sanitizálás | `src/Events/AbstractChunkyEvent.php`, `UploadMetadata::toPublicArray()` |
| Lock-driver kompatibilitás boot-guard | `src/ChunkyServiceProvider.php` |
| Sticky terminal-event replay | `packages/core/src/internal/EventEmitter.ts` |
| Pause/resume élversenyei | `packages/core/src/ChunkUploader.ts:340-560` |
| Adaptív polling + broadcast reconcile | `packages/core/src/CompletionWatcher.ts` |

A 0.x teszt-suite (`git show main:tests/...`) a viselkedési spec kivonata —
minden ottani edge-case-nek legyen v1 megfelelője (új struktúrában).

### 1.5 Rögzített döntések (NE nyisd újra)

1. PHP **^8.3**, Laravel **^12 || ^13**, Pest 4, PHPStan max.
2. Namespace marad `NETipar\Chunky`, csomagnév `netipar/laravel-chunky`, verzió `1.0.0-beta.1`-ig.
3. Filesystem driver **marad**, vékony adapterként.
4. Kontextus-registry helyett **UploadProfile osztályok** (breaking change, UPGRADE.md dokumentálja).
5. Frontend deprecated mutable mezők (`progress`, `isUploading`, …) **törölve** — csak `getState()` + `subscribe()`.
6. `assembly.mode` default: **`auto`**, küszöb 256 MB.
7. Broadcast **opcionális enhancement** — a teljes suite-nak Echo és queue worker nélkül is zöldnek kell lennie.
8. Wire-protokoll: snake_case JSON, a lenti 3. szakasz szerint — eltérés tilos.
9. **D1** (2026-07-11 eldöntve): non-owner status/cancel → `404` (anti-enumeration), chunk non-owner → `403`.
10. **D2**: globális `limits.metadata_max_keys` (default 50), request-validációban kényszerítve.
11. **D3**: `broadcasting.except` default a magas-frekvenciájú eseményeket tartalmazza.
12. **D4**: nincs `413 file_too_large` — a túl nagy `file_size` → `422 validation_failed`.
   (A D1–D4 részletei: [behavior-inventory.md](behavior-inventory.md) A. szakasz.)

---

## 2. Cél-architektúra — könyvtárszerkezet

```
src/
├── ChunkyServiceProvider.php
├── Facades/Chunky.php
├── Config/
│   └── ChunkyConfig.php              # readonly, boot-kor validált konfig-objektum
├── Domain/
│   ├── UploadStatus.php              # enum + átmeneti tábla
│   ├── BatchStatus.php               # enum + átmeneti tábla
│   ├── UploadRecord.php              # readonly DTO (a 0.x UploadMetadata utódja)
│   ├── BatchRecord.php
│   ├── ChunkProgress.php             # {uploadedCount, totalChunks, isComplete}
│   ├── CompletedFile.php             # {disk, path, size, url?}
│   ├── UploadResult.php              # {status, file?, payload?}
│   └── Fingerprint.php               # value object + normalizálás
├── Ports/
│   ├── UploadRepository.php
│   ├── BatchRepository.php
│   ├── ChunkStore.php
│   ├── LockProvider.php
│   └── Clock.php
├── Adapters/
│   ├── Database/{DatabaseUploadRepository,DatabaseBatchRepository}.php
│   ├── Filesystem/{FilesystemUploadRepository,FilesystemBatchRepository,JsonFileStore}.php
│   ├── Storage/FlysystemChunkStore.php
│   └── Lock/{CacheLockProvider,FlockProvider}.php
├── Services/
│   ├── UploadService.php             # initiate / uploadChunk / status / cancel
│   ├── BatchService.php              # batch életciklus + finalizálás (EGY helyen)
│   └── Assembly/
│       ├── AssemblyPipeline.php      # lépések + kompenzáció
│       └── Steps/{PreflightStep,MergeStep,IntegrityStep,MoveStep,ProfileStep,CleanupStep}.php
├── Profiles/
│   ├── UploadProfile.php             # absztrakt ős
│   ├── SimpleProfile.php             # Chunky::simple() mögött
│   ├── ProfileRegistry.php
│   └── UploadContext.php             # {user, request-metadata} a hookoknak
├── Authorization/{Authorizer,DefaultAuthorizer}.php
├── Http/
│   ├── Controllers/  (8 invokable, a 0.x nevekkel)
│   ├── Requests/
│   ├── Middleware/VerifyChunkIntegrity.php
│   └── ErrorCode.php                 # enum: gépi hibakódok
├── Jobs/AssembleFileJob.php          # vékony: claim + pipeline hívás
├── Events/  (11 event, lásd 3.5)
├── Models/{ChunkedUpload,ChunkyBatch}.php
├── Console/{InstallCommand,DoctorCommand,CleanupCommand,MakeProfileCommand}.php
└── Exceptions/{ChunkyException,InvalidStateException,UploadExpiredException,ChunkIntegrityException}.php

packages/
├── core/src/{Uploader,Batch,UploadManager,CompletionWatcher,EventEmitter,RetryPolicy,http,config,types}.ts
├── vue3/src/{plugin.ts,useUpload.ts,useUploads.ts,useBatch.ts,components/UploadTray.vue,components/ChunkDropzone.vue}
├── react/src/{ChunkyProvider.tsx,useUpload.ts,useUploads.ts,useBatch.ts}
└── alpine/src/{index.ts,chunk-upload.ts,batch-upload.ts}
```

### 2.1 Állapotgép (normatív)

```
UploadStatus:  pending → uploading → assembling → completed
                  │          │           └────────→ failed
                  │          ├──→ cancelled
                  └──→ cancelled
```

- Terminális: `completed`, `failed`, `cancelled`. Terminálisból NINCS kiút.
- `cancel` csak `pending|uploading`-ból engedélyezett; `assembling` alatt → `409 invalid_state`.
- Assembly-retry NEM állapotváltás: a claim a `claimed_at` attribútum CAS-e
  (`transition(Assembling, Assembling, ['claimed_at' => now])` stale-claim esetén).
- Expiry nem külön státusz: `expires_at` timestamp; lejárt, nem-terminális upload
  chunk-POST-ra `410 upload_expired`-et ad, a cleanup `cancelled`-re viszi.

```
BatchStatus:  pending → in_progress → completed | partially_completed
                 │            └──→ cancelled
                 └──→ cancelled
```

Finalizálás: amikor `completed + failed == total` → `failed == 0 ? completed : partially_completed`.
A finalizáló event dispatch **lock-on/tranzakción KÍVÜL** történik (0.x tanulság).

### 2.2 Portok (pontos szignatúrák — ettől eltérni tilos)

```php
interface UploadRepository
{
    public function create(UploadRecord $record): void;

    public function find(string $uploadId): ?UploadRecord;

    public function findByFingerprint(string $fingerprint, ?string $userId): ?UploadRecord;

    /** @return list<UploadRecord> */
    public function findByBatch(string $batchId): array;

    /** Atomi: chunk-index hozzáadása + friss progress egy műveletben. */
    public function markChunk(string $uploadId, int $chunkIndex): ChunkProgress;

    /**
     * Atomi compare-and-swap. False, ha a rekord $from állapota közben elmozdult
     * VAGY ($guard megadva és a guard-feltétel nem áll). $attrs a $to-val együtt,
     * ugyanabban az atomi műveletben íródik (pl. final_path, claimed_at).
     *
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $guard   pl. ['claimed_before' => $staleThreshold]
     */
    public function transition(
        string $uploadId,
        UploadStatus $from,
        UploadStatus $to,
        array $attrs = [],
        array $guard = [],
    ): bool;

    /** @return list<UploadRecord>  Nem-terminális, lejárt rekordok (cleanup). */
    public function findExpired(\DateTimeImmutable $before, int $limit): array;

    public function delete(string $uploadId): void;
}

interface BatchRepository
{
    public function create(BatchRecord $record): void;

    public function find(string $batchId): ?BatchRecord;

    /**
     * Atomi inkrement + finalizálás-detektálás. A closure-t AKKOR ÉS CSAK AKKOR
     * hívja (lockon/tranzakción belül), ha ez az inkrement zárta le a batch-et;
     * a closure a végstátuszt állítja be. Visszatérés: a friss BatchRecord.
     */
    public function increment(string $batchId, BatchCounter $counter, \Closure $onFinalize): BatchRecord;

    public function transition(string $batchId, BatchStatus $from, BatchStatus $to): bool;

    public function delete(string $batchId): void;
}

enum BatchCounter: string { case Completed = 'completed'; case Failed = 'failed'; }

interface ChunkStore
{
    public function put(string $uploadId, int $chunkIndex, UploadedFile $chunk): void;

    public function exists(string $uploadId, int $chunkIndex): bool;

    /** @return resource  Olvasó stream a chunkhoz. */
    public function readStream(string $uploadId, int $chunkIndex);

    public function purge(string $uploadId): void;
}

interface LockProvider
{
    /**
     * @template T
     * @param \Closure(): T $callback
     * @return T
     * @throws LockTimeoutException
     */
    public function withLock(string $key, \Closure $callback, ?int $timeoutSeconds = null): mixed;
}

interface Clock
{
    public function now(): \DateTimeImmutable;
}
```

**Fontos:** a `Services/` réteg KIZÁRÓLAG a portokon keresztül ér el tárolást.
`instanceof <konkrét adapter>` ellenőrzés a services/domain rétegben = azonnali
review-bukás (a 0.x `cancelBatchUploads` hibájának megismétlése).

### 2.3 UploadProfile (publikus API)

```php
abstract class UploadProfile
{
    /** Validációs szabályok az initiate kérés `file_name/file_size/mime_type` + metadata mezőire. */
    public function rules(): array { return []; }

    public function disk(): ?string { return null; }          // null = config default

    abstract public function directory(UploadContext $context): string;

    public function authorize(UploadContext $context): bool { return true; }

    public function fileName(UploadContext $context, string $original): string
    {
        return \Illuminate\Support\Str::uuid().'.'.pathinfo($original, PATHINFO_EXTENSION);
    }

    /**
     * A sikeres assembly után, MÉG a completed-átmenet előtt fut.
     * A visszatérési érték a válasz `payload` mezőjébe kerül (JSON-serializálhatónak
     * kell lennie). Kivétel esetén az upload failed-re megy, a fájl törlődik.
     *
     * @return array<string, mixed>|null
     */
    public function completed(CompletedUpload $upload): ?array { return null; }

    public function maxFileSize(): ?int { return null; }      // byte; null = config default
}
```

Regisztráció a configban: `'profiles' => ['avatar' => AvatarProfile::class]`.
A kliens az initiate kérésben `profile: 'avatar'`-t küld. Ismeretlen profil →
`422 profile_not_found`. `Chunky::simple('dir')` továbbra is működik
(runtime `SimpleProfile`-t regisztrál).

### 2.4 ChunkyConfig — a teljes config-séma (~25 kulcs)

```php
return [
    'tracker' => env('CHUNKY_TRACKER', 'database'),            // database | filesystem
    'disk' => env('CHUNKY_DISK', 'local'),                     // végleges fájlok
    'chunks' => [
        'disk' => env('CHUNKY_CHUNK_DISK', 'local'),
        'directory' => 'chunky/chunks',
        'size' => (int) env('CHUNKY_CHUNK_SIZE', 5 * 1024 * 1024),
    ],
    'limits' => [
        'max_file_size' => (int) env('CHUNKY_MAX_FILE_SIZE', 2 * 1024 ** 3),
        'expiration_hours' => 24,
        'metadata_max_keys' => 50,                             // D2: metadata kulcsok max száma (DoS-guard)
    ],
    'assembly' => [
        'mode' => env('CHUNKY_ASSEMBLY_MODE', 'auto'),         // sync | queue | auto
        'sync_threshold' => 256 * 1024 * 1024,                 // auto-nál: ez alatt sync
        'connection' => env('CHUNKY_ASSEMBLY_CONNECTION'),     // null = default queue conn
        'queue' => env('CHUNKY_ASSEMBLY_QUEUE'),
        'timeout' => 600,
        'tries' => 3,                                          // job retry (0.x-ből visszahozva)
        'backoff' => 30,
        'stale_claim_seconds' => 900,
    ],
    'locking' => [
        'driver' => 'auto',                                    // auto | cache | flock
        'timeout' => 10,
    ],
    'idempotency' => ['ttl' => 300],
    'integrity' => ['algorithm' => 'sha256', 'required' => false],
    'resume' => ['fingerprint' => true],
    'authorization' => ['authorizer' => \NETipar\Chunky\Authorization\DefaultAuthorizer::class],
    'routes' => [
        'enabled' => true,
        'prefix' => 'api/chunky',
        'middleware' => ['api'],
    ],
    'throttle' => [
        'initiate' => '30,1',
        'chunks' => '300,1',
    ],
    'broadcasting' => [
        'enabled' => env('CHUNKY_BROADCASTING', false),
        // D3: a magas-frekvenciájú események default-off (bekapcsoláskor csak a
        // ritka completion-események mennek, amíg a fejlesztő ki nem veszi őket).
        'except' => [
            \NETipar\Chunky\Events\ChunkUploaded::class,
            \NETipar\Chunky\Events\ChunkUploadFailed::class,
            \NETipar\Chunky\Events\BatchInitiated::class,
        ],
        'queue' => null,
    ],
    'profiles' => [],
    'cleanup' => ['enabled' => true],
];
```

A `ChunkyConfig::fromArray()` boot-kor fut, MINDEN kulcsot validál (típus,
tartomány, enum-érték), hibánál `InvalidConfigurationException`-t dob a hibás
kulcs megnevezésével. A kódban `config('chunky.*')` hívás **tilos** — mindenhol
az injektált `ChunkyConfig` objektum. (Kivétel: a ServiceProvider maga.)
Boot-guardok a 0.x-ből átveendők: lock-driver kompatibilitás (array/database
cache lock tiltása multi-server jelek mellett), filesystem tracker + nem-local
chunk disk kombináció figyelmeztetés.

### 2.5 Események és broadcast

Nevek és trigger-pontok változatlanok a 0.x-hez képest:
`UploadInitiated`, `ChunkUploaded`, `ChunkUploadFailed`, `FileAssembled`,
`UploadCompleted`, `UploadFailed`, `UploadCancelled`, `BatchInitiated`,
`BatchCompleted`, `BatchPartiallyCompleted`, `BatchCancelled`.

- Közös `ChunkyEvent` interface + `BroadcastsChunkyEvent` trait (ősosztály helyett).
- Broadcast payload: **verziózott** — `{'v': 1, ...UploadRecord::toPublicArray()}`.
  A public array SOHA nem tartalmaz: `disk`, `final_path`, `user_id`, abszolút útvonal.
- Csatornák a 0.x `routes/channels.php` szerint (privát, upload/batch tulajdonos).
- Broadcast csak ha `broadcasting.enabled` és az event nincs az `except` listán.
  Default `enabled => false` — bekapcsolás explicit (nincs mergeConfigFrom-akna).
  Az `except` default a magas-frekvenciájú eseményeket tartalmazza (D3).

---

## 3. Wire-protokoll v1 (normatív — a contract-tesztek erre assertelnek)

Minden válasz JSON, snake_case. Hiba-envelope MINDEN nem-2xx válaszra:

```json
{ "error": { "code": "upload_expired", "message": "..." } }
```

`ErrorCode` enum: `validation_failed(422)`, `profile_not_found(422)`,
`unauthorized(403)`, `upload_not_found(404)`, `batch_not_found(404)`,
`upload_expired(410)`, `invalid_state(409)`, `lock_timeout(503)`,
`checksum_mismatch(422)`, `chunk_index_out_of_range(422)`, `assembly_failed(500)`.

Döntések (D1–D4), a [protocol.md](protocol.md) mérvadó:
- **D4:** nincs `file_too_large(413)` — a túl nagy `file_size` és a túl sok
  `metadata` kulcs egyaránt `422 validation_failed`.
- **D1:** non-owner a **status/cancel** végponton `404`-et kap (anti-enumeration,
  nem árulja el az ID létezését); a **chunk** végponton non-owner `403`.

### 3.1 `POST {prefix}/upload` — initiate

Kérés:
```json
{
  "file_name": "video.mp4", "file_size": 104857600, "mime_type": "video/mp4",
  "profile": "avatar", "metadata": {"album_id": 7},
  "fingerprint": "sha1-hex", "batch_id": null
}
```
Válasz `201`:
```json
{
  "upload_id": "uuid", "chunk_size": 5242880, "total_chunks": 20,
  "resumed": false, "uploaded_chunks": []
}
```
Fingerprint-találatnál (élő, nem-terminális, azonos user): `200`, `resumed: true`,
`uploaded_chunks` a már meglévő indexekkel — ilyenkor NEM jön létre új rekord.

### 3.2 `POST {prefix}/upload/{id}/chunks` — chunk

Multipart: `chunk` (file), `chunk_index` (int, 0-alapú), `checksum` (opcionális,
hex). Fejléc: `Idempotency-Key` (opcionális). Integritás-middleware: ha jön
checksum, SHA-256 egyezés kötelező, különben `422 checksum_mismatch`.

Köztes chunk válasz `200`:
```json
{ "status": "uploading", "chunk_index": 5, "uploaded_count": 6,
  "total_chunks": 20, "progress": 30.0 }
```

**Záró chunk** — mode-függő:
- `sync` (vagy `auto` küszöb alatt) — az assembly a kérésen belül lefut:
```json
{ "status": "completed", "progress": 100.0,
  "file": { "path": "avatars/42/x.jpg", "size": 104857600, "url": "https://..." },
  "payload": { "media_id": 123 } }
```
  Sync assembly-hiba: `500` + `{"error":{"code":"assembly_failed",...}}`, az
  upload `failed` státuszba kerül, a chunkok megőrződnek a hibavizsgálathoz a
  cleanup-ig. (`url` csak akkor, ha a disk támogatja; `path` a diskhez relatív.)
- `queue` (vagy `auto` küszöb felett):
```json
{ "status": "assembling", "progress": 100.0 }
```

Idempotency: kulcs (vagy checksum-fallback) találatnál a cache-elt válasz
byte-ra pontos visszajátszása — beleértve a záró-chunk completed választ is.

### 3.3 `GET {prefix}/upload/{id}` — status

```json
{ "upload_id": "uuid", "status": "completed", "progress": 100.0,
  "uploaded_chunks": [0,1,2], "total_chunks": 20,
  "file_name": "video.mp4", "file_size": 104857600,
  "file": { "...": "csak completed státusznál" },
  "payload": { "...": "csak completed státusznál" } }
```
A `payload`-ot a completed-átmenetkor perzisztálni kell a rekordban (a status
endpoint nem futtathatja újra a profil-hookot).

### 3.4 `DELETE {prefix}/upload/{id}` — cancel

`200 {"status": "cancelled"}`; `assembling` alatt `409 invalid_state`;
terminális állapotban idempotens `200` ha már `cancelled`, egyébként `409`.

### 3.5 Batch végpontok

- `POST {prefix}/batch` `{total_files, profile?, metadata?}` → `201 {batch_id, total_files}`
- `POST {prefix}/batch/{id}/upload` — mint a 3.1, `batch_id` kötve → tag-initiate
- `GET {prefix}/batch/{id}` → `{batch_id, status, total_files, completed_count, failed_count, uploads: [{upload_id, status, progress}]}`
- `DELETE {prefix}/batch/{id}` → batch cancel + MINDEN nem-terminális tag cancel
  (`findByBatch` — drivertől függetlenül működnie kell, erre külön teszt van).

---

## 4. Frontend spec (packages/)

### 4.1 core — publikus API

```ts
// A modul-szintű manager: MINDEN upload ide regisztrál, komponens-élettartamtól független.
export class UploadManager {
    upload(file: File, options?: UploadOptions): Uploader;
    batch(files: File[], options?: BatchOptions): Batch;
    uploads(): readonly Uploader[];                 // aktív + terminális (amíg nincs remove)
    subscribe(fn: (uploads: readonly Uploader[]) => void): Unsubscribe;
    remove(uploadId: string): void;                 // csak terminálisat enged eltávolítani
}
export const manager: UploadManager;                // default singleton

export class Uploader {
    readonly id: string;                            // kliens-oldali id (uploadId initiate után)
    upload(): Promise<UploadResult>;                // MINDHÁROM módban a végeredménnyel rezolvál
    pause(): void;
    resume(): boolean;
    cancel(): Promise<void>;
    getState(): UploadState;                        // immutábilis snapshot — NINCS mutable publikus mező
    subscribe(fn: (state: UploadState) => void): Unsubscribe;
    on<E extends keyof UploadEvents>(event: E, fn: UploadEvents[E]): Unsubscribe;
}

export interface UploadState {
    status: 'idle' | 'uploading' | 'paused' | 'assembling' | 'completed' | 'failed' | 'cancelled';
    progress: number;                               // 0..100
    uploadedChunks: number; totalChunks: number;
    bytesPerSecond: number | null; etaSeconds: number | null;
    file: { name: string; size: number };
    result: UploadResult | null;                    // terminálisnál kitöltve
    error: ChunkyError | null;
}

export interface UploadResult {
    uploadId: string;
    status: 'completed' | 'failed' | 'cancelled';
    file: { path: string; size: number; url?: string } | null;
    payload: Record<string, unknown> | null;
}
```

Kötelező viselkedések:
- **EventEmitter egyetlen implementáció** (`EventEmitter.ts`), sticky terminal
  replay-jel: terminális event után feliratkozó azonnal megkapja az utolsó
  terminális eventet. Az `Uploader`, a `Batch` és az `UploadManager` MIND ezt
  használja — saját listener-Map implementáció bármelyikben = review-bukás.
- `Batch` = `Uploader`-ek kompozíciója: batch-initiate, tagonként `manager.upload`
  becsomagolva, aggregált progress, konfigurálható konkurencia (default 3).
  Chunk-szintű logika a Batch-ben NINCS.
- `upload()` promise: sync módnál a záró chunk válaszból; `assembling` státusznál
  a `CompletionWatcher`-ből (polling default; Echo ha konfigurálva). A watcher
  a status endpoint `file`/`payload` mezőit adja vissza.
- Fingerprint: `name + size + lastModified + első chunk SHA-1` → localStorage
  `chunky:fp:<fingerprint>` → uploadId. Initiate előtt ellenőrzés; `resumed: true`
  válasznál a hiányzó chunkoktól folytat. Terminális válasz vagy 404/410 →
  bejegyzés törlése.
- Retry: `RetryPolicy` (exponenciális backoff + jitter) chunk-szinten; `lock_timeout`
  (503) és hálózati hiba retryable, 4xx (kivéve 409/410 speciális kezelés) nem.
- `beforeunload` guard: opt-in (`config.confirmUnload = true`), csak aktív upload mellett.

### 4.2 Wrapperek

```ts
// vue3
app.use(chunkyPlugin, config)                       // manager provide app-szinten
useUpload(): { start(file, opts?), state: DeepReadonly<Ref<UploadState>> | null, uploader }
useUploads(): { uploads: Ref<readonly UploaderBinding[]>, totalProgress: Ref<number> }
useBatch(): analóg
// <UploadTray> — headless (slot-alapú), a useUploads()-ra épül
// <ChunkDropzone> — a 0.x-ből átemelve, az új API-ra kötve

// react — MINDEN hook useSyncExternalStore-ra épül (subscribe + getState pontosan illik)
<ChunkyProvider config={...}>
useUpload(); useUploads(); useBatch();

// alpine — Alpine.data komponensek a manager felett, a 0.x publikus attribútum-nevekkel
```

Wrapper-szabály: unmount = leiratkozás, SOHA nem cancel/abort. Az upload a
manageré.

### 4.3 Csomagolás

- `.d.ts` KIZÁRÓLAG build-output (`types/`), `src/*.d.ts` fájl nem létezhet.
- Minden package `exports` mezője: ESM + types; `publint` és `attw --pack` zöld CI-kapu.
- Verziók lockstepben a composer.json-nal (a meglévő `scripts/bump-version.sh` frissítendő).

---

## 5. Fázisok és ticketek

Minden ticket DoD-ja implicit tartalmazza: `composer ci` zöld, kapcsolódó
tesztek megírva és zöldek, commit kész.

### 0. fázis — Spec-fagyasztás (a kódtörlés ELŐTT!)

- **T0.1** Írd meg `docs/protocol.md`-t a fenti 3. szakasz kibontásával (minden
  mező típusa, kötelezősége, minden hibaeset), és `docs/openapi.yaml`-t ugyanerről.
- **T0.2** Vond ki a 0.x tesztekből a viselkedési leltárt `docs/behavior-inventory.md`-be:
  menj végig `tests/Feature` + `tests/Unit` minden test-case nevén, és sorold be:
  `KEEP` (v1-ben is kell), `CHANGED` (v1-ben más — írd le, mi), `DROP` (indokkal).
  Ez a lista a v1 teszt-suite munkalistája; a 6. fázis kapuja, hogy minden
  `KEEP`/`CHANGED` tételnek van v1 tesztje.
- **DoD:** a két doksi commitolva a `v1` branchen, még a régi kód mellett.

### 1. fázis — Domain mag + portok + kontraktus-suite váza

- **T1.1** Takarítás az 1.1 szerint + `composer.json`/`phpstan.neon` frissítés
  (PHP ^8.3, L12|13, level max, baseline-hivatkozás törölve).
- **T1.2** `Domain/`: enumok átmeneti táblával (`allowedTransitions()`,
  `isTerminal()`, `assertCanTransition()`), readonly DTO-k (`UploadRecord` a 0.x
  `UploadMetadata` mezőivel + `fingerprint`, `expires_at`, `claimed_at`,
  `result_payload`; `toArray/fromArray/toPublicArray`).
- **T1.3** `Ports/` interfészek a 2.2 szerint, szó szerint.
- **T1.4** `Config/ChunkyConfig` + validáció + `config/chunky.php` a 2.4 szerint.
- **T1.5** **Kontraktus-suite**: `tests/Contracts/UploadRepositoryContractTest.php`
  és `BatchRepositoryContractTest.php` absztrakt Pest osztályokként/megosztott
  tesztekként, amelyeket adapterenként egy-egy vékony fájl példányosít
  (Pest: közös closure-ök + dataset a driverre). Lefedendő: CRUD, `markChunk`
  idempotenciája (kétszer ugyanaz az index = egy elem), `transition` CAS
  (siker, rossz `from`, guard-bukás), `findByBatch`, `findByFingerprint`
  (user-szűréssel!), `findExpired`. Első futtatás in-memory (array) fake
  adapterrel — ez a fake a services-tesztek eszköze is lesz.
- **DoD:** kontraktus-suite zöld az in-memory adapteren; phpstan max zöld.

### 2. fázis — Adapterek

- **T2.1** Migrációk: `chunky_uploads` (uuid PK, status index, fingerprint+user
  index, batch_id FK-mentes index, expires_at index), `chunky_batches`.
  Csak `up()` (repo-konvenció). A 0.x migrációk sorrend-bugja (FK-enforcing DB,
  v0.22.4) ne ismétlődjön: egyetlen fájl, mindkét tábla, FK nélkül.
- **T2.2** `DatabaseUploadRepository`: `transition` = egyetlen
  `UPDATE ... WHERE id=? AND status=?` (+ guard feltételek), affected rows alapú
  bool — NEM select-then-update. `markChunk` `lockForUpdate` tranzakcióban.
- **T2.3** `DatabaseBatchRepository::increment`: tranzakció + `lockForUpdate`,
  finalize-detektálás benn, `$onFinalize` benn fut, de a **kapott eredményből az
  event-dispatch a hívóban, lockon kívül** (a BatchService felelőssége).
- **T2.4** `JsonFileStore` (közös JSON-perzisztencia: read/write/delete +
  atomikus write-temp-rename), erre `FilesystemUploadRepository` +
  `FilesystemBatchRepository`. Lock MINDEN olvasás-módosítás-írásra a
  `LockProvider`-en át.
- **T2.5** Lock adapterek: `CacheLockProvider` (Cache::lock + block),
  `FlockProvider`. `auto` feloldás: cache ha a store támogat lockot, különben flock.
- **T2.6** `FlysystemChunkStore` + path-traversal guard (basename-only, a 0.x
  `PathTraversalTest` esetei átveendők).
- **T2.7** Konkurencia-tesztek (dedikált fájl): (a) `transition` CAS — két
  egymás utáni azonos `from` hívásból pontosan egy tér vissza true-val;
  (b) stale-claim takeover — friss claim guard-bukás, lejárt claim siker;
  (c) `markChunk` duplikált index; (d) batch `increment` — N inkrement után
  pontosan egyszer fut `$onFinalize` (loopban, mindkét driveren).
- **DoD:** a TELJES kontraktus-suite zöld `database` ÉS `filesystem` adapteren
  (sqlite in-memory + temp dir), konkurencia-tesztek zöldek.

### 3. fázis — Services + HTTP + job + események

- **T3.1** `UploadService`: `initiate` (profil-feloldás, authorize, validálás,
  fingerprint-dedupe, chunk-kalkuláció — a 0.x `ChunkCalculator` átemelhető),
  `uploadChunk` (állapot-guard, store, markChunk), `status`, `cancel`.
- **T3.2** `AssemblyPipeline` + lépések. Minden lépés `execute(AssemblyState): AssemblyState`
  + `compensate(AssemblyState): void`; hibánál a már lefutott lépések kompenzációja
  fordított sorrendben fut, az upload `failed`-re transitionöl, `UploadFailed` event.
  A `ProfileStep` a `completed()` hook visszatérését az `AssemblyState.payload`-ba
  teszi; a completed-átmenet `attrs`-ában perzisztálódik (`result_payload`).
- **T3.3** `AssembleFileJob`: claim (CAS + stale takeover) → pipeline → kész.
  `failed()` csak annyit tud: ha a rekord még `assembling` és a claim a miénk,
  `failed`-re transitionöl. Mode-feloldás (`sync|queue|auto`) a `UploadService`-ben:
  sync esetben a pipeline közvetlen hívása a kérésben, a válaszba a result.
- **T3.4** `BatchService`: initiate, tag-initiate, status, cancel (`findByBatch`
  + tagonként cancel), completed/failed inkrement a repository `increment`-jével,
  finalizáló event dispatch lockon kívül.
- **T3.5** Controllerek + FormRequestek + `VerifyChunkIntegrity` + idempotency
  (a 0.x controller-logika normatív) + `ErrorCode` envelope exception-handler
  (renderable `ChunkyException`-ök). Route-ok a 0.x nevekkel.
- **T3.6** Események + broadcast trait + channels + payload-sanitizálás tesztek
  (a 0.x `BroadcastSanitizationTest` esetei).
- **T3.7** Console: `chunky:install` (config + migráció publish, összefoglaló
  kiírás), `chunky:doctor` (tracker elérhető, disk írható, lock-driver kompat,
  queue worker gyanú — `assembly.mode != sync` és nincs worker jelzés, broadcast
  konfig), `chunky:cleanup` (expired → cancel + purge), `make:chunky-profile`.
- **T3.8** **Protokoll-tesztek**: minden 3. szakaszbeli végpont minden válasz-alakja
  Pest feature-tesztben, a `docs/openapi.yaml`-lal összevetve (legalább: mező-
  készlet és típus assert). Sync és queue mód külön tesztelve (Queue::fake +
  Bus assert, illetve sync módban a záró válasz `file`/`payload` assert).
- **DoD:** teljes backend suite zöld MINDKÉT trackerrel (env-mátrix vagy dataset),
  broadcast és queue worker NÉLKÜL is zöld; `behavior-inventory.md` KEEP-tételei
  kipipálva backend-oldalon.

### 4. fázis — Frontend core

- **T4.1** `EventEmitter` (sticky replay — a 0.x implementáció normatív) +
  `RetryPolicy` + `http.ts` (fetchJson + error-envelope parse → `ChunkyError`
  a `code`-dal).
- **T4.2** `Uploader`: chunkolás, párhuzamos chunk-workerek (a 0.x worker-pool
  minta), pause/resume/cancel (a 0.x élverseny-kommentek átvezetése tesztekkel:
  pause utáni stale pendingChunks, cancel-vs-resume, GC-lt upload resume),
  fingerprint + localStorage resume, snapshot+subscribe, speed/ETA számítás
  (mozgóátlag), `upload(): Promise<UploadResult>` mindhárom mód-ágon.
- **T4.3** `CompletionWatcher` átemelés az új status-sémára (`file`/`payload`).
- **T4.4** `Batch` kompozícióként + `UploadManager` singleton + `confirmUnload`.
- **T4.5** Csomagolás: exports, csak generált types, `publint` + `attw` CI-be.
- **DoD:** vitest zöld, `tsc --noEmit` zöld, publint/attw zöld, NINCS `src/*.d.ts`.

### 5. fázis — Wrapperek + Livewire

- **T5.1** vue3: plugin + useUpload/useUploads/useBatch + UploadTray (headless) +
  ChunkDropzone port. Teszt: komponens-unmount NEM állítja meg az uploadot
  (vitest + happy-dom: unmount után a manager állapota tovább változik).
- **T5.2** react: Provider + hookok `useSyncExternalStore`-ral, ugyanaz az
  unmount-túlélés teszt.
- **T5.3** alpine port az új core-ra.
- **T5.4** Livewire komponens: a PHP komponens a core JS-re épül (a blade view
  a `@netipar/chunky-core`-t használja), nem duplikál protokoll-logikát.
- **DoD:** minden workspace tesztje zöld; `examples/` frissítve és kézzel
  átfuttatva legalább a vue3 példán (testbench + vite dev szerver, vagy a
  példakód statikus review-ja, ha böngésző nem elérhető — jelezd, melyik történt).

### 6. fázis — Kiadás-előkészítés

- **T6.1** README újraírás: 5 perces quickstart legelöl (install → chunky:install
  → egy profil → egy composable), utána mélyülő szakaszok. `docs/configuration.md`
  frissítés az új sémára.
- **T6.2** `UPGRADE.md`: 0.x → 1.0 táblázatos megfeleltetés (config-kulcs → új
  kulcs; `Chunky::register()` callback → profil-osztály minta kóddal; frontend
  mező → `getState()` mező; törölt API-k listája).
- **T6.3** `behavior-inventory.md` záró-audit: minden KEEP/CHANGED tételnél ott
  a v1 teszt hivatkozása. Hiány = pótlás.
- **T6.4** CHANGELOG bejegyzés, verziók `1.0.0-beta.1`-re (bump-version.sh),
  CI teljes futás.
- **DoD:** `composer ci` + `pnpm -r test` + publint/attw zöld; README/UPGRADE
  kész; NEM adsz ki release-t és NEM pusholsz tag-et — a kiadás emberi döntés,
  jelezd, hogy kiadásra kész.

---

### 7. fázis — v1.1 bővítések (a stabil 1.0.0 UTÁN indítható)

> A [v1-rewrite-plan.md](v1-rewrite-plan.md) 8. szakaszának backlog-tételei,
> végrehajtható formában. Kiadás: **1.1.0** minor. Minden protokoll-változás
> **additív** (új mezők, új végpontok) — meglévő 1.0-s kliens változtatás
> nélkül működik az 1.1-es backenddel. A `docs/openapi.yaml` és a
> `docs/protocol.md` minden ticketnél frissítendő.
>
> Két 8. szakaszbeli tétel már a v1.0-ban landolt, itt NEM kell újra:
> sebesség + ETA (T4.2, `UploadState.bytesPerSecond/etaSeconds`) és a
> `chunky:doctor` alapszintje (T3.7).

#### T7.1 Teljes-fájl checksum (end-to-end integritás)

A chunk-szintű integritás megvan; ez az összefűzött VÉGEREDMÉNYT hitelesíti.

- **Backend:** a `MergeStep` streamelés közben inkrementális SHA-256-ot számol
  (`hash_init/hash_update/hash_final` a már futó íróciklusban — nincs második
  olvasás). Az eredmény a rekordba (`file_checksum`) és a completed válasz
  `file.checksum` mezőjébe kerül.
- Az initiate kérés új, opcionális mezője: `expected_checksum` (hex SHA-256).
  Ha meg van adva, az `IntegrityStep` a merge utáni hash-sel veti össze;
  eltérésnél az upload `failed`, hibakód: `file_checksum_mismatch` (új
  `ErrorCode` eset, 422-es mapping a status-válaszban / sync módban 500 helyett
  422-vel térünk vissza, mert kliens-adat hiba).
- **Frontend:** a kliens-oldali számoláshoz NE húzz be kötelező függőséget.
  `config.checksum: 'none' | 'server' | 'client'` (default `'server'`):
  `server` = csak a visszakapott `file.checksum` eltárolása az eredményben;
  `client` = inkrementális SHA-256 chunkolás közben — a `hash-wasm` **optional
  peer dependency**, hiányánál értelmes hibaüzenet. WebCrypto streaming digest
  hiánya miatt (2 GB-os fájl nem fér memóriába) a `crypto.subtle.digest`
  teljes-fájlos útja TILOS.
- **DoD:** kontraktus- és protokoll-tesztek az új mezőkre; mismatch-teszt
  (szándékosan rossz expected_checksum → failed + hibakód); hash-wasm nélküli
  és melletti vitest futás.

#### T7.2 Kliens-oldali kép-előnézet hook

- `packages/core/src/preview.ts`: `createPreview(file, opts?): Promise<PreviewHandle>`
  — képeknél `URL.createObjectURL` (gyors út), `opts.thumbnail = {maxWidth, maxHeight}`
  esetén canvas-lekicsinyítés. `PreviewHandle = { url: string; revoke(): void }`.
- Az `UploadOptions.preview: boolean | ThumbnailOptions` bekapcsolásával az
  `Uploader` maga hozza létre; az `UploadState` új mezője: `previewUrl: string | null`.
  A `revoke()` automatikus: `UploadManager.remove()` híváskor (memory-leak guard —
  teszt kötelező rá).
- Nem-kép fájlnál `previewUrl` `null` marad, hiba nélkül.
- Wrapperek: a `UploadTray` és a `ChunkDropzone` megjeleníti, ha van.
- **DoD:** vitest happy-dom alatt (createObjectURL mock), revoke-lifecycle teszt.

#### T7.3 IndexedDB auto-resume (fájl-újrakiválasztás nélkül)

A v1.0 fingerprint-resume-ja megköveteli, hogy a user újra kiválassza a fájlt.
Ez a ticket ezt is kiváltja — **opt-in**, mert File-objektumok IndexedDB-ben
tárolása böngészőfüggő viselkedésű.

- `config.persistFiles: boolean` (default `false`). Bekapcsolva az `Uploader`
  induláskor a `File`-t IndexedDB-be írja (`chunky-files` store, kulcs:
  fingerprint), terminális állapotnál törli.
- `UploadManager.restorable(): Promise<RestorableUpload[]>` — IndexedDB-bejegyzések,
  amelyekhez a backend status endpointja élő, nem-terminális uploadot igazol
  (a halott bejegyzéseket a hívás takarítja). `RestorableUpload = { fingerprint,
  fileName, fileSize, uploadId, progress }`.
- `UploadManager.restore(fingerprint): Uploader` — a tárolt File-lal és a
  szerver szerinti hiányzó chunkokkal folytat.
- Wrapper: `useUploads()` kiegészül `restorable` listával; a `UploadTray`
  "Folytatás" akciót kap.
- Kvóta/serializálási hibánál (Safari private mode stb.) csendes fallback a
  v1.0-s viselkedésre + `console.warn` — az upload SOHA nem bukhat el a
  perzisztálás miatt (teszt: IndexedDB-hiba szimulálva, upload zölden lefut).
- **DoD:** fake-indexeddb alapú vitest suite (persist, restore, terminál-törlés,
  hiba-fallback).

#### T7.4 Direct-to-S3 multipart transport

A legnagyobb tétel: a chunkok a böngészőből közvetlenül S3-ra mennek presigned
URL-ekkel, a Laravel csak orchesztrál. Külön PR-ben, a többi T7.x után.

- **Koncepció:** új `transport` fogalom. `server` (a v1.0 útvonal, default) |
  `s3`. Profilonként kapcsolható: `UploadProfile::transport(): Transport`
  (default `Transport::Server`). Az S3 transport előfeltétele:
  `league/flysystem-aws-s3-v3` (composer `suggest`, futásidejű ellenőrzés
  értelmes hibával; a profil diskje S3-driveres kell legyen).
- **Backend — új port:** `MultipartStore` (`Adapters/S3/S3MultipartStore`):

  ```php
  interface MultipartStore
  {
      public function create(string $disk, string $path, ?string $mimeType): string; // providerUploadId
      /** @param list<int> $partNumbers  @return array<int, string>  partNumber => presigned URL */
      public function signParts(string $disk, string $path, string $providerUploadId, array $partNumbers, int $ttlSeconds): array;
      /** @param array<int, string> $etags  partNumber => ETag */
      public function complete(string $disk, string $path, string $providerUploadId, array $etags): void;
      public function abort(string $disk, string $path, string $providerUploadId): void;
  }
  ```

- **Wire-protokoll (additív):**
  - Initiate válasz S3 transportnál: `"transport": "s3"` + `"part_size"` mező
    (⚠️ S3-megkötés: minden part ≥ 5 MB az utolsó kivételével, part-számozás
    **1-alapú**, max 10 000 part — a `ChunkCalculator` S3-módban ehhez igazít).
    Server transportnál a mező `"transport": "server"` (a v1.0 kliens ignorálja).
  - Új végpont: `POST {prefix}/upload/{id}/sign` `{part_numbers: [1,2,3]}` →
    `{urls: {"1": "https://...", ...}, expires_in: 3600}` — batch-elt aláírás,
    hogy ne kelljen partonként round-trip.
  - Új végpont: `POST {prefix}/upload/{id}/complete` `{parts: [{part_number, etag}]}`
    → a szerver `CompleteMultipartUpload`-ot hív, MAJD a normál befejező út fut:
    ProfileStep (`completed()` hook, payload), completed-átmenet, események —
    a válasz a 3.2 szerinti completed séma. Assembly-pipeline merge-lépése S3-nál
    kimarad (az S3 fűz össze), a Preflight/Integrity lépések transzport-tudatosak.
  - Cancel S3-uploadnál: `AbortMultipartUpload` + normál cancel-átmenet.
    A cleanup command a lejárt S3-uploadokra is abort-ot hív (árva multipart
    upload = néma S3-költség — erre külön teszt).
- **Frontend:** `Uploader` alá transport-absztrakció: `ServerTransport`
  (a meglévő kód kiemelve) és `S3Transport` (sign-batch lekérés, part PUT
  **XHR-rel** — a fetch nem ad upload-progress eventet —, ETag gyűjtés a
  válasz-headerből, complete hívás). ⚠️ Az ETag-header olvasásához az S3 bucket
  CORS konfigurációjában `ExposeHeaders: ["ETag"]` kell — ezt a README S3
  szakasza ÉS a `chunky:doctor` is ellenőrzendő tételként kapja meg.
  Presigned URL lejáratnál (403) a transport új sign-batch-et kér és retryol.
  A publikus `Uploader` API (4.1) NEM változik — a transport belső részlet.
- **Tesztek:** backend — `MultipartStore` fake + kontraktus-teszt az
  S3MultipartStore-ra mockolt S3 klienssel (create/sign/complete/abort hívás-
  szekvencia assert); protokoll-tesztek a két új végpontra + állapot-guardok
  (sign/complete csak `uploading` státuszban, idegen user 403). Frontend —
  `S3Transport` vitest XHR-mockkal (progress, ETag-gyűjtés, 403 → resign-retry).
- **DoD:** teljes suite zöld; server transport viselkedése bitre változatlan
  (a v1.0 protokoll-tesztek érintetlenül zöldek); README S3-szekció + doctor-
  ellenőrzések megírva.

**7. fázis kapuja:** minden T7.x külön committal/PR-rel; az `openapi.yaml`
diffje csak additív; verzió `1.1.0`, CHANGELOG-bejegyzéssel.

## 6. Zárójelentés formátuma (minden fázis után)

```
✅/⚠️/⛔ <fázis neve>
- Létrehozott/módosított fájlok: ...
- Futtatott ellenőrzések + eredmény: composer ci ✅, pnpm -r test ✅, ...
- Eltérések a tervtől (ha volt, indokkal): ...
- Nyitott kérdések / kockázatok: ...
- Következő lépés: ...
```

Eltérés a tervtől csak akkor megengedett, ha a terv ellentmond a valóságnak
(pl. egy előírt szignatúra nem implementálható) — ilyenkor a legkisebb eltérést
válaszd, és a zárójelentésben EXPLICIT jelezd. A publikus API-t (2.2, 2.3, 3.,
4.1 szakaszok) tilos csendben megváltoztatni.
