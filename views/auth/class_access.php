<?php
/**
 * ADR-032 — Accesso per la classe (scenari 1 e 2).
 *
 * Gli studenti non hanno un account: entrano con la credenziale che il
 * docente ha creato per la classe (teacher_access_credentials). Il POST va
 * all'endpoint /api/access/student-login, che mette il grant nel portachiavi
 * di sessione; nessun dato personale dello studente viene raccolto.
 *
 * Piano classi-credenziali-scenari, C — il portachiavi: qui si vedono le
 * credenziali gia' inserite, se ne aggiunge un'altra, si esce da una sola, si
 * sceglie di ricordare il dispositivo e si mostra il QR del pacchetto per i
 * compagni. Il modulo per aggiungerne una c'e' in ogni scenario: lo scenario
 * decide solo come si chiama cio' che si aggiunge.
 *
 * @var string $csrf
 * @var array<string,mixed> $scenario
 * @var list<array<string,mixed>> $grants
 * @var list<array{label:string,reason:string}> $notices
 * @var bool $remember_enabled
 * @var string $errore
 */
$controller = (string)($scenario['controller'] ?? '');
// 19/9/2026 — nello scenario 1 c'e' solo l'autore (ADR-032): non si parla di
// «un altro docente». Il modulo pero' resta (20/9/2026): lo scenario dice
// quanti docenti ci sono, non quante credenziali, e il portachiavi ne tiene
// piu' d'una anche dello stesso docente (la classe e il gruppo di recupero,
// o quella rifatta mentre la vecchia e' ancora valida). Senza il modulo la
// pagina, col portachiavi aperto, non aveva nessun campo dove digitare.
$piuDocenti = (bool)($scenario['more_teachers'] ?? true);
$grants     = $grants ?? [];
$notices    = $notices ?? [];
$errore     = (string)($errore ?? '');
$erroriQr   = [
    'qr'       => 'Questo QR non è più valido: chiedi al docente quello nuovo.',
    'servizio' => 'Servizio momentaneamente non disponibile. Riprova tra poco.',
];
$reasonLabel = ['disattivata' => 'è stata disattivata dal docente', 'scaduta' => 'è scaduta', 'eliminata' => 'non esiste più'];
?>
<div class="fm-card fm-card--modal">
    <h1 class="fm-title">🎓 Accesso per la classe</h1>

    <?php if ($notices !== []): ?>
        <div class="fm-alert fm-alert--warn" role="status">
            <?php foreach ($notices as $n): ?>
                <p class="fm-m-0">La credenziale «<?= e($n['label']) ?>» <?= e($reasonLabel[$n['reason']] ?? 'non è più valida') ?>: chiedi al docente quella nuova.</p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($errore !== '' && isset($erroriQr[$errore])): ?>
        <div class="fm-alert fm-alert--error" role="alert"><?= e($erroriQr[$errore]) ?></div>
    <?php endif; ?>

    <?php if ($grants !== []): ?>
        <section class="fm-keychain" aria-labelledby="fm-keychain-title">
            <h2 id="fm-keychain-title" class="fm-text-em-md fm-mb-1">🔑 Le tue credenziali</h2>
            <ul class="fm-keychain__list fm-pl-0" style="list-style:none">
                <?php foreach ($grants as $g): ?>
                    <li class="fm-keychain__item fm-d-flex fm-items-center fm-gap-2 fm-mb-1">
                        <span class="fm-keychain__label">
                            <?php /* ADR-044 — le sigle delle materie per esteso, al passaggio del mouse. */ ?>
                            <strong<?php if (!empty($g['materie_nomi'])): ?> title="Materie: <?= e((string)$g['materie_nomi']) ?>"<?php endif; ?>><?= e($g['label'] !== '' ? $g['label'] : 'Credenziale') ?></strong>
                            <?php if (!empty($g['classe'])): ?>
                                <span class="fm-muted fm-text-13">· <?= e((string)$g['classe']) ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($g['credential_id'])): ?>
                            <form method="post" action="/accesso-classe/esci" class="fm-inline fm-ml-auto">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="credential_id" value="<?= (int)$g['credential_id'] ?>">
                                <button type="submit" class="fm-btn fm-btn--ghost fm-btn--sm" title="Togli questa credenziale">Esci</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="fm-d-flex fm-gap-2 fm-items-center fm-mt-2">
                <a class="fm-btn fm-btn--primary" href="/" data-full-reload>Vai ai materiali</a>
                <a class="fm-btn fm-btn--ghost fm-btn--sm" href="/accesso-classe/pacchetto.svg" target="_blank" rel="noopener"
                   title="Un QR con tutte le tue credenziali: un compagno lo scansiona e ha le stesse">📱 QR del pacchetto</a>
            </p>
            <p class="fm-muted fm-text-13">Il QR del pacchetto vale come le credenziali che contiene: mostralo solo a chi le riceverebbe dal docente.</p>
        </section>
        <hr class="fm-my-4">
        <?php if ($piuDocenti): ?>
            <h2 class="fm-text-em-md fm-mb-1">➕ Aggiungi la credenziale di un altro docente</h2>
        <?php else: ?>
            <h2 class="fm-text-em-md fm-mb-1">➕ Aggiungi un'altra credenziale</h2>
            <p class="fm-muted fm-text-13">Per esempio quella di un gruppo, o quella nuova che ti ha dato il docente: le vedrai insieme.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="fm-muted fm-text-em-md">
            Inserisci la credenziale che ti ha dato il docente. Non serve un account e
            non viene raccolto alcun dato personale: la sessione non è associata a te.
            <?php if ($piuDocenti): ?>
                Se hai più docenti sul sito, inserisci una credenziale per ciascuno: le vedrai insieme.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div id="fm-class-access-error" class="fm-alert fm-alert--error" role="alert" hidden></div>

    <form id="fm-class-access-form" method="post" action="/api/access/student-login" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php /* 21/9/2026 — l'etichetta diceva «Credenziale della classe», che è
                 il nome della cosa intera (username + password) e non del campo:
                 chi riceve dal docente «username e password» qui non ritrovava
                 la parola, e si chiedeva che cosa scrivere. Adesso il campo si
                 chiama come quello che ci va. */ ?>
        <label class="fm-label" for="fm-ca-username">Username della classe</label>
        <input id="fm-ca-username" class="fm-input" type="text" name="username"
               autocomplete="username" required minlength="3" autofocus
               aria-describedby="fm-ca-username-aiuto">
        <p id="fm-ca-username-aiuto" class="fm-muted fm-text-13 fm-m-0">
            È il nome che ti ha dato il docente insieme alla password: somiglia
            alla tua classe (per esempio <code>3b-rossi</code>), e non è il tuo nome.
        </p>
        <label class="fm-label" for="fm-ca-pwd">Password</label>
        <input id="fm-ca-pwd" class="fm-input" type="password" name="password"
               autocomplete="current-password" required>
        <label class="fm-pwtoggle">
            <input class="fm-pwtoggle__input" type="checkbox" data-pw-toggle="fm-ca-pwd">
            <span class="fm-pwtoggle__text">Mostra password</span>
        </label>
        <?php if (!empty($remember_enabled)): ?>
            <label class="fm-pwtoggle" title="Solo su un dispositivo tuo: su un PC condiviso lascia vuoto">
                <input class="fm-pwtoggle__input" type="checkbox" name="remember" value="1">
                <span class="fm-pwtoggle__text">Ricorda su questo dispositivo (fino alla scadenza della credenziale)</span>
            </label>
        <?php endif; ?>
        <button type="submit" class="fm-btn fm-btn--primary fm-btn--full"><?= $grants !== [] ? 'Aggiungi' : 'Entra' ?></button>
    </form>

    <p class="fm-muted fm-mt-4 fm-text-center">
        Sei un docente? <a class="fm-link" href="/login">Accedi con il tuo account</a>
    </p>
    <p class="fm-mt-4 fm-text-center">
        <a class="fm-btn fm-btn--ghost fm-btn--full fm-login-home" href="/" data-full-reload>← Torna alla home</a>
    </p>
    <p class="fm-muted fm-text-13 fm-text-center fm-mt-2">
        <a class="fm-link" href="/privacy/informativa">Informativa privacy</a>
        <?php if ($controller !== ''): ?> · Titolare: <?= e($controller) ?><?php endif; ?>
    </p>
    <?= \App\Support\ViteManifest::script('js/entries/auth-class-access.js') ?>
</div>
