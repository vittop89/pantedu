<?php /** @var array $user */ ?>
<div class="fm-tpl-page">
    <header class="fm-tpl-header">
        <h1 class="fm-m-0 fm-mb-1 fm-text-xl">Modelli esercizi</h1>
        <p class="fm-m-0 fm-text-muted fm-text-13">
            Personalizza i contenuti dei template <strong>Collezione</strong>, <strong>RM</strong> e <strong>Vero o Falso</strong>
            usati dal pulsante <em>+ nuovo esercizio</em>. La struttura (layout delle tabelle, checkbox, etc) è fissa;
            qui modifichi solo il <strong>contenuto</strong> (testi, opzioni, items preset).
        </p>
    </header>

    <div id="fm-tpl-status" role="status" aria-live="polite" class="fm-mb-3"></div>

    <section class="fm-tpl-card fm-tpl-card" data-kind="Collect" >
        <h2 class="fm-tpl-card__title">Collezione</h2>
        <label class="fm-d-block fm-mb-2">
            <span class="fm-tpl-label">Titolo (collapsible del gruppo)</span>
            <input type="text" class="fm-tpl-title fm-tpl-input" >
        </label>
        <label class="fm-d-block fm-mb-3">
            <span class="fm-tpl-label">Intro (testo della consegna)</span>
            <textarea class="fm-tpl-intro fm-tpl-textarea" rows="2" ></textarea>
        </label>
        <div class="fm-tpl-items-collect"></div>
        <button type="button" class="fm-tpl-add-collect fm-tpl-btn fm-tpl-btn--soft" >+ Aggiungi item</button>
    </section>

    <section class="fm-tpl-card fm-tpl-card" data-kind="RM" >
        <h2 class="fm-tpl-card__title">Risposta multipla (RM)</h2>
        <label class="fm-d-block fm-mb-2">
            <span class="fm-tpl-label">Titolo (collapsible del gruppo)</span>
            <input type="text" class="fm-tpl-title fm-tpl-input" >
        </label>
        <label class="fm-d-block fm-mb-3">
            <span class="fm-tpl-label">Intro (testo della consegna)</span>
            <textarea class="fm-tpl-intro fm-tpl-textarea" rows="2" ></textarea>
        </label>
        <div class="fm-tpl-items-rm"></div>
        <button type="button" class="fm-tpl-add-rm fm-tpl-btn fm-tpl-btn--soft" >+ Aggiungi quesito</button>
    </section>

    <section class="fm-tpl-card fm-tpl-card" data-kind="VF" >
        <h2 class="fm-tpl-card__title">Vero o Falso (VF)</h2>
        <label class="fm-d-block fm-mb-2">
            <span class="fm-tpl-label">Titolo (collapsible del gruppo)</span>
            <input type="text" class="fm-tpl-title fm-tpl-input" >
        </label>
        <label class="fm-d-block fm-mb-3">
            <span class="fm-tpl-label">Intro (testo della consegna)</span>
            <textarea class="fm-tpl-intro fm-tpl-textarea" rows="2" ></textarea>
        </label>
        <div class="fm-tpl-items-vf"></div>
        <button type="button" class="fm-tpl-add-vf fm-tpl-btn fm-tpl-btn--soft" >+ Aggiungi affermazione</button>
    </section>

    <footer class="fm-d-flex fm-justify-end fm-gap-3 fm-mt-5">
        <button type="button" id="fm-tpl-reload" class="fm-tpl-btn fm-tpl-btn--ghost">Annulla modifiche</button>
        <button type="button" id="fm-tpl-save" class="fm-tpl-btn fm-tpl-btn--primary">Salva modelli</button>
    </footer>
</div>

<script type="module" src="/js/modules/features/teacher-templates.js"></script>
