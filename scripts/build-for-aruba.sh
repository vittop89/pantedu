#!/usr/bin/env bash
# Phase X — build deploy bundle per Aruba Linux basic (o simile shared hosting).
#
# Esegue pre-build locale (composer no-dev + npm build + seed PT opzionale)
# e struttura l'output in due directory pronte per FTP upload:
#
#   dist/httpdocs/  → contenuto da mettere nella webroot (httpdocs/)
#   dist/private/   → contenuto da mettere in una dir sibling (fuori webroot)
#
# Uso:
#   bash scripts/build-for-aruba.sh [--with-seed]
#
# Requirements: composer, npm, php ≥8.3 sul dev machine.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
WITH_SEED=0

for arg in "$@"; do
    case "$arg" in
        --with-seed) WITH_SEED=1 ;;
        *) echo "Unknown arg: $arg"; exit 1 ;;
    esac
done

echo "==> Build for Aruba Linux basic hosting"
echo "    root: $ROOT"
echo "    dist: $DIST"

# ── 1. Clean dist ──
rm -rf "$DIST"
mkdir -p "$DIST/httpdocs" "$DIST/private"

# ── 2. Composer production install ──
echo "==> composer install --no-dev --optimize-autoloader"
cd "$ROOT"
composer install --no-dev --optimize-autoloader --quiet

# ── 3. Vite build ──
echo "==> npm ci + npm run build"
npm ci --silent
npm run build

# ── 4. (Opt) seed PT defaults ──
if [ "$WITH_SEED" = "1" ]; then
    echo "==> php bin/risdoc-pt-seed.php --all --auto-annotate --apply"
    php bin/risdoc-pt-seed.php --all --auto-annotate --apply
fi

# ── 5. Copy httpdocs (webroot) ──
echo "==> Copying public/ → dist/httpdocs/"
cp -r "$ROOT/public/." "$DIST/httpdocs/"

# ── 6. Copy private (tutto il resto necessario a runtime) ──
echo "==> Copying private payload → dist/private/"
for d in app vendor routes schemas views bin; do
    [ -d "$ROOT/$d" ] && cp -r "$ROOT/$d" "$DIST/private/"
done
# storage/: solo templates + data. Logs/sessions vuoti sul server (chmod 777 post-upload).
mkdir -p "$DIST/private/storage/logs" "$DIST/private/storage/sessions" "$DIST/private/storage/risdoc-tmp"
[ -d "$ROOT/storage/templates" ] && cp -r "$ROOT/storage/templates" "$DIST/private/storage/"
[ -d "$ROOT/storage/data" ]      && cp -r "$ROOT/storage/data"      "$DIST/private/storage/"
[ -d "$ROOT/storage/overrides" ] && cp -r "$ROOT/storage/overrides" "$DIST/private/storage/"

# composer.json/.lock per eventuali update server-side (se permesso)
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$DIST/private/" 2>/dev/null || true

# ── 7. .env template ──
if [ ! -f "$DIST/private/.env" ]; then
    cat > "$DIST/private/.env.example" <<'ENV'
# Copia questo file in `private/.env` e compila con credenziali Aruba
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tuo-dominio.it
APP_TIMEZONE=Europe/Rome

DB_ENABLED=true
DB_HOST=sql.tuohost.aruba.it
DB_PORT=3306
DB_NAME=Sqltuonome
DB_USER=Sqltuonome
DB_PASS=
DB_CHARSET=utf8mb4

SESSION_DRIVER=file
SESSION_LIFETIME=120
CSRF_SECRET=generate_random_32_chars_here
ENV
fi

# ── 8. README deploy instructions ──
cat > "$DIST/README.txt" <<EOF
Pantedu — deploy bundle for Aruba Linux basic hosting
========================================================

Struttura:
  dist/httpdocs/  → upload via FTP in /home/USER/httpdocs/ (webroot)
  dist/private/   → upload via FTP in /home/USER/private/ (sibling dir)

Step post-upload:
  1. chmod 775 private/storage/logs/ private/storage/sessions/ private/storage/risdoc-tmp/
  2. Copia private/.env.example → private/.env e compila credenziali DB
  3. Importa DB schema via phpMyAdmin (database/schema.sql dal repo)
  4. Apri https://TUO-DOMINIO.it/ — verifica home carica senza errori
  5. Login admin + verifica /risdoc/view/{id} renderizza

Detection automatica del layout:
  public/index.php cerca private/app/bootstrap.php. Se presente → usa
  private/ come app root. Altrimenti → fallback dev layout.

Per dettagli: wiki/deployment/aruba-linux-basic.md nel repo.
EOF

echo ""
echo "============================================================"
echo " Bundle creato in $DIST/"
echo "   httpdocs/  ($(du -sh "$DIST/httpdocs" | cut -f1))"
echo "   private/   ($(du -sh "$DIST/private"  | cut -f1))"
echo ""
echo " Prossimi step:"
echo "   1. FTP upload dist/httpdocs/* → /home/USER/httpdocs/"
echo "   2. FTP upload dist/private/   → /home/USER/private/"
echo "   3. chmod 775 private/storage/{logs,sessions,risdoc-tmp}/"
echo "   4. Crea private/.env da .env.example (credenziali DB Aruba)"
echo "============================================================"
