# shellcheck shell=bash
#
# Credenziali del database senza metterle sulla riga di comando (23/9/2026,
# A-86 della revisione del 23/9).
#
# `mysqldump -u "$U" -p"$PASS"` mette la password fra gli argomenti del
# processo: finché il comando gira la legge chiunque abbia un accesso alla
# macchina, con `ps` o in /proc/<pid>/cmdline. Il salvataggio notturno delle
# chiavi dei docenti lo faceva ogni notte, e i due script che creano le utenze
# di manutenzione e di migrazione lo facevano per la prova di connessione.
#
# Qui la password va in un file di opzioni temporaneo, creato da `mktemp`
# (0600, solo il proprietario lo legge), che il client riceve con
# `--defaults-file` e che si cancella all'uscita del comando: anche quando il
# comando fallisce, e anche per un SIGTERM (bash esegue la trappola EXIT;
# misurato il 23/9/2026). Sotto systemd, con PrivateTmp, il file sta per di più
# nella /tmp privata dell'unità.
#
# Perché `--defaults-file` e non `--defaults-extra-file`. Con il secondo il
# client legge DOPO anche ~/.my.cnf, e fra due valori vince l'ultimo (misurato
# con my_print_defaults di MariaDB 11.8: `--password` del file dato, poi quella
# di ~/.my.cnf). Da root, dove /root/.my.cnf porta le credenziali di root (è
# quello che usa il rilascio per l'istantanea), la prova di connessione di
# un'utenza nuova si sarebbe collegata come root e avrebbe detto «riuscita»
# comunque. `--defaults-file` legge solo il file dato; perché non si perda la
# configurazione di sistema (socket, set di caratteri) il file la include per
# prima con `!include`, e le credenziali vengono dopo, quindi vincono.
#
# Uso:
#   . "<cartella di questo file>/opzioni-mysql.sh"
#   con_credenziali_mysql UTENTE PASSWORD mysqldump -h HOST … DB tabella
#
# Il comando riceve `--defaults-file=…` come primo argomento, che è dove il
# client lo vuole.
#
# Variabile per le prove (tests/ops/credenziali-mysql.test.sh):
#   OPZIONI_MYSQL_SISTEMA  la configurazione di sistema da includere
#                          (predefinita /etc/mysql/my.cnf, se leggibile)

# Un valore per il file di opzioni: fra virgolette doppie, con `\` e `"`
# protetti. È la regola di lettura di MariaDB e MySQL; così passano anche `#`,
# gli spazi e gli apici, che senza virgolette troncherebbero la password.
_valore_opzione_mysql() {
    local v=$1
    v=${v//\\/\\\\}
    v=${v//\"/\\\"}
    printf '"%s"' "$v"
}

# con_credenziali_mysql UTENTE PASSWORD COMANDO [ARGOMENTI…]
con_credenziali_mysql() {
    local utente=$1 password=$2
    shift 2
    case "$utente$password" in
        *$'\n'* | *$'\r'*)
            echo "credenziali del database con un a capo: un file di opzioni non le può contenere" >&2
            return 2
            ;;
    esac
    # Una sotto-shell: la sua trappola EXIT cancella il file e non tocca quelle
    # di chi chiama (gli script delle utenze ne hanno una per .env.local).
    (
        opzioni=$(mktemp "${TMPDIR:-/tmp}/pantedu-mysql.XXXXXX") || exit 1
        trap 'rm -f "$opzioni"' EXIT
        sistema=${OPZIONI_MYSQL_SISTEMA:-/etc/mysql/my.cnf}
        {
            if [ -r "$sistema" ]; then
                printf '!include %s\n' "$sistema"
            fi
            printf '[client]\n'
            printf 'user=%s\n' "$(_valore_opzione_mysql "$utente")"
            printf 'password=%s\n' "$(_valore_opzione_mysql "$password")"
        } > "$opzioni" || exit 1
        comando=$1
        shift
        "$comando" --defaults-file="$opzioni" "$@"
    )
}
