<?php
/**
 * Phase G14 — RisDoc admin panel partial.
 *
 * Body-only markup (no <html>/<head>/<style>) per essere incluso inline
 * dentro la tab "RisDoc" della pagina /admin/templates moderna.
 * I CSS sono ora in css/layout.css sotto namespace .fm-ar-* (G14.3).
 * Lo script admin-risdoc.js gia' cerca #fm-ar-root e funziona invariato.
 */
?>
<div class="fm-ar-wrap" id="fm-ar-root">
    <div class="fm-d-flex fm-items-center fm-gap-2" style="margin:0 0 12px">
        <strong>Catalogo template RisDoc</strong>
        <button type="button" class="fm-infotip" aria-label="Info catalogo template"><span class="fm-infotip__body" hidden><p>Tabella unica: modifica <strong>posizione</strong>, <strong>argomento</strong> e <strong>partizione</strong> (e rinomina un'intera partizione), leggi in un colpo d'occhio chi vede ogni modello e che cosa ci hanno fatto sopra i docenti (la legenda qui sotto lo spiega pastiglia per pastiglia), e gestisci visibilità per-docente, immagini e schema — tutto da qui.</p><p>ℹ️ Le <strong>partizioni</strong> organizzano i <em>template istituzionali</em> (questo catalogo) e determinano quali gruppi sono <em>forkabili</em> (vedi <a href="/admin/sidebar-config">sidebar-config</a>). Sono cosa diversa dalle <strong>categorie</strong> con cui ogni <em>docente</em> organizza i propri documenti (Area docente → Categorie): stesso nome di default, scopo diverso.</p></span></button>
    </div>
    <?php /* 21/9/2026 — la legenda della colonna delle pastiglie.
             Segnalazione dell'utente: «che significano 8 override, 8 drift,
             ok... visib tutti a 0 ecc...». La risposta deve stare in pagina,
             non in una conversazione: qui, con lo stesso vestito del riquadro
             «Come si decide chi vede un modello» del pannello «Gestisci». */ ?>
    <details class="fm-ar-detail-help fm-ar-legenda">
        <summary>ℹ Che cosa dicono le pastiglie di ogni riga</summary>
        <p>La colonna risponde a due domande: <strong>chi vede questo modello</strong> e <strong>che cosa ci
        hanno già fatto sopra i docenti</strong>. Di tutto quello che può comparire, una cosa sola chiede un tuo
        intervento — 🛡 <em>N da approvare</em>, ed è l'unica colorata; il resto è informazione. Le pastiglie si
        scrivono solo quando hanno qualcosa da dire: se una non c'è, quel numero è zero.</p>
        <ul>
            <li><strong>tutti i docenti</strong> (oppure <strong>solo un Istituto</strong>, <strong>solo indirizzo
            SCI</strong>, <strong>solo classe 3A</strong>, <strong>solo su invito</strong>) — chi vede il modello.
            È l'unica pastiglia che decida davvero: finché dice «tutti i docenti», lo vede ogni docente della
            piattaforma, spunte o non spunte. Si cambia da «Gestisci» → «Chi lo vede adesso».</li>
            <li><strong>👁 2 in più</strong> — due docenti aggiunti a mano con la spunta 👁, che vedono il modello
            pur restando fuori dall'ambito. Aggiungono e basta: per togliere l'accesso a qualcuno si stringe
            l'ambito, non si toglie la spunta. Compare solo quando l'ambito è ristretto, perché con «tutti i
            docenti» non aggiungerebbe nessuno.</li>
            <li><strong>✎ 1 può modificare</strong> — un docente può cambiare l'originale, quello che usano tutti,
            non una sua copia. Se il numero non ti torna, apri «Gestisci» e guarda chi è.</li>
            <li><strong>8 salvataggi</strong> — quello che i docenti hanno salvato nelle loro copie: un testo
            riscritto, un'immagine sostituita, anche solo una copia creata. Conta i pezzi, non le persone: dietro
            a otto possono esserci due docenti. L'originale non è toccato; serve a sapere se, cambiando il
            modello, tocchi un lavoro già avviato.</li>
            <li><strong>3 non allineati</strong> — di quei salvataggi, tre sono partiti da una versione precedente
            del modello: chi li usa non ha le tue ultime correzioni. Non si rompe niente e tornano allineati
            quando il docente risalva quel pezzo. L'elenco per esteso è nella scheda «⚠ Copie non allineate».</li>
            <li><strong>🛡 2 da approvare</strong> — due modifiche proposte da un collaboratore ✎ e ferme in
            attesa di te: finché restano lì, il lavoro di un collega è fermo. Si risponde dalla scheda «🛡
            Modifiche in revisione», approvando o rifiutando.</li>
            <li><strong>Gli zeri non si scrivono.</strong> Una riga con la sola pastiglia di chi lo vede vuol dire
            che quel modello non l'ha ancora toccato nessuno. Per lo stesso motivo è sparito il vecchio
            «ok», che diceva soltanto che i non allineati erano zero.</li>
            <li><strong>Le parole di prima.</strong> «override» erano i salvataggi dei docenti, «drift» i
            salvataggi non allineati, «visib» le spunte 👁, «collab» i collaboratori ✎.</li>
        </ul>
    </details>

    <div class="fm-ar-tabs" role="tablist">
        <button class="fm-ar-tab" role="tab" data-tab="templates" aria-selected="true">📋 Template (modifica + visibilità)</button>
        <button class="fm-ar-tab" role="tab" data-tab="pending"   aria-selected="false">🛡 Modifiche in revisione <span id="fm-ar-pending-badge" class="fm-ar-pill fm-d-none" ></span></button>
        <button class="fm-ar-tab" role="tab" data-tab="drift" aria-selected="false">⚠ Copie non allineate</button>
    </div>

    <div class="fm-ar-panel" id="fm-ar-panel">
        <div class="fm-ar-loading">Caricamento…</div>
    </div>
</div>
