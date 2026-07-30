# Laravel Chunky — Wire Protocol v1.0

> 🇬🇧 English version: [docs/en/protocol.md](../en/protocol.md)

> **Normatív dokumentum.** Ez a v1.0 HTTP protokoll teljes leírása. A backend
> protokoll-tesztjei (T3.8) és a frontend transport-kódja (T4.2) erre a
> dokumentumra assertelnek. Forrás: [v1-implementation-plan.md](../v1-implementation-plan.md)
> 3. szakasz. Az OpenAPI-változat: [openapi.yaml](../openapi.yaml) — a kettőnek
> mezőről mezőre egyeznie kell.

## Általános szabályok

- Minden kérés- és válasz-törzs **JSON**, `Content-Type: application/json`, kivéve
  a chunk-feltöltést (`multipart/form-data`).
- Minden mezőnév **snake_case**.
- Az útvonalak alapértelmezett prefixe `api/chunky` (`config chunky.routes.prefix`),
  az alapértelmezett middleware `['api']`. A prefix a szerveren konfigurálható;
  a kliens a base URL-t kapja meg, a végpont-relatív útvonalak fixek.
- Verziózás: a protokoll **additív** egy major sorozaton belül — új opcionális
  mezők és új végpontok kerülhetnek be, meglévő mező jelentése/típusa nem változik.
  Az ismeretlen válasz-mezőket a kliens hagyja figyelmen kívül.
- Kliens által küldött ismeretlen mezőket a szerver figyelmen kívül hagy
  (nem `422`), kivéve ahol a validáció szigorú (lásd az egyes végpontoknál).

## Hiba-envelope

Minden nem-2xx válasz törzse **egységes**:

```json
{ "error": { "code": "upload_expired", "message": "The upload has expired." } }
```

- `error.code` — gépi feldolgozásra alkalmas stabil azonosító (lásd Hibakódok).
  A frontend retry-döntései EZEN alapulnak, sosem a `message` szövegén.
- `error.message` — ember-olvasható, lokalizált (Laravel `__()`), UI-ban
  megjeleníthető. Nem stabil, nem parse-olható.
- `422 validation_failed` esetén a Laravel szokásos `errors` map is jelen lehet
  az envelope mellett (mezőnkénti üzenetek), de a gépi ág a `code`-ot használja.

### Hibakódok

| `code` | HTTP | Mikor |
|---|---|---|
| `validation_failed` | 422 | A kérés mezői nem mennek át a validáción (hiányzó/rossz típusú mező, profil `rules()`). Ide tartozik a **túl nagy `file_size`** (`limits.max_file_size` / profil `maxFileSize()`) és a **túl sok `metadata` kulcs** (`limits.metadata_max_keys`) is. |
| `profile_not_found` | 422 | Az `initiate` `profile` mezője nem regisztrált profilra hivatkozik. |
| `unauthorized` | 403 | A profil `authorize()` vagy az `Authorizer` elutasította az **initiate** kérést, vagy a hívó nem tulajdonosa az uploadnak a **chunk** végponton. (A status/cancel non-owner esetben `404`-et ad — lásd alább.) |
| `upload_not_found` | 404 | Nincs ilyen `upload_id`, **vagy** a hívó nem az upload tulajdonosa a status/cancel végponton. A non-owner tudatosan megkülönböztethetetlen a nemlétezőtől, hogy ne szivárogjon ki, mely ID-k léteznek (anti-enumeration). |
| `batch_not_found` | 404 | Nincs ilyen `batch_id`, vagy a hívó nem a batch tulajdonosa a status/cancel végponton (ugyanaz az anti-enumeration elv). |
| `upload_expired` | 410 | Az upload lejárt (`expires_at` a múltban) és nem terminális — új chunk nem fogadható. |
| `invalid_state` | 409 | Az upload/batch nincs olyan állapotban, ahol a művelet értelmezhető (pl. chunk `assembling`/`completed` alatt; cancel `assembling` alatt). |
| `chunk_index_out_of_range` | 422 | A `chunk_index` < 0 vagy ≥ `total_chunks`. |
| `checksum_mismatch` | 422 | A megadott chunk-`checksum` nem egyezik a kiszámított SHA-256-tal (integritás-middleware). |
| `lock_timeout` | 503 | Túl nagy a versengés az upload lockján; a kérés biztonságosan újrapróbálható. |
| `assembly_failed` | 500 | **Sync** módban az összefűzés hibára futott; az upload `failed`-re állt. |

---

## 1. `POST {prefix}/upload` — upload indítása

Új chunk-feltöltés életciklusát indítja. Fingerprint-egyezésnél meglévő,
folytatható uploadot ad vissza új rekord létrehozása nélkül.

### Kérés (application/json)

| Mező | Típus | Köt. | Leírás |
|---|---|---|---|
| `file_name` | string | ✔ | Eredeti fájlnév (kiterjesztéssel). A tárolt név a profil `fileName()`-je szerint keletkezik. |
| `file_size` | integer | ✔ | Teljes fájlméret bájtban. > 0. |
| `mime_type` | string \| null | — | Kliens által bejelentett MIME-típus. |
| `profile` | string | — | Regisztrált profil neve (`config chunky.profiles`). Ha nincs megadva, az alapértelmezett/`simple` viselkedés érvényes. |
| `metadata` | object | — | Tetszőleges JSON kulcs-érték adat, amit a profil `rules()`-a validálhat és a `completed()` hook megkap. A kulcsok száma legfeljebb `limits.metadata_max_keys` (túllépésnél `422 validation_failed`). |
| `fingerprint` | string \| null | — | Kliens-oldali fájl-ujjlenyomat (lásd Fingerprint resume). Ha `config chunky.resume.fingerprint` be van kapcsolva, ez alapján történik a folytatás-detektálás. |
| `batch_id` | string \| null | — | Csak a batch-tag végponton keresztül van értelme kitölteni; közvetlen initiate-nél általában `null`. |

### Válasz `201 Created` — új upload

```json
{
  "upload_id": "9b2c...uuid",
  "chunk_size": 5242880,
  "total_chunks": 20,
  "resumed": false,
  "uploaded_chunks": []
}
```

| Mező | Típus | Leírás |
|---|---|---|
| `upload_id` | string | Az upload azonosítója (UUID); minden további kérés ezt használja. |
| `chunk_size` | integer | A szerver által elvárt chunk-méret bájtban. A kliens ENNYI bájtos darabokra vágja a fájlt. |
| `total_chunks` | integer | A várt chunkok száma. |
| `resumed` | boolean | `false` új uploadnál. |
| `uploaded_chunks` | integer[] | Már megérkezett chunk-indexek (új uploadnál üres). |

### Válasz `200 OK` — folytatott upload (fingerprint-találat)

Ha `resume.fingerprint` aktív, és a szerver élő, **nem terminális** uploadot
talál ugyanazzal a `fingerprint`-tel **ugyanattól a felhasználótól**, akkor NEM
hoz létre új rekordot, hanem a meglévőt adja vissza:

```json
{
  "upload_id": "meglévő-uuid",
  "chunk_size": 5242880,
  "total_chunks": 20,
  "resumed": true,
  "uploaded_chunks": [0, 1, 2, 3, 4]
}
```

A kliens a `uploaded_chunks`-ban NEM szereplő indexektől folytatja.

### Hibák

`422 validation_failed` (beleértve a túl nagy `file_size`-t és a túl sok
`metadata` kulcsot) · `422 profile_not_found` · `403 unauthorized`.

---

## 2. `POST {prefix}/upload/{uploadId}/chunks` — chunk feltöltése

Egyetlen chunk feltöltése. A `VerifyChunkIntegrity` middleware fut előtte.

### Kérés (multipart/form-data)

| Rész | Típus | Köt. | Leírás |
|---|---|---|---|
| `chunk` | file (binary) | ✔ | A chunk bájtjai. |
| `chunk_index` | integer | ✔ | 0-alapú index. `0 ≤ index < total_chunks`. |
| `checksum` | string (hex) | — | A chunk SHA-256 hexje. Ha jelen van, a middleware ellenőrzi. |
| `file_checksum` | string (hex) | — | A **TELJES fájl** SHA-256 hexje (64 karakter; mindig SHA-256, az `integrity.algorithm`-tól függetlenül). Bármely chunk-kérésen elfogadott — tipikusan a zárón érkezik; a szerver az első nem-üres értéket perzisztálja, a többit ignorálja. Az összefűzés után az eredmény ez ellen ellenőrződik. `integrity.require_full_file = true` esetén a záró chunk `file_checksum` nélkül `422 validation_failed`. |

Fejléc:

| Fejléc | Köt. | Leírás |
|---|---|---|
| `Idempotency-Key` | — | Ha megadva, a válasz a `idempotency.ttl` másodpercig cache-elődik, és hálózati retry-ra **byte-ra pontosan** visszajátszódik. Hiányában a szerver `checksum` alapján képez fallback idempotency-kulcsot. |

### Válasz `200 OK` — köztes chunk

```json
{
  "status": "uploading",
  "chunk_index": 5,
  "uploaded_count": 6,
  "total_chunks": 20,
  "progress": 30.0
}
```

| Mező | Típus | Leírás |
|---|---|---|
| `status` | string | Mindig `"uploading"` köztes chunknál. |
| `chunk_index` | integer | A most feldolgozott index (visszaigazolás). |
| `uploaded_count` | integer | Eddig megérkezett chunkok száma. |
| `total_chunks` | integer | Összes várt chunk. |
| `progress` | number | Százalék, `0.0`–`100.0`, két tizedes pontossággal. |

### Válasz `200 OK` — záró chunk, **sync** összefűzés

Akkor, ha `assembly.mode = sync`, vagy `assembly.mode = auto` és a fájlméret a
`sync_threshold` alatt van. Az összefűzés a kérésen belül lefut, és az eredmény
azonnal visszatér:

```json
{
  "status": "completed",
  "progress": 100.0,
  "file": {
    "path": "avatars/42/9b2c.jpg",
    "size": 104857600,
    "url": "https://cdn.example.com/avatars/42/9b2c.jpg"
  },
  "payload": { "media_id": 123 }
}
```

| Mező | Típus | Leírás |
|---|---|---|
| `status` | string | `"completed"`. |
| `progress` | number | `100.0`. |
| `file` | object | A végleges fájl. `path` a diskhez relatív; `size` bájt; `url` csak akkor, ha a disk támogatja publikus URL-t (különben hiányzik/`null`). A `disk` és az abszolút útvonal SOHA nem szivárog ki. |
| `payload` | object \| null | A profil `completed()` hookjának visszatérési értéke (pl. létrehozott domain-objektum azonosítója). `null`, ha a hook nem ad vissza semmit. |

### Válasz `200 OK` — záró chunk, **queue** összefűzés

Akkor, ha `assembly.mode = queue`, vagy `auto` és a fájlméret a küszöb felett.
Az összefűzés háttér-jobra kerül:

```json
{ "status": "assembling", "progress": 100.0 }
```

A kliens a `GET {prefix}/upload/{id}` végponton pollozza az eredményt (vagy
broadcasten kapja), amíg `status = completed`/`failed` nem lesz.

### Idempotens visszajátszás

Ha az idempotency-kulcs (vagy checksum-fallback) találatot ad, a szerver a
korábban cache-elt választ adja vissza változatlanul — beleértve a fenti
`completed` záró-választ is, a `payload`-dal együtt. Nem fut újra semmilyen
mellékhatás (event, assemble-dispatch).

### Hibák

`422 validation_failed` (ideértve a hibás formátumú `file_checksum`-ot, és a
hiányzót a befejezéskor, ha az `integrity.require_full_file` be van kapcsolva) ·
`422 chunk_index_out_of_range` · `422 checksum_mismatch` (a middleware által
elutasított chunk-`checksum`, vagy — sync záró chunkon — az összefűzött fájl
nem egyezik a bejelentett `file_checksum`-mal; az upload `failed`-re áll, a
chunkok a cleanupig megőrződnek diagnózishoz) ·
`403 unauthorized` (nem a hívó az upload tulajdonosa — a chunk végponton 403,
nem 404) · `404 upload_not_found` · `410 upload_expired` · `409 invalid_state`
(az upload `cancelled`/`completed`/`failed`/`assembling`) · `503 lock_timeout` ·
`500 assembly_failed` (csak sync módban, az összefűzés bukásakor — az upload
`failed`-re áll, a chunkok a cleanupig megőrződnek).

**Queue** módban a `file_checksum`-eltérés nem tud a chunk-válaszon megjelenni
— az upload `failed`-re áll, és a státusz végpont (vagy a broadcast) mutatja.

---

## 3. `GET {prefix}/upload/{uploadId}` — státusz lekérdezés

### Válasz `200 OK`

```json
{
  "upload_id": "9b2c...uuid",
  "status": "completed",
  "progress": 100.0,
  "uploaded_chunks": [0, 1, 2],
  "total_chunks": 20,
  "file_name": "video.mp4",
  "file_size": 104857600,
  "file": { "path": "avatars/42/9b2c.jpg", "size": 104857600, "url": "https://..." },
  "payload": { "media_id": 123 }
}
```

| Mező | Típus | Leírás |
|---|---|---|
| `upload_id` | string | |
| `status` | string | `pending` \| `uploading` \| `assembling` \| `completed` \| `failed` \| `cancelled`. |
| `progress` | number | `0.0`–`100.0`. |
| `uploaded_chunks` | integer[] | Megérkezett indexek. |
| `total_chunks` | integer | |
| `file_name` | string | Eredeti fájlnév. |
| `file_size` | integer | |
| `file` | object \| absent | Csak `completed` státusznál. Ugyanaz a séma, mint a záró-chunk `file`-je. |
| `payload` | object \| null \| absent | Csak `completed` státusznál; a completed-átmenetkor perzisztált érték (a status endpoint NEM futtatja újra a profil-hookot). |

A válasz a **publikus** projekció: `disk`, `final_path` (abszolút út), `user_id`
soha nincs benne.

### Hibák

`404 upload_not_found` — non-owner esetben is `404` (anti-enumeration: nem
árulja el, hogy az ID létezik, nem `403`).

---

## 4. `DELETE {prefix}/upload/{uploadId}` — upload megszakítása

### Válasz `200 OK`

```json
{ "status": "cancelled" }
```

Idempotens: ha az upload már `cancelled`, ismét `200 { "status": "cancelled" }`.

### Hibák

`404 upload_not_found` (non-owner esetben is `404`, anti-enumeration) ·
`409 invalid_state` — cancel csak `pending`/`uploading` állapotból megengedett;
`assembling` alatt, illetve `completed`/`failed` terminális állapotban `409`.

---

## 5. `POST {prefix}/batch` — batch indítása

### Kérés (application/json)

| Mező | Típus | Köt. | Leírás |
|---|---|---|---|
| `total_files` | integer | ✔ | A batch-be tartozó fájlok száma. > 0. |
| `profile` | string | — | Az összes tag-uploadra alkalmazott profil. |
| `metadata` | object | — | Batch-szintű metaadat. |

### Válasz `201 Created`

```json
{ "batch_id": "b1a2...uuid", "total_files": 3 }
```

### Hibák

`422 validation_failed` · `422 profile_not_found` · `403 unauthorized`.

---

## 6. `POST {prefix}/batch/{batchId}/upload` — batch-tag indítása

Mint az [1. initiate](#1-post-prefixupload), de az upload a megadott batch-hez
kötve jön létre (`batch_id` szerverileg beállítva). A kérés- és válaszséma
azonos az initiate-tel; a válasz `batch_id`-t is tartalmaz.

### Válasz `201 Created`

```json
{
  "upload_id": "uuid",
  "batch_id": "b1a2...uuid",
  "chunk_size": 5242880,
  "total_chunks": 20,
  "resumed": false,
  "uploaded_chunks": []
}
```

### Hibák

`404 batch_not_found` · `422 validation_failed` (beleértve a túl nagy
`file_size`-t) · `403 unauthorized` · `409 invalid_state` (a batch már terminális).

---

## 7. `GET {prefix}/batch/{batchId}` — batch státusz

### Válasz `200 OK`

```json
{
  "batch_id": "b1a2...uuid",
  "status": "in_progress",
  "total_files": 3,
  "completed_count": 1,
  "failed_count": 0,
  "uploads": [
    { "upload_id": "u1", "status": "completed", "progress": 100.0 },
    { "upload_id": "u2", "status": "uploading", "progress": 40.0 },
    { "upload_id": "u3", "status": "pending", "progress": 0.0 }
  ]
}
```

| Mező | Típus | Leírás |
|---|---|---|
| `batch_id` | string | |
| `status` | string | `pending` \| `in_progress` \| `completed` \| `partially_completed` \| `cancelled`. |
| `total_files` | integer | |
| `completed_count` | integer | |
| `failed_count` | integer | |
| `uploads` | object[] | Tagonként `{upload_id, status, progress}`. |

### Hibák

`404 batch_not_found` — non-owner esetben is `404` (anti-enumeration).

---

## 8. `DELETE {prefix}/batch/{batchId}` — batch megszakítása

A batch-et **és minden nem terminális tag-uploadját** megszakítja
(driver-független — a `findByBatch` porton keresztül, nem konkrét adaptert
sniffelve).

### Válasz `200 OK`

```json
{ "status": "cancelled" }
```

### Hibák

`404 batch_not_found` (non-owner esetben is `404`, anti-enumeration) ·
`409 invalid_state` (a batch már `completed`/`partially_completed`).

---

## Fingerprint resume

Cél: reload/újrakiválasztás után a feltöltés a már feltöltött chunkok
újraküldése nélkül folytatódjon.

- A kliens az `initiate` kérésben `fingerprint`-et küld: `name + size +
  lastModified + az első chunk SHA-1`-je alapján képzett stabil azonosító.
- Szerver oldal (`resume.fingerprint = true`): az initiate megkeresi az élő,
  nem terminális uploadot ugyanezzel a fingerprinttel, **ugyanattól a
  felhasználótól** (`UploadRepository::findByFingerprint($fp, $userId)`).
  Találatnál `200`, `resumed: true`, a meglévő `upload_id` és `uploaded_chunks`.
- A kliens `localStorage`-ben tart egy `fingerprint → upload_id` térképet;
  terminális válasznál vagy `404`/`410`-nél a bejegyzést törli.
- Kikapcsolt `resume.fingerprint` esetén minden initiate új uploadot hoz létre
  (`resumed` mindig `false`).

---

## Broadcasting (opcionális)

A broadcast **opt-in enhancement** — a teljes protokoll queue worker és Echo
nélkül is működik (sync mód + polling). Bekapcsolása: `broadcasting.enabled = true`.

- **Események** (11): `UploadInitiated`, `ChunkUploaded`, `ChunkUploadFailed`,
  `FileAssembled`, `UploadCompleted`, `UploadFailed`, `UploadCancelled`,
  `BatchInitiated`, `BatchCompleted`, `BatchPartiallyCompleted`, `BatchCancelled`.
- Egy esemény akkor broadcastol, ha `broadcasting.enabled` **és** az esemény
  osztályneve nincs a `broadcasting.except` listán. A default `enabled = false`,
  így egy régi publikált config nem tud csendben mindent kikapcsolni (nincs
  `mergeConfigFrom`-akna).
- Az `except` **alapértéke a magas-frekvenciájú események**: `ChunkUploaded`,
  `ChunkUploadFailed`, `BatchInitiated`. Így a broadcast bekapcsolása alapból csak
  a hasznos, ritka completion-eseményeket adja; a chunkonkénti zaj kimarad, amíg a
  fejlesztő szándékosan ki nem veszi őket az `except`-ből.
- **Payload — verziózott**:

  ```json
  { "v": 1, "upload_id": "uuid", "status": "completed", "progress": 100.0, "...": "..." }
  ```

  A payload a `UploadRecord::toPublicArray()` (illetve batch megfelelője) + a
  `"v": 1` séma-verzió. A public projekció SOHA nem tartalmaz `disk`,
  `final_path`, `user_id` mezőt vagy abszolút útvonalat.
- **Csatornák**: privát csatornák az upload/batch tulajdonosára szűrve
  (`routes/channels.php`). A frontend `CompletionWatcher` a broadcastot és a
  pollozást összeegyezteti (broadcast ha van, polling a garancia).
