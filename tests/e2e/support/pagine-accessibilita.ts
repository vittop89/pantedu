/**
 * Le pagine che i controlli di accessibilità guardano, e come si aspetta che
 * siano pronte.
 *
 * Stavano scritte dentro le due spec di accessibilità, una copia per parte.
 * Da quando esiste anche il censimento della voce 79 le copie sarebbero tre, e
 * tre elenchi che devono restare uguali restano uguali finché qualcuno non si
 * distrae: una pagina aggiunta al controllo pubblico e non al censimento
 * sarebbe una pagina di cui non si sa quanto dipende dalla rete a runtime.
 * Perciò l'elenco è uno solo, qui.
 */
import { expect } from "@playwright/test";
import type { Page } from "@playwright/test";
import { attendiAnimazioniFerme } from "./sync/signals";

/**
 * Una pagina da controllare. `attendi` è il selettore di ciò che la pagina
 * tira giù dopo il caricamento: dove c'è, si aspetta che sia comparso prima di
 * guardare.
 */
export interface PaginaDaControllare {
    percorso: string;
    nome: string;
    attendi?: string;
}

export const PAGINE_PUBBLICHE: readonly PaginaDaControllare[] = [
    { percorso: "/", nome: "pagina iniziale" },
    { percorso: "/login", nome: "modulo di accesso" },
    { percorso: "/register", nome: "iscrizioni" },
    { percorso: "/legal/tos", nome: "termini di servizio" },
    { percorso: "/legal/aup", nome: "regole d'uso" },
    { percorso: "/privacy/informativa", nome: "informativa privacy" },
    { percorso: "/accessibility", nome: "dichiarazione di accessibilità" },
    { percorso: "/security", nome: "sicurezza tecnica" },
    { percorso: "/dpo-contact", nome: "contatto con il responsabile dei dati" },
    { percorso: "/segnalazione-contenuti", nome: "segnalazione di contenuti" },
    { percorso: "/tos-acceptance", nome: "accettazione dei termini" },
];

export const PAGINE_DEL_DOCENTE: readonly PaginaDaControllare[] = [
    { percorso: "/area-docente/profilo", nome: "profilo del docente" },
    { percorso: "/area-docente/dashboard", nome: "cruscotto del docente" },
    // ADR-041: l'avviso dei materiali su sezioni senza incarico e la sezione
    // sospesa come partenza. Con il caso vero li ha misurati axe il 14/9/2026.
    { percorso: "/area-docente/sposta-di-classe", nome: "sposta di classe" },
    { percorso: "/me/change-password", nome: "cambio della password" },
];

export const PAGINE_DELL_AMMINISTRAZIONE: readonly PaginaDaControllare[] = [
    { percorso: "/admin/dashboard", nome: "cruscotto dell'amministrazione" },
    { percorso: "/admin/waf/dashboard", nome: "cruscotto delle difese" },
    // Il pannello delle anomalie arriva da `/api/admin/security/config`, dopo
    // il caricamento: senza questa attesa la scansione guardava la pagina
    // senza i suoi trenta campi (`js/entries/admin-waf-config.js`).
    { percorso: "/admin/waf/config", nome: "configurazione delle difese", attendi: '[name="ea_enabled"]' },
    { percorso: "/admin/templates", nome: "modelli" },
    { percorso: "/admin/sidebar-config", nome: "configurazione della barra" },
    { percorso: "/admin/institutes", nome: "istituti" },
    { percorso: "/admin/sections", nome: "sezioni e incarichi" },
    { percorso: "/admin/system/deployment", nome: "modalità di installazione" },
];

/**
 * Aspetta che il documento abbia smesso di cambiare.
 *
 * Serve perché una pagina che si compone dopo il caricamento non è pronta a
 * `domcontentloaded`: guardarla lì significa spesso non trovare niente, perché
 * non c'è ancora niente. Si osserva il DOM e lo si considera fermo dopo mezzo
 * secondo senza modifiche.
 *
 * Se non si ferma mai — una pagina che interroga il server a intervalli, un
 * indicatore che gira — si smette di aspettare dopo `limite` e si guarda
 * comunque: è il momento migliore disponibile, e vale più di non guardare.
 */
export async function attendiDomFermo(pagina: Page, limite = 8000): Promise<void> {
    await pagina.evaluate(
        async ({ quiete, limite: max }) => {
            await new Promise<void>((risolvi) => {
                let orologio = setTimeout(fine, quiete);
                const scadenza = setTimeout(fine, max);
                const osservatore = new MutationObserver(() => {
                    clearTimeout(orologio);
                    orologio = setTimeout(fine, quiete);
                });
                osservatore.observe(document.documentElement, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    characterData: true,
                });
                function fine(): void {
                    clearTimeout(orologio);
                    clearTimeout(scadenza);
                    osservatore.disconnect();
                    risolvi();
                }
            });
        },
        { quiete: 500, limite },
    );
}

/**
 * Apre la pagina e aspetta che sia davvero pronta da guardare.
 *
 * L'ordine conta: prima ciò che si sa dover comparire (`attendi`, la garanzia
 * più forte), poi il documento fermo, poi le animazioni. Quest'ultima perché
 * il controllo misura il contrasto fra testo e sfondo, e a metà di una
 * dissolvenza il colore è una mescolanza fra quello di partenza e quello
 * d'arrivo: elementi a norma risultano insufficienti.
 */
export async function preparaPagina(pagina: Page, percorso: string, attendi?: string): Promise<void> {
    const risposta = await pagina.goto(percorso, { waitUntil: "domcontentloaded" });
    expect(risposta, `${percorso} non ha risposto`).toBeTruthy();
    expect(risposta?.status(), `${percorso} ha risposto ${risposta?.status()}`).toBeLessThan(400);

    if (attendi) {
        await expect(
            pagina.locator(attendi).first(),
            `${percorso}: «${attendi}» non è comparso, si guarderebbe una pagina incompleta`,
        ).toBeAttached({ timeout: 30_000 });
    }
    await attendiDomFermo(pagina);
    await attendiAnimazioniFerme(pagina);
}
