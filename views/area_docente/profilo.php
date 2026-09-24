<?php
/** G20.0 — Profilo docente: gestione istituti collegati. */
$pageTitle    = 'PANTEDU — Profilo docente';
$bodyClass    = 'fm-area-docente-profilo';
$currentRoute = '/area-docente/profilo';
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
    <header>
        <h1>👤 Profilo del docente <button type="button" class="fm-infotip" aria-label="Info profilo"><span class="fm-infotip__body" hidden>Gestisci i tuoi istituti di lavoro, le materie/classi/indirizzi che insegni e il curriculum dell'istituto attivo.</span></button></h1>
    </header>

    <?php /* 2026-09-01 — /me/change-password e /me/2fa esistevano gia' ma non
             erano linkate da nessuna pagina dell'area docente: il cambio
             password compariva solo nell'intestazione admin, la 2FA da nessuna
             parte. Funzioni irraggiungibili valgono quanto funzioni assenti. */ ?>
    <section class="fm-card">
        <h2>🔐 Sicurezza dell'account</h2>
        <p class="fm-muted fm-text-13 fm-mt-0">
            Email, password e verifica in due passaggi del tuo account.
        </p>
        <div class="fm-d-flex fm-gap-2 fm-flex-wrap fm-mt-2">
            <a class="fm-btn fm-btn--ghost" href="/me/account" data-full-reload>👤 Il mio account</a>
        </div>
    </section>

    <section class="fm-card">
        <h2>📌 Istituti collegati <button type="button" class="fm-infotip" aria-label="Info istituti collegati"><span class="fm-infotip__body" hidden>L'istituto attivo si seleziona dalla sidebar a sinistra (dropdown <em>Istituto</em>). Le risorse (verifiche, mappe) vengono filtrate per quello.</span></button></h2>
        <div id="fm-profile-current">
            <p class="fm-muted">Caricamento…</p>
        </div>

        <h3 class="fm-mt-4 fm-mb-1">➕ Aggiungi un istituto</h3>
        <p class="fm-muted fm-m-0 fm-mb-3 fm-text-13" >Cerca per nome, codice meccanografico o città:</p>
        <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap">
            <input type="text" id="fm-profile-search" placeholder="Es. Esempio Comune Esempio…" autocomplete="off"
                   class="fm-input-pill">
            <button type="button" class="fm-btn fm-btn--primary" id="fm-profile-add-btn">Collega istituto</button>
        </div>
        <div id="fm-profile-add-feedback" class="fm-muted fm-text-13 fm-mt-2" ></div>
    </section>

    <?php /* Piano classi-credenziali-scenari, C — credenziali di classe: la
             porta degli studenti negli scenari 1 e 2. Logica in
             js/modules/features/teacher-credentials.js (dal Vite entry). */ ?>
    <section class="fm-card" id="fm-credenziali">
        <h2>🎟️ Credenziali di classe <button type="button" class="fm-infotip" aria-label="Info credenziali di classe"><span class="fm-infotip__body" hidden>Negli scenari 1 e 2 gli studenti non hanno un account: entrano con una credenziale che crei tu, legata alla tua classe. La dai come username e password, come QR, o come codice a tempo proiettato in classe. Scade per default il 31 agosto. Gli studenti con più docenti tengono le credenziali insieme, in un portachiavi. I contatori sono solo numeri: nessuna identità di chi entra.</span></button></h2>
        <div id="fm-cred-list" class="fm-text-13">Caricamento…</div>
        <h3 class="fm-mt-4 fm-mb-1">➕ Nuova credenziale</h3>
        <?php /* ADR-044 — le regole stanno nel repository e in EtichettaCredenziale:
                 qui si stampano, negli attributi dei campi e nel testo d'aiuto.
                 teacher-credentials.js usa il testo d'aiuto come messaggio del
                 browser (setCustomValidity). L'etichetta non si scrive più: la
                 compone il server con classe, indirizzo, materie e aggiunta. */
        $credRepo = \App\Repositories\TeacherCredentialRepository::class;
        $credEtichetta = \App\Domain\EtichettaCredenziale::class;
        $credAiutoUsername = 'Da ' . $credRepo::USERNAME_MIN . ' a ' . $credRepo::USERNAME_MAX
            . ' caratteri: lettere senza accenti, cifre, punto (.), trattino (-) e trattino basso (_). '
            . 'Niente spazi né altri simboli. Deve essere diverso da quelli di tutti i docenti della piattaforma; '
            . 'all\'accesso maiuscole e minuscole si equivalgono. Non usare nomi di persone.';
        $credAiutoPassword = 'Da ' . $credRepo::PASSWORD_MIN . ' a ' . $credRepo::PASSWORD_MAX
            . ' caratteri: lettere senza accenti, cifre e simboli (per esempio ! ? # _), senza spazi. '
            . 'Maiuscole e minuscole contano.';
        $credAiutoAggiunta = 'Facoltativa, al massimo ' . $credEtichetta::AGGIUNTA_MAX
            . ' caratteri: lettere senza accenti, cifre e trattino (-); diventa maiuscola. '
            . 'Serve a distinguere due credenziali della stessa classe. Non usare nomi di persone.';
        ?>
        <form id="fm-cred-form" class="fm-form fm-cred-form" autocomplete="off">
            <?php /* La coppia indirizzo/classe e' quella dei selettori in sidebar,
                     che seguono il curriculum dell'istituto (le classi portano il
                     loro corso): qui si sceglie solo se delimitare o no. */ ?>
            <p class="fm-text-13 fm-m-0">
                <label class="fm-mr-3"><input type="radio" name="perimetro" value="classe" checked>
                    Solo la classe selezionata in sidebar: <strong id="fm-cred-scope-label">nessuna classe selezionata</strong></label>
                <label><input type="radio" name="perimetro" value="tutte"> Tutte le mie classi (credenziale non delimitata)</label>
            </p>
            <p class="fm-muted fm-text-13 fm-m-0 fm-mt-1">Per un'altra classe cambia indirizzo e classe nella sidebar: la coppia è sempre coerente con il curriculum dell'istituto.</p>

            <fieldset class="fm-cred-materie fm-mt-2" id="fm-cred-materie">
                <legend class="fm-text-13">Materie nell'etichetta</legend>
                <div id="fm-cred-materie-list" class="fm-text-13 fm-d-flex fm-gap-2" style="flex-wrap:wrap"><span class="fm-muted">Caricamento…</span></div>
            </fieldset>

            <div class="fm-cred-campi fm-mt-2">
                <div class="fm-cred-campo">
                    <label class="fm-label" for="fm-cred-aggiunta">Aggiunta all'etichetta (facoltativa)</label>
                    <input type="text" id="fm-cred-aggiunta" name="aggiunta" class="fm-input"
                           maxlength="<?= (int)$credEtichetta::AGGIUNTA_MAX ?>"
                           pattern="<?= e($credEtichetta::AGGIUNTA_HTML_PATTERN) ?>"
                           aria-describedby="fm-cred-aggiunta-help" title="<?= e($credAiutoAggiunta) ?>"
                           placeholder="es. GRUPPO-B">
                    <small id="fm-cred-aggiunta-help" class="fm-muted fm-text-12"><?= e($credAiutoAggiunta) ?></small>
                </div>
                <div class="fm-cred-campo">
                    <label class="fm-label" for="fm-cred-username">Username</label>
                    <input type="text" id="fm-cred-username" name="username" class="fm-input" required
                           minlength="<?= (int)$credRepo::USERNAME_MIN ?>" maxlength="<?= (int)$credRepo::USERNAME_MAX ?>"
                           pattern="<?= e($credRepo::USERNAME_HTML_PATTERN) ?>"
                           aria-describedby="fm-cred-username-help" title="<?= e($credAiutoUsername) ?>"
                           placeholder="es. 3a-mate" autocapitalize="off" spellcheck="false">
                    <small id="fm-cred-username-help" class="fm-muted fm-text-12"><?= e($credAiutoUsername) ?></small>
                </div>
                <div class="fm-cred-campo">
                    <label class="fm-label" for="fm-cred-password">Password</label>
                    <input type="text" id="fm-cred-password" name="password" class="fm-input" required
                           minlength="<?= (int)$credRepo::PASSWORD_MIN ?>" maxlength="<?= (int)$credRepo::PASSWORD_MAX ?>"
                           pattern="<?= e($credRepo::PASSWORD_HTML_PATTERN) ?>"
                           aria-describedby="fm-cred-password-help" title="<?= e($credAiutoPassword) ?>"
                           autocomplete="new-password" autocapitalize="off" spellcheck="false">
                    <small id="fm-cred-password-help" class="fm-muted fm-text-12"><?= e($credAiutoPassword) ?></small>
                </div>
                <div class="fm-cred-campo">
                    <label class="fm-label" for="fm-cred-scadenza">Scadenza</label>
                    <input type="date" id="fm-cred-scadenza" name="expires_at" class="fm-input"
                           min="<?= e(date('Y-m-d')) ?>" aria-describedby="fm-cred-scadenza-help">
                    <small id="fm-cred-scadenza-help" class="fm-muted fm-text-12">Vuota = 31 agosto. Da oggi in poi.</small>
                </div>
            </div>

            <p class="fm-text-13 fm-m-0 fm-mt-2" aria-live="polite">Etichetta: <strong id="fm-cred-anteprima" class="fm-mono">—</strong>
                <span id="fm-cred-anteprima-nota" class="fm-muted"></span></p>
            <p class="fm-muted fm-text-12 fm-m-0">La compone il sistema: classe, indirizzo, sigle delle materie in ordine alfabetico e l'aggiunta. Gli studenti la vedono nel loro portachiavi.</p>
            <p class="fm-m-0 fm-mt-2"><button type="submit" class="fm-btn fm-btn--primary">Crea</button></p>
        </form>
        <div id="fm-cred-feedback" class="fm-muted fm-text-13 fm-mt-2" aria-live="polite"></div>
        <div id="fm-cred-qr" class="fm-mt-3" hidden>
            <p class="fm-m-0 fm-mb-1"><strong id="fm-cred-qr-title"></strong> <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" id="fm-cred-qr-close">Chiudi</button></p>
            <img id="fm-cred-qr-img" alt="QR della credenziale" style="max-width:260px;border:1px solid var(--fm-c-border,#ddd);background:#fff">
            <p class="fm-muted fm-text-13" id="fm-cred-qr-note"></p>
        </div>
    </section>

    <section class="fm-card">
        <h2>🎓 Curriculum dell'istituto attivo <button type="button" class="fm-infotip" aria-label="Info curriculum istituto"><span class="fm-infotip__body" hidden><p>Il catalogo — <strong>indirizzi, classi e materie</strong> — è della <strong>scuola</strong>: qui spunti le voci che ti riguardano nell'istituto attivo, nell'ordine in cui le usi — prima gli indirizzi, poi le classi (che compaiono sotto il loro corso), poi le materie. I selettori della barra laterale seguono le spunte, e si scelgono nello stesso ordine. Il nome che scrivi accanto a una voce lo vedi solo tu; quello della scuola lo cambia l'amministratore, una volta per tutti. Togliere la spunta spegne la voce e conserva il tuo nome.</p><p>Non puoi creare voci nuove. Il motivo è pratico: se tu registrassi <code>SCI</code> e un collega <code>SCIE</code>, entrambi chiamati "Scientifico", uno studente in iscrizione si troverebbe due voci identiche e sceglierne una sbagliata lo lascerebbe senza i tuoi materiali, senza alcun messaggio d'errore. Se manca un indirizzo, chiedi a un amministratore di aggiungerlo all'istituto.</p><p>Tutto questo elenco è <strong>legato all'istituto attivo</strong>: cambiandolo dal selettore qui sopra cambiano indirizzi, classi e materie. Anche lo stesso codice può avere un nome diverso da una scuola all'altra.</p><?php if (!\App\Support\DeploymentScenario::isInstitute()): ?><p><strong>Le spunte sono tue, non della scuola.</strong> Il catalogo (indirizzi, classi, materie) è dell'istituto; <em>quali</em> di quelle voci ti riguardano lo dichiari tu, e la scuola non lo fornisce, non lo conferma e non lo può correggere. Non è l'organizzazione ufficiale delle classi: è la tua dichiarazione, e la cambi quando cambia. Una classe la <em>usi</em> solo se un amministratore te ne ha dato l'incarico; finché non ce l'hai, la spunta resta sospesa.</p><?php endif; ?><p><strong>Istituto</strong>: serve come boundary di condivisione (pool materiali con colleghi dello stesso istituto) e come identità anagrafica (codice MIUR, nome scuola). Per condividere i tuoi contenuti, attiva il toggle "Condivisa" sulla riga della materia (oppure il toggle "🤝 Condividi con colleghi" nella scheda del singolo contenuto).</p></span></button></h2>
        <p class="fm-muted fm-text-13 fm-mt-1 fm-mb-2" id="fm-curr-active-inst">Caricamento…</p>
        <div class="fm-subtabs" id="fm-curr-tabs" role="tablist" aria-label="Tipo di voce del curriculum">
            <button class="fm-subtab fm-subtab--active" id="fm-tab-indirizzi" role="tab" aria-selected="true" aria-controls="fm-panel-indirizzi" data-kind="indirizzi" type="button">🎯 Indirizzi</button>
            <button class="fm-subtab" id="fm-tab-classi" role="tab" aria-selected="false" aria-controls="fm-panel-classi" tabindex="-1" data-kind="classi" type="button">🏫 Classi</button>
            <button class="fm-subtab" id="fm-tab-materie" role="tab" aria-selected="false" aria-controls="fm-panel-materie" tabindex="-1" data-kind="materie" type="button">📚 Materie</button>
        </div>
        <div id="fm-curr-panels">
            <section class="fm-curr-panel" id="fm-panel-indirizzi" role="tabpanel" aria-labelledby="fm-tab-indirizzi" tabindex="0" data-panel="indirizzi"></section>
            <section class="fm-curr-panel fm-d-none" id="fm-panel-classi" role="tabpanel" aria-labelledby="fm-tab-classi" tabindex="0" data-panel="classi" ></section>
            <section class="fm-curr-panel fm-d-none" id="fm-panel-materie" role="tabpanel" aria-labelledby="fm-tab-materie" tabindex="0" data-panel="materie" ></section>
        </div>
    </section>
    <?php /* G22.S25 — "Gruppi di condivisione" vive solo in dashboard pool tab. */ ?>
</main>

<style>
    /* ADR-044 — il modulo delle credenziali: campi con etichetta e aiuto sotto. */
    .fm-cred-campi { display: flex; flex-wrap: wrap; gap: 10px 12px; align-items: flex-start; }
    .fm-cred-campo { display: flex; flex-direction: column; gap: 3px; flex: 1 1 14em; min-width: 12em; }
    .fm-cred-campo small { line-height: 1.3; }
    .fm-cred-materie { border: 1px solid rgba(0,0,0,0.08); border-radius: 6px; padding: 4px 10px 8px; margin: 0; }
    .fm-cred-materie legend { font-weight: 600; padding: 0 4px; }
    body.fm-dark .fm-cred-materie { border-color: #334155; }
    .fm-cred-etichetta-edit { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 4px; }

    /* ADR-035 — il catalogo a caselle: una riga per voce della scuola. */
    .fm-catalogo__gruppo { border: 1px solid rgba(0,0,0,0.08); border-radius: 6px; padding: 4px 10px 8px; margin: 8px 0; }
    .fm-catalogo__gruppo legend { font-weight: 600; font-size: 0.8125rem; padding: 0 4px; }
    .fm-catalogo__voce { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 4px 6px; font-size: 0.8125rem; }
    .fm-catalogo__voce--on { background: rgba(37,99,235,0.06); border-radius: 4px; }
    .fm-catalogo__voce label { cursor: pointer; }
    .fm-catalogo__nome { flex: 1 1 12em; max-width: 22em; padding: 3px 6px; font-size: 0.8125rem; }
    .fm-catalogo__condivisa { display: inline-flex; align-items: center; gap: 4px; font-size: 0.75rem; }
    .fm-catalogo__avviso { color: #92400e; }
    body.fm-dark .fm-catalogo__gruppo { border-color: #334155; }
    body.fm-dark .fm-catalogo__voce--on { background: rgba(96,165,250,0.12); }
    body.fm-dark .fm-catalogo__avviso { color: #fcd34d; }
    .fm-curr-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.8125rem; }
    .fm-curr-table th, .fm-curr-table td { padding: 6px 8px; border-bottom: 1px solid rgba(0,0,0,0.08); text-align: left; }
    .fm-curr-table th { background: rgba(0,0,0,0.04); font-weight: 600; }
    .fm-curr-table input[type="text"] { width: 100%; box-sizing: border-box; padding: 4px 6px; }
    .fm-curr-form { display: grid; grid-template-columns: 1fr 2fr 1fr 60px 100px; gap: 6px; align-items: center; margin: 8px 0; padding: 8px; background: rgba(0,0,0,0.03); border-radius: 4px; }
    body.fm-dark .fm-curr-table th { background: rgba(255,255,255,0.05); }
    body.fm-dark .fm-curr-form    { background: rgba(255,255,255,0.05); }

    /* Autocomplete istituti — dark-aware via tokens, WCAG AA. */
    .fm-ac { position: relative; flex: 1 1 320px; min-width: 220px; }
    .fm-ac > input { width: 100%; box-sizing: border-box; }
    .fm-ac__list {
        position: absolute; z-index: 50; left: 0; right: 0; top: calc(100% + 4px);
        margin: 0; padding: 4px; list-style: none; max-height: 280px; overflow-y: auto;
        background: var(--fm-c-surface, #fff); color: var(--fm-c-text, #1f2937);
        border: 1px solid var(--fm-c-border, #d9dee6); border-radius: 10px;
        box-shadow: 0 12px 32px rgba(0,0,0,.18);
    }
    .fm-ac__item {
        display: flex; flex-direction: column; gap: 2px; padding: 8px 10px;
        border-radius: 7px; cursor: pointer; line-height: 1.25;
    }
    .fm-ac__item:hover,
    .fm-ac__item--active { background: var(--fm-c-primary-light, #e0ecf9); }
    .fm-ac__item--active { outline: 2px solid var(--fm-c-primary, #0b5fd1); outline-offset: -2px; }
    .fm-ac__label { font-weight: 600; font-size: .875rem; }
    .fm-ac__meta  { font-size: .75rem; color: var(--fm-c-text-2, #4b5563); font-variant-numeric: tabular-nums; }
    .fm-ac__note  { color: var(--fm-c-accent, #2a9d8f); font-weight: 600; }
</style>

<?= \App\Support\ViteManifest::script('js/entries/area-docente-profilo.js') ?>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
