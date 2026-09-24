#!/bin/bash
#
# Prove di `tools/ops/ingressi-container.sh`, nei due versi (15/9/2026).
#
# Ogni comando che entra in un container deve contare come ingresso, e ogni
# altro comando docker **non** deve contare. Le righe hanno la forma di
# `ausearch -i`: gli eventi separati da «----», l'EXECVE con gli argomenti e il
# SYSCALL con l'auid. Niente root e niente auditd.
#
# Uso: bash tests/ops/ingressi-container.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
CLASSIFICA="${CLASSIFICA:-$QUI/../../tools/ops/ingressi-container.sh}"

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Un evento come lo scrive `ausearch -i`.
evento() {
    local chi="$1"; shift
    local riga="type=EXECVE msg=audit(09/15/2026 01:23:45.678:4242) : argc=$#"
    local i=0
    for a in "$@"; do riga="$riga a$i=$a"; i=$((i + 1)); done
    echo "----"
    echo "type=PATH msg=audit(09/15/2026 01:23:45.678:4242) : item=0 name=/usr/bin/$1 nametype=NORMAL"
    echo "$riga"
    echo "type=SYSCALL msg=audit(09/15/2026 01:23:45.678:4242) : arch=x86_64 syscall=execve success=yes exit=0 auid=$chi uid=root comm=$1 exe=/usr/bin/$1 key=pantedu_container_ingressi"
}

# Controlla quanti ingressi e altri escono per un evento.
prova() {
    local nome="$1" attesi_ingressi="$2" attesi_altri="$3"; shift 3
    local uscita
    uscita=$(evento root "$@" | bash "$CLASSIFICA")
    local ingressi altri
    ingressi=$(printf '%s\n' "$uscita" | sed -n 's/^INGRESSI=//p')
    altri=$(printf '%s\n' "$uscita" | sed -n 's/^ALTRI=//p')
    if [ "$ingressi" = "$attesi_ingressi" ] && [ "$altri" = "$attesi_altri" ]; then
        ok
    else
        ko "$nome: attesi INGRESSI=$attesi_ingressi ALTRI=$attesi_altri, usciti INGRESSI=$ingressi ALTRI=$altri"
    fi
}

echo "── entrano in un container: devono contare"
prova "docker exec" 1 0 docker exec pantedu-app-b cat /var/www/pantedu/.env.local
prova "docker exec interattivo" 1 0 docker exec -it pantedu-app-b sh
prova "docker cp" 1 0 docker cp pantedu-app-b:/var/www/pantedu/.env.local /tmp/x
prova "docker run con un volume" 1 0 docker run --rm -v /var/www:/w alpine cat /w/pantedu/.env.local
prova "docker container exec" 1 0 docker container exec pantedu-app-b sh
prova "opzione globale con valore" 1 0 docker --context default exec pantedu-app-b sh
prova "opzione globale con uguale" 1 0 docker --log-level=error exec pantedu-app-b sh
prova "docker compose exec" 1 0 docker compose -f x.yml exec app sh
prova "nsenter" 1 0 nsenter -t 1234 -m cat /var/www/pantedu/.env.local
prova "runc" 1 0 runc exec abc sh
prova "ctr" 1 0 ctr -n moby tasks exec --exec-id x abc sh

echo "── non entrano: non devono contare come ingressi"
prova "docker ps" 0 1 docker ps -a
prova "docker logs" 0 1 docker logs --tail 50 pantedu-app-b
prova "docker inspect" 0 1 docker inspect -f '{{.State.Status}}' pantedu-app-b
prova "docker container ls" 0 1 docker container ls
prova "un valore che si chiama exec" 0 1 docker --context exec ps
prova "un eseguibile che non è dei container" 0 0 curl -s http://127.0.0.1

echo "── più eventi, e nessun evento"
USCITA=$({ evento root docker exec pantedu-app-b sh; evento root docker ps; evento root nsenter -t 1 -m sh; } | bash "$CLASSIFICA")
if printf '%s\n' "$USCITA" | grep -qx 'INGRESSI=2' && printf '%s\n' "$USCITA" | grep -qx 'ALTRI=1'; then ok; else ko "tre eventi: $USCITA"; fi
if printf '%s\n' "$USCITA" | grep -q '^09/15/2026 01:23:45 auid=root docker exec pantedu-app-b sh$'; then ok; else ko "la riga dell'ingresso dice quando, chi e il comando: $USCITA"; fi
VUOTO=$(printf '<no matches>\n' | bash "$CLASSIFICA")
if [ "$VUOTO" = "$(printf 'INGRESSI=0\nALTRI=0')" ]; then ok; else ko "senza eventi: $VUOTO"; fi

echo "passate: $PASSATE, fallite: $FALLITE"
[ "$FALLITE" -eq 0 ]
