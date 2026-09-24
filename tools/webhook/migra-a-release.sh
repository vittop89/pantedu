#!/bin/bash
# Passa dalla directory servita che si aggiorna sul posto a una directory di
# release scambiata con un collegamento atomico.
#
# ── Perché ────────────────────────────────────────────────────────────────
#
# Oggi `deploy.sh` fa `git reset --hard` sulla directory che nginx serve: da
# quell'istante il codice nuovo risponde a richieste vere, mentre le migrazioni
# devono ancora girare. Sono decine di secondi in cui il codice nuovo gira su
# uno schema vecchio. La regola dei due passi (wiki/dev-workflow.md) rende
# quella finestra innocua per convenzione; questo la chiude per costruzione.
#
# ── Il disegno, e perché questo e non un altro ────────────────────────────
#
# Lo schema classico (releases/ + current/ + nginx che punta a current) qui
# costerebbe caro: nginx, otto unità systemd e lo script di rilascio stesso
# nominano `/var/www/pantedu`, e ognuno di quei posti è un'occasione di
# sbagliare.
#
# Qui invece **`/var/www/pantedu` diventa esso stesso il collegamento**. Punta
# a `/var/www/pantedu-releases/<commit>`, e si sposta con `ln -sfn`, che è
# atomico. Chi nomina `/var/www/pantedu` non se ne accorge: nginx no, le unità
# no, i timer no. Cambia solo `deploy.sh`.
#
# Lo stato che deve sopravvivere ai rilasci sta in `/var/www/pantedu-shared/`
# e viene collegato dentro ogni release: `.env`, `.env.local`, e la cartella
# `storage/` per quel poco che ancora ci scrive (i dati veri stanno già fuori,
# in PANTEDU_DATA_PATH).
#
# ── Sicurezza ─────────────────────────────────────────────────────────────
#
# Con `--prova` non tocca niente di vivo: prepara la struttura accanto, la
# verifica, e dice cosa farebbe. È il modo giusto di eseguirlo la prima volta.
#
# Il ritorno indietro è un comando solo e vale un istante:
#
#     rm /var/www/pantedu && mv /var/www/pantedu-originale /var/www/pantedu
#
# La directory di prima non viene cancellata: viene rinominata e lasciata lì.

set -euo pipefail

SERVITA=/var/www/pantedu
RELEASES=/var/www/pantedu-releases
CONDIVISO=/var/www/pantedu-shared
ORIGINALE=/var/www/pantedu-originale
UTENTE=pantedu

PROVA=0
[[ "${1:-}" == "--prova" ]] && PROVA=1

nota() { printf '[migrazione] %s\n' "$*"; }
errore() { printf '[migrazione] ERRORE: %s\n' "$*" >&2; exit 1; }

# ── Controlli preliminari ─────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || errore "va eseguito come root"

if [[ -L "$SERVITA" ]]; then
    nota "già migrato: $SERVITA è un collegamento a $(readlink "$SERVITA")"
    exit 0
fi

[[ -d "$SERVITA/.git" ]] || errore "$SERVITA non sembra il repository"

COMMIT=$(sudo -u "$UTENTE" git -C "$SERVITA" rev-parse HEAD)
nota "commit in produzione: ${COMMIT:0:8}"
DEST="$RELEASES/$COMMIT"

if [[ $PROVA -eq 1 ]]; then
    nota "=== PROVA: preparo la struttura senza toccare quella viva ==="
    DEST="$RELEASES/prova-${COMMIT:0:8}"
fi

# ── 1. Lo stato condiviso ─────────────────────────────────────────────────
nota "preparo $CONDIVISO"
mkdir -p "$CONDIVISO"
# 2026-09-08 — il gruppo è www-data e la cartella è attraversabile dal gruppo.
# Senza, PHP-FPM non arriva a `.env`/`.env.local` (che sono 0640
# pantedu:www-data: il permesso sul file non serve a niente se la cartella che
# lo contiene non si attraversa). Successo davvero: l'applicazione è girata
# per qualche minuto SENZA la propria configurazione, e l'unico sintomo
# visibile era un health check che diceva «marker assente».
chown "$UTENTE:www-data" "$CONDIVISO"
chmod 0750 "$CONDIVISO"

for f in .env .env.local; do
    if [[ -f "$SERVITA/$f" && ! -e "$CONDIVISO/$f" ]]; then
        # `cp -p` conserva permessi e proprietario: quei file hanno 0640
        # pantedu:www-data, e uno di loro è immutabile.
        if lsattr "$SERVITA/$f" 2>/dev/null | grep -q 'i'; then
            nota "  $f è immutabile: copio e rimetto l'attributo"
            chattr -i "$SERVITA/$f"
            cp -p "$SERVITA/$f" "$CONDIVISO/$f"
            chattr +i "$SERVITA/$f"
            chattr +i "$CONDIVISO/$f"
        else
            cp -p "$SERVITA/$f" "$CONDIVISO/$f"
        fi
        nota "  copiato $f"
    fi
done

if [[ ! -d "$CONDIVISO/storage" ]]; then
    nota "  sposto storage/ (quel che ci resta: i dati veri stanno in PANTEDU_DATA_PATH)"
    if [[ $PROVA -eq 1 ]]; then
        cp -a "$SERVITA/storage" "$CONDIVISO/storage"
    else
        mv "$SERVITA/storage" "$CONDIVISO/storage"
    fi
fi

# ── 2. La release ─────────────────────────────────────────────────────────
nota "preparo $DEST"
mkdir -p "$RELEASES"
chown "$UTENTE:$UTENTE" "$RELEASES"
rm -rf "$DEST"
sudo -u "$UTENTE" git clone --quiet --no-hardlinks --shared "$SERVITA" "$DEST"
sudo -u "$UTENTE" git -C "$DEST" checkout --quiet "$COMMIT"

# Lo stato condiviso entra nella release come collegamento.
rm -rf "$DEST/storage"
sudo -u "$UTENTE" ln -sfn "$CONDIVISO/storage" "$DEST/storage"
for f in .env .env.local; do
    [[ -e "$CONDIVISO/$f" ]] || continue
    rm -f "$DEST/$f"
    sudo -u "$UTENTE" ln -sfn "$CONDIVISO/$f" "$DEST/$f"
done

# Le dipendenze e il build: si ricostruiscono nella release.
nota "composer + build nella release (può volerci un minuto)"
sudo -u "$UTENTE" composer --working-dir="$DEST" install --no-dev --no-interaction \
    --optimize-autoloader --quiet 2>&1 | tail -3 || errore "composer fallito"
if [[ -f "$DEST/package.json" ]]; then
    ( cd "$DEST" && sudo -u "$UTENTE" npm ci --no-audit --no-fund --prefer-offline --silent \
        && sudo -u "$UTENTE" npm run build --silent ) 2>&1 | tail -3 || errore "build front-end fallito"
fi

# ── 3. I permessi per www-data ────────────────────────────────────────────
#
# 2026-09-08 — il primo tentativo di scambio ha messo il sito in 404 per
# qualche minuto, e la causa è tutta qui: `git clone` crea l'albero come
# `pantedu` con permessi che `www-data` non attraversa, e nginx rispondeva
#
#     stat() ".../public/index.php" failed (13: Permission denied)
#
# `deploy.sh` ha una sezione intera per questo (la sua §2-3), che gira dopo
# ogni `reset --hard`. Una release nuova ne ha bisogno esattamente allo stesso
# modo, e la prima versione di questo script non la faceva.
nota "sistemo i permessi per www-data"

# La directory che contiene le release va attraversata da www-data.
chmod 0755 "$RELEASES"

for d in app routes database public config tools js views css img wasm schemas docs; do
    [[ -d "$DEST/$d" ]] || continue
    chgrp -R www-data "$DEST/$d" 2>/dev/null || true
    find "$DEST/$d" -type d -exec chmod 0755 {} + 2>/dev/null || true
    find "$DEST/$d" -type f -exec chmod 0644 {} + 2>/dev/null || true
done

# I file di primo livello (index.php legacy, i markdown letti da PHP).
find "$DEST" -maxdepth 1 -type f -exec chgrp www-data {} + 2>/dev/null || true
find "$DEST" -maxdepth 1 -type f \( -name '*.php' -o -name '*.md' -o -name '*.json' \) \
    -exec chmod 0644 {} + 2>/dev/null || true
chmod 0755 "$DEST"

# `vendor/`: composer scrive con l'umask del suo utente, e senza questo
# l'autoloader non è leggibile (incidente del 2026-05-25 in deploy.sh).
if [[ -d "$DEST/vendor" ]]; then
    chgrp -R www-data "$DEST/vendor" 2>/dev/null || true
    find "$DEST/vendor" -type d -exec chmod 0755 {} + 2>/dev/null || true
    find "$DEST/vendor" -type f -exec chmod 0644 {} + 2>/dev/null || true
fi

# ── 4. La verifica, PRIMA di scambiare ────────────────────────────────────
#
# Due verifiche, e servono tutte e due.
#
# La prima è **come www-data**, ed è quella che mancava: il primo tentativo
# passava la seconda e falliva in produzione, perché il Kernel dispacciato
# come `pantedu` legge file che nginx non legge. Chi verifica deve essere chi
# poi serve le pagine.
nota "verifico che www-data veda la release (è quello che è mancato la prima volta)"
for f in public/index.php app/bootstrap.php public/sw.js; do
    [[ -e "$DEST/$f" ]] || continue
    if ! sudo -u www-data test -r "$DEST/$f"; then
        errore "www-data NON legge $f: non scambio niente. Resta tutto com'era."
    fi
done
if ! sudo -u www-data test -x "$DEST/public"; then
    errore "www-data non attraversa public/: non scambio niente."
fi
nota "  www-data legge l'entry point e attraversa public/"

# 2026-09-08 — e l'albero CONDIVISO, che la prima versione di questo controllo
# non guardava. Al secondo tentativo lo scambio è riuscito, nginx rispondeva
# 200, e intanto l'applicazione girava senza `.env.local` perché
# `/var/www/pantedu-shared` non era attraversabile da www-data. Il permesso
# giusto sul file non serve a niente se la cartella che lo contiene è chiusa.
for f in .env .env.local; do
    [[ -e "$CONDIVISO/$f" ]] || continue
    if ! sudo -u www-data test -r "$DEST/$f"; then
        errore "www-data NON legge $f attraverso il collegamento: non scambio niente."
    fi
done
nota "  www-data legge la configurazione attraverso i collegamenti"

# La prova più forte: la configurazione si carica DAVVERO, letta da www-data.
# Un `test -r` dice che il file si apre; questo dice che l'applicazione ci
# ricava quello che le serve. La differenza fra le due l'ha già pagata il
# sito una volta.
if ! sudo -u www-data php -r "
    require '$DEST/app/bootstrap.php';
    \$p = App\\Core\\Config::get('app.paths.storage');
    if (!is_string(\$p) || \$p === '' || !is_dir(\$p)) { exit(1); }
" 2>/dev/null; then
    errore "www-data non riesce a caricare la configurazione della release: non scambio niente."
fi
nota "  www-data carica la configurazione e ne ricava i percorsi"

# La seconda dispaccia il Kernel dentro la release: stesse rotte, stessa
# configurazione, stesso database, nessuna rete di mezzo (il WAF risponde 403
# ai client automatici, quindi un `curl` mentirebbe).
nota "verifico che la release risponda, prima di scambiare"
if ! sudo -u "$UTENTE" php "$DEST/tools/ops/smoke_after_deploy.php" 2>&1 | sed 's/^/  /'; then
    errore "la release NON risponde: non scambio niente. Resta tutto com'era."
fi
nota "la release risponde"

# ── 5. Lo scambio ─────────────────────────────────────────────────────────
#
# Il cancello che mancava, aggiunto l'8 settembre 2026 (voce 86 del debito).
#
# Cos'è successo la prima volta. Lo scambio ha funzionato — il sito rispondeva,
# la verifica come `www-data` passava — ma la release era stata clonata DALLA
# DIRECTORY SERVITA, che dopo lo scambio è un collegamento a se stessa. Il suo
# `origin` puntava quindi a sé, e `git fetch` rispondeva `not our ref`. Con
# `set -euo pipefail`, il rilascio successivo si sarebbe fermato alla seconda
# riga: la produzione avrebbe smesso di aggiornarsi. Non l'ha trovato una
# verifica — l'ho visto per caso guardando altro, e il layout è stato riportato
# indietro.
#
# Perché un cancello e non una correzione. La correzione vera è insegnare a
# `deploy.sh` a costruire una release nuova da un sorgente mantenuto e a
# scambiare il collegamento: è mezza giornata di lavoro sul pezzo che mette il
# codice in produzione, e la sua condizione di attivazione (`piu-docenti`, cioè
# un secondo docente sul sito) non è ancora arrivata. Finché non c'è, questo
# script resta nel repository ed è rilanciabile da chiunque, compreso me fra un
# mese: senza cancello, rifarebbe esattamente lo stesso mezzo passo.
#
# Il contratto è una riga sola. Quando `deploy.sh` saprà fare le release,
# dichiarerà `RILASCIO_A_RELEASE=1`; qui si cerca quella riga nello script
# INSTALLATO — non in quello nel repository, perché è l'installato che il
# webhook esegue.
DEPLOY_INSTALLATO=/usr/local/bin/pantedu-deploy.sh

if [[ $PROVA -eq 0 ]]; then
    if [[ ! -f "$DEPLOY_INSTALLATO" ]]; then
        errore "non trovo $DEPLOY_INSTALLATO: non scambio niente."
    fi
    if ! grep -q '^RILASCIO_A_RELEASE=1' "$DEPLOY_INSTALLATO"; then
        nota "Lo scambio funzionerebbe, ma il rilascio successivo no."
        nota ""
        nota "$DEPLOY_INSTALLATO non dichiara RILASCIO_A_RELEASE=1, cioè non sa"
        nota "costruire una release nuova da un sorgente mantenuto: dopo lo"
        nota "scambio farebbe 'git fetch' dentro un collegamento che punta a sé,"
        nota "e si fermerebbe lì. La produzione smetterebbe di aggiornarsi."
        nota ""
        nota "Con --prova la struttura si può preparare e guardare quando si"
        nota "vuole: è la parte che è già stata verificata e che funziona."
        nota "Voce 86 di wiki/technical-debt.md."
        errore "rilascio non pronto per le release: non scambio niente."
    fi
    nota "$DEPLOY_INSTALLATO dichiara RILASCIO_A_RELEASE=1: procedo."
fi

if [[ $PROVA -eq 1 ]]; then
    nota "=== PROVA finita: la release è pronta e verificata in $DEST ==="
    nota "Non ho scambiato niente. Per farlo davvero:"
    nota "    bash $0"
    exit 0
fi

nota "scambio: $SERVITA → $DEST"
mv "$SERVITA" "$ORIGINALE"
ln -sfn "$DEST" "$SERVITA"
systemctl restart php8.4-fpm
systemctl reload nginx

# La verifica dopo lo scambio passa da **nginx**, non dal Kernel: è nginx che
# ha rifiutato la prima volta, e un controllo che non passa da lui non se ne
# sarebbe accorto. Header `Host` e `X-Forwarded-For` come fa `deploy.sh`, per
# non farsi respingere dal blocco geografico del WAF.
sleep 2
CODICE=$(curl -sk -o /dev/null -w '%{http_code}' \
    -H 'Host: pantedu.eu' -H 'X-Forwarded-For: 127.0.0.1' \
    --max-time 10 https://127.0.0.1/login 2>/dev/null || echo "000")

case "$CODICE" in
    2[0-9][0-9]|3[0-9][0-9])
        nota "nginx risponde $CODICE: fatto."
        nota "La directory di prima è in $ORIGINALE e NON è stata cancellata."
        nota "Per tornare indietro:"
        nota "    rm $SERVITA && mv $ORIGINALE $SERVITA && systemctl restart php8.4-fpm && systemctl reload nginx"
        ;;
    *)
        nota "nginx risponde $CODICE: torno indietro adesso"
        rm -f "$SERVITA"
        mv "$ORIGINALE" "$SERVITA"
        systemctl restart php8.4-fpm
        systemctl reload nginx
        errore "scambio annullato, ripristinata la directory di prima"
        ;;
esac
