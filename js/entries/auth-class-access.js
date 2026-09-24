// Entry Vite di views/auth/class_access.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ──
{
(function () {
    const form = document.getElementById('fm-class-access-form');
    const box  = document.getElementById('fm-class-access-error');
    if (!form || !box) return;
    const messages = {
        invalid_credentials:  'Credenziale non valida. Controlla quello che ti ha dato il docente.',
        missing_credentials:  'Inserisci credenziale e password.',
        two_factor_required:  'Questo è un account personale con verifica in due passaggi: entra da /login.',
        db_unavailable:       'Servizio momentaneamente non disponibile. Riprova tra poco.',
        rate_limited:         'Troppi tentativi: aspetta qualche minuto.'
    };
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        box.hidden = true;
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        try {
            const r = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: new URLSearchParams(new FormData(form)).toString()
            });
            let j = {};
            try { j = await r.json(); } catch (_) {}
            if (r.ok && j.ok) {
                window.location.href = '/';
                return;
            }
            const code = j.error || (r.status === 429 ? 'rate_limited' : 'invalid_credentials');
            box.textContent = j.message || messages[code] || (`Accesso non riuscito (${  code  }).`);
            box.hidden = false;
        } catch (_) {
            box.textContent = 'Connessione non riuscita. Riprova.';
            box.hidden = false;
        } finally {
            btn.disabled = false;
        }
    });
})();

}
