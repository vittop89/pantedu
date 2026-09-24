#!/usr/bin/env bash
#
# Il database di sviluppo, in un contenitore che sopravvive ai riavvii.
#
# Perché un contenitore e non MariaDB installato in WSL: si crea e si butta
# via con un comando, la versione è quella della CI (10.11) e non quella che
# capita col sistema, e i dati stanno in un volume con un nome — quindi
# `docker rm` del contenitore non li porta via.
#
# Uso:
#   bash tools/dev/wsl/database.sh                        crea (o riusa) e avvia
#   bash tools/dev/wsl/database.sh --importa  dump.sql    carica un dump in pantedu_dev
#   bash tools/dev/wsl/database.sh --importa-test dump.sql  e in pantedu_test
#
# Le credenziali qui sotto valgono solo per questo contenitore, che ascolta
# sulla loopback di WSL: non sono segreti, e non devono esserlo quelle vere.
set -euo pipefail

CONTENITORE=pantedu-mariadb-dev
VOLUME=pantedu-mariadb-dev
PORTA=3307          # non 3306: su un computer che ha ancora XAMPP acceso, collide
IMMAGINE=mariadb:10.11
PASSWORD=root

command -v docker >/dev/null 2>&1 \
    || { echo "docker non è nel percorso: in Docker Desktop, Settings → Resources → WSL Integration → Ubuntu."; exit 1; }
docker info >/dev/null 2>&1 \
    || { echo "Docker non risponde: apri Docker Desktop e aspetta «Engine running»."; exit 1; }

docker volume inspect "$VOLUME" >/dev/null 2>&1 || docker volume create "$VOLUME" >/dev/null

if docker ps -a --format '{{.Names}}' | grep -qx "$CONTENITORE"; then
    docker start "$CONTENITORE" >/dev/null
    echo "contenitore $CONTENITORE già esistente: avviato"
else
    docker run -d --name "$CONTENITORE" --restart unless-stopped \
        -v "$VOLUME":/var/lib/mysql \
        -e MARIADB_ROOT_PASSWORD="$PASSWORD" \
        -p "127.0.0.1:${PORTA}:3306" \
        "$IMMAGINE" \
        --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
    echo "contenitore $CONTENITORE creato"
fi

printf 'attendo che risponda'
for _ in $(seq 1 40); do
    if docker exec "$CONTENITORE" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
        echo " — pronto"; break
    fi
    printf '.'; sleep 2
done

sql() { docker exec -i "$CONTENITORE" mariadb -uroot -p"$PASSWORD" --default-character-set=utf8mb4 "$@"; }

for DB in pantedu_dev pantedu_test; do
    sql -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
done

importa() {   # importa <database> <file>
    [ -f "$2" ] || { echo "il file $2 non esiste"; exit 1; }
    echo "carico $2 in $1..."
    local t0; t0=$(date +%s)
    sql "$1" < "$2"
    echo "  fatto in $(( $(date +%s) - t0 ))s: $(sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'") tabelle"
}

case "${1:-}" in
    --importa)       importa pantedu_dev  "${2:?manca il file}" ;;
    --importa-test)  importa pantedu_test "${2:?manca il file}" ;;
    "")              ;;
    *)               echo "opzione sconosciuta: $1"; exit 2 ;;
esac

cat <<RIGHE

In .env.local (una volta sola):

  DB_HOST=127.0.0.1
  DB_PORT=${PORTA}
  DB_NAME=pantedu_dev
  DB_USER=root
  DB_PASS=${PASSWORD}
RIGHE
