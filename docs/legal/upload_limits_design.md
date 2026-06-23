---
title: "Upload Limits — Design Constraint Specification"
subtitle: "Vincoli tecnici per upload utente (Scenario B/C multi-tenant)"
version: "1.0"
date: "20 maggio 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Upload Limits — Design Specification

**Versione**: 1.0 · **Data**: 20 maggio 2026
**Applicativo**: pantedu.eu · **Status**: SPEC PRONTA — implementazione futura

---

## Scope

Specifica dei vincoli tecnici da applicare alla **funzionalità upload
file** (Phase futura) per ridurre la superficie d'abuso multi-tenant.
La funzionalità è prevista per:

- Studenti: upload foto/PDF dei propri svolgimenti di esercizi
- Docenti: eventuali allegati a verifiche/risdoc

I limiti sono studiati per mitigare:
- **Violazioni copyright** (caricamento intere pagine libri)
- **Esfiltrazione dati** (uso piattaforma come storage personale)
- **Attacchi DoS** (storage exhaustion via upload massivo)
- **Caricamento malware** (file binari malevoli)

---

## 1. Limiti per categoria

### 1.1 Studenti

| Vincolo | Valore | Rationale |
|---------|--------|-----------|
| Dimensione massima file singolo | **5 MB** | Foto smartphone moderna ~3MB; PDF 1-2 pagine ~2MB; oltre = scansione libro |
| Tipi MIME ammessi | `image/jpeg`, `image/png`, `image/heic`, `application/pdf` | Foto svolgimenti + PDF singoli |
| Numero pagine PDF max | **10 pagine** | Singolo esercizio o quesito; oltre = sospetto |
| Upload max al giorno | **30 file** | Anti-abuse generoso |
| Storage totale max | **100 MB** | ~30-50 foto a 2-3MB ciascuna |

### 1.2 Docenti

| Vincolo | Valore | Rationale |
|---------|--------|-----------|
| Dimensione massima file singolo | **5 MB** | Stesso ragionamento |
| Tipi MIME ammessi | `image/jpeg`, `image/png`, `image/heic`, `application/pdf`, `application/vnd.jgraph.mxfile` (drawio export) | Foto + PDF + mappe export |
| Numero pagine PDF max | **20 pagine** | Materiale didattico moderato |
| Upload max al giorno | **50 file** | Più alto di studenti |
| Storage totale max | **500 MB** | ~150-250 file medi |

### 1.3 Rate limit (entrambi)

| Vincolo | Valore |
|---------|--------|
| Upload max al minuto | **10 file** |
| Rate burst (5 sec) | **3 file** |
| Cooldown dopo trigger | **5 minuti** |

---

## 2. Validazione lato server

### 2.1 Pipeline validazione

```
[Client upload]
    ↓
[1. nginx client_max_body_size 6M (gate iniziale)]
    ↓
[2. PHP upload_max_filesize 5M + post_max_size 6M]
    ↓
[3. Controller: validazione user quota + rate limit]
    ↓
[4. MIME sniff server-side (ImageMagick / pdfinfo)]
    ↓
[5. PDF pagine count check (pdfinfo)]
    ↓
[6. Antivirus scan (ClamAV)]
    ↓
[7. EXIF strip (per privacy + dimensione)]
    ↓
[8. Hash SHA256 + dedup check]
    ↓
[9. Watermark embedded (opzionale)]
    ↓
[10. Envelope encryption + storage]
    ↓
[11. Audit log MariaDB]
```

### 2.2 Validazione MIME server-side

**NON fidarsi del Content-Type** dichiarato dal client (può essere
falsificato). Usare:

- `finfo_file()` PHP (libmagic) per detection iniziale
- `ImageMagick identify` per immagini (rifiuta se identify fallisce o
  warn su corrupted)
- `pdfinfo` (poppler-utils) per PDF (estrazione metadata + page count)

### 2.3 PDF pagine count

```php
$result = exec("pdfinfo " . escapeshellarg($filepath) . " | grep Pages | awk '{print \$2}'");
$pages = (int)$result;
if ($pages > $MAX_PAGES_STUDENT) {
    throw new TooManyPagesException("PDF $pages pagine > $MAX_PAGES_STUDENT");
}
```

### 2.4 Antivirus scan ClamAV

```bash
# VPS-side install
apt-get install -y clamav clamav-daemon
freshclam   # update signatures
systemctl enable --now clamav-daemon
```

```php
$result = exec("clamdscan --no-summary --stream < " . escapeshellarg($filepath));
// Output: "OK" oppure "VIRUS_NAME FOUND"
if (str_contains($result, 'FOUND')) {
    throw new VirusFoundException($result);
}
```

### 2.5 EXIF strip per JPEG/PNG

Rimuove metadata GPS, foto camera, ecc. che possono contenere PII:

```php
$image = new \Imagick($filepath);
$image->stripImage();  // rimuove EXIF/IPTC/XMP
$image->writeImage($filepath);
```

---

## 3. Dedup via SHA256

**Pattern**: prima di salvare, calcolare SHA256 del file uploaded.
Confrontare con hash già presenti nel DB:

```sql
SELECT file_id FROM uploads WHERE sha256_hash = ? LIMIT 1
```

Se hash matcha:
- Stesso utente: riferimento al file esistente (no duplicate storage)
- Utente diverso: blocco con messaggio "questo file è già stato caricato
  da un altro utente — possibile violazione" (audit log evento)

Beneficio: dedup automatico + detection cross-tenant di upload sospetti.

---

## 4. Hash blocklist per file noti illeciti

Mantenere tabella `upload_hash_blocklist`:

```sql
CREATE TABLE upload_hash_blocklist (
    sha256_hash CHAR(64) PRIMARY KEY,
    reason VARCHAR(255) NOT NULL,
    added_by INT UNSIGNED,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

Popolata da:
- File rimossi a seguito takedown (hash della versione rimossa)
- Editori segnalano file noti coperti copyright
- Stato manuale admin

All'upload, prima di salvare, check:

```sql
SELECT reason FROM upload_hash_blocklist WHERE sha256_hash = ?
```

Se matcha → blocco upload + alert + audit log.

---

## 5. Watermark embedded (opzionale)

Per foto JPEG, embed in EXIF metadata un watermark identificativo:

```
User-Comment: pantedu.eu upload by user_id=42 at 2026-05-20T14:30:15Z
```

Beneficio: anche se la foto fosse esfiltrata, contiene metadata di
provenienza.

Implementazione via ExifTool:

```bash
exiftool -overwrite_original \
    -UserComment="pantedu.eu upload by user_id=$UID at $TS" \
    "$filepath"
```

---

## 6. Configurazioni nginx + PHP

### nginx

```nginx
# In site config beta.pantedu.eu
client_max_body_size 6M;        # tolleranza margin per upload + form fields
client_body_buffer_size 256k;
client_body_timeout 60s;

# Rate limit per IP
limit_req_zone $binary_remote_addr zone=upload:10m rate=10r/m;

location ~ ^/api/upload {
    limit_req zone=upload burst=3 nodelay;
    # passa a PHP-FPM
    fastcgi_pass unix:/run/php/php-fpm.sock;
    # ...
}
```

### PHP (`/etc/php/8.4/fpm/conf.d/99-pantedu-upload.ini`)

```ini
upload_max_filesize = 5M
post_max_size = 6M
max_file_uploads = 5         ; max file per richiesta multipart
file_uploads = On
upload_tmp_dir = /var/www/pantedu/storage/tmp/uploads
```

### App-level rate limit

In controller PHP:

```php
$key = "upload_rate:user:{$userId}";
$today_count = $redis->incr($key);
$redis->expire($key, 86400);  // 24h TTL

if ($today_count > $DAILY_LIMIT) {
    http_response_code(429);
    throw new RateLimitException("Daily upload limit ($DAILY_LIMIT) exceeded");
}
```

---

## 7. Schema DB upload

```sql
CREATE TABLE uploads (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    sha256_hash     CHAR(64) NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(127) NOT NULL,
    size_bytes      INT UNSIGNED NOT NULL,
    pages_count     SMALLINT UNSIGNED DEFAULT NULL,
    blob_path       VARCHAR(512) NOT NULL,    -- path al blob cifrato envelope
    uploaded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uploaded_from_ip VARCHAR(45) NOT NULL,
    uploaded_via_ua VARCHAR(512),
    associated_with VARCHAR(64) DEFAULT NULL, -- ID dell'esercizio/risorsa correlata
    status          ENUM('active', 'removed_by_user', 'removed_by_admin', 'quarantined') DEFAULT 'active',
    KEY idx_user (user_id, status),
    KEY idx_hash (sha256_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_upload_quota (
    user_id         INT UNSIGNED PRIMARY KEY,
    role            ENUM('student','teacher') NOT NULL,
    total_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_count      INT UNSIGNED NOT NULL DEFAULT 0,
    daily_count     INT UNSIGNED NOT NULL DEFAULT 0,
    daily_reset_at  DATE NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

Migration `058_upload_infrastructure.sql` da scrivere quando si attiva
feature.

---

## 8. Error codes e response

| HTTP Code | Casi |
|-----------|------|
| `201 Created` | Upload OK, file salvato |
| `400 Bad Request` | File non valido (MIME, struttura) |
| `403 Forbidden` | Hash in blocklist o virus rilevato |
| `409 Conflict` | Quota utente raggiunta o dedup match |
| `413 Payload Too Large` | File > 5 MB |
| `415 Unsupported Media Type` | MIME non ammesso |
| `429 Too Many Requests` | Rate limit hit |
| `500 Internal Server Error` | Errore server (logged) |

---

## 9. Logging + observability

### Audit log MariaDB

Già coperto da `server_audit` plugin. Cattura automatically ogni INSERT
su `uploads`.

### Loki centralized logs

Pattern log strutturato JSON per nginx upload:

```json
{
  "ts": "2026-05-20T14:30:15Z",
  "user_id": 42,
  "action": "upload",
  "size_bytes": 2456789,
  "mime": "application/pdf",
  "sha256": "abc...",
  "pages": 3,
  "ip": "192.168.1.x",
  "outcome": "success"
}
```

### Grafana alerts

Aggiungere alert rules:
- Upload rate spike (> 100 upload/5min totali) → warning
- Hash blocklist match → critical
- Antivirus scan FOUND → critical
- Storage utilizzo > 80% di quota → warning

---

## 10. Roadmap implementativa

**Fase 1 — Foundation** (~10h):
- Migration `058_upload_infrastructure.sql`
- UploadService con pipeline validazione
- nginx + PHP config

**Fase 2 — Frontend** (~8h):
- Form upload con preview + progress bar
- Drag & drop interface
- Validazione client-side (sanity check pre-upload)

**Fase 3 — Hardening** (~6h):
- ClamAV integration
- EXIF strip
- Watermark
- Hash blocklist

**Fase 4 — Admin tools** (~4h):
- Dashboard quota per utente
- Hash blocklist management UI
- Audit log query helper

**Totale**: ~28h work.

---

## 11. Riferimenti

- [Migration 057 takedown](../../database/migrations/057_takedown_requests.sql)
- [Migration 056 ToS](../../database/migrations/056_tos_aup_acceptance.sql)
- [Multitenancy framework](../todo/multitenancy_responsibility_framework.md)
- [Takedown procedure](takedown_procedure.md)
- [AUP](aup.md) §3 limiti tecnici
- ClamAV: <https://docs.clamav.net/>
- pdfinfo (poppler): <https://poppler.freedesktop.org/>
- ImageMagick: <https://imagemagick.org/>

---

*Versione documento: 1.0 — 20 maggio 2026.*

*Per chiarimenti: {{OPERATORE_EMAIL}}*
