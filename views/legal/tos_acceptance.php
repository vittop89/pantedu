<?php
/**
 * Form di click-acceptance ToS + AUP.
 *
 * Variabili:
 *   $tosVersion, $aupVersion  string  versioni proposte
 *   $isEarly                  bool    accettazione anticipata (versione
 *                                     pubblicata ma non ancora efficace)
 *   $effectiveFrom            ?string data di efficacia, se $isEarly
 *   $pending                  list    versioni pendenti (per il riepilogo)
 *   $csrf                     string
 *   $redirect                 string  path interno di ritorno
 *   $error                    ?string chiave errore di validazione
 */
$checkboxes = [
    'read_tos' => 'Ho letto e compreso i <strong>Terms of Service</strong> versione '
        . e($tosVersion),
    'read_aup' => 'Ho letto e compreso l\'<strong>Acceptable Use Policy</strong> versione '
        . e($aupVersion),
    'accept_responsibility' => 'Mi assumo la <strong>piena responsabilità civile, penale '
        . 'e disciplinare</strong> per i contenuti che caricherò',
    'accept_safe_harbor' => 'Sollevo l\'operatore tecnico da responsabilità per i contenuti '
        . 'caricati, riconoscendo che l\'envelope encryption per-docente gli impedisce di '
        . 'accedervi',
    'accept_takedown' => 'Mi impegno a cooperare in buona fede su procedure di Notice &amp; '
        . 'Takedown e sull\'obbligo di segnalazione ex DPR 62/2013 art. 13',
];
?>
<div class="fm-card fm-card--form fm-tos-gate">
    <h1 class="fm-title">Accettazione Termini di Servizio</h1>

    <?php if (!empty($error)): ?>
        <div class="fm-alert fm-alert--warn" role="alert">
            <?php if ($error === 'all_required'): ?>
                Per procedere devi spuntare <strong>tutte</strong> le caselle: ognuna
                corrisponde a un impegno distinto e non è possibile accettarne solo una parte.
            <?php else: ?>
                Non è stato possibile registrare l'accettazione. Riprova.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($isEarly)): ?>
        <div class="fm-alert fm-alert--info" role="status">
            Stai accettando in anticipo la nuova versione dei documenti, che diventerà
            vincolante il <strong><?= e(date('d/m/Y', strtotime((string)$effectiveFrom))) ?></strong>.
            Fino a quella data puoi continuare a usare pantedu anche senza accettare.
        </div>
    <?php else: ?>
        <div class="fm-alert fm-alert--info" role="status">
            Per continuare a utilizzare pantedu come docente devi accettare i seguenti
            documenti contrattuali.
        </div>
    <?php endif; ?>

    <div class="fm-tos-gate__docs">
        <p class="fm-mb-2"><strong>Versioni proposte:</strong></p>
        <ul>
            <li>Terms of Service (ToS) — versione <strong><?= e($tosVersion) ?></strong></li>
            <li>Acceptable Use Policy (AUP) — versione <strong><?= e($aupVersion) ?></strong></li>
        </ul>
        <?php foreach (($pending ?? []) as $p): ?>
            <?php if (!empty($p['summary'])): ?>
                <p class="fm-muted">
                    <?= e(strtoupper($p['doc_type'])) ?> <?= e($p['version']) ?>:
                    <?= e($p['summary']) ?>
                </p>
            <?php endif; ?>
        <?php endforeach; ?>
        <p>
            <a href="/legal/tos" target="_blank" rel="noopener">📄 Leggi i Terms of Service</a>
        </p>
        <p>
            <a href="/legal/aup" target="_blank" rel="noopener">📄 Leggi l'Acceptable Use Policy</a>
        </p>
    </div>

    <form method="POST" action="/tos-acceptance" id="tos-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <input type="hidden" name="tos_version" value="<?= e($tosVersion) ?>">
        <input type="hidden" name="aup_version" value="<?= e($aupVersion) ?>">

        <?php foreach ($checkboxes as $name => $labelHtml): ?>
            <label class="fm-tos-gate__check">
                <input type="checkbox" name="<?= e($name) ?>" value="1" required>
                <span><?= $labelHtml ?></span>
            </label>
        <?php endforeach; ?>

        <div class="fm-form-actions fm-mt-6">
            <button type="submit" class="fm-btn fm-btn--primary">Accetto e continuo</button>
            <a href="/logout" class="fm-btn">Esci</a>
        </div>
    </form>
</div>

<style>
.fm-tos-gate { max-width: 48rem; margin: 2rem auto; }
.fm-tos-gate__docs {
    background: var(--fm-c-bg-soft);
    border: 1px solid var(--fm-c-border);
    border-radius: 8px;
    padding: 1.25rem;
    margin: 1.5rem 0;
}
.fm-tos-gate__check {
    display: flex;
    gap: .6rem;
    align-items: flex-start;
    padding: .55rem 0;
    border-top: 1px solid var(--fm-c-divider);
}
.fm-tos-gate__check:first-of-type { border-top: 0; }
.fm-tos-gate__check input { margin-top: .25rem; flex: none; }
</style>
