# Konfigurációs referencia

> 🇬🇧 English version: [docs/en/configuration.md](../en/configuration.md)

A `config/chunky.php` minden kulcsa boot-kor típusos `ChunkyConfig`-gá
validálódik — a tartományon kívüli vagy rossz típusú érték azonnal, a hibás
kulcs megnevezésével bukik. A config publikálása:

```bash
php artisan chunky:install   # vagy: php artisan vendor:publish --tag=chunky-config
```

## Kulcsok

### Tracker és diskek

| Kulcs | Alapérték | Megjegyzés |
|---|---|---|
| `tracker` | `database` | `database` (Eloquent) vagy `filesystem` (JSON a disken, nulla migráció). |
| `disk` | `local` | A kész, összefűzött fájlok diskje (profilonként felülírható). |
| `chunks.disk` | `local` | Az ideiglenes chunkok diskje. |
| `chunks.directory` | `chunky/chunks` | A chunkok helye az összefűzésig. |
| `chunks.size` | 5 MB | Chunkonkénti bájtméret. |

### Limitek

| Kulcs | Alapérték | Megjegyzés |
|---|---|---|
| `limits.max_file_size` | 2 GB | A maximálisan elfogadott `file_size`; a profil `maxFileSize()`-a felülírja. `0` = korlátlan. |
| `limits.expiration_hours` | 24 | A befejezetlen upload ennyi után lejár, és a `chunky:cleanup` eltakarítja. |
| `limits.metadata_max_keys` | 50 | A kliens által küldhető metadata-kulcsok maximuma (DoS-védelem). |

### Összefűzés (assembly)

| Kulcs | Alapérték | Megjegyzés |
|---|---|---|
| `assembly.mode` | `auto` | `sync` (kérésen belül), `queue` (job), vagy `auto` (a küszöb alatt sync, felette queue). |
| `assembly.sync_threshold` | 256 MB | `auto` módban eddig a méretig szinkron az összefűzés. |
| `assembly.connection` | `null` | A job queue-kapcsolata (`null` = alapértelmezett). Az üres string `null`-nak számít. |
| `assembly.queue` | `null` | A job queue-neve. |
| `assembly.timeout` | 600 | Próbálkozásonkénti job-timeout (másodperc). |
| `assembly.tries` / `assembly.backoff` | 3 / 30 | Job-újrapróbálkozások száma és a backoff (másodperc). |
| `assembly.stale_claim_seconds` | 900 | Ennyi után egy összeomlott worker assembly-claimje átvehető. |

### Zárolás (locking)

| Kulcs | Alapérték | Megjegyzés |
|---|---|---|
| `locking.driver` | `auto` | `auto` (cache-lock, ha elérhető, különben flock), `cache` vagy `flock`. |
| `locking.timeout` | 10 | Ennyi másodpercet vár a lockra az `503 lock_timeout` előtt. |

A `locking.driver = cache` atomi lockokat támogató store-t igényel (pl.
Redis); `array`/`file`/`null` store-ral a csomag bootolni sem hajlandó.

### Integritás, folytatás, idempotencia

| Kulcs | Alapérték | Megjegyzés |
|---|---|---|
| `integrity.algorithm` | `sha256` | A chunkok opcionális `checksum`-jának ellenőrző hash-e. |
| `integrity.required` | `false` | Checksum megkövetelése minden chunkon. Kikapcsolva is ellenőrzi a megadott checksumot. |
| `integrity.require_full_file` | `false` | A teljes-fájl `file_checksum` (mindig SHA-256) megkövetelése a befejezéshez; nélküle a záró chunk `422 validation_failed`. Kikapcsolva is ellenőrzi a megadott `file_checksum`-ot az összefűzés után. |
| `resume.fingerprint` | `true` | Fingerprint-alapú folytatás reload után. |
| `idempotency.ttl` | 300 | Ennyi másodpercig cache-elődik a chunk-válasz a byte-pontos retry-visszajátszáshoz. |

### Transportok (direct-to-S3)

| Kulcs | Alapérték | Megjegyzés |
|---|---|---|
| `transports.direct_s3.disk` | `null` | Az s3 filesystem disk neve, ahová a direct uploadok mennek. `direct_s3` profil használatához kötelező; `aws/aws-sdk-php` szükséges. |
| `transports.direct_s3.url_ttl` | 3600 | A presigned part URL-ek élettartama másodpercben. |

A profil az `UploadProfile::transport(): 'direct_s3'`-mal kapcsol be.
Kényszerek (initiate-kor kikényszerítve): `chunks.size ≥ 5 MB`, legfeljebb
10000 part fájlonként. S3-kompatibilis célok (MinIO, Cloudflare R2) a disk
`endpoint` + `use_path_style_endpoint` beállításain át működnek — a MinIO
támogatott, az R2 best effort. Lásd a CORS-receptet lent; a wire-részletek a
[protocol.md](protocol.md)-ben.

### Route-ok és rate limit

| Kulcs | Alapérték | Megjegyzés |
|---|---|---|
| `routes.enabled` | `true` | A csomag route-jainak regisztrálása. |
| `routes.prefix` | `api/chunky` | URL-prefix. |
| `routes.middleware` | `['api']` | A route-csoport middleware-jei. |
| `throttle.initiate` / `throttle.chunks` | `30,1` / `300,1` | Rate limitek (kérés, perc), ha alkalmazod a `throttle` middleware-t. |

### Jogosultság, profilok, broadcast, takarítás

| Kulcs | Alapérték | Megjegyzés |
|---|---|---|
| `authorization.authorizer` | `DefaultAuthorizer::class` | Tulajdonlási szabályzat. Saját `Authorizer`-re cserélhető. |
| `profiles` | `[]` | `név => UploadProfile::class` térkép. |
| `broadcasting.enabled` | `false` | Opt-in. Kikapcsolva a kliens a státusz-végpontot pollozza. |
| `broadcasting.except` | magas frekvenciájú események | Sosem broadcastoló event-osztályok (alapból `ChunkUploaded`, `ChunkUploadFailed`, `BatchInitiated`). |
| `broadcasting.queue` | `null` | A broadcast-események queue-ja. |
| `cleanup.enabled` | `true` | A `chunky:cleanup` aktív-e. |

## Receptek

**Nulla infrastruktúra (alapértelmezés).** `tracker=database`,
`assembly.mode=auto`, `broadcasting.enabled=false`. A fájl a kérésen belül
fűződik össze, a kliens azonnal megkapja az eredményt. Semmi mást nem kell
futtatni.

**Nagy fájlok, skálán.** Állítsd `assembly.mode=queue`-ra (vagy maradj
`auto`-n és bízd a küszöbre), futtass queue workert, és tedd a
chunkokat/készfájlokat S3-ra (`disk`/`chunks.disk`). Több app-szerver esetén
használj `locking.driver=cache`-t Redis-szel.

**Élő progress fülek/eszközök között.** Állítsd `broadcasting.enabled=true`-ra,
kösd be az Echo/Reverb-et, és (opcionálisan) vegyél ki eseményeket a
`broadcasting.except`-ből, ha finomabb felbontású frissítést akarsz pusholni.

**Direct-to-S3 (bucket CORS — kritikus).** A `direct_s3` transportnál a
böngésző közvetlenül a bucketbe PUT-olja a partokat, ezért a CORS-nak
engednie kell a PUT-ot ÉS ki kell tennie az `ETag` fejlécet — az
`ExposeHeaders: ETag` nélkül a kliens nem tudja összegyűjteni a
part-ETageket, és a complete sosem sikerül:

```json
[
    {
        "AllowedOrigins": ["https://app.example.com"],
        "AllowedMethods": ["PUT"],
        "AllowedHeaders": ["*"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

Állíts be `AbortIncompleteMultipartUpload` lifecycle rule-t is (pl. 7 nap)
védőhálóként a soha le nem zárt/abortált feltöltésekre.

**Karbantartás.** Ütemezd a `php artisan chunky:cleanup`-ot (pl. óránként) a
lejárt, befejezetlen uploadok és chunkjaik törlésére (direct uploadnál a
távoli multipart feltöltést is abortálja). A `php artisan chunky:doctor`
átvizsgálja a diskeket, a queue workert, a broadcastot, a zárolást, a
trackert, és — ha be van állítva — a direct_s3 presigninget.
