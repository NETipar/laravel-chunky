# Behavior Inventory — 0.x → v1.0

> **Phase 0 / T0.2 artifact.** A 0.x teszt-suite (28 fájl, ~141 teszteset)
> viselkedési leltára. Minden teszteset besorolva: **KEEP** (a garancia v1-ben is
> él, akár másik osztályban), **CHANGED** (megfigyelhető viselkedés/API változik),
> **DROP** (a feature megszűnik). Ez a lista a v1 teszt-suite munkalistája; a
> 6. fázis (T6.3) záró-auditja minden KEEP/CHANGED tételhez v1 tesztet követel.
>
> A `v1_home` a v1 megfelelő helyét adja meg a
> [v1-implementation-plan.md](v1-implementation-plan.md) alapján.

---

## A. Terv-eltérések és nyitott döntések (a spec-fagyasztás közben felszínre került)

A tesztek felolvasása több olyan pontot tárt fel, ahol a lefagyasztott
[protocol.md](protocol.md) / a terv eltért a 0.x bejáratott viselkedésétől.
Az alábbi **négy döntés 2026-07-11-én megszületett** (mind az ajánlott, biztonságosabb
opció), és át van vezetve a [protocol.md](protocol.md) / [openapi.yaml](openapi.yaml) /
[v1-implementation-plan.md](v1-implementation-plan.md) fájlokba. A többi (ℹ️) a tervben
már tudatosan eldöntött törés, itt csak a nyomon követhetőségért.

### ✅ D1 — Non-owner olvasás/cancel → **404** (eldöntve; biztonsági)
A 0.x szándékosan **404**-et ad, ha nem-tulajdonos kéri le vagy szakítja meg egy
másik user uploadját (`AuthorizationTest`: "blocks non-owners … 404"), hogy ne
szivárogjon ki, mely upload-ID-k léteznek. A chunk-POST viszont **403**.
A [protocol.md](protocol.md) jelenleg minden non-owner esetre `403 unauthorized`-t
ír. **Javaslat:** tartsuk meg a 0.x mintát — status/cancel non-owner → **404
upload_not_found**, chunk non-owner → **403**. Ez tudatos anti-enumeration döntés.

### ✅ D2 — Globális `metadata` méret-korlát megtartva (eldöntve; DoS-védelem)
A 0.x globálisan korlátozza a metadata kulcsok számát (`chunky.metadata.max_keys`,
`InitiateBatchUploadTest`: "caps the metadata array size"). A v1 config (2.4)-ben
nincs `metadata` szekció → a korlát elveszne, és egy kliens tetszőlegesen nagy
metadata-t küldhetne (a trackerbe/DB-be írva). **Javaslat:** vigyük vissza egy
`limits.metadata_max_keys` kulcsként (default pl. 50), a request-validációban.

### ✅ D3 — Broadcast: magas-frekvenciájú események alapból kihagyva (eldöntve)
A 0.x finomhangolt: a completion-események (`UploadCompleted`, `BatchCompleted`,
…) **default-on**, a magas-frekvenciájúak (`ChunkUploaded`, `BatchInitiated`)
**default-off** (`AbstractChunkyEventTest`). A v1 egyszerűsített modellje
(`broadcasting.enabled` + üres `except`) bekapcsoláskor **mindent** broadcastolna,
a zajos `ChunkUploaded`-et is. **Javaslat:** a v1 `broadcasting.except` default
tartalmazza a magas-frekvenciájú eseményeket (`ChunkUploaded`, `ChunkUploadFailed`,
`BatchInitiated`), így a bekapcsolás alapból a hasznos eseményeket adja.

### ✅ D4 — Túl nagy `file_size` → **422 validation_failed** (eldöntve; nincs 413)
A 0.x a túl nagy bejelentett `file_size`-ra **422 validációs hibát** ad
(`InitiateUploadTest`). A terv **413**-at ír. Mivel a `file_size` egy bejelentett
egész (nem a tényleges kérés-törzs mérete), a **422** szemantikailag pontosabb és
kevésbé meglepő. **Javaslat:** legyen 422 `validation_failed`, a `413`-at
elhagyjuk a hibakód-enumból. (Ha marad 413, az is védhető — de döntsük el most.)

### ℹ️ Tudatos törések (a tervben már eldöntve, itt csak nyomon követve)
- **`is_complete` bool → `status` string** a chunk-válaszban (D: protocol §2).
- **Sync záró-chunk most a teljes eredményt adja** (`file`+`payload`), nem csak jelzést.
- **Cancel: `204` → `200 {status:cancelled}`** (protocol §4).
- **Rossz checksum: `500` → `422 checksum_mismatch`** (protocol hibakódok).
- **`context` paraméter → `profile`**; a context-registry → `UploadProfile` osztályok.
- **`UploadStatus`: `Expired` eset megszűnik**; a lejárat timestamp + cleanup→`cancelled`.
  Új `Uploading` eset (a 0.x chunkolás közben `pending`-et jelzett, a v1 `uploading`-ot).
- **`BatchStatus`: `Processing`→`in_progress`, `Expired`→(nincs; cleanup→`cancelled`)**, + `cancelled`.
- **`broadcasting.expose_internal_paths` opt-in megszűnik** — a public projekció mindig strip-el.
- **Metrics alrendszer teljesen megszűnik** (lásd `MetricsTest` DROP-jai).
- **Config-kulcsok átnevezése**: `storage.temp_directory`/`final_directory` → `chunks.directory`;
  `lifecycle.expiration_minutes` → `limits.expiration_hours`; `chunks.verify_integrity` →
  `integrity.required`; `lifecycle.assembly_stale_after_minutes` → `assembly.stale_claim_seconds`.
- **`assembly.tries`/`assembly.backoff`** — a v1 config 2.4-ből kimaradt; **vissza kell tenni**
  (a job retry-konfigja hasznos), ez mechanikus pótlás, nem külön döntés.

---

## B. Teljes teszteset-leltár (fájlonként)

### tests/Feature/InitiateUploadTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| initiates an upload via the API | KEEP | `POST /upload` (InitiateUploadController) | 201 + upload_id/chunk_size/total_chunks; UploadInitiated event. |
| validates required fields | KEEP | InitiateRequest | file_name+file_size kötelező → 422. |
| validates file size must be positive | KEEP | InitiateRequest | file_size > 0. |
| validates max file size when configured | CHANGED | `limits.max_file_size` + profil `maxFileSize()` | Hibakód: D4 (422 vs 413). |
| validates allowed mime types when configured | CHANGED | profil `rules()` | Globális `limits.allowed_mimes` megszűnik → profil-szintű mime-szabály. |
| accepts metadata | KEEP | InitiateRequest metadata | Lásd D2 (méret-korlát). |
| accepts context parameter | CHANGED | `profile` mező | context → profile átnevezés. |
| rejects unregistered context | CHANGED | 422 `profile_not_found` | context → profile. |
| rejects path-traversal sequences in file_name | KEEP | InitiateRequest validáció + ChunkStore basename-guard | 6 hostile eset → 422. |
| accepts unicode and normal punctuation in file_name | KEEP | InitiateRequest validáció | 4 legit név → 201. |
| merges context validation rules | CHANGED | profil `rules()` | context-rules merge → profil rules. |

### tests/Feature/UploadChunkTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| uploads a chunk successfully | CHANGED | `POST /upload/{id}/chunks` | Válasz `is_complete` → `status:"uploading"` + uploaded_count/progress; ChunkUploaded event KEEP. |
| validates required chunk fields | KEEP | UploadChunkRequest | chunk+chunk_index kötelező → 422. |
| rejects chunk with invalid checksum | CHANGED | VerifyChunkIntegrity middleware | 500 → **422 checksum_mismatch**. |
| allows chunk without checksum when integrity verification is enabled | KEEP | integrity middleware | checksum opcionális; hiányában átmegy. |
| rejects a chunk_index above total_chunks | KEEP | UploadChunkRequest | → 422 (`chunk_index_out_of_range`). |
| rejects late chunks against a cancelled upload with HTTP 409 | KEEP | UploadService állapot-guard | 409 `invalid_state`; üzenet-szöveg CHANGED (envelope). |
| skips checksum verification when disabled | CHANGED | `integrity.required=false` | config-kulcs átnevezés (`chunks.verify_integrity` → `integrity`). |

### tests/Feature/UploadStatusTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| returns upload status | CHANGED | `GET /upload/{id}` | Séma bővül (`file`/`payload` completed-nél); `status` "pending"→"uploading" chunkolás közben. Public strip KEEP. |
| returns 404 for non-existent upload | KEEP | 404 `upload_not_found` | Envelope CHANGED. |

### tests/Feature/CancelUploadTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| cancels an in-progress upload via DELETE and removes the temp chunks | CHANGED | `DELETE /upload/{id}` + ChunkStore purge | 204 → **200 {status:cancelled}**; chunk-törlés KEEP. |
| returns 404 when cancelling an unknown upload | KEEP | 404 `upload_not_found` | |
| returns 404 when cancelling an already completed upload | CHANGED | 409 `invalid_state` | Terminális → 409 (nem 404). |
| cancel() returns false when called twice | CHANGED | `UploadService::cancel` idempotens | Kétszeri cancel → 2. hívás 200 idempotens (nem false/409), lásd protocol §4. |

### tests/Feature/InitiateBatchUploadTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| uses the batch context for validation, not the request context | CHANGED | BatchService + profil | context-bypass védelem KEEP; context→profile. |
| rejects new uploads on a Completed batch with a 422 validation error | CHANGED | `409 invalid_state` | Terminális batch → 409 (nem 422 batch-error). |
| rejects new uploads on a PartiallyCompleted batch | CHANGED | `409 invalid_state` | Ugyanaz. |
| accepts uploads on a Pending batch normally | KEEP | `POST /batch/{id}/upload` | 201 + upload_id. |
| caps the metadata array size to chunky.metadata.max_keys | CHANGED | `limits.metadata_max_keys` | Lásd **D2** — a korlát megtartandó, config-kulcs átnevezve. |

### tests/Feature/IdempotencyTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| replays the cached response when the same Idempotency-Key is retried | KEEP | UploadChunkController idempotency | Byte-pontos replay; ChunkUploaded nem dispatchel újra. |
| replays based on the chunk checksum when no Idempotency-Key is sent | KEEP | checksum-fallback kulcs | |

### tests/Feature/AssembleFileJobTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| marks upload as failed and dispatches UploadFailed when save callback throws | CHANGED | AssemblyPipeline `ProfileStep` kompenzáció | save-callback → profil `completed()` hook; failed-átmenet+event KEEP. |
| marks the batch as failed when save callback throws | KEEP | BatchService increment(Failed) | |
| completes successfully when save callback succeeds | CHANGED | AssemblyPipeline happy path | completed()-hook megkapja a completed rekordot; UploadCompleted KEEP. |
| dispatches UploadFailed and marks batch failed in failed() callback | KEEP | AssembleFileJob::failed() | queue-halál → failed. |
| does not flip Completed back to Failed when failed() runs after a successful handle() | KEEP | terminál-guard (CAS) | Kritikus crash-window garancia. |
| does not double-dispatch UploadFailed when failed() runs after handle() already failed | KEEP | terminál-guard (CAS) | Egyszeri UploadFailed. |

### tests/Feature/AssembleClaimTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| claimForAssembly transitions Pending to Assembling exactly once | CHANGED | `UploadRepository::transition` CAS | Pending→Assembling; a v1-ben Uploading→Assembling (mert van Uploading). |
| second AssembleFileJob is a no-op when first one already claimed | KEEP | claim CAS | Egyszeri UploadCompleted. |
| claimForAssembly returns false for missing upload | KEEP | transition false | |
| claimForAssembly takes over a stale Assembling claim after the configured threshold | KEEP | transition guard (`claimed_before`) + `assembly.stale_claim_seconds` | Config-kulcs perc→másodperc CHANGED. |
| AssembleFileJob retried after a simulated crash recovers and emits UploadCompleted | KEEP | claim takeover | |
| expiredUploadIds includes stale Assembling rows but skips fresh ones | KEEP | `UploadRepository::findExpired` | |

### tests/Feature/AssembleJobConfigTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| uses the default queue connection when chunky.assembly.connection is null | KEEP | `assembly.connection` | |
| routes the assemble job to the configured connection | KEEP | `assembly.connection` | |
| treats an empty-string connection as unset | KEEP | `assembly.connection` normalizálás | v0.22.6 fix — megtartandó. |
| routes the assemble job to the configured queue | KEEP | `assembly.queue` | |
| honours both connection and queue when both are configured | KEEP | `assembly.*` | |
| reads tries / backoff / timeout from chunky.assembly config | CHANGED | `assembly.tries/backoff/timeout` | tries/backoff visszateendő a v1 configba (lásd A. szekció vége). |

### tests/Feature/DefaultChunkHandlerTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| stores a chunk to the configured disk | KEEP | `ChunkStore::put` | Útvonal `chunky/temp/...` → `chunks.directory`. |
| stores multiple chunks | KEEP | `ChunkStore::put` | |
| assembles chunks into a single file | CHANGED | `AssemblyPipeline` MergeStep | Végleges útvonal a profil `directory()`-ból (nem fix `chunky/uploads`). |
| cleans up temp directory after assembly | KEEP | `ChunkStore::purge` / CleanupStep | |
| assembles chunks via filesystem streams without relying on disk()->path() | KEEP | MergeStep stream-alapú | Nem-local disk kompatibilitás. |
| throws when a chunk is missing during assembly | KEEP | MergeStep integritás | |

### tests/Feature/DatabaseTrackerTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| initiates an upload and creates a database record | KEEP | `DatabaseUploadRepository::create` | Kontraktus-teszt. |
| marks chunks as uploaded | KEEP | `markChunk` | rendezett indexek. |
| returns the freshly updated metadata from markChunkUploaded | CHANGED | `markChunk(): ChunkProgress` | Visszatérés UploadRecord→ChunkProgress. |
| does not duplicate chunk indices | KEEP | `markChunk` idempotencia | Kontraktus-teszt. |
| detects completion when all chunks uploaded | KEEP | `ChunkProgress.isComplete` | |
| returns metadata | KEEP | `find(): ?UploadRecord` | |
| returns null metadata for non-existent upload | KEEP | `find` → null | |
| expires an upload | CHANGED | `expires_at` timestamp | Nincs `Expired` státusz; a lejárt rekordot cleanup viszi cancelledbe. |
| updates status and final path | CHANGED | `transition(..., ['final_path'=>...])` | completed_at KEEP. |
| throws exception for non-existent upload on operations | CHANGED | `markChunk` visszatérése/hibája | Kontraktus dönti el (exception vagy null-guard). |
| throws exception for expired upload | KEEP | UploadExpiredException | 410 a HTTP-rétegben. |
| wraps markChunkUploaded in a database transaction to prevent racing writes | KEEP | `markChunk` lockForUpdate | Atomicitás — kontraktus/konkurencia-teszt. |
| persists every chunk index when markChunkUploaded is called repeatedly | KEEP | `markChunk` | |

### tests/Feature/FilesystemTrackerTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| initiates and stores metadata as JSON file | KEEP | `FilesystemUploadRepository` (JsonFileStore) | Kontraktus-teszt filesystem driveren. |
| marks chunks as uploaded | KEEP | `markChunk` | |
| does not duplicate chunk indices | KEEP | `markChunk` idempotencia | |
| detects completion | KEEP | `ChunkProgress.isComplete` | |
| returns metadata | KEEP | `find` | |
| returns null for non-existent upload | KEEP | `find` → null | |
| expires an upload | CHANGED | `expires_at` | Mint DB — nincs Expired státusz. |
| throws exception for non-existent upload on operations | CHANGED | kontraktus | Mint DB. |
| throws exception for expired upload | KEEP | UploadExpiredException | |

*(A fenti két blokk a v1-ben EGY közös kontraktus-suite, dataset-tel `database`
és `filesystem` adapterre — ez szünteti meg a driver-driftet.)*

### tests/Feature/FilesystemTrackerBootTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| boots successfully when the configured disk exposes a local path | KEEP | boot-guard (`ChunkyConfig` + LockProvider) | |
| refuses to boot on a non-local disk under flock mode | KEEP | boot-guard | flock + non-local → hiba. |
| boots successfully on a non-local disk when chunky.locking.driver is cache | KEEP | boot-guard | cache-lock non-local OK. |

### tests/Feature/LockDriverCompatTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| rejects chunky.lock_driver=cache with cache.default=array | KEEP | boot-guard | cache-lock atomicitás. |
| rejects chunky.lock_driver=cache with cache.default=file | KEEP | boot-guard | |
| accepts chunky.lock_driver=cache with cache.default=redis | KEEP | boot-guard | |
| does not check the cache driver under the default flock locking | KEEP | boot-guard | |

### tests/Feature/CleanupCommandTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| removes expired uploads and their chunks | KEEP | `chunky:cleanup` + `findExpired` + ChunkStore purge | |
| lists removable uploads in dry-run mode without deleting | KEEP | `chunky:cleanup --dry-run` | |
| reports nothing to remove when no uploads have expired | KEEP | `chunky:cleanup` | |

### tests/Feature/AuthorizationTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| blocks non-owners from reading another user upload status | KEEP | Authorizer + **404** | Lásd **D1** — 404 (anti-enumeration), nem 403. |
| blocks non-owners from cancelling another user upload | KEEP | Authorizer + **404** | Lásd **D1**. |
| blocks non-owners from POSTing chunks to another user upload | KEEP | FormRequest authorize → **403** | |
| lets the owner access their own upload normally | KEEP | Authorizer | |
| keeps anonymous uploads accessible without auth (backward compat) | KEEP | Authorizer (nincs user_id → nyitott) | |

### tests/Feature/ConfigTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| loads the default configuration | CHANGED | `ChunkyConfig` + `config/chunky.php` (2.4) | Sok kulcs átnevezve (lásd A. szekció). Új asserttel a v1 kulcsokra. |
| registers the chunky manager singleton | CHANGED | `UploadService`/`BatchService` binding | Nincs "ChunkyManager" isten-osztály; facade megmarad. |
| binds the correct tracker based on config | CHANGED | `tracker` enum → repository binding | ServiceProvider match a portokra. |
| registers routes with configured prefix | KEEP | 8 route, `routes.prefix` | 8 route marad. |

### tests/Unit/ChunkyManagerTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| registers and retrieves contexts | CHANGED | `ProfileRegistry` | context → UploadProfile osztály. |
| returns empty rules for unregistered context | CHANGED | ProfileRegistry | |
| registers and retrieves save callbacks | CHANGED | profil `completed()` hook | |
| returns null save callback for context without save | CHANGED | profil default `completed()` no-op | |
| registers a class-based context | CHANGED | UploadProfile osztály (ez lesz az alap) | A class-based minta a v1 default-ja. |
| registers a simple context with validation and save callback | KEEP | `Chunky::simple()` → SimpleProfile | simple() marad; rules-generálás (max_size→max, mimes→in) KEEP. |
| registers a simple context without options | KEEP | `Chunky::simple()` | |
| initiates an upload and dispatches event | KEEP | `UploadService::initiate` → InitiateResult | UploadInitiated event. |

### tests/Unit/UploadMetadataTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| calculates progress from uploaded chunks | KEEP | `UploadRecord::progress()` | |
| converts to array and back | CHANGED | `UploadRecord::toArray/fromArray` | +fingerprint/expires_at/claimed_at/result_payload mezők. |
| creates a new instance with updated status via withStatus | KEEP | `UploadRecord` copy-on-write | |
| strips disk, final_path and user_id from the public array payload | KEEP | `toPublicArray()` | Kritikus a broadcast/status-sanitizáláshoz. |
| defaults to pending status when constructing from array | KEEP | `fromArray` default | |

### tests/Unit/BatchMetadataTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| progress() includes failed files (terminal-state semantics) | KEEP | `BatchRecord::progress()` | |
| progress() reports 0 when no files | KEEP | `BatchRecord::progress()` | osztás-nullával guard. |
| successProgress() decouples from progress() for partial batches | KEEP | `BatchRecord::successProgress()` | |
| accepts CarbonImmutable for expiresAt | KEEP | `BatchRecord` datetime típus | v0.22.0 regresszió — DateTimeImmutable a v1-ben. |
| accepts mutable Carbon for expiresAt | KEEP | `BatchRecord` | |

### tests/Unit/AbstractChunkyEventTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| respects DEFAULT_BROADCAST_EVENTS when published config omits the events map | CHANGED | `broadcasting.enabled` + `except` | Lásd **D3** — a magas-frekvenciás default-off logika `except`-alapértékbe költözik. mergeConfigFrom-akna megszűnik (enabled default false). |
| respects an explicit override in the events map | CHANGED | `broadcasting.except` | events-map → except-lista. |
| returns false when broadcasting is globally disabled | KEEP | `broadcasting.enabled=false` | |

### tests/Unit/BroadcastSanitizationTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| UploadCompleted broadcastWith strips disk and finalPath by default | KEEP | `toPublicArray()` + verziózott payload | Kulcsnevek camelCase→ a v1 payload snake_case + `v:1`. |
| UploadCompleted broadcastWith includes disk and finalPath when opted in | DROP | — | `expose_internal_paths` opt-in megszűnik; a public projekció MINDIG strip-el. |
| UploadFailed broadcastWith strips disk by default | KEEP | `toPublicArray()` + reason | |
| UploadFailed broadcastWith includes disk when opted in | DROP | — | Mint fent — opt-in expose megszűnik. |

### tests/Unit/MetricsTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| invokes the configured callback with the payload | DROP | — | Metrics alrendszer megszűnik (nincs a v1 struktúrában). Megfigyelhetőség → sima event-listenerek. |
| is a no-op when no callback is configured | DROP | — | |
| swallows callback exceptions so observability cannot break the pipeline | DROP | — | |
| resolves a class-string handler through the container and calls __invoke | DROP | — | |
| falls back to handle() on a non-invokable class | DROP | — | |
| resolves the handler each time so the container can manage its lifecycle | DROP | — | |
| swallows class-handler exceptions just like callable ones | DROP | — | |
| swallows the InvalidArgumentException when a handler class is not invokable nor has handle() | DROP | — | |

### tests/Unit/ChunkCalculatorTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| calculates total chunks correctly | KEEP | `ChunkCalculator::totalChunks` | ceil-osztás. |
| returns chunk size from config or override | KEEP | `ChunkCalculator::chunkSize` | ChunkyConfig-ból. |
| casts a string chunk size from env-backed config to int | KEEP | ChunkyConfig típuskényszerítés | v0.22.6 fix — megtartandó. |
| calculates progress percentage | KEEP | `ChunkCalculator::progress` | 2 tizedes. |
| returns zero progress when total chunks is zero | KEEP | `ChunkCalculator::progress` | |

### tests/Unit/EnumTerminalTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| UploadStatus::isTerminal classifies each case | CHANGED | `UploadStatus::isTerminal` | Esethalmaz változik: -Expired, +Uploading. |
| BatchStatus::isTerminal classifies each case | CHANGED | `BatchStatus::isTerminal` | Processing→in_progress, -Expired, +Cancelled. |

### tests/Unit/UploadStatusTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| has expected cases | CHANGED | `UploadStatus` (6 eset) | Más összetétel: pending/uploading/assembling/completed/failed/cancelled. |
| can be created from string value | KEEP | `UploadStatus::from` | |
| returns null for invalid value with tryFrom | KEEP | `UploadStatus::tryFrom` | |

### tests/Unit/ChunkIntegrityExceptionTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| creates checksum mismatch exception with correct message | KEEP | `ChunkIntegrityException::checksumMismatch` | |
| creates upload expired exception with correct message | KEEP | `UploadExpiredException::forUpload` | |

### tests/Unit/PathTraversalTest.php
| Teszteset | Kat. | v1 home | Megjegyzés |
|---|---|---|---|
| DefaultChunkHandler::assemble strips leading directories from a hostile file name (basename) | KEEP | AssemblyPipeline MoveStep basename-guard | |
| DefaultChunkHandler::assemble refuses dot-only file names that basename leaves empty | KEEP | MoveStep guard | `.`/`..` → invalid. |
| simple() context save callback strips leading directories from a hostile file name | CHANGED | SimpleProfile `completed()` basename-guard | context→profile. |
| simple() context save callback rejects dot-only file names | CHANGED | SimpleProfile guard | context→profile. |

---

## C. Összegzés

- **Tesztfájlok:** 28 · **Tesztesetek:** ~141.
- **Nagyságrendi bontás:** KEEP ~85, CHANGED ~40, DROP ~12 (10 Metrics + 2 broadcast expose opt-in).
- **A DROP-ok** kizárólag a Metrics alrendszerre és a `broadcasting.expose_internal_paths`
  opt-in expose-ra korlátozódnak — minden más viselkedés v1-ben KEEP vagy CHANGED.
- **Döntések (mind eldöntve 2026-07-11):** D1 → 404 (anti-enumeration), D2 → globális
  `limits.metadata_max_keys=50`, D3 → `broadcasting.except` default a magas-frekvenciájú
  eseményekkel, D4 → 422 (nincs 413). Átvezetve a [protocol.md](protocol.md),
  [openapi.yaml](openapi.yaml) és [v1-implementation-plan.md](v1-implementation-plan.md) fájlokba.

---

## D. Záró-audit (6. fázis) — a KEEP/CHANGED tételek v1 lefedettsége

A KEEP/CHANGED garanciák területenként, a lefedő v1 tesztekkel. Zöld
(179 PHP + 37 JS teszt).

| Terület (0.x KEEP/CHANGED) | v1 teszt |
|---|---|
| Repository CRUD, `markChunk` idempotencia, `transition` CAS, fingerprint/batch/expired lekérdezés | `tests/Contracts/*` mindkét adapteren (`Database*`/`Filesystem*RepositoryTest`) |
| Versenyhelyzetek: CAS-egy-győztes, chunk-akkumuláció, egyszeri claim, batch finalize-egyszer | `tests/Feature/RepositoryConcurrencyTest` (mindkét driver) |
| Initiate: validáció, max-size, metadata-cap, path-traversal, unicode, profil, fingerprint-resume | `tests/Feature/InitiateUploadTest` |
| Chunk: uploading-válasz, index-range, checksum-mismatch, late-chunk 409, idempotencia | `tests/Feature/{UploadChunkTest,IdempotencyTest}` |
| Sync assembly + `completed()` payload, queue-mód, assembly-hiba → failed | `tests/Feature/AssemblyTest` |
| Status public-sanitizálás, cancel (200/idempotens/409/404), authz 404/403/anon | `tests/Feature/{UploadStatusTest,CancelUploadTest,AuthorizationTest}` |
| Batch: initiate/status/cancel, terminális 409, batch-profil validáció, finalizálás | `tests/Feature/BatchTest` |
| Broadcast sanitizálás + `v:1` + gate | `tests/Feature/BroadcastSanitizationTest` |
| Cleanup (expired + chunks, dry-run, semmi), config-binding, 8 route, lock-driver compat | `tests/Feature/{CleanupCommandTest,ConfigTest,LockDriverCompatTest}` |
| Filesystem tracker teljes HTTP-életciklus (paritás) | `tests/Feature/FilesystemTrackerParityTest` |
| Domain: enum-átmenetek, DTO round-trip, ChunkCalculator (string→int), Fingerprint | `tests/Unit/*` |
| Frontend: EventEmitter sticky-replay, RetryPolicy, http-envelope, Uploader (sync/queue/resume/pause/cancel/retry), Batch, UploadManager, CompletionWatcher | `packages/core/src/*.test.ts` |
| Frontend unmount-túlélés (unmount = leiratkozás) | `packages/{vue3,react}/src/useUpload.test.*` |

**DROP-ok (nem igényelnek tesztet, szándékosan eltávolítva):** Metrics alrendszer,
`Expired` státusz, `broadcasting.expose_internal_paths` opt-in. Plusz a 0.x
„FS+non-local+flock boot-guard" — a v1-ben a lockolás elvált a tárolástól, így a
constraint tárgytalan (a `FilesystemTrackerParityTest` igazolja, hogy az FS
tracker működik).
