/**
 * Le zone di accesso di chi guarda la pagina (14/9/2026).
 *
 * Il server scrive sul body `data-fm-zones` con le zone di
 * `app/Config/roles.php` a cui l'utente ha accesso, calcolate con
 * `Auth::hasAccess` (views/layout/app.php, `Auth::zone()`): tiene conto del
 * ruolo vero e del super-admin. Qui si chiede la zona che l'endpoint
 * richiede, non il ruolo.
 *
 * Perché: `data-fm-role` è `users.role`, e in database l'amministratore è
 * `administrator`; `admin` è solo un alias storico. Due moduli confrontavano
 * il ruolo con `"admin"` e mandavano l'amministratore sugli elenchi dello
 * studente; un terzo teneva una lista di ruoli scritta a mano. Una zona si
 * legge dove la decide il server, e non c'è una seconda copia da tenere
 * allineata.
 *
 * @param {string} zona  una chiave di `access_zones`: public, student, teacher, admin, istituto
 * @param {HTMLElement|null|undefined} [corpo]  il body da leggere (per le prove)
 * @returns {boolean}
 */
export function haZona(zona, corpo = globalThis.document?.body) {
    const zone = String(corpo?.dataset?.fmZones || "").split(/\s+/).filter(Boolean);
    return zone.includes(zona);
}
