<?php
/** @var string|null $errorMessage */
/** @var bool|null   $done */
/** @var string      $csrf */
/** @var bool        $singleMode */
/** @var list<string>|null $allowedRoles  ADR-032: [] (1), ['teacher'] (2), ['student','teacher'] (3) */
$singleMode   = (bool)($singleMode ?? false);
$allowedRoles = isset($allowedRoles) && is_array($allowedRoles)
    ? array_values($allowedRoles)
    : ($singleMode ? ['student'] : ['student', 'teacher']);
$onlyRole     = count($allowedRoles) === 1 ? $allowedRoles[0] : null;
?>
<div class="fm-card fm-card--form fm-card--modal">
    <h1 class="fm-title">✍️ Registrazione</h1>

    <?php if ($allowedRoles === []): ?>
        <div class="fm-alert fm-alert--warning">
            Le iscrizioni non sono aperte: la piattaforma è in uso personale.
            Gli studenti entrano con la <a class="fm-link" href="/accesso-classe">credenziale della classe</a>.
        </div>
        <a class="fm-btn fm-btn--primary fm-btn--full" href="/login">Vai al login</a>
    <?php elseif (!empty($done)): ?>
        <div class="fm-alert fm-alert--success">
            Registrazione inviata. In attesa di approvazione da parte dell'amministratore.
            Riceverai comunicazione via email quando l'account sarà attivo.
        </div>
        <a class="fm-btn fm-btn--primary fm-btn--full" href="/login">Vai al login</a>
    <?php else: ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="fm-alert fm-alert--error"><?= e($errorMessage) ?></div>
        <?php endif; ?>
        <form method="post" action="/register" autocomplete="on" id="fm-register-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <?php if ($onlyRole !== null): ?>
                <!-- ADR-032 — un solo ruolo ammesso nello scenario: campo nascosto.
                     L'id="role" serve al JS in fondo (isTeacher legge #role). -->
                <input type="hidden" id="role" name="role" value="<?= e($onlyRole) ?>">
                <p class="fm-muted fm-text-em-md fm-mt-0" >
                    <?= $onlyRole === 'teacher'
                        ? 'Registrazione riservata ai docenti. Gli studenti non hanno account: entrano con la credenziale della classe.'
                        : 'Registrazione riservata agli studenti del docente.' ?>
                </p>
            <?php else: ?>
                <label class="fm-label" for="role">Ruolo</label>
                <select id="role" class="fm-input" name="role" required>
                    <option value="student">Studente</option>
                    <option value="teacher">Docente</option>
                </select>
            <?php endif; ?>

            <label class="fm-label" for="first_name">Nome</label>
            <input id="first_name" class="fm-input" type="text" name="first_name" maxlength="80" required>

            <label class="fm-label" for="last_name">Cognome</label>
            <input id="last_name"  class="fm-input" type="text" name="last_name"  maxlength="80" required>

            <label class="fm-label" for="fm-reg-email">Email</label>
            <input id="fm-reg-email" class="fm-input" type="email" name="email" maxlength="180" required>

            <label class="fm-label" for="fm-reg-pwd">Password (min 8 caratteri)</label>
            <input id="fm-reg-pwd"   class="fm-input" type="password" name="password"
                   autocomplete="new-password" minlength="8" required>

            <?php /* WS3 — età/genitore solo in modalità 'full'. In 'reduced' niente
                     data di nascita né dati del genitore (minimizzazione). */ ?>
            <?php if (($studentRegMode ?? 'full') === 'full'): ?>
            <!-- Phase 25.C2 — birth_date per studenti (Art. 8 GDPR validation
                 minori D.Lgs. 101/2018: < 14 anni richiede parent_email).
                 Hidden per docenti (non serve età). -->
            <div id="fm-reg-birth-block" class="fm-inst-block" hidden>
                <label class="fm-label" for="birth_date">Data di nascita</label>
                <input id="birth_date" class="fm-input" type="date" name="birth_date"
                       max="<?= date('Y-m-d') ?>">
                <p class="fm-muted fm-text-em-base" >Necessaria per validare i requisiti GDPR minori (Art. 8). Solo studenti.</p>
            </div>

            <!-- Phase 25.C7 — parent_email obbligatorio se età < 14. Mostrato
                 dinamicamente quando birth_date è compilata e calcola età < 14. -->
            <div id="fm-reg-parent-block" class="fm-inst-block" hidden>
                <label class="fm-label" for="parent_email">Email del genitore o tutore legale</label>
                <input id="parent_email" class="fm-input" type="email" name="parent_email"
                       maxlength="180" autocomplete="off">
                <label class="fm-label" for="parent_name">Nome del genitore o tutore (opzionale)</label>
                <input id="parent_name" class="fm-input" type="text" name="parent_name" maxlength="120">
                <p class="fm-muted fm-text-em-md" >
                    Hai meno di 14 anni: il GDPR (Art. 8) richiede il consenso di un genitore.
                    Invieremo un'email al genitore/tutore con un link di conferma. L'account
                    sarà attivo solo dopo la conferma del genitore (entro 30 giorni).
                </p>
            </div>
            <?php endif; ?>

            <!-- Termini e AUP si accettano; l'informativa si legge (art. 13):
                 è una presa visione, non un consenso (24/9/2026). Casella non
                 pre-spuntata. -->
            <div class="fm-inst-block fm-mt-4" >
                <label class="fm-label fm-d-flex fm-gap-2 fm-items-start fm-fw-400" >
                    <input type="checkbox" name="accept_tos" value="1" required class="fm-mt-1">
                    <span>Accetto i <a href="/legal/tos" target="_blank" class="fm-link">Termini di Servizio</a>
                    e l'<a href="/legal/aup" target="_blank" class="fm-link">uso accettabile (AUP)</a>.
                    Ho letto l'<a href="/privacy/informativa" target="_blank" class="fm-link">Informativa privacy</a>
                    (art. 13 GDPR).</span>
                </label>
            </div>

            <!-- Phase 14 — istituto MIUR autocomplete server-side (/api/scuole).
                 Il JSON sorgente (~54 MB) non viene MAI esposto al client.
                 Progressione studente: cerca istituto → seleziona → sblocca
                 indirizzo (tipologie distinte per quella scuola) → classe.
                 Teacher: stesso meccanismo, istituti accumulati in lista. -->
            <div id="fm-reg-inst-student" class="fm-inst-block">
                <label class="fm-label" for="fm-reg-inst-search">Istituto (cerca almeno 3 caratteri)</label>
                <input id="fm-reg-inst-search" class="fm-input" type="text"
                       autocomplete="off" placeholder="Nome scuola o comune">
                <div id="institute_results" class="fm-autocomplete" hidden></div>
                <input type="hidden" name="institute_denom"   id="institute_denom">
                <input type="hidden" name="institute_comune"  id="institute_comune">
                <input type="hidden" name="institute_code"    id="institute_code">
                <p id="institute_selected" class="fm-muted fm-text-em-md"  hidden></p>

                <label class="fm-label" for="reg_indirizzo">Indirizzo</label>
                <select id="reg_indirizzo" class="fm-input" name="reg_indirizzo" disabled>
                    <option value="">— Seleziona prima l'istituto —</option>
                </select>
                <label class="fm-label" for="reg_classe">Classe</label>
                <select id="reg_classe" class="fm-input" name="reg_classe" disabled>
                    <option value="">— Seleziona prima l'indirizzo —</option>
                </select>
                <p class="fm-muted fm-text-em-base" >Istituto, indirizzo e classe servono al docente per profilare gli accessi degli studenti.</p>
            </div>
            <div id="fm-reg-inst-teacher" class="fm-inst-block" data-scuola-obbligatoria="<?= \App\Services\SenzaScuola::obbligatoria() ? '1' : '0' ?>" hidden>
                <label class="fm-label" for="institute_search_t">Istituti in cui lavori<?= \App\Services\SenzaScuola::obbligatoria() ? '' : ' (facoltativo)' ?></label>
                <input id="institute_search_t" class="fm-input" type="text"
                       autocomplete="off" placeholder="Nome scuola o comune">
                <div id="institute_results_t" class="fm-autocomplete" hidden></div>
                <ul id="institute_chips" class="fm-chip-list" hidden></ul>
                <p class="fm-muted fm-text-em-base" >Cerca e clicca per aggiungere. I dati ufficiali provengono dal MIUR.</p>
                <?php if (!\App\Services\SenzaScuola::obbligatoria()): ?>
                    <?php /* 2026-09-22 — la scuola e' facoltativa fuori dallo scenario 3.
                             Dice DOVE lavori, non che cosa insegni: e' un dato che
                             identifica il posto di lavoro di una persona, e chiederlo
                             per forza a chi si iscrive a uno strumento personale non
                             e' proporzionato (art. 5(1)(c)).
                             Facoltativo non vuol dire indolore: l'elenco di cio' che
                             resta spento sta in App\Services\SenzaScuola, in un posto
                             solo, perche' serve anche qui, nelle API e nel profilo. */ ?>
                    <details class="fm-mt-2 fm-text-13">
                        <summary>Puoi anche non indicarne nessuna: che cosa cambia</summary>
                        <p class="fm-muted fm-mt-1">
                            Senza una scuola collegata restano <strong>spenti</strong>:
                        </p>
                        <ul class="fm-muted fm-text-13">
                            <?php foreach (\App\Services\SenzaScuola::cosaSiPerde() as $_v): ?>
                                <li><strong><?= e($_v['cosa']) ?></strong> — <?= e($_v['perche']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="fm-muted fm-mt-1">
                            Continuano invece a funzionare:
                            <?= e(implode(', ', \App\Services\SenzaScuola::cosaResta())) ?>.
                        </p>
                        <p class="fm-muted fm-mt-1">
                            La puoi aggiungere quando vuoi dal tuo profilo, e toglierla
                            allo stesso modo.
                        </p>
                    </details>
                <?php endif; ?>
                <?php if (!\App\Support\DeploymentScenario::isInstitute()): ?>
                    <?php /* Scenari 1 e 2. Nello scenario 3 l'Istituto è Titolare e
                             quei dati li fornisce davvero: lì questo testo sarebbe
                             falso, e non compare. Si usa <details> e non l'infotip
                             perché questa pagina non carica il modulo che lo apre, e
                             una spiegazione che dipende da JavaScript su un modulo di
                             iscrizione è una spiegazione che qualcuno non leggerà. */ ?>
                    <details class="fm-mt-2 fm-text-13">
                        <summary>Perché ti chiediamo la scuola, e che valore ha quello che scrivi</summary>
                        <p class="fm-muted fm-mt-1">
                            Scuola, indirizzo e classe <strong>li dichiari tu</strong>, e servono
                            a delimitare a chi sono visibili i contenuti che pubblichi.
                            Non arrivano dalla tua scuola: l'Istituto non li fornisce,
                            non li conferma e non li può correggere. Non sono quindi
                            l'organizzazione ufficiale delle classi — sono la tua
                            dichiarazione, e la cambi tu quando cambia.
                        </p>
                        <p class="fm-muted">
                            Una classe, poi, la puoi <strong>usare</strong> solo se un
                            amministratore della piattaforma te ne ha dato l'incarico:
                            finché non ce l'hai, la tua spunta resta sospesa e i
                            materiali legati a quella classe non sono raggiungibili.
                        </p>
                    </details>
                <?php endif; ?>
            </div>

            <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Invia richiesta</button>
        </form>
        <p class="fm-muted fm-mt-4 fm-text-center" >
            Hai già un account? <a class="fm-link" href="/login">Accedi</a>
        </p>
        <style>
            .fm-autocomplete { position: relative; margin-top: -6px; margin-bottom: 8px;
                max-height: 240px; overflow-y: auto; background: #fff; border: 1px solid #c7cdd6;
                border-top: none; border-radius: 0 0 4px 4px; box-shadow: 0 2px 4px rgba(0,0,0,.08); }
            .fm-autocomplete-item { padding: 6px 10px; cursor: pointer; font-size: .9rem; color: #1a1a1a; }
            .fm-autocomplete-item:hover,
            .fm-autocomplete-item[aria-selected="true"] { background: #e3ecf7; }
            .fm-autocomplete-item small { color: #555; }
            .fm-chip-list { list-style: none; padding: 0; margin: 6px 0; display: flex; flex-wrap: wrap; gap: 4px; }
            .fm-chip { background: #e3ecf7; color: #0a4fad; padding: 4px 8px; border-radius: 12px;
                font-size: .85rem; display: inline-flex; align-items: center; gap: 6px; }
            .fm-chip button { background: transparent; border: 0; color: #8a1024; font-size: 1rem;
                cursor: pointer; padding: 0 2px; line-height: 1; }
        </style>
        <?= \App\Support\ViteManifest::script('js/entries/auth-register.js') ?>
    <?php endif; ?>
</div>
