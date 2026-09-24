// Entry Vite di views/admin/sections.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

import { chiediConferma } from "../modules/features/avviso-incarichi.js";

/**
 * Un modulo che toglie incarichi si invia solo dopo la finestra di conferma
 * (avviso-incarichi.js). `dati()` dice chi e dove; null = niente da togliere,
 * il modulo parte senza chiedere.
 */
function confermaPrimaDiInviare(form, dati) {
    form.addEventListener("submit", async (ev) => {
        if (form.dataset.fmConfermato === "1") { return; }
        const d = dati();
        if (!d) { return; }
        ev.preventDefault();
        if (await chiediConferma(d)) {
            form.dataset.fmConfermato = "1";
            // submit() non rilancia l'evento: il modulo parte una volta sola.
            form.submit();
        }
    });
}

// La revoca dalla tabella degli incarichi: stessa finestra, una sezione. Fuori
// dal blocco 1, che esce presto quando l'istituto non ha classi censite.
document.querySelectorAll("form[data-fm-revoca]").forEach((f) => {
    confermaPrimaDiInviare(f, () => ({
        istituto: f.querySelector("input[name='institute_id']").value,
        docente: f.dataset.docente,
        nomeDocente: f.dataset.nome || "",
        indirizzo: f.dataset.indirizzo || "",
        classi: [f.dataset.classe],
    }));
});

// ── blocco 1 ──
{
(function () {
    const form = document.getElementById("fm-assign-form");
    const sel  = document.getElementById("fm-assign-ind");
    const box  = document.getElementById("fm-assign-sezioni");
    if (!form || !sel || !box) { return; }
    // Anni e sezioni stanno nelle stesse righe (ADR-042: anche l'anno ha il suo corso).
    function applica() {
        const ind = (sel.value || "").toUpperCase();
        let visibili = 0;
        box.querySelectorAll("label[data-ind]").forEach((l) => {
            const suo = (l.getAttribute("data-ind") || "").toUpperCase();
            const ok  = !ind || suo === "" || suo === ind;
            // Servono ENTRAMBE le classi. Non basta aggiungere fm-d-none:
            // in utilities.css e' definita PRIMA di fm-d-flex, stessa
            // specificita', quindi display:flex vince e l'elemento resta
            // visibile. Va tolta la classe che lo mostra.
            l.classList.toggle("fm-d-none", !ok);
            l.classList.toggle("fm-d-flex", ok);
            if (!ok) { l.querySelector("input").checked = false; }
            if (ok) { visibili++; }
        });
        // Una riga d'anno senza sezioni visibili e' rumore.
        box.querySelectorAll("[data-anno-riga]").forEach((r) => {
            const viva = r.querySelector("label:not(.fm-d-none)") !== null;
            r.classList.toggle("fm-d-none", !viva);
            r.classList.toggle("fm-d-flex", viva);
        });
        let vuoto = box.querySelector("[data-empty]");
        if (!vuoto) {
            vuoto = document.createElement("p");
            vuoto.setAttribute("data-empty", "1");
            vuoto.className = "fm-muted fm-text-13 fm-m-0";
            box.appendChild(vuoto);
        }
        vuoto.classList.toggle("fm-d-none", visibili > 0);
        vuoto.textContent = "Nessuna classe censita per questo indirizzo.";
    }
    sel.addEventListener("change", applica);

    // ── Lo stato di partenza ────────────────────────────────────────
    // Il modulo mostra gli incarichi che il docente ha gia' in quel
    // corso. Da qui il salvataggio e' un "questo e' l'elenco": cio' che
    // si spunta viene assegnato, cio' a cui si toglie la spunta viene
    // tolto. Mostrare lo stato e poi ignorare le caselle tolte sarebbe
    // peggio che non mostrarlo: il modulo direbbe una cosa e ne farebbe
    // un'altra.
    const doc   = document.getElementById("fm-assign-doc");
    const stato = document.getElementById("fm-assign-stato-val");
    let mappa = {};
    try {
        mappa = JSON.parse(document.getElementById("fm-assign-stato").textContent || "{}");
    } catch (e) { mappa = {}; }

    function correnti() {
        const perDoc = mappa[doc && doc.value] || {};
        return perDoc[sel.value] || [];
    }
    function riflettiStato() {
        const gia = correnti();
        form.querySelectorAll("input[name='classe[]']").forEach((i) => {
            i.checked = gia.indexOf(i.value) !== -1;
        });
        // Le caselle nascoste dal filtro non contano: applica() le
        // sbianca, e lo stato deve restare quello dell'indirizzo scelto.
        applica();
        stato.value = gia.join(",");
    }
    if (doc) { doc.addEventListener("change", riflettiStato); }
    sel.addEventListener("change", riflettiStato);

    confermaPrimaDiInviare(form, () => {
        const gia = correnti();
        const ora = Array.prototype.slice
            .call(form.querySelectorAll("input[name='classe[]']:checked"))
            .map((i) => { return i.value; });
        const tolte = gia.filter((c) => { return ora.indexOf(c) === -1; });
        if (tolte.length === 0 || !doc) { return null; }
        const opzione = doc.options[doc.selectedIndex];
        return {
            istituto: form.querySelector("input[name='institute_id']").value,
            docente: doc.value,
            nomeDocente: opzione ? opzione.textContent.trim() : "",
            indirizzo: sel.value,
            classi: tolte,
        };
    });

    applica();
    riflettiStato();
})();

}

// ── blocco 2 ──
{
(function () {
    const q = document.getElementById("fm-inc-q");
    const fi = document.getElementById("fm-inc-ind");
    const fa = document.getElementById("fm-inc-anno");
    const conta = document.getElementById("fm-inc-conta");
    const tab = document.getElementById("fm-inc-tabella");
    if (!tab || !q) { return; }
    const righe = Array.prototype.slice.call(tab.querySelectorAll("tbody tr"));
    function filtra() {
        const testo = (q.value || "").trim().toLowerCase();
        let visti = 0;
        righe.forEach((r) => {
            const ok = (!testo || r.getAttribute("data-doc").toLowerCase().indexOf(testo) !== -1)
                  && (!fi.value || r.getAttribute("data-ind") === fi.value)
                  && (!fa.value || r.getAttribute("data-anno") === fa.value);
            // Su <tr> basta fm-d-none: nessuna utility di display
            // concorrente, a differenza delle label flex.
            r.classList.toggle("fm-d-none", !ok);
            if (ok) { visti++; }
        });
        conta.textContent = `${visti  } di ${  righe.length}`;
    }
    [q, fi, fa].forEach((el) => {
        el.addEventListener("input", filtra);
        el.addEventListener("change", filtra);
    });
    filtra();
})();

}

// ── blocco 3 ──
{
(function () {
    const q = document.getElementById("fm-mat-cerca");
    const corpo = document.getElementById("fm-mat-corpo");
    const conta = document.getElementById("fm-mat-conta");
    if (!q || !corpo) { return; }
    const righe = Array.prototype.slice.call(corpo.querySelectorAll("tr"));
    function filtra() {
        const t = q.value.trim().toLowerCase();
        let visti = 0;
        righe.forEach((tr) => {
            const ok = t === "" || (tr.dataset.doc || "").indexOf(t) !== -1;
            tr.classList.toggle("fm-d-none", !ok);
            if (ok) { visti++; }
        });
        conta.textContent = `${visti  } di ${  righe.length  } docenti`;
    }
    q.addEventListener("input", filtra);
    filtra();
})();

}

// ── blocco 4 ──
{
(function () {
    const q     = document.getElementById("fm-stud-q");
    const fInd  = document.getElementById("fm-stud-ind");
    const fCls  = document.getElementById("fm-stud-cls");
    const fSenza= document.getElementById("fm-stud-senza");
    const conta = document.getElementById("fm-stud-conta");
    const tab   = document.getElementById("fm-stud-tabella");
    if (!tab) { return; }
    const righe = Array.prototype.slice.call(tab.querySelectorAll("tbody tr"));

    function filtra() {
        const testo = (q.value || "").trim().toLowerCase();
        const ind   = fInd.value, cls = fCls.value, senza = fSenza.checked;
        let visti = 0;
        righe.forEach((r) => {
            const ok = (!testo || r.getAttribute("data-user").toLowerCase().indexOf(testo) !== -1)
                  && (!ind   || r.getAttribute("data-ind") === ind)
                  && (!cls   || r.getAttribute("data-cls") === cls)
                  && (!senza || r.getAttribute("data-senza") === "1");
            r.classList.toggle("fm-d-none", !ok);
            if (ok) { visti++; }
        });
        conta.textContent = `${visti  } di ${  righe.length}`;
    }
    [q, fInd, fCls, fSenza].forEach((el) => {
        el.addEventListener("input", filtra);
        el.addEventListener("change", filtra);
    });
    filtra();

    // Nella riga, la tendina sezione segue l'indirizzo scelto: offrire a
    // uno studente dello scientifico una sezione dello sportivo lo
    // ancorerebbe a un corso non suo, e non vedrebbe nulla.
    tab.querySelectorAll("form").forEach((f) => {
        const si = f.querySelector(".fm-stud-ind-sel");
        const sc = f.querySelector(".fm-stud-cls-sel");
        if (!si || !sc) { return; }
        function sync() {
            const ind = (si.value || "").toUpperCase();
            Array.prototype.slice.call(sc.options).forEach((o) => {
                if (!o.value) { return; }
                const suo = (o.getAttribute("data-ind") || "").toUpperCase();
                const ok = (!ind || suo === "" || suo === ind);
                o.hidden = !ok;
                o.disabled = !ok;
                if (!ok && o.selected) { sc.value = ""; }
            });
        }
        si.addEventListener("change", sync);
        sync();
    });
})();



// ── P8 (2026-09-04): il select dell'istituto invia il form con l'attributo
// data-fm-autosubmit, gestito dal 2026-09-05 da core/declarative.js.
}
