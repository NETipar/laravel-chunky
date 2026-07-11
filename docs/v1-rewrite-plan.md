# Laravel Chunky v1.0 — Újraírási terv (zöldmezős)

> Állapotfelmérés dátuma: 2026-07-11, a v0.22.6 kódbázis alapján.
> Cél: a package újraírása új alapokról, használhatóság (DX) és kódminőség mentén,
> a 0.x sorozat alatt felhalmozott tapasztalatok beépítésével.

---

## 0. Kiindulópont — mit tanultunk a 0.x-ből

A jelenlegi kódbázis nem romhalmaz (a v0.18-as refaktor után a manager vékony,
a phpstan-baseline 6 triviális bejegyzés), de a szerkezeti problémái olyanok,
amiket foltozgatással nem, csak újratervezéssel lehet rendbe tenni:

1. **Négy tracker, egy állapotgép.** A `DatabaseTracker` / `FilesystemTracker` /
   `DatabaseBatchTracker` / `FilesystemBatchTracker` ugyanazt az upload-életciklust
   implementálja kétszer-kétszer, két idiómában. A CHANGELOG tanúsága szerint a
   bugfixek rendre driverenként landoltak (stale-claim reclaim: DB `updated_at` vs
   FS `claimed_at`; batch-finalizálás ~60 sora szinte szó szerint duplikálva).
2. **Kontraktus-kerülő manager.** `ChunkyManager::cancelBatchUploads()` `instanceof
   DatabaseTracker`-t ellenőriz és közvetlenül Eloquent-et hív — filesystem driveren
   a batch-cancel **nem cancelli a tag-uploadokat**. A `UploadTracker` interfészből
   hiányzik a batch-tag lekérdezés, ezért a manager csal.
3. **Elárvult frontend absztrakció.** A core `EventEmitter` "single source of truth"-nak
   készült, publikus API-ként ki is került — de a `ChunkUploader` (617 sor) és a
   `BatchUploader` (608 sor) továbbra is egymástól függetlenül duplikálja az
   esemény-gépezetet és a `fetchJson`-t. A `src/*.d.ts` fájlok kézzel commitolt,
   v0.18 óta **elavult** másolatai a build outputnak.
4. **Szétkent completion-állapotgép.** Az `AssembleFileJob::handle()` + `failed()`
   párosban a claim / assemble / save / rollback / cleanup / batch-inkrement /
   event-dispatch crash-window logikája szét van terítve — minden versenyhelyzet-bug
   ide vezetett vissza (pause/cancel race, resume-vs-initiate, counter race).
5. **Config-merge aknamező.** A `mergeConfigFrom` csak top-level kulcsokat merge-öl:
   régi publikált config kinullázta a `broadcasting.events` térképet, és minden
   broadcast némán leállt. ~50 leaf-opció, 17 top-level namespace.

**Ami bevált, és koncepcionálisan megtartandó:**
immutábilis DTO-k, idempotency-key + checksum dedupe, `claimForAssembly` CAS
stale-takeoverrel, atomi batch-finalizálás lock-on kívüli event-dispatch-csel,
assembly-biztonság (staging file, disk-space preflight, méret-ellenőrzés,
path-traversal guard), boot-time config-validáció, vékony framework-wrapperek,
per-event broadcast opt-in, sticky terminal-event replay.

---

## 1. Célok és nem-célok

### Célok
- **5 perces quickstart**: `composer require` → `php artisan chunky:install` → működő upload.
- **Egyetlen állapotgép**: az upload-életciklus egy helyen, driverek csak tárolók.
- **Kontraktus-tesztelt driverek**: ugyanaz a teszt-suite fut minden driveren — a driver-drift kategóriaként szűnik meg.
- **Egységes frontend mag**: a batch a single-upload kompozíciója, nem duplikátuma.
- **PHPStan max, baseline nélkül; TS strict, kézi .d.ts nélkül.**
- Semver-tiszta **1.0.0** — innentől stabil publikus API.

### Nem-célok
- Wire-protokoll radikális újratervezése (a HTTP végpontok sémája jó, marad közel azonos).
- Új feature-ök (pl. párhuzamos multipart-S3 direkt upload) — v1.1+ téma.
- 0.x-szel bináris kompatibilitás. Lesz UPGRADE.md, de az 1.0 tiszta törés.

---

## 2. Támogatási mátrix (szűkítés = minőség)

| | 0.x | v1.0 |
|---|---|---|
| PHP | ^8.2 | **^8.3** (typed class constants, readonly-anonim, `Override`) |
| Laravel | 11 / 12 / 13 | **12 / 13** |
| Pest | 3 / 4 | **4** |
| PHPStan | baseline-nal | **level max, baseline nélkül** |
| Node/TS | vegyes | TS 5.x strict, csak generált típusok |

---

## 3. Backend architektúra

### 3.1 Rétegek (ports & adapters)

```
Http (controllerek, requestek, middleware)   ← vékony, csak transzport
   │
Application: UploadService / BatchService    ← use-case-ek, event-dispatch
   │
Domain: UploadLifecycle (állapotgép), Upload/Batch aggregátumok, DTO-k, enumok
   │
Ports: UploadRepository, BatchRepository, ChunkStore, LockProvider, Clock
   │
Adapters: Database*, Filesystem* repository; FlysystemChunkStore; Cache/Flock lock
```

**Kulcsdöntés:** az életciklus-logika (mikor milyen átmenet érvényes, mikor kell
claimelni, mikor számít stale-nek egy claim, hogyan finalizálódik a batch) a
**domain rétegben él, egyszer**. A repository interfész kicsi és buta:

```php
interface UploadRepository
{
    public function create(UploadRecord $record): void;
    public function find(string $uploadId): ?UploadRecord;
    public function findByBatch(string $batchId): array;   // ← a 0.x-ből hiányzó metódus
    public function markChunk(string $uploadId, int $index): ChunkProgress;
    /** Atomi compare-and-swap; false, ha a from-állapot közben elmozdult. */
    public function transition(string $uploadId, UploadStatus $from, UploadStatus $to, array $attrs = []): bool;
    public function delete(string $uploadId): void;
}
```

Minden versenyhelyzet-érzékeny művelet a `transition()` CAS-primitívre épül —
a claim, a cancel-vs-complete, a resume mind ugyanazt az egy atomi eszközt
használja, driverenkénti egyedi lock-koreográfia helyett.

### 3.2 Explicit állapotgép

```php
enum UploadStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Assembling = 'assembling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array { /* átmeneti tábla egy helyen */ }
    public function isTerminal(): bool;
}
```

Az `AssembleFileJob` vékony lesz: claimel (CAS), meghív egy `AssemblyPipeline`-t
(preflight → stream-merge → integrity-check → move → profile-save → cleanup),
és a pipeline lépései deklarálják a saját rollback-jüket. A `failed()` nem
duplikálja a guard-okat — a pipeline kompenzációja fut le.

### 3.3 Lock: egy absztrakció

A 0.x-ben a flock/cache-lock plumbing ~60 sora kétszer van copy-paste-elve.
V1-ben egyetlen `LockProvider` port (`withLock(string $key, Closure $fn)`),
két adapterrel (cache-lock, flock), és a boot-time kompatibilitás-guard
(local disk + multi-server → hiba) megmarad.

### 3.4 Batch = kompozíció

A batch nem külön párhuzamos univerzum: a `Batch` aggregátum upload-ID-k
halmaza + számlálók. A finalizálási logika (completed+failed == total →
Completed/PartiallyCompleted, event lock-on kívül) **egy** helyen, a
`BatchService`-ben él; a repository csak atomi inkrementet ad. A
`cancelBatch` a `findByBatch()`-en keresztül minden driveren cancelli a tagokat.

### 3.5 Kontextusok → Upload profilok

A callback-registry helyett osztály-alapú, IDE-barát, önállóan tesztelhető profilok:

```php
class AvatarProfile extends UploadProfile
{
    public function rules(): array { return ['file' => ['image', 'max:10240']]; }
    public function disk(): string { return 'public'; }
    public function directory(UploadContext $ctx): string { return "avatars/{$ctx->user->id}"; }
    public function authorize(UploadContext $ctx): bool { return $ctx->user !== null; }
    public function completed(CompletedUpload $upload): void { /* save hook */ }
}
```

Regisztráció: config `profiles` tömb VAGY auto-discovery attribútummal.
A `Chunky::simple('dir')` gyorsút megmarad. A `make:chunky-profile` generátor
lecseréli a mostani `make:context`-et.

### 3.6 Config: kevesebb, validált, merge-biztos

- Cél: ~50 leaf-opcióról **~25-re**, egy szinttel laposabb szerkezettel.
- Boot-kor a nyers array egy `ChunkyConfig` readonly objektummá parszolódik,
  amely **validál és értelmes hibával elszáll** (a mostani assert-ek utódja).
  A kódban soha nem `config('chunky.x.y')`, hanem injektált `ChunkyConfig`.
- A broadcast-térkép footgun megszűnik: eseményenkénti opt-out lista helyett
  `broadcasting.enabled` + `broadcasting.except` (üres default) — publikált
  régi config nem tud némán mindent kikapcsolni.

### 3.7 Események

A 11 event és a broadcast-payload sanitizálás bevált — marad, de:
- közös `ChunkyEvent` interface + egy `BroadcastsChunkyEvent` trait (absztrakt ősosztály helyett),
- a payload-séma verziózott (`v: 1`), hogy a frontend `CompletionWatcher` stabilan tudjon rá építeni.

### 3.8 HTTP réteg

Marad a 8 invokable controller + FormRequest + integrity middleware minta
(ez jó). Változás:
- A wire-protokoll **írott kontraktus** lesz: `docs/protocol.md` + OpenAPI yaml
  a repóban; a Pest suite erre asserttel (contract snapshot-tesztek).
- Egységes error-envelope (`{error: {code, message}}`) gépi feldolgozásra
  alkalmas `code`-dal — a frontend retry-döntései ne string-matchen múljanak.

---

## 4. Frontend architektúra (packages/)

### 4.1 Egy motor, kompozíció

```
core/
  src/
    Uploader.ts          — EGY osztály: fájl → chunkolás → retry → lifecycle
    Batch.ts             — Uploader-ek kompozíciója + aggregált progress/finalizálás
    CompletionWatcher.ts — broadcast + adaptív polling reconcile (marad)
    EventEmitter.ts      — sticky-replay; MINDEN osztály ezt használja
    RetryPolicy.ts
    http.ts              — fetchJson egyszer, error-envelope értelmezéssel
    config.ts / types.ts
```

- A `BatchUploader` 608 sorából a chunk-szintű logika törlődik: a `Batch` csak
  `Uploader` példányokat komponál és aggregál.
- **Nincs mutable publikus mező** (`progress`, `isUploading` törölve): egyetlen
  immutábilis `getState(): UploadState` snapshot + `subscribe()`. A wrapperek
  ebből csinálnak reaktivitást.
- `.d.ts` **csak** build-output (`types/`), a `src/*.d.ts` másolatok törölve;
  CI-ben `attw`/`publint` ellenőrzi a package exports helyességét.

### 4.2 Wrapperek

A jelenlegi stratégia (vékony adapterek) marad:
- `vue3`: `useUpload`, `useBatchUpload` → `shallowRef` + subscribe; headless komponensek maradnak.
- `react`: `useSyncExternalStore`-alapú bindolás (a snapshot+subscribe modell erre pontosan illik).
- `alpine`: komponens-glue a core felett.
- `livewire`: a PHP komponens a core JS-t használja, nem saját protokoll-implementációt.

### 4.3 Verziózás

Backend és npm csomagok **lockstep** verzióban maradnak (a 0.22.5 re-sync
incidens tanulsága), de a release-script a v1-ben egyetlen forrásból
(`composer.json` version) derivál mindent.

---

## 5. Minőségi kapuk (a rewrite definíciós része, nem utólagos ráadás)

| Kapu | Eszköz | Szabály |
|---|---|---|
| Statikus analízis | PHPStan level max | baseline tilos |
| Driver-paritás | Pest **kontraktus-suite** | ugyanaz a teszt-osztály fut `database` és `filesystem` adapteren, datasetként |
| Versenyhelyzetek | dedikált concurrency tesztek | claim-race, cancel-vs-complete, batch-counter — párhuzamos folyamat-szimulációval |
| Wire-protokoll | snapshot/contract tesztek | OpenAPI ↔ tényleges response séma |
| Frontend | vitest + `expect-type` | strict TS, type-level tesztek a publikus API-ra |
| Package-hygiene | `publint` + `attw` | exports/types konzisztencia CI-ben |
| Stílus | Pint + eslint/prettier | változatlan |

A kontraktus-suite a terv legfontosabb eleme: a 0.x driver-drift bugjainak
osztálya ettől szűnik meg strukturálisan.

---

## 6. Ütemterv és fázisok

A rewrite a repóban, `v1` branchen történik; a `main` a 0.x-et szolgálja ki
(kritikus fixek backporttal), amíg az 1.0 stabil nem lesz.

| Fázis | Tartalom | Kapu (Definition of Done) |
|---|---|---|
| **0. Spec-fagyasztás** (1-2 nap) | Wire-protokoll leírása OpenAPI-ban a mostani viselkedésből; event-payload séma; a megtartandó viselkedések listája a mostani tesztekből kivonatolva | `docs/protocol.md` + openapi.yaml review-zva |
| **1. Domain mag** (3-4 nap) | `UploadStatus` állapotgép, DTO-k, portok, `UploadLifecycle`, `AssemblyPipeline` váz; kontraktus-teszt suite in-memory adapterrel | PHPStan max zöld, domain-tesztek zöldek |
| **2. Adapterek** (3-4 nap) | Database + Filesystem repository, ChunkStore, LockProvider adapterek; migrációk | teljes kontraktus-suite zöld **mindkét** driveren + concurrency tesztek |
| **3. Application + HTTP** (3-4 nap) | UploadService/BatchService, controllerek, requestek, middleware, job, események, broadcast, cleanup command, `chunky:install` + `chunky:doctor` | protokoll-snapshot tesztek zöldek az OpenAPI ellen |
| **4. Frontend core** (3-4 nap) | `Uploader` + `Batch` + közös `EventEmitter`; http/error-envelope; CompletionWatcher átemelés | vitest + type-tesztek zöldek, publint/attw zöld |
| **5. Wrapperek** (2-3 nap) | vue3 / react / alpine / livewire adapterek | wrapper-tesztek + példaappok működnek |
| **6. Kiadás** (2-3 nap) | README, docs, UPGRADE.md (0.x → 1.0 táblázatos API-megfeleltetés), examples frissítés, `1.0.0-beta.1` | beta kint Packagist + npm; egy valós projektben (NIP) próbaintegráció |

Összesen nagyságrendileg **3-4 hét** részmunkaidőben. A beta → stable közé
érdemes 2-4 hét valós használatot tenni.

## 7. V1 kötelező követelmények — közvetlen státusz és navigáció-túlélő feltöltés

A 0.x két beépített feltételezése v1-ben megfordul: (a) hogy az assembly
eredményéről broadcast/polling útján értesül a kliens, (b) hogy az upload a
komponens élettartamához kötődik.

### 7.1 Szinkron eredmény a közvetlen válaszban ("broadcast nélkül is teljes értékű")

**Mai állapot:** az `assembly.connection = 'sync'` beállítással az összefűzés
in-process fut, de a záró chunk POST válasza akkor is csak `{is_complete: true}` —
az eredményt (végleges fájl, profil-payload) a kliens csak broadcaston vagy
a status endpoint pollozásával tudja meg. Ez felesleges kerülő.

**V1 terv:**
- `assembly.mode`: `sync` | `queue` | `auto`. Az **alapértelmezés az `auto`**:
  konfigurált méret-küszöb (pl. 256 MB) alatt in-process összefűzés, felette
  queue — így a quickstart queue worker és Echo nélkül is komplett, de egy
  többgigás merge nem futhat bele a PHP request-timeoutba. A `chunky:doctor`
  jelzi, ha `auto`/`queue` mellett nem fut worker.
- **Sync módban a záró chunk POST válasza maga a végeredmény:**

  ```json
  {
      "status": "completed",
      "file": { "disk": "public", "path": "avatars/42/kep.jpg", "size": 10485760, "url": "..." },
      "payload": { "media_id": 123 }
  }
  ```

  A `payload` a profil `completed()` hookjának visszatérési értéke — így a
  létrehozott domain-objektum (pl. Media ID) egy körben visszajut a klienshez.
- Queue módban a záró chunk válasza `{"status": "assembling"}`, és a
  **status endpoint ugyanezt a result-sémát** adja vissza elkészülés után.
- Frontend: `const result = await uploader.upload(file)` — a promise
  **mindhárom módban** a végeredménnyel rezolvál: sync-nél a záró válaszból,
  queue-nál a CompletionWatcher (polling default, Echo opcionális gyorsítás)
  eredményéből. A hívónak nem kell tudnia, melyik mód aktív.
- Broadcast v1-ben tisztán **opcionális enhancement**: a package Echo/Reverb
  nélkül, sőt queue worker nélkül is teljes funkcionalitást ad.

### 7.2 Navigáció-túlélő feltöltés (globális UploadManager)

**Mai állapot:** az uploader példányt a `useUpload()` composable a komponensben
hozza létre — másik menüpontra navigálva a komponens unmountol, a folyamatban
lévő feltöltés referenciája (és UI-ja) elveszik.

**V1 terv — az upload app-szintű erőforrás, nem komponens-állapot:**
- Core: **`UploadManager` singleton** (modul-szintű registry): minden induló
  upload/batch ide regisztrál, életciklusa független bármely komponenstől.
- A wrapperek szemantikája megfordul: `useUpload()` **nem birtokolja** az
  uploadot, csak rá-bindol; unmountkor leiratkozik, de NEM szakítja meg.
  Vue plugin (`app.use(chunky)`) / React `<ChunkyProvider>` app-gyökérben.
- Új composable-ök: `useUploads()` (globális aktív lista, aggregált progress) +
  kész headless **`<UploadTray>`** komponens az app layoutba — a user bármelyik
  oldalról látja és vezérelheti a futó feltöltéseket.
- `beforeunload` guard opció: teljes oldal-elhagyásnál figyelmeztetés, amíg
  aktív upload van. (SPA-navigáció így már nem érinti a feltöltést; a guard
  csak a valódi page unload ellen véd.)

### 7.3 Folytatás reload után (fingerprint-alapú resume)

A chunk-szintű resume ma is létezik sessionön belül; v1-ben session-átívelővé válik:
- Fájl-**fingerprint** (név + méret + lastModified + első chunk hash-e) az
  initiate kérésben. Ha a backend élő, nem-terminális uploadot talál ugyanazzal
  a fingerprinttel (ugyanattól a usertől), a meglévő `uploadId`-t és a már
  feltöltött chunk-indexeket adja vissza — a kliens onnan folytatja.
- Kliens: `localStorage`-ben fingerprint → uploadId map; reload után ha a user
  újra kiválasztja ugyanazt a fájlt, automatikus folytatás fájl-újraküldés nélkül.
- (v1.1 opció: File objektum IndexedDB-ben a fájlkiválasztás nélküli teljes
  auto-resume-hoz — böngésző-kompatibilitási felmérés után.)

**Fázis-hatás:** a 7.1 a 3. fázist (Application + HTTP) és a wire-protokoll
spec-et érinti; a 7.2–7.3 a 4–5. fázist. Az ütemtervbe belefér, a 4. fázis
+1-2 nappal nő.

## 8. További fejlesztési ötletek (backlog, v1.1+)

> Végrehajtható ticketekké kidolgozva: [v1-implementation-plan.md](v1-implementation-plan.md) 7. fázis (T7.1–T7.4).

- **Direct-to-S3 multipart upload** — a chunkok közvetlenül S3-ra, presigned
  URL-ekkel; a Laravel backend csak orchestrál. A legnagyobb skálázási nyereség.
- **Sebesség + ETA** a state snapshotban (`bytesPerSecond`, `etaSeconds`) —
  olcsó, nagy UX-érték, a Tray komponens is építhet rá.
- **Kliens-oldali kép-előnézet hook** (thumbnail a feltöltés alatt).
- **Teljes-fájl checksum** opció (a chunk-szintű integrity mellett) az
  assembly utáni end-to-end verifikációhoz.
- **`chunky:doctor` bővítés**: queue worker élő-e, broadcast driver elérhető-e,
  storage írható-e — a supportkérdések nagy részét megelőzi.

## 9. Döntési pontok (javaslattal)

1. **Filesystem driver maradjon-e?** — *Javaslat: maradjon*, de már csak vékony
   adapter a közös állapotgép alatt, így a fenntartási költsége minimális; a
   zero-migration onboardinghoz (quickstart!) értékes.
2. **Laravel 11 támogatás?** — *Javaslat: elengedni.* A 0.x marad L11-eseknek.
3. **Kontextus-API törése (registry → profil-osztályok)?** — *Javaslat: igen*,
   ez a legnagyobb DX-nyereség; az UPGRADE.md mutat 1:1 átírási mintát.
4. **Deprecated frontend mezők** (`progress`, `isUploading`) — *Javaslat: törlés*,
   a JSDoc már 0.x-ben v1.0-ra ígérte.
5. **Namespace** — *Javaslat: marad `NETipar\Chunky`* és `netipar/laravel-chunky`;
   a major verzió jelzi a törést, új csomagnév nem kell.
