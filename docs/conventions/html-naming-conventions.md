# Convenzioni di naming HTML (classi + ID)

Documento di riferimento per il refactor del naming HTML del progetto **pantedu**.
Serve da guida per tutti i work unit (unit 2-10) del piano di refactor `refactor-html-naming`.

**Obiettivo:** normalizzare classi e ID nelle view PHP, CSS e JS in modo coerente,
**senza toccare** i nomi accoppiati alla pipeline TeX/PDF (regex, XPath, jQuery
critici).

---

## 1. Convenzione target

La convenzione è già adottata in ~84% del codice moderno. Gli unit di refactor
devono allineare il restante (legacy, sezioni miste).

### 1.1 Regole base

- **kebab-case** (no camelCase, no snake_case).
- **Prefisso `fm-`** obbligatorio per tutte le classi/ID del design system.
- **ID unici per file**: se un ID si ripete → convertire in class.
- **No ID numerici** (`btn0`, `btn1`…) → usare data-attribute (es.
  `data-sidepage="mappe"`).
- **No class generate da PHP per stato runtime**
  (`class="fm-status--<?= $role ?>"`) → usare data-attribute
  (`data-role="teacher"`) e selettore CSS `[data-role="teacher"]`.

### 1.2 BEM semplificato

- **Modifier `--`** consentito: `fm-btn--primary`, `fm-card--modal`.
- **Element `__`** NON consentito → sostituire con single-dash (abbreviato).
  Esempio: `fm-breadcrumb__sep` → `fm-bc-sep`.

### 1.3 Tabella pattern

| Ruolo | Pattern | Esempio |
|-------|---------|---------|
| Componente design system | `fm-<noun>` | `fm-btn`, `fm-card`, `fm-alert` |
| Modifier | `fm-<noun>--<mod>` | `fm-btn--primary`, `fm-card--modal` |
| Element interno | `fm-<noun>-<child>` (single-dash, abbreviato) | `fm-bc-sep` (ex `fm-breadcrumb__sep`) |
| Scope di pagina | `fm-<area>-<noun>` | `fm-an-grid`, `fm-ex-row` |
| ID singleton | `fm-<scope>` unico, semantico | `#fm-content`, `#fm-sidebar` |
| Stato runtime | data-attribute | `data-role="teacher"`, `data-section="mappe"` |

### 1.4 Prefissi di scope area

| Prefisso | Area |
|----------|------|
| `fm-an-*` | analytics |
| `fm-ex-*` | exercises (zona non protetta) |
| `fm-re-*` | risdoc editor |
| `fm-tpl-*` | templates |
| `fm-curr-*` | curriculum |
| `fm-risdoc-*` | risdoc viewer |
| `fm-sb-*` | sidebar |
| `fm-tb-*` | topbar |
| `fm-bc-*` | breadcrumb |
| `fm-login-*` | auth login |
| `fm-reg-*` | auth register |
| `fm-cookie-*` | cookie banner |
| `fm-modal-*` | modali generiche |

**Principio guida:** *scope > classe condivisa*. Meglio una classe con scope
chiaro (`fm-sb-sec`) che una classe generica (`btn`).

---

## 2. Zona PROTETTA — NO-TOUCH

Queste classi/ID sono parsate via regex/XPath/jQuery dalla pipeline TeX/PDF e
rinominarle rompe la produzione. **Nessun unit può toccarle.**

### 2.1 Classi TikZ / modelli

Accoppiate a `app/Services/TikzService.php`, `app/Services/TikzElementsService.php`
(regex + XPath).

```
tex-group, element-tex, label_tikz, label_latex,
group-options, group-btn, element-traccia,
fm-tex-group, fm-tex-groups
```

### 2.2 Classi esercizi / verifiche

Accoppiate a `app/Services/PhpContentParser.php`, `app/Services/DsaService.php`
(XPath) e `js/modules/print/print-export.js`,
`js/modules/print/verifiche-print-ui.js` (jQuery).

```
problem, testo, collex, collex-item, collexTab,
titolo_quesito, sol, giustsol, collapsible, li-inline,
defPositionImp, inputPt, checksol, checkgiust
```

### 2.3 Classi DSA

```
dsa-checkbox-container, dsa-checkbox,
AddTextDSA, checkboxRM, giustifica-checked
```

### 2.4 ID `layout_es.css` (esame)

Accoppiati a `js/modules/features/upbar-controls.js` e
`js/modules/features/checkin-handlers.js`.

```
#infoVer, #header_page, #scrollbarInfo, #verTitle,
#istituto, #verTime, #anno, #classe, #sezione,
#vers, #versione, #nPrint,
#SumPtot*, #SumTot*, #numCopy,
#addressSchool, #wrapInfoSchool, #wrapInfoVer, #wrapInfoStudent,
#wrapDSA, #wrapGriglieMisure,
#btnP, #modHeaderBtn, #saveBtnHeader,
#sel-origin, #multiarg, #savePrintInfoBtn,
#toggle-checkboxABin-control
```

### 2.5 Classi single-letter

`.A` … `.Z` — marker esercizi/origini aggiunti via `addClass(selectedValue)` in
`js/modules/events/event-handler.js`. **Nessuna eccezione.**

### 2.6 Classi runtime dinamiche

- `pos-top`, `pos-bottom`, `pos-left`, `pos-right` (aggiunte da
  `js/modules/editor/table-manager.js`).
- `.active`, `.is-open`, `.selected`, `.solchecked`
- `.fmv-selected-A`, `.fmv-selected-B`
- `.findHighlight*`

### 2.7 Campi Selection (TeX payload)

Definiti in `app/Services/TexBuilder/Selection.php`, usati come nomi form + DB:

```
verTitle, selectedIIS, selectedCLS, selectedMATER, anno, sezione
```

### 2.8 File da NON toccare mai

- `storage/templates/modelli_tikz.php`
- tutto sotto `eser/`
- tutto sotto `verifiche/`
- `storage/data/modelli_tikz*.json`

---

## 3. Tabella sostituzioni pianificate

Rename previsti dagli unit 2-7. Gli unit 8-10 sono audit e possono lasciare
invariato se già conforme.

### 3.1 Sidebar legacy → `fm-sb-*` (unit 2)

File cluster: `views/partials/sidebar.php`, `css/layout.css` (blocchi sidebar),
`js/modules/bootstrap-compat.js`, `js/modules/features/sidepage-highlight.js`,
`js/modules/features/db-sidepage.js`, relativi spec e2e.

| Before | After | Tipo | Motivo |
|--------|-------|------|--------|
| `.btn` (sidebar) | `.fm-sb-sec` | class | nome generico, rischio collisione |
| `.sidepage` | `.fm-sb-panel` | class | no prefix, ambiguo |
| `.materia` | `.fm-sb-subject` | class | no prefix |
| `.sel` | `.fm-sb-sel` | class | nome criptico |
| `.sel-lab` | `.fm-sb-lab` | class | `sel-` riservato ai selettori curriculum |
| `.tooltip` (sidebar) | `.fm-sb-tip` | class | troppo generico |
| `.scrollbar` (sidebar) | `.fm-sb-scroll` | class | collide con nomi browser |
| `.closeTextMenu` | `.fm-sb-close` | class | camelCase → kebab |
| `.slider` | `.fm-sb-slider` | class | troppo generico |
| `#btn0` … `#btn5` | `<button class="fm-sb-sec" data-sidepage="mappe\|lab\|eser\|verif\|bes\|risdoc">` | id→attr | ID numerici non-semantici |
| `#Mappe`, `#DidLab`, `#Eser`, `#Verif`, `#StrumBesAltro`, `#RisDoc` | `#fm-sp-mappe`, `#fm-sp-lab`, `#fm-sp-eser`, `#fm-sp-verif`, `#fm-sp-bes`, `#fm-sp-risdoc` | id rename | CamelCase inconsistente |
| `#darkmode-btn` ×3 (duplicato) | `.fm-sb-dark` (class) + wrapper `#fm-sb-dark` unico | id→class | HTML invalido |

### 3.2 Auth / profile forms (unit 3)

File cluster: `views/auth/login.php`, `views/auth/register.php`,
`views/profile/change_password.php`, `css/shell.css` (se referenziato),
relativi spec e2e.

| Before | After | Motivo |
|--------|-------|--------|
| `#password` (login) | `#fm-login-pwd` | ID duplicato con register |
| `#password` (register) | `#fm-reg-pwd` | idem |
| `#email` (login) | `#fm-login-email` | coerenza |
| `#email` (register) | `#fm-reg-email` | coerenza |
| `#username` (register) | `#fm-reg-username` | coerenza |
| `#institute_search` | `#fm-reg-inst-search` | prefisso area |
| `#fm-inst-student` | `#fm-reg-inst-student` | scope area |
| `#fm-inst-teacher` | `#fm-reg-inst-teacher` | scope area |

### 3.3 Modali + banner + cookie (unit 4)

File cluster: `views/partials/modals.php`, `css/layout.css` (sezione modali) o
`css/shell.css`, eventuali handler JS (`cookie-consent-modal`),
`tests/e2e/legacy_modernization.spec.js`.

| Before | After | Motivo |
|--------|-------|--------|
| `.banner-modal` | `.fm-modal` | no prefix |
| `.banner-content` | `.fm-modal-body` | no prefix + BEM single-dash |
| `.close-banner-btn` | `.fm-modal-close` | nome funzionale |
| `#modal-overlay` | `#fm-modal-overlay` | coerenza |
| `#license-info-modal` | `#fm-license-modal` | idem |
| `#cookie-consent-modal` | `#fm-cookie-modal` | idem |
| `#author-banner` | `#fm-author-modal` | idem |
| `.cookie-category` | `.fm-cookie-cat` | shorten |
| `.cookie-category-title` | `.fm-cookie-cat-title` | shorten |

### 3.4 BEM `__element` → single-dash (unit 5)

Rename globale meccanico. Grep in `views/**/*.php`,
`app/Controllers/**/*.php` (HTML echoato), `css/layout.css`, `css/shell.css`.
Nessun JS aggancia queste classi.

| Before | After | Occorrenze |
|--------|-------|------------|
| `fm-breadcrumb__sep` | `fm-bc-sep` | ~8 |
| `fm-topbar__actions` | `fm-tb-actions` | ~3 |
| `fm-breadcrumb` | `fm-bc` | opzionale, lasciare se già chiaro |

**Principio:** toccare solo BEM `__element`; lasciare invariato `--modifier`.

### 3.5 Dashboard class PHP-dinamica → data-attribute (unit 6)

File: `views/admin/dashboard.php`, `views/teacher/dashboard.php`,
`css/layout.css` o `shell.css` (selettori `.fm-status--*`),
`tests/e2e/admin_dashboard_notifications.spec.js`.

| Before | After |
|--------|-------|
| `<span class="fm-status fm-status--<?= $role ?>">` | `<span class="fm-status" data-role="<?= $role ?>">` |
| CSS: `.fm-status--teacher { … }` | CSS: `.fm-status[data-role="teacher"] { … }` |

**Motivo:** le class generate da PHP sono invisibili al grep; data-attribute è
più robusto e ispezionabile da DevTools.

### 3.6 ID duplicati (unit 7)

| Issue | File(s) | Fix |
|-------|---------|-----|
| `#fm-content` duplicato (embed vs full) | `views/layout/app.php` (righe 66, 83) | rimuovere ID del ramo embed — usare `<main>` implicito |
| `#password`, `#email` | risolto da unit 3 | — |
| `#darkmode-btn` ×3 | risolto da unit 2 | — |

---

## 4. Flusso di refactor

Workflow standard per ogni unit di rename:

1. **Grep globale prima del rename** — cercare il nome in:
   - `views/**/*.php`
   - `css/**/*.css`
   - `js/**/*.js`
   - `app/Controllers/**/*.php` (HTML echoato)
   - `tests/e2e/**/*.spec.js`
2. **Check zona protetta** — se il nome compare nella lista sezione 2 →
   **skip** e documentare nel PR.
3. **Aggiornamento atomico** — in un solo commit aggiornare:
   - CSS
   - template PHP
   - selector JS
   - spec e2e
4. **Verifica** — eseguire Playwright (`npm run e2e -- <spec>`) oppure, se
   XAMPP non disponibile, grep negativo del vecchio nome con report nel PR.

---

## 5. Dipendenze critiche (cluster di file)

Se tocchi una classe di uno dei cluster seguenti, aggiorna *tutto* il cluster
nello stesso commit per evitare rotture runtime.

### 5.1 Cluster sidebar

- `views/partials/sidebar.php`
- `css/layout.css`
- `js/modules/bootstrap-compat.js`
- `js/modules/features/sidepage-highlight.js`
- `js/modules/features/db-sidepage.js`
- `tests/e2e/sidebar*.spec.js`

### 5.2 Cluster modali

- `views/partials/modals.php`
- `css/layout.css` (sezione modali)
- handler JS cookie/modal
- `tests/e2e/legacy_modernization.spec.js`

### 5.3 Cluster dashboard dinamico

- `views/admin/dashboard.php`
- `views/teacher/dashboard.php`
- CSS `.fm-status--*` / `[data-role]`
- `tests/e2e/admin_dashboard_notifications.spec.js`

---

## 6. Verifica e2e — ricetta comune

```bash
# 1. Build frontend (Vite)
cd c:/Users/vitto/progetti_vscode/pantedu
npm run build

# 2. Verificare XAMPP Apache attivo
curl -sSI http://pantedu.local/ | head -5

# 3. Eseguire SOLO gli spec dello scope del tuo unit
npm run e2e -- tests/e2e/<spec-file>.spec.js

# 4. Fallback grep negativo (se XAMPP non disponibile)
grep -rn "<old-selector>" views/ css/ js/ app/ tests/ || true
# Report PR: "e2e skipped — env XAMPP unavailable, grep clean"
```

Per unit "doc only" (unit 1): skip e2e, solo lint markdown.
Per unit "rename meccanico" (unit 5): grep negativo + 1 spec smoke.

---

## 7. Riferimenti

- Plan completo: `C:/Users/vitto/.claude/plans/eventual-doodling-starlight.md`
- Branch di integrazione: `refactor-html-naming`
- Branch vietati: `master` (legacy read-only, vedi memoria progetto)
