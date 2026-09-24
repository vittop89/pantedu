// Entry Vite di views/area_docente/sposta_di_classe.php.
//
// La pagina funziona anche senza questo script (caselle e modulo sono HTML):
// qui ci sono solo le comodita' — «seleziona tutti», il conto nel bottone,
// il bottone chiuso finche' non c'e' niente da spostare — e un avviso quando
// la classe di arrivo porta con se' un corso diverso da quello dei materiali.
// Dal 15/9/2026, prima di inviare, la finestra di riepilogo
// (riepilogo-spostamento.js).

import { confermaSpostamento } from "../modules/features/riepilogo-spostamento.js";
import { collegaPartenza } from "../modules/features/sposta-partenza.js";

const form = document.getElementById("sposta-form");

// Cambiare la classe di partenza ricarica l'elenco: prima restava quello vecchio.
collegaPartenza(document.getElementById("sposta-classe-da"), {
    modulo: form,
    invia: document.getElementById("sposta-invia"),
    elenco: document.getElementById("sposta-elenco"),
});
if (form) {
    const tutti = document.getElementById("sposta-tutti");
    const voci = Array.from(form.querySelectorAll("[data-sposta-voce]"));
    const invia = document.getElementById("sposta-invia");
    const arrivo = document.getElementById("sposta-classe-a");
    const nota = document.getElementById("sposta-nota");
    const testoNota = nota ? nota.textContent : "";

    const aggiorna = () => {
        const scelte = voci.filter((v) => v.checked).length;
        if (invia) {
            invia.disabled = scelte === 0 || !(arrivo && arrivo.value);
            invia.textContent = scelte === 0
                ? "Sposta gli elementi scelti"
                : (scelte === 1 ? "Sposta 1 elemento" : `Sposta ${scelte} elementi`);
        }
        if (tutti) {
            tutti.checked = scelte > 0 && scelte === voci.length;
            tutti.indeterminate = scelte > 0 && scelte < voci.length;
        }
        if (nota && arrivo) {
            const opzione = arrivo.options[arrivo.selectedIndex];
            const corso = opzione ? (opzione.dataset.indirizzo || "") : "";
            nota.textContent = corso
                ? `La classe di arrivo è del corso ${corso}: anche il corso dei materiali diventa ${corso}. ${testoNota}`
                : testoNota;
        }
    };

    if (tutti) {
        tutti.addEventListener("change", () => {
            for (const v of voci) v.checked = tutti.checked;
            aggiorna();
        });
    }
    for (const v of voci) v.addEventListener("change", aggiorna);
    if (arrivo) arrivo.addEventListener("change", aggiorna);
    aggiorna();

    form.addEventListener("submit", async (ev) => {
        if (form.dataset.fmConfermato === "1" || form.dataset.fmVecchio === "1") return;
        const opzione = arrivo ? arrivo.options[arrivo.selectedIndex] : null;
        if (!opzione || !opzione.value) return;
        ev.preventDefault();
        const scelti = (nome) => voci
            .filter((v) => v.checked && v.name === nome)
            .map((v) => v.dataset.titolo || "");
        const ok = await confermaSpostamento({
            partenza: {
                code: form.dataset.partenzaCodice || "",
                indirizzo: form.dataset.partenzaIndirizzo || null,
                sospesa: form.dataset.partenzaSospesa === "1",
            },
            arrivo: { code: opzione.dataset.codice || "", indirizzo: opzione.dataset.indirizzo || null },
            contenuti: scelti("contenuti[]"),
            verifiche: scelti("verifiche[]"),
            totale: voci.length,
        });
        if (ok) {
            form.dataset.fmConfermato = "1";
            // submit() non rilancia l'evento: il modulo parte una volta sola.
            form.submit();
        }
    });
}
