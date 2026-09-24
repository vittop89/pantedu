#!/usr/bin/env bash
#
# Hook di Claude Code (PostToolUse su Write|Edit, da .claude/settings.json):
# quando cambia `routes/web.php`, rigenera `docs/ROUTES.md` **nel repository
# del file modificato**.
#
# Perché uno script. Fino al 15 settembre 2026 il hook era una riga dentro
# settings.json che lanciava il generatore nella cartella di lavoro della
# sessione. Le sessioni dell'app desktop avevano come cartella la vecchia copia
# Windows, ma lavoravano sul repository in WSL: ogni modifica a
# `routes/web.php` in WSL riscriveva il ROUTES.md della copia Windows, a
# partire dal suo `routes/web.php` vecchio, e lasciava com'era quello in WSL.
# Nella copia Windows compariva un «main +123 -116» che nessuno aveva scritto.
#
# Le sessioni girano dentro WSL e prendono il ramo Linux. La copia su Windows è
# in dismissione dal 22/9/2026: eliminazione decisa, la fa l'utente. Il ramo
# UNC qui sotto serve a una sessione aperta da Windows sulla cartella
# `\\wsl.localhost\...`.
#
# Il hook legge dallo standard input il JSON dello strumento. Scrive su un file
# temporaneo e sostituisce ROUTES.md solo se il generatore riesce: con un
# `> docs/ROUTES.md` diretto un errore lasciava il file vuoto. Se non riesce lo
# dice, invece di tacere.
#
# Variabili per le prove (tests/ops/rigenera-routes.test.sh):
#   RIGENERA_ROUTES_PHP   il php da usare (predefinito: quello nel PATH)
#   RIGENERA_ROUTES_WSL   il wsl.exe da usare (predefinito: wsl.exe)
set -u

ingresso=$(cat)
if command -v jq >/dev/null 2>&1; then
    f=$(printf '%s' "$ingresso" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
else
    f=$(printf '%s' "$ingresso" | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo $j["tool_input"]["file_path"] ?? "";' 2>/dev/null)
fi
# Barre di Windows in barre normali, e le barre doppie di un percorso UNC in una.
f=$(printf '%s' "$f" | tr '\\' '/' | sed 's#//*#/#g')

case "$f" in
    */routes/web.php) ;;
    *) exit 0 ;;
esac
radice=${f%/routes/web.php}

messaggio() {
    # Il testo va dentro una stringa JSON: niente virgolette né barre inverse.
    printf '{"systemMessage":"%s"}\n' "$(printf '%s' "$1" | tr '"\\' "'/")"
}

GENERA='[ -f tools/dev/gen_routes_md.php ] || exit 3
"$PHP" tools/dev/gen_routes_md.php > docs/ROUTES.md.nuovo 2>/dev/null \
    && mv docs/ROUTES.md.nuovo docs/ROUTES.md \
    || { rm -f docs/ROUTES.md.nuovo; exit 1; }'

case "$radice" in
    /wsl.localhost/*/* | /wsl\$/*/*)
        # Un file del repository in WSL, visto da Windows: si genera dentro WSL,
        # con il php di WSL, nella cartella del repository.
        # `-e` passa gli argomenti così come sono. Senza, wsl.exe li fa passare
        # dalla shell di login di Linux, che espande "$PHP" prima del tempo (a
        # vuoto): il generatore non partiva mai (misurato il 15/9/2026).
        resto=${radice#/wsl*/}
        distro=${resto%%/*}
        linux=/${resto#*/}
        dove="$distro:$linux"
        MSYS_NO_PATHCONV=1 "${RIGENERA_ROUTES_WSL:-wsl.exe}" -d "$distro" --cd "$linux" \
            -e env PHP=php sh -c "$GENERA"
        esito=$?
        ;;
    *)
        dove=$radice
        if [ -d "$radice" ]; then
            ( cd "$radice" && PHP="${RIGENERA_ROUTES_PHP:-$(command -v php || echo php)}" sh -c "$GENERA" )
            esito=$?
        else
            esito=3
        fi
        ;;
esac

case "$esito" in
    0) messaggio "routes/web.php modificato: docs/ROUTES.md rigenerato in $dove. Rivedi e committa docs/ROUTES.md." ;;
    3) exit 0 ;;  # non è un repository di Pantedu: niente da fare
    *) messaggio "routes/web.php modificato, ma docs/ROUTES.md NON è stato rigenerato in $dove (esito $esito). Lancia a mano: php tools/dev/gen_routes_md.php > docs/ROUTES.md" ;;
esac
exit 0
