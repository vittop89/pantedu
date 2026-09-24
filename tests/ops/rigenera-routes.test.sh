#!/bin/bash
#
# Prove di `tools/dev/hooks/rigenera-routes.sh`, nei due versi (15/9/2026).
#
# Il difetto che l'ha fatto nascere: il hook rigenerava docs/ROUTES.md nella
# cartella di lavoro della sessione, non nel repository del file modificato.
# Una sessione aperta sulla copia Windows che toccava routes/web.php in WSL
# riscriveva il ROUTES.md della copia Windows e lasciava vecchio quello in WSL.
#
# Qui due repository finti con un generatore finto, che scrive da quale cartella
# è stato lanciato, e un wsl.exe finto che registra gli argomenti. La cartella di
# lavoro della prova è un terzo posto, che non deve cambiare mai.
#
# Uso: bash tests/ops/rigenera-routes.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
HOOK="${HOOK:-$QUI/../../tools/dev/hooks/rigenera-routes.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/rigenera-routes.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Un repository finto: routes/web.php, docs/ROUTES.md vecchio, un generatore che
# stampa la cartella da cui gira (o fallisce, se c'è il file `rompi`).
repo() {
    mkdir -p "$1/routes" "$1/docs" "$1/tools/dev"
    echo "<?php // rotte" > "$1/routes/web.php"
    echo "ROUTES vecchio" > "$1/docs/ROUTES.md"
    cat > "$1/tools/dev/gen_routes_md.php" <<'PHP'
<?php
if (is_file('rompi')) { echo "mezzo file"; exit(1); }
echo "generato in " . getcwd() . "\n";
PHP
}
repo "$T/wsl"
repo "$T/altro"
mkdir -p "$T/sessione/docs"
echo "ROUTES della sessione" > "$T/sessione/docs/ROUTES.md"

cat > "$T/wsl-finto" <<'FINTO'
#!/bin/bash
printf '%s\n' "$*" > "$REGISTRO_WSL"
# Come wsl.exe: -d DISTRO --cd CARTELLA [-e] comando...
shift 2; cd "$2" || exit 9; shift 2
if [ "${1:-}" = "-e" ]; then
    shift
    exec "$@"
fi
# Senza -e, wsl.exe unisce gli argomenti e li fa leggere a una shell, che
# espande le variabili: così si comporta anche il finto.
exec bash -c "$*"
FINTO
chmod +x "$T/wsl-finto"

# lancia PERCORSO: il hook con il JSON di un Edit su quel percorso, dalla
# cartella della sessione.
lancia() {
    : > "$T/wsl.log"
    printf '{"tool_name":"Edit","tool_input":{"file_path":%s}}' "$(printf '%s' "$1" | php -r 'echo json_encode(stream_get_contents(STDIN));')" \
        | ( cd "$T/sessione" && REGISTRO_WSL="$T/wsl.log" RIGENERA_ROUTES_WSL="$T/wsl-finto" bash "$HOOK" )
}

# ── Verso buono: rigenera nel repository del file ─────────────────────────
OUT=$(lancia "$T/altro/routes/web.php")
[ "$(cat "$T/altro/docs/ROUTES.md")" = "generato in $T/altro" ] \
    && ok || ko "percorso locale: ROUTES.md del repository dice «$(cat "$T/altro/docs/ROUTES.md")»"
[ "$(cat "$T/sessione/docs/ROUTES.md")" = "ROUTES della sessione" ] \
    && ok || ko "percorso locale: è cambiato il ROUTES.md della cartella della sessione"
case "$OUT" in *'"systemMessage"'*rigenerato*) ok ;; *) ko "percorso locale: messaggio «$OUT»" ;; esac
[ ! -e "$T/altro/docs/ROUTES.md.nuovo" ] && ok || ko "percorso locale: è rimasto il file temporaneo"

# Un file in WSL visto da Windows, con le barre di Windows come le scrive Claude:
# \\wsl.localhost\Ubuntu\<cartella>\routes\web.php. Il wsl.exe finto va nella
# cartella che riceve con --cd, che qui è la cartella vera del repository finto.
repo "$T/wsl"
OUT=$(lancia "\\\\wsl.localhost\\Ubuntu$(printf '%s' "$T/wsl" | tr '/' '\\')\\routes\\web.php")
case "$(head -1 "$T/wsl.log")" in
    "-d Ubuntu --cd $T/wsl -e env PHP=php sh -c "*) ok ;;
    *) ko "percorso WSL: wsl.exe chiamato così: «$(head -1 "$T/wsl.log")»" ;;
esac
[ "$(cat "$T/wsl/docs/ROUTES.md")" = "generato in $T/wsl" ] \
    && ok || ko "percorso WSL: ROUTES.md del repository dice «$(cat "$T/wsl/docs/ROUTES.md")»"
[ "$(cat "$T/sessione/docs/ROUTES.md")" = "ROUTES della sessione" ] \
    && ok || ko "percorso WSL: è cambiato il ROUTES.md della cartella della sessione"
case "$OUT" in *"rigenerato in Ubuntu:$T/wsl"*) ok ;; *) ko "percorso WSL: messaggio «$OUT»" ;; esac
# Anche la forma \\wsl$\ di Windows 10.
repo "$T/wsl"
lancia "\\\\wsl\$\\Ubuntu$(printf '%s' "$T/wsl" | tr '/' '\\')\\routes\\web.php" > /dev/null
[ "$(cat "$T/wsl/docs/ROUTES.md")" = "generato in $T/wsl" ] \
    && ok || ko "percorso \\\\wsl\$: ROUTES.md dice «$(cat "$T/wsl/docs/ROUTES.md")»"

# ── Verso cattivo: non tocca niente quando non deve ───────────────────────
repo "$T/altro"
OUT=$(lancia "$T/altro/routes/api.php")
[ -z "$OUT" ] && [ "$(cat "$T/altro/docs/ROUTES.md")" = "ROUTES vecchio" ] && [ ! -s "$T/wsl.log" ] \
    && ok || ko "un file che non è routes/web.php ha fatto qualcosa: «$OUT»"

OUT=$(lancia "$T/altro/tools/routes/web.php.bak")
[ -z "$OUT" ] && ok || ko "routes/web.php.bak ha fatto scattare il hook: «$OUT»"

# Un routes/web.php fuori da un repository di Pantedu: silenzio, niente file.
mkdir -p "$T/estraneo/routes"
OUT=$(lancia "$T/estraneo/routes/web.php")
[ -z "$OUT" ] && [ ! -e "$T/estraneo/docs" ] && ok || ko "fuori da Pantedu: «$OUT»"

# Il generatore fallisce: ROUTES.md resta com'era (non vuoto, non a metà) e il
# messaggio lo dice.
repo "$T/altro"
touch "$T/altro/rompi"
OUT=$(lancia "$T/altro/routes/web.php")
[ "$(cat "$T/altro/docs/ROUTES.md")" = "ROUTES vecchio" ] \
    && ok || ko "generatore rotto: ROUTES.md è diventato «$(cat "$T/altro/docs/ROUTES.md")»"
[ ! -e "$T/altro/docs/ROUTES.md.nuovo" ] && ok || ko "generatore rotto: è rimasto il file temporaneo"
case "$OUT" in *"NON è stato rigenerato"*) ok ;; *) ko "generatore rotto: messaggio «$OUT»" ;; esac

# Il messaggio è JSON valido, anche con una cartella che ha virgolette nel nome.
repo "$T/con \"virgolette\""
OUT=$(lancia "$T/con \"virgolette\"/routes/web.php")
printf '%s' "$OUT" | php -r 'exit(json_decode(stream_get_contents(STDIN)) === null ? 1 : 0);' \
    && ok || ko "messaggio non è JSON valido: «$OUT»"

# ── Controprova: il hook di prima, sullo stesso caso, scriveva nella sessione ─
# È la riga che stava in .claude/settings.json fino al 15/9/2026.
cat > "$T/vecchio.sh" <<'VECCHIO'
f=$(jq -r ".tool_input.file_path // empty" 2>/dev/null | tr '\\' '/' 2>/dev/null); case "$f" in */routes/web.php) php=$(command -v php || echo /c/php-8.3.30/php); if "$php" tools/dev/gen_routes_md.php > docs/ROUTES.md 2>/dev/null; then printf "{\"systemMessage\":\"routes/web.php modificato -> docs/ROUTES.md rigenerato.\"}"; fi;; esac
VECCHIO
if command -v jq >/dev/null 2>&1; then
    repo "$T/altro"
    mkdir -p "$T/sessione/tools/dev"
    cp "$T/altro/tools/dev/gen_routes_md.php" "$T/sessione/tools/dev/gen_routes_md.php"
    printf '{"tool_input":{"file_path":"%s/routes/web.php"}}' "$T/altro" | ( cd "$T/sessione" && bash "$T/vecchio.sh" ) > /dev/null
    [ "$(cat "$T/sessione/docs/ROUTES.md")" = "generato in $T/sessione" ] && [ "$(cat "$T/altro/docs/ROUTES.md")" = "ROUTES vecchio" ] \
        && ok || ko "controprova: il hook vecchio non riproduce il difetto (sessione: «$(cat "$T/sessione/docs/ROUTES.md")»)"
else
    echo "  (controprova saltata: jq assente)"
fi

echo "rigenera-routes: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
