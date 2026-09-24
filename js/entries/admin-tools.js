// Entry Vite di views/admin/tools.php (23/9/2026, revisione architetturale
// A-17). Il modulo della pagina era servito grezzo da /js/, accanto al bundle:
// senza impronta nel nome, con un giorno di cache (nginx e la rete di
// distribuzione davanti), e con un'istanza sua di dom-utils e audit-reason.
// Dopo un rilascio la copia di ieri poteva restare nel browser; la #268 lo
// aggirava con `?v=a69` nell'indirizzo. Dal bundle il nome cambia a ogni
// modifica, e le dipendenze sono quelle di tutte le altre pagine.
import "../modules/features/admin-tools.js";
