# DOCUMENTO DI PROGETTO (FINALE) — Sidebar dinamica & unificazione button `.fm-sb-sec`

## STATO IMPLEMENTAZIONE (aggiornato 2026-05-30, in produzione)

Decisioni prese: **DR-1 = solo admin crea sezioni** · **DR-2 = MariaDB 11.8 (CHECK effettivi)** · **DR-3 = sì, `teacher_content.section_id`** (multi-tipo) · **DR-4 = audit fatto: solo 6 righe default in `teacher_sidebar_sections`, incoerenza `risorsa` confermata**.

- ✅ **Step 1** — Migration `070_sidebar_sections.sql` (`sidebar_sections` + `sidebar_section_overrides` + 6 seed globali). Deployata, idempotente.
- ✅ **Step 2** — `SidebarSectionRepository` (`resolveFor`/`forRender`/`listForAdmin`/`upsert`/`delete`/`setPositions`). Test integrazione 4/4.
- ✅ **Step 3** — `sidebar.php` render data-driven con fallback hardcoded. Parità DOM verificata prod (studente esclude risdoc via `visible_roles`).
- ✅ **Step 4** — `GET /api/sidebar/config` + idratazione registry (`hydrate`). Loader data-driven; le sezioni custom sono FUNZIONALI. Verificato prod (commit `4e55435`).
- ✅ **Step 5-6** — Migration 071 `teacher_content_data.section_id` + creazione multi-tipo per pannello (selettore se >1 `allowed_content_types`) + validazione `type ∈ allowed` in `store` (400 `type_not_allowed_in_section`). Verificato prod (commit `5d5b0e6`).
- ✅ **Step 7** — UI admin `/admin/sidebar-config` (super_admin): rinomina/colore/visibilità-studenti/ordine/attiva sulle 6 sezioni + add/remove custom. Verificata prod (commit `a9a764c`).
### UNIFICAZIONE (richiesta utente: ogni sezione = label + tipi + fork, key anonime)
- ✅ **Migration 072** — `allow_template_fork` + `template_origin` su sidebar_sections; backfill `teacher_content_data.section_id` (content_type→sezione). Capability bes/risdoc.
- ✅ **Admin capability** — tolto dropdown loader/mixed → checkbox "Permetti fork da template" + origine; loader_kind derivato.
- ✅ **Passo 5 / Migration 073** — `CREATE OR REPLACE VIEW teacher_content` con `tc.*` espone `section_id` (atomico, verificato: nessuna colonna persa, prod backuppato).
- ✅ **Passo 3 — loader unico per sezione**: `search` filtro `section_id`; `/api/teacher/content` + `/api/study/content.json` accettano `?section=<key>` (scoped istituto+ruolo, visibilità studente preservata); db-sidepage carica per `section` le sezioni multi-tipo (allowedTypes>1) → **mappe + esercizi nello STESSO pannello**. I 6 default mono-tipo invariati. Verificato prod (`aae7d93`).
- ✅ **Passo 4 — colori data-driven**: render `background` inline → custom colorabili; default su token CSS. Verificato prod (`39e1c8b`).

- ✅ **Edge fork-per-sezione** (commit `38825af`): in ogni sezione si crea mappa/esercizio (selettore tipo) O documento da template istituzionale (custom doc-mode + `templateSeed` → `teacher_content` con `model_template_id`, ancorato via `section_id`). Il picker template rispetta `template_origin` della sezione (config→registry→openModal→populateTemplatePicker). Il fork-via-templateSeed produce un documento del docente mostrato dal loader unico.
- ✅ **Step 8 — visibilità ancorata alla sezione** (commit `c256724`): `search` filtro `section_id_not_in`; `scopedFilters` (studente) esclude le sezioni non-visibili dell'istituto; `contentSingleJson` 403 su URL diretto. Verificato: published in sezione nascosta → studente lista 0 + single 403.
- ✅ **Step 9 — deprecazione `teacher_sidebar_sections`** (commit `914b7bb`/`dadf159`): rimossi endpoint `/api/teacher/sidebar*` + controller (orfani); migration 074 DROP TABLE. Audit: solo seed default, zero dati reali. Verificato prod: route → 404, tabella droppata, 0 regressioni.

## TUTTO COMPLETATO — ADR-027 in produzione

La sidebar è interamente dinamica e data-driven: configurabile da `/admin/sidebar-config` (nome/colore/ordine/visibilità-studenti/add-remove), ogni pannello supporta più tipi + fork da template (tutti documenti del docente ancorati alla sezione), loader unico per `section_id`, visibilità studente ancorata alla sezione (defense-in-depth), tabella legacy rimossa. Migrations 070-074. Verificato end-to-end docente + studente.

---



**Repo:** `pantedu` · **ADR proposto:** ADR-027 · **Migration target:** `070` (la 069 è l'ultima esistente)
**Stato:** revisione 2 — incorpora la critica avversariale (B1, B2, A1–A5, M1–M5, P1–P6).
**Obiettivo:** centralizzare/uniformare i 6 button `.fm-sb-sec`; consentire la creazione di più `content_type` in un pannello; aggiungere in `/admin` una sezione per stile (nome+colore), add/remove button+pannelli e visibilità per-pannello agli studenti; dinamicizzare la sidebar **mantenendo il render server-side** e **riconciliando** la feature per-docente già esistente.

> **CAMBIO DI PREMESSA (critico).** `teacher_sidebar_sections` NON è una tabella morta: esiste `app/Controllers/TeacherSidebarController.php` (Phase 15) con CRUD completo (`index/create/update/delete/reorder`) instradato in `routes/web.php:756-766`. Campi `code/label/icon/content_type/color/position/active/is_default`, vincolo `uq_tss_user_code`, FK su `users(id)`. Va **riconciliata**, non droppata.

---

## 0. ESITO AUDIT FATTUALE

| Affermazione | Verifica | Esito |
|---|---|---|
| `TeacherSidebarController` esiste e fa CRUD | file `:1-215` | **CONFERMATO** |
| Route `/api/teacher/sidebar[...]` attive | `routes/web.php:756-766` | **CONFERMATO** |
| Tabella `teacher_sidebar_sections` | `schema.sql:242-258` | **CONFERMATO** |
| Consumer JS di `GET /api/teacher/sidebar` | grep `**/*.js` → 0 | **API NON consumata da JS** (CRUD orfano lato UI) |
| `teacher_content.section_id` | assente | **CONFERMATO assente** → A2 valida |
| `TeacherContentRepository::TYPES` | `['mappa','esercizio','lab','verifica','bes','risdoc','didattica']` | **CONFERMATO** (7 tipi) |
| `CATEGORY_MAP` usa `'risorsa'` | `:37` `'risorsa'=>'risdoc'` | **Incoerenza pre-esistente** (`risorsa` non in TYPES) |
| Migration successiva | 069 ultima | **070** |

**B1-bis:** nessun client JS chiama `GET /api/teacher/sidebar` → la sidebar reale è sempre quella hardcoded in `sidebar.php`. Ma `create()` è raggiungibile via POST autenticato+CSRF → **possono esistere righe utente reali**. Audit-dati su VPS obbligatorio prima di qualunque drop.

---

## 1. TABELLA DIFFERENZE tra i 6 button/pannelli

| key | label (fonte) | loader | group | content_type | customCategories | fonte colore | creazione | visibilità studente |
|---|---|---|---|---|---|---|---|---|
| `mappe` | "Mappe concettuali" `sidebar.php:241` | **db** | **subject** | `mappa` | false | token `--fm-c-sec-mappe` | solo `mappa`; 5 doc_mode | **SÌ** |
| `lab` | "Laboratorio" `:244` | db | subject | `lab` | false | `--fm-c-sec-lab` | solo `lab`; 2 doc_mode | SÌ |
| `eser` | "Esercizi" `:247` | db | subject | `esercizio` | false | `--fm-c-sec-eser` | solo `esercizio`; 2 doc_mode | SÌ |
| `verif` | "Verifiche" `:250` | db | **category** | `verifica` | **true** | `--fm-c-sec-verif` + border | solo `verifica` | SÌ |
| `bes` | "BES/DSA - RECUPERI" `:253` | **risdoc** | category | `bes` | true (`origin:strcomp`, fork) | `--fm-c-sec-bes` | custom + fork | SÌ |
| `risdoc` | "Risorse docente (riservato)" `:259` | risdoc | category | `risdoc` | true (`origin:risdoc`, fork) | `--fm-c-sec-risdoc` + border | custom + fork | **NO** — `if($isTeacher||$isAdmin)` |

**Asimmetrie (★):** loader doppio (db vs risdoc); group misto (subject vs category, `verif` ibrido db+category); customCategories 3/6; content_type 1:1 (`byType()` univoco); nomi+colori hardcoded; visibilità studente configurabile solo per `risdoc` e solo via `if` PHP; ETag su db ma non risdoc; **persistenza per-docente (`teacher_sidebar_sections`) esiste ma non è renderizzata né consumata** → nodo da riconciliare.

---

## 2. PROBLEMI da risolvere

1. Loader doppio db/risdoc → loader unico data-driven (`loader_kind` + `sources[]`).
2. group subject vs category → attributo dato `group_mode`.
3. Creazione vincolata per tipo → `allowed_content_types[]`; selettore nel modal se >1.
4. Nomi/colori hardcoded → DB; tinte come **override del valore del token** (non nuovo token).
5. Visibilità non configurabile → `visible_roles` (meccanismo unico) su render PHP + config API + content API via policy centralizzata.
6. Modello per-docente esistente da **riconciliare, non deprecare a vuoto** (DR-1).

---

## 3. MODELLO DATI

### 3.1 Riconciliazione con `teacher_sidebar_sections`
Due livelli da far convivere: **governance d'istituto** (nuova, template per-istituto) + **personalizzazione per-docente** (esistente: estetica/posizione + creazione sezioni custom). Il nuovo modello mappa il per-docente in `sidebar_section_overrides` (estetica/posizione/attivazione). La creazione di sezioni custom per-docente → **DR-1**.

> **DR-1 — chi crea sezioni custom?** Opzione A (solo admin crea; docente personalizza estetica) *[raccomandata: la feature per-docente è oggi orfana]* vs Opzione B (mantieni creazione per-docente con righe a scope `teacher_id`).

### 3.2 Scope (multitenant)
Template **per-istituto** + override **per-docente**. `institute_id = 0` (sentinel, NOT NULL) = template di default globale ereditato. Admin d'istituto definisce loader/group/allowed_content_types/visible_roles; docente sovrascrive label/colore/icon/position/active; super_admin edita qualunque istituto + il globale.

### 3.3 Algoritmo resolve
`resolveFor($instituteId, $teacherId)`: (1) carica globale `institute_id=0`; (2) carica istituto; (3) merge per `section_key` (riga-istituto vince, altrimenti globale; istituto-only si aggiungono); (4) tombstone = riga-istituto `active=0`; (5) applica override per-docente; (6) ordina per `position`. Cache-key resolve/API = `(institute_id, role, teacher_id?)` — istituto-aware obbligatorio.

### 3.4 DDL — Migration `070_sidebar_sections.sql`
```sql
CREATE TABLE IF NOT EXISTS sidebar_sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institute_id INT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = default globale (NON NULL)
    section_key VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    icon VARCHAR(32) NULL,
    color VARCHAR(16) NULL,
    color_border VARCHAR(16) NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    loader_kind ENUM('db','risdoc','mixed') NOT NULL DEFAULT 'db',
    group_mode ENUM('subject','category') NOT NULL DEFAULT 'subject',
    allowed_content_types JSON NOT NULL,
    default_content_type VARCHAR(32) NOT NULL,
    origin VARCHAR(32) NULL,
    default_categories JSON NULL,
    custom_categories TINYINT(1) NOT NULL DEFAULT 0,
    supports_fork TINYINT(1) NOT NULL DEFAULT 0,
    visible_roles JSON NOT NULL,                    -- UNICO meccanismo: ["student","teacher","admin"]
    active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ss_inst_key (institute_id, section_key),
    INDEX idx_ss_inst_pos (institute_id, position),
    CONSTRAINT chk_ss_color CHECK (color IS NULL OR color RLIKE '^#[0-9a-fA-F]{3,8}$'),
    CONSTRAINT chk_ss_border CHECK (color_border IS NULL OR color_border RLIKE '^#[0-9a-fA-F]{3,8}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sidebar_section_overrides (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    label VARCHAR(128) NULL, color VARCHAR(16) NULL, icon VARCHAR(32) NULL,
    position INT UNSIGNED NULL, active TINYINT(1) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sso (section_id, teacher_id),
    CONSTRAINT chk_sso_color CHECK (color IS NULL OR color RLIKE '^#[0-9a-fA-F]{3,8}$'),
    CONSTRAINT fk_sso_sec FOREIGN KEY (section_id) REFERENCES sidebar_sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_sso_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
Seed: 6 righe `institute_id=0, is_default=1` (mappe/lab/eser/verif/bes/risdoc) con `ON DUPLICATE KEY UPDATE` (re-run safe grazie al sentinel 0, NON NULL). `risdoc` con `visible_roles=["teacher","admin"]` (studente escluso, replica `if`). `down` = drop delle 2 tabelle. La 070 è **solo schema+seed**; la migrazione dati legacy è step separato gateato con backup.

> **DR-2 — versione DB VPS:** i `CHECK` richiedono MySQL 8.0.16+; altrimenti validazione hex solo app-side (comunque obbligatoria).

---

## 4. UNIFICAZIONE LOADER & CREAZIONE

- **Loader unico** `sidepage-loader.js`: dispatch su `loader_kind`/`group_mode`; `db-sidepage.js` e `risdoc-sidepage.js` diventano adapter interni `(def)→rows[]`. ETag merged risdoc **deve includere user/teacher/institute** (M4) per evitare leak cross-utente da cache.
- **Idratazione SOLO contenuti, NON struttura** (P1/A5): il DOM dei button resta server-rendered; `/api/sidebar/config` versionato (ETag) idrata i loader; fallback hardcoded solo offline totale; cache-key client `(institute_id, role)`.
- **Creazione multi-tipo:** il modal riceve `allowed_content_types`+`default_content_type`; >1 → `<select> Tipo documento`. `byType()` → `byTypePreferred(type, sectionKey)` obbligatorio.
- **A2 (BLOCKER coerenza):** il confine di sicurezza NON può essere `content_type` (uno stesso tipo può vivere in sezioni con visibilità diverse). → aggiungere `teacher_content.section_id` (DR-3); visibilità derivata da `visible_roles` della section + `publish_scope` (migr.069). Backfill legacy deterministico (seed 1:1).
- **Validazione server 3 livelli** (M3) in `store`: (1) tipo ∈ `TYPES`; (2) tipo ∈ `allowed_content_types` della section; (3) ruolo del chiamante ∈ `visible_roles` **e** section nello scope istituto.

> **DR-3 — `teacher_content.section_id`:** confermare l'aggiunta (abilita multi-tipo coerente) vs mantenere sezioni mono-tipo (e bloccare multi-tipo finché non implementato).

---

## 5. UI ADMIN

- **Dove:** voce toolnav in `views/admin/_partials/page_head.php` → `/admin/sidebar-config`.
- **Controller:** `Admin/AdminSidebarConfigController` (pattern `AdminSystemController`), group `auth+role:admin+super_admin_required`. Repo `SidebarSectionRepository`.
- **Route:** `GET /admin/sidebar-config` (page) · `POST .../save|.../reorder|.../{id}/delete` [csrf] · `GET /api/sidebar/config` (istituto+ruolo scoped).
- **Scope check:** super_admin → qualunque istituto + globale; admin d'istituto → solo il proprio, mai il globale. `section_key` slug `^[a-z0-9_-]{2,32}$`, custom con prefisso `custom_`, riservati i 6 key default.
- **View:** rinomina (`input text`), color picker (`input color` + validazione contrasto WCAG 2.2 AA dark), riordino drag (**SortableJS bundlato Vite, non CDN**), toggle `visible_roles`, checkbox `allowed_content_types` (da `TYPES`), add/remove (solo `is_default=0`). Persistenza DB (non `storage/config/*.json`).

---

## 6. RENDER PHP DATA-DRIVEN (primario)
`sidebar.php` cicla `resolveFor()`; salta sezioni `!active` o ruolo non in `visible_roles` (button **non emesso**, no `display:none` aggirabile). Colori: `style="--fm-c-sec-<key>: <hex>"` (override del **valore del token**, non nuovo token → border/hover/dark preservati); custom → token derivati via `color-mix`. Hex sempre `htmlspecialchars` + whitelist regex (no CSS-injection/XSS). Render server-side ⇒ niente CLS (P1).

---

## 7. SICUREZZA / VISIBILITÀ (defense in depth)
Policy centralizzata `ContentVisibilityPolicy::canStudentSee($content,$student)` basata su `content.section_id → section.visible_roles` (+ `publish_scope`). **Audit ESAUSTIVO** prima del gate: OGNI route che serve byte di un content (file privati, blob, drawio XML, esercizi, export/import bundle, permalink/share, export-html), non solo `/api/study/content.json`. Config API restituisce solo sezioni visibili al ruolo, istituto-scoped. CSS/JS mai confine di sicurezza.

---

## 8. PIANO INCREMENTALE (retrocompat-first)
- **Step 0** — Audit dati VPS su `teacher_sidebar_sections` (bloccante per step distruttivi).
- **Step 1** — Migration 070 (additiva, idempotente, reversibile): 2 tabelle + 6 seed globali 1:1 con l'attuale.
- **Step 2** — `SidebarSectionRepository::resolveFor()` + `GET /api/sidebar/config` read-only.
- **Step 3** — Render PHP data-driven (pixel-diff DOM identico vs riferimento).
- **Step 4** — Idratazione registry (solo loader contenuti); test cache-bust locale+VPS sui 3 scenari di divergenza.
- **Step 5** — Loader unico + `teacher_content.section_id` (backfill); e2e cross-utente; ETag risdoc per-utente.
- **Step 6** — Creazione multi-tipo + validazione 3 livelli (abilitato solo dopo DR-3 in prod).
- **Step 7** — UI admin completa.
- **Step 8 ★** — Gate visibilità server centralizzato su tutti gli endpoint (audit §7). Punto di non-ritorno funzionale.
- **Step 9 ★** — Migrazione dati + deprecazione `teacher_sidebar_sections` (idempotente, backup, gate esplicito). Distruttivo.

**Rischi:** cache SW stantia; bypass visibilità se gate incompleto; incoerenza multi-tipo↔visibilità; drift loader; scope multitenant errato; re-run seed (mitigato dal sentinel 0); XSS via `style=`; parità DOM/dark; perdita dati legacy.
**Non-ritorno:** Step 8 (sicurezza dal codice al dato) e Step 9 (drop legacy, backup obbligatorio).

---

## 9. DECISIONI RICHIESTE
- **DR-1** — Creazione sezioni custom per-docente: A (solo admin) *[racc.]* vs B (mantieni per-docente).
- **DR-2** — Versione DB VPS (CHECK effettivi o solo app-side).
- **DR-3** — `teacher_content.section_id` (abilita multi-tipo coerente) sì/no.
- **DR-4** — Autorizzare audit dati VPS su `teacher_sidebar_sections` (precondizione Step 0/9).

---

## 10. INCOERENZE PRE-ESISTENTI (da tracciare)
- `TeacherSidebarController::CATEGORY_MAP` accetta `content_type='risorsa'` non presente in `TYPES` → mappare `risorsa→risdoc` in Step 9.
- `TYPES` include `didattica` (7° tipo) senza button → decidere se esporlo prima della UI admin add/remove.

---

_Generato dal workflow `sidebar-unify-design` (10 agenti: mappatura parallela → design → critica avversariale → finalizzazione). Vedi anche [[project_studio_visibility]]._
