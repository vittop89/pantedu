<?php
/**
 * Global modals partial — license, author banner,
 * bottom bar. Rendered together with the sidebar on any full page.
 * Skipped when the layout is invoked in embed mode (?embed=1).
 *
 * Convention: modal IDs and classes use the `fm-modal` /
 * `fm-bb` prefix (kebab-case, single-dash).
 *
 * WCAG 2.1 AA (Phase C.1):
 *   - role="dialog" + aria-modal="true" su ogni modal
 *   - aria-labelledby pointing al <h2> dialog title
 *   - hidden attribute usato al posto di class="fm-d-none" per
 *     coerenza con AT (lo style inline e' mantenuto per compat con
 *     codice JS legacy che fa display:block/none toggle)
 */
?>
<div id="fm-modal-overlay" class="fm-d-none" aria-hidden="true" hidden></div>

<div id="fm-license-modal"
     class="fm-modal fm-d-none"
     role="dialog"
     aria-modal="true"
     aria-labelledby="fm-license-modal-title"
     aria-hidden="true"
     hidden>
    <div class="fm-modal-body">
        <h2 id="fm-license-modal-title">Informazioni sulla Licenza</h2>
        <div class="license-details">
            <b>Copyright:</b>
            <span>Contenuti originali di <a href="/" target="_blank" rel="noopener noreferrer">pantedu.eu</a>
                © 2022 rilasciati sotto
                <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/deed.it" target="_blank"
                    rel="license noopener noreferrer">CC BY-NC-SA 4.0</a>
                <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/deed.it" target="_blank"
                    rel="license noopener noreferrer"
                    class="fm-link-reset fm-d-inline-block fm-vt-middle"
                    aria-label="Vai a Creative Commons BY-NC-SA 4.0">
                    <img src="https://mirrors.creativecommons.org/presskit/icons/cc.svg"
                        class="fm-icon-inline" alt="" aria-hidden="true">
                    <img src="https://mirrors.creativecommons.org/presskit/icons/by.svg"
                        class="fm-icon-inline" alt="" aria-hidden="true">
                    <img src="https://mirrors.creativecommons.org/presskit/icons/nc.svg"
                        class="fm-icon-inline" alt="" aria-hidden="true">
                    <img src="https://mirrors.creativecommons.org/presskit/icons/sa.svg"
                        class="fm-icon-inline" alt="" aria-hidden="true">
                </a>.
            </span>
        </div>
        <p class="fm-mt-4 fm-text-em-lg">
            <strong>Documenti correlati:</strong>
            <a href="/legal/tos">📜 Termini di Servizio</a> ·
            <a href="/legal/aup">📏 Uso accettabile (AUP)</a> ·
            <a href="/legal/takedown-procedure">🛡️ Procedura takedown</a>
        </p>
        <button class="fm-modal-close" data-target-modal="fm-license-modal" type="button">Chiudi</button>
    </div>
</div>

<?php /* Bottom-bar globale.
         TODO legacy: ID `fm-license-section`, `open-author-modal`
         da rinominare in `fm-bb-*` quando si
         modernizza la barra (vedi Phase 13 naming guidelines).
         Trust links GDPR (Privacy/Sicurezza/DPO) inseriti qui per Art. 12
         §2 GDPR (facilitazione esercizio diritti). */ ?>
<nav id="bottom-bar" aria-label="Informazioni legali e contatti">
    <div class="bar-content">
        <a href="#" id="fm-license-section">© 2022 pantedu.eu</a>
        <div class="bar-content-links">
            <a href="#" id="open-author-modal">Info Autore</a>
            <?php /* Link legali/trust raggruppati in disclosure (BEM fm-bb-menu).
                     Apertura: click (JS, sincronizza aria-expanded) oppure ~0.5s
                     mouseover / focus-within (CSS, fallback no-JS — i link GDPR
                     Art.12 §2 restano raggiungibili anche senza JavaScript). */ ?>
            <div class="fm-bb-menu" data-fm-bb-menu>
                <button type="button"
                        class="fm-bb-menu__trigger"
                        id="fm-bb-menu-trigger"
                        aria-expanded="false"
                        aria-haspopup="true"
                        aria-controls="fm-bb-menu-panel">
                    <span class="fm-bb-menu__label">📑 Note legali</span>
                    <span class="fm-bb-menu__caret" aria-hidden="true">▾</span>
                </button>
                <div class="fm-bb-menu__panel"
                     id="fm-bb-menu-panel"
                     role="menu"
                     aria-labelledby="fm-bb-menu-trigger">
                    <a class="fm-bb-menu__link" role="menuitem" href="/legal/tos" title="Termini di Servizio">📜 ToS</a>
                    <a class="fm-bb-menu__link" role="menuitem" href="/legal/aup" title="Acceptable Use Policy">📏 AUP</a>
                    <a class="fm-bb-menu__link" role="menuitem" href="/privacy/informativa" title="Informativa privacy">📋 Privacy</a>
                    <a class="fm-bb-menu__link" role="menuitem" href="/security" title="Sicurezza tecnica">🛡️ Sicurezza</a>
                    <a class="fm-bb-menu__link" role="menuitem" href="/segnalazione-contenuti" title="Segnala contenuto illecito">⚠️ Segnala</a>
                    <a class="fm-bb-menu__link" role="menuitem" href="/dpo-contact" title="Richieste privacy ed esercizio dei diritti">✉️ Privacy</a>
                    <a class="fm-bb-menu__link" role="menuitem" href="/accessibility" title="Dichiarazione di accessibilita' AgID Form-A">♿ Accessibilità</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<div id="fm-author-modal"
     class="fm-modal fm-d-none"
     role="dialog"
     aria-modal="true"
     aria-labelledby="fm-author-modal-title"
     aria-hidden="true"
     hidden>
    <div class="fm-modal-body">
        <h2 id="fm-author-modal-title">Informazioni Autore</h2>
        <b>Autore:</b><span> {{OPERATORE_NOME}} (Docente di Matematica e Fisica).</span><br>
        <?php /* 23/9/2026 — la casella generale dell'istanza (`mail.contact_email`),
                 non quella di produzione scritta qui; senza, la riga non c'è. */
        $fmContatto = trim((string) \App\Core\Config::get('mail.contact_email', ''));
        if ($fmContatto !== ''): ?>
        <b>Contatti:</b><span> email - <a
                href="mailto:<?= htmlspecialchars($fmContatto, ENT_QUOTES) ?>"><?= htmlspecialchars($fmContatto) ?></a></span><br>
        <?php endif; ?>
        <button class="fm-modal-close" data-target-modal="fm-author-modal" type="button">Chiudi</button>
    </div>
</div>
