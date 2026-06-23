<?php
/** @var array $user */
/** @var array<string,int> $counts */
$total = array_sum($counts);
$pageTitle    = 'PANTEDU — Dashboard docente';
$pageContent  = ob_get_clean();
$bodyClass    = 'fm-area-docente-dashboard';
$currentRoute = '/area-docente/dashboard';
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
<div class="fm-dash-shell">
    <?php /* G22.S25 — Sub-tabs: sync→pool→sicurezza→panoramica. Panoramica
            include strumenti rapidi (cerca esercizi); il vecchio tab dedicato
            è stato fuso. */ ?>
    <?php /* Audit 25.R.31 (L10a) — CSS estratto in css/modules/_teacher-dashboard.css */ ?>
    <nav class="fm-dash-tabs" role="tablist" aria-label="Sezioni dashboard">
        <button type="button" class="fm-dash-tab fm-dash-tab--active" data-dash-tab="sync"       role="tab" aria-selected="true">☁ Sincronizzazione</button>
        <button type="button" class="fm-dash-tab" data-dash-tab="pool"       role="tab" aria-selected="false">🤝 Pool colleghi</button>
        <button type="button" class="fm-dash-tab" data-dash-tab="sicurezza"  role="tab" aria-selected="false">🔐 Sicurezza & manutenzione</button>
        <button type="button" class="fm-dash-tab" data-dash-tab="panoramica" role="tab" aria-selected="false">🏠 Panoramica</button>
    </nav>

    <?php /* PANEL: Panoramica — compact tiles + tools rapidi (Cerca esercizi). */ ?>
    <div class="fm-dash-panel fm-card" data-dash-panel="panoramica" hidden>
        <p class="fm-overview-intro">
            Benvenuto nella tua area riservata. Sotto vedi quante risorse hai caricato
            (mappe, esercizi, laboratorio, verifiche). Le sezioni
            <strong>BES/DSA-Recuperi</strong> e <strong>Risorse docente</strong> sono gestite dall'amministratore.
        </p>

        <div class="fm-overview-tiles">
            <div class="fm-overview-tile">
                <span class="fm-overview-tile__icon" aria-hidden="true">🗺️</span>
                <span class="fm-overview-tile__body">
                    <span class="fm-overview-tile__num"><?= (int)($counts['mappe'] ?? 0) ?></span>
                    <span class="fm-overview-tile__lbl">Mappe</span>
                </span>
            </div>
            <div class="fm-overview-tile">
                <span class="fm-overview-tile__icon" aria-hidden="true">📝</span>
                <span class="fm-overview-tile__body">
                    <span class="fm-overview-tile__num"><?= (int)($counts['eser'] ?? 0) ?></span>
                    <span class="fm-overview-tile__lbl">Esercizi</span>
                </span>
            </div>
            <div class="fm-overview-tile">
                <span class="fm-overview-tile__icon" aria-hidden="true">🧪</span>
                <span class="fm-overview-tile__body">
                    <span class="fm-overview-tile__num"><?= (int)($counts['lab'] ?? 0) ?></span>
                    <span class="fm-overview-tile__lbl">Laboratorio</span>
                </span>
            </div>
            <div class="fm-overview-tile">
                <span class="fm-overview-tile__icon" aria-hidden="true">📋</span>
                <span class="fm-overview-tile__body">
                    <span class="fm-overview-tile__num"><?= (int)($counts['verifiche'] ?? 0) ?></span>
                    <span class="fm-overview-tile__lbl">Verifiche</span>
                </span>
            </div>
        </div>

        <?php if ($total === 0): ?>
            <div class="fm-alert fm-alert--info fm-my-2 fm-text-13" >
                Nessuna risorsa ancora associata al tuo account.
            </div>
        <?php endif; ?>

        <div class="fm-overview-tools">
            <a class="fm-btn fm-btn--sm fm-btn--ghost" href="/area-docente/resources">📑 Elenco completo (JSON)</a>
        </div>
    </div>

    <?php /* G22.S25 — Ricerca contenuti del docente (teacher_content). Filtri
            dropdown popolati on-mount dal curriculum del docente (indirizzi,
            classi, materie owned). Sostituisce la legacy /exercises page che
            usava la tabella `exercises` (57 record M11 dead-path). */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="panoramica" hidden id="fm-ex-search-section">
        <h2 class="fm-title fm-text-17">🔍 Ricerca nei tuoi contenuti</h2>
        <p class="fm-muted fm-text-13 fm-m-0 fm-mb-3" >
            Filtra mappe, esercizi, verifiche e lab caricati nel tuo account
            (per istituto attivo). Click su un risultato per aprirlo in studio.
        </p>
        <form id="fm-ex-form" class="fm-ex-filters">
            <select name="type" class="fm-input fm-input--sm">
                <option value="">— tipo —</option>
                <option value="mappa">🗺️ Mappa</option>
                <option value="esercizio">📝 Esercizio</option>
                <option value="verifica">📋 Verifica (template)</option>
                <option value="lab">🧪 Lab</option>
            </select>
            <select name="indirizzo" class="fm-input fm-input--sm" data-fm-curriculum="indirizzi">
                <option value="">— indirizzo —</option>
            </select>
            <select name="classe" class="fm-input fm-input--sm" data-fm-curriculum="classi">
                <option value="">— classe —</option>
            </select>
            <select name="subject" class="fm-input fm-input--sm" data-fm-curriculum="materie">
                <option value="">— materia —</option>
            </select>
            <input type="text" name="q" placeholder="ricerca testuale (titolo/topic)" class="fm-input fm-input--sm fm-flex-1-grow fm-min-w-40" >
            <label class="fm-d-flex fm-gap-1 fm-items-center fm-text-xs fm-cursor-pointer fm-text-muted" title="Mostra solo gli elementi che hai archiviato (per ripristinarli).">
                <input type="checkbox" name="archived"> 🗄 archiviati
            </label>
            <button type="submit" class="fm-btn fm-btn--sm fm-btn--primary">Cerca</button>
        </form>
        <div id="fm-ex-results" class="fm-ex-results">
            <p class="fm-muted fm-text-13 fm-mt-3 fm-mr-0 fm-mb-0 fm-ml-0" >Imposta dei filtri e premi <strong>Cerca</strong> (o lascia tutto vuoto per vedere tutto).</p>
        </div>
    </section>

    <?php /* Phase G1.a — Google Drive integration status pill. */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="sync" hidden id="fm-drive-section">
        <h2 class="fm-title fm-text-17">☁ Google Drive</h2>
        <p class="fm-muted">Sincronizza mappe concettuali e PDF verifiche/risdoc verso il tuo Drive personale.</p>
        <div id="fm-drive-status" class="fm-drive-pill" data-state="loading">
            <span class="fm-drive-dot"></span>
            <span class="fm-drive-label">Verifico stato…</span>
            <span class="fm-drive-actions"></span>
        </div>
    </section>

    <?php /* G22.S15.bis Fase 5 — Cartella locale FS Access (per il bottone 💾 Sync locale). */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="sync" hidden id="fm-local-section">
        <h2 class="fm-title fm-text-17">💾 Cartella locale (sul tuo computer)</h2>
        <p class="fm-muted">
            Quando clicchi 💾 nella barra "Sync" in alto, una copia di mappe e
            verifiche viene salvata in questa cartella sul tuo PC. Richiede Chrome o
            Edge desktop (File System Access API).
        </p>
        <div id="fm-local-folder" class="fm-drive-pill fm-mt-2" data-state="loading">
            <span class="fm-drive-dot"></span>
            <span class="fm-drive-label">Verifico cartella…</span>
            <span class="fm-drive-actions">
                <button id="fm-local-folder-pick" class="fm-btn fm-btn--ghost fm-btn--sm">
                    Scegli/cambia cartella
                </button>
                <button id="fm-local-folder-clear" class="fm-btn fm-btn--ghost fm-btn--sm" hidden>
                    Rimuovi
                </button>
            </span>
        </div>
    </section>

    <?php /* G22.S15.bis Fase 5 — GitHub sync. */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="sync" hidden id="fm-github-section">
        <h2 class="fm-title fm-text-17">🐙 GitHub</h2>
        <p class="fm-muted">
            Backup automatico delle tue mappe e verifiche su un repository GitHub privato.
            Richiede un <a href="https://github.com/settings/tokens?type=beta" target="_blank" rel="noopener" class="fm-link">Personal Access Token (fine-grained)</a>
            con permessi <em>Contents: Read &amp; Write</em> sul repo scelto.
        </p>
        <div id="fm-github-status" class="fm-drive-pill fm-mt-2" data-state="loading">
            <span class="fm-drive-dot"></span>
            <span class="fm-drive-label">Verifico configurazione…</span>
            <span class="fm-drive-actions">
                <button id="fm-github-configure" class="fm-btn fm-btn--ghost fm-btn--sm">Configura</button>
                <button id="fm-github-disconnect" class="fm-btn fm-btn--ghost fm-btn--sm" hidden>Disconnetti</button>
            </span>
        </div>
        <details class="fm-mt-2">
            <summary class="fm-muted fm-cursor-pointer fm-text-13">Come creare un Personal Access Token</summary>
            <ol class="fm-muted fm-text-13 fm-lh-base fm-pl-5 fm-my-2" >
                <li>Apri <a href="https://github.com/settings/tokens?type=beta" target="_blank" rel="noopener">github.com/settings/tokens</a> (fine-grained)</li>
                <li>"Generate new token" → seleziona repository (o crea un nuovo repo privato)</li>
                <li>Scope: <code>Contents: Read &amp; Write</code></li>
                <li>Copia il token <code>github_pat_…</code> e incollalo nel popup di configurazione</li>
                <li>Indica owner/repo (es. <code>vittop89/my-pantedu-backup</code>)</li>
            </ol>
        </details>
    </section>

    <?php /* G22.S20 — Recovery Key (Modalità A: signed manifest per import bundle). */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="sicurezza" hidden id="fm-recovery-section">
        <h2 class="fm-title fm-text-17">🔐 Sicurezza — Recovery Key</h2>
        <p class="fm-muted">
            La Recovery Key è un codice di 32 byte usato per <strong>firmare il manifest
            del bundle di esportazione</strong> e per <strong>verificarne l'autenticità
            all'import</strong>. Genera la chiave una sola volta, salva il codice in
            cassaforte (PDF stampato o gestore password). Senza la Recovery Key non
            potrai re-importare i bundle scaricati su un altro account/server.
        </p>
        <div id="fm-recovery-status" class="fm-drive-pill fm-mt-2" data-state="loading">
            <span class="fm-drive-dot"></span>
            <span class="fm-drive-label">Verifico stato…</span>
            <span class="fm-drive-actions">
                <button id="fm-recovery-generate" class="fm-btn fm-btn--ghost fm-btn--sm">Genera</button>
                <button id="fm-recovery-rotate" class="fm-btn fm-btn--ghost fm-btn--sm" hidden
                        title="Revoca la chiave attuale e ne genera una nuova (operazione atomica). Il vecchio codice non sarà più valido.">🔄 Rigenera</button>
                <button id="fm-recovery-revoke" class="fm-btn fm-btn--ghost fm-btn--sm" hidden>Revoca</button>
            </span>
        </div>
        <details class="fm-mt-2">
            <summary class="fm-muted fm-cursor-pointer fm-text-13">Quando serve la Recovery Key?</summary>
            <ul class="fm-muted fm-text-13 fm-lh-base fm-pl-5 fm-my-2" >
                <li><strong>Re-import bundle</strong>: dopo aver scaricato i tuoi file via Sync locale, se vuoi caricarli in un altro account/server Pantedu ti serve questa chiave per firmare e verificare l'integrità.</li>
                <li><strong>Cassaforte</strong>: stampa il PDF generato + salva il codice in un gestore password. Se la perdi, perdi la capacità di re-importare quel bundle.</li>
                <li><strong>Privacy</strong>: il codice viene mostrato UNA SOLA VOLTA al momento della generazione. Non può essere recuperato successivamente dal server.</li>
            </ul>
        </details>
    </section>

    <?php /* G22.S21 Fase D — Pool browse: recupera materiali condivisi da colleghi. */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="pool" hidden id="fm-pool-section">
        <h2 class="fm-title fm-text-17">🤝 Recupera materiali da altri docenti</h2>
        <p class="fm-muted">
            Vedi qui i contenuti (mappe, verifiche, esercizi) che i colleghi
            del tuo stesso istituto hanno reso disponibili. Puoi <strong>copiarli
            nel tuo account</strong> dentro una tua materia: dopo la copia
            sono tuoi e puoi modificarli liberamente, l'originale del collega
            resta intatto.
        </p>
        <p class="fm-muted fm-text-xs" >
            Compaiono solo le materie/contenuti per cui un altro docente ha
            attivato l'opzione "condivisibile". Tu rendi visibile un tuo
            contenuto cambiando il flag "Condividi con il pool" nella sua
            scheda (o, per intera materia, dal pannello di gestione materie).
        </p>
        <div class="fm-d-flex fm-items-center fm-gap-2 fm-my-2 fm-flex-wrap">
            <label class="fm-text-13">Tipo:
                <select id="fm-pool-type" class="fm-input fm-input--sm">
                    <option value="">Tutti</option>
                    <option value="mappa">Mappe</option>
                    <option value="esercizio">Esercizi</option>
                    <option value="verifica_doc">Verifiche TEX/PDF</option>
                    <option value="verifica">Esercizi di verifica</option>
                    <option value="document">Documenti (BES / Risdoc / Didattica)</option>
                </select>
            </label>
            <label class="fm-text-13">Materia (codice):
                <input id="fm-pool-subject" type="text" placeholder="es. MAT" maxlength="8"
                       class="fm-input fm-input--sm fm-w-20 fm-uppercase"  />
            </label>
            <button id="fm-pool-load" class="fm-btn fm-btn--ghost fm-btn--sm">🔄 Aggiorna lista</button>
        </div>
        <div id="fm-pool-list" class="fm-muted fm-mt-2">
            <em>Clicca "Aggiorna lista" per caricare i materiali disponibili.</em>
        </div>
    </section>

    <?php /* G22.S24 — Card "I miei contenuti condivisi". */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="pool" hidden id="fm-my-shares-section">
        <h2 class="fm-title fm-text-17">📋 I miei contenuti condivisi <span id="fm-my-shares-count" class="fm-muted fm-fw-400 fm-text-14" ></span></h2>
        <p class="fm-muted">
            Qui controlli ciò che hai messo a disposizione dei colleghi.
            Puoi <strong>ritirare la condivisione</strong> selezionando le righe e cliccando "Rimuovi dal pool".
            I contenuti restano nel tuo account: solo la visibilità dei colleghi viene revocata.
        </p>
        <div class="fm-d-flex fm-items-center fm-gap-2 fm-my-2 fm-flex-wrap">
            <button id="fm-my-shares-load" class="fm-btn fm-btn--ghost fm-btn--sm">🔄 Aggiorna</button>
            <button id="fm-my-shares-select-all" class="fm-btn fm-btn--ghost fm-btn--sm">☑ Seleziona tutti</button>
            <button id="fm-my-shares-unshare" class="fm-btn fm-btn--sm fm-btn--danger" disabled>
                🚫 Rimuovi dal pool (<span id="fm-my-shares-selected">0</span>)
            </button>
        </div>
        <div id="fm-my-shares-list" class="fm-muted fm-mt-2">
            <em>Clicca "Aggiorna" per caricare la lista.</em>
        </div>
    </section>

    <?php /* G22.S25 — Card "Gruppi di condivisione" (spostata da profilo). */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="pool" hidden id="fm-share-groups-section">
        <h2 class="fm-title fm-text-17">👥 Gruppi di condivisione</h2>
        <p class="fm-muted">
            Crea gruppi di colleghi (es. "Dipartimento Matematica", "Tutor 3°D") per
            condividere materiali a un <em>sotto-insieme</em> di docenti invece dell'intero istituto.
            I gruppi vengono usati nel popup "🎯 Avanzato" della condivisione contenuti.
        </p>
        <div class="fm-d-flex fm-gap-2 fm-items-center fm-mb-3 fm-flex-wrap">
            <input id="fm-sg-name" type="text" placeholder="Nome gruppo" maxlength="120"
                   class="fm-input fm-input--sm fm-flex-1-grow fm-min-w-50" >
            <input id="fm-sg-desc" type="text" placeholder="Descrizione (opzionale)" maxlength="500"
                   class="fm-input fm-input--sm fm-flex-2-grow fm-min-w-50" >
            <button id="fm-sg-create" class="fm-btn fm-btn--primary fm-btn--sm">➕ Crea gruppo</button>
        </div>
        <div id="fm-sg-list" class="fm-muted">Caricamento…</div>
    </section>

    <?php /* G22.S15.bis Fase 5 — Cleanup orphan rows (DB ↔ blob mismatch). */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="sicurezza" hidden id="fm-cleanup-section">
        <h2 class="fm-title fm-text-17">🧹 Pulizia file rotti</h2>
        <p class="fm-muted">
            A volte un contenuto (verifica, mappa, esercizio) appare nella tua
            lista ma quando lo apri non funziona perché il file vero e proprio
            è andato perso. Succede raramente, ad esempio se hai cancellato un
            file dal disco a mano, o dopo un ripristino parziale del sistema.
        </p>
        <p class="fm-muted">
            Questo strumento trova i "fantasmi" (puntatori senza il file reale)
            e li rimuove dalla tua lista. <strong>Non perdi nulla di
            recuperabile</strong>: i contenuti erano già danneggiati, qui togli
            solo i collegamenti inutili che ti fanno apparire l'errore.
        </p>
        <div class="fm-d-flex fm-items-center fm-gap-2 fm-my-2 fm-flex-wrap">
            <button id="fm-cleanup-scan" class="fm-btn fm-btn--ghost fm-btn--sm"
                    title="Prima cerca: nessuna cancellazione, solo lista di cosa rimuoverebbe">🔍 Trova fantasmi</button>
            <button id="fm-cleanup-confirm" class="fm-btn fm-btn--ghost fm-btn--sm" disabled
                    title="Disponibile solo dopo aver cercato e trovato fantasmi">
                🗑 Rimuovi fantasmi trovati
            </button>
        </div>
        <pre id="fm-cleanup-result" class="fm-console-log-sm"
             ></pre>
    </section>

    <?php /* G22.S15.bis Fase 5 — Log errori sync (drive + locale + github + sync-all). */ ?>
    <section class="fm-dash-panel fm-card" data-dash-panel="sync" hidden id="fm-sync-log-section">
        <h2 class="fm-title fm-text-17">📋 Log sincronizzazioni</h2>
        <p class="fm-muted">
            Le ultime sincronizzazioni e gli eventuali errori. Utile per capire
            quale file ha avuto problemi.
        </p>
        <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap fm-my-2">
            <label class="fm-text-13 fm-d-flex fm-items-center fm-gap-1 fm-m-0" for="fm-sync-log-filter">
                Filtro:
                <select id="fm-sync-log-filter" class="fm-input fm-input--sm fm-w-auto">
                    <option value="all">Tutti</option>
                    <option value="error" selected>Solo errori</option>
                    <option value="ok">Successi</option>
                </select>
            </label>
            <button id="fm-sync-log-clear" class="fm-btn fm-btn--ghost fm-btn--sm">Pulisci log</button>
            <button id="fm-sync-log-refresh" class="fm-btn fm-btn--ghost fm-btn--sm">↻ Aggiorna</button>
        </div>
        <div id="fm-sync-log-list" class="fm-console-log" >
            Caricamento…
        </div>
    </section>

    <?php /* G22.S25 — Strumenti rapidi fusi in Panoramica (cerca esercizi). */ ?>
</div>
</main>
<?php /* Audit 25.R.31 (L10a) — JS dashboard estratto da inline a file esterno
         (abilita CSP no-unsafe-inline). Stessa posizione, senza defer, per
         preservare l'ordine d'esecuzione (window.FM gia' disponibile). */ ?>
<script src="/js/teacher-dashboard.js<?= ($_fmd = @filemtime(dirname(__DIR__, 2) . '/js/teacher-dashboard.js')) ? ('?v=' . $_fmd) : '' ?>"></script>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . "/views/layout/app.php";
