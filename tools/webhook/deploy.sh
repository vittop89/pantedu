#!/usr/bin/env bash
#
# pantedu-deploy.sh — auto-deploy script triggered by GitHub webhook.
#
# Installazione:
#   sudo cp tools/webhook/deploy.sh /usr/local/bin/pantedu-deploy.sh
#   sudo chmod 755 /usr/local/bin/pantedu-deploy.sh
#   sudo chown root:root /usr/local/bin/pantedu-deploy.sh
#
# Sudoers (su nuovo file /etc/sudoers.d/pantedu-deploy):
#   www-data ALL=(root) NOPASSWD: /usr/local/bin/pantedu-deploy.sh
#
# Log: /var/log/pantedu-deploy.log (creato dal webhook handler).
#
# Step:
#   1. cd /var/www/pantedu
#   2. git fetch + reset --hard origin/master_vps (idempotente, pulisce drift)
#   3. composer install --no-dev (se composer.lock cambiato)
#   4. systemctl reload php8.4-fpm (clear opcache)
#   5. tex-compile sync: se tools/tex-compile-vps/app/* cambiati →
#      rsync /opt/tex-compile/app/ + chown texcompile + systemctl restart tex-compile
#   6. nginx tex-compile config sync: se tools/tex-compile-vps/nginx/*.conf cambiati →
#      install + nginx -t + systemctl reload nginx
#   7. pantedu systemd units sync: se tools/systemd/*.{service,timer} cambiati →
#      install in /etc/systemd/system/, daemon-reload, enable+start dei .timer
#   8. self-update: re-installa questo stesso script da repo se modificato

set -euo pipefail

REPO_DIR="/var/www/pantedu"
BRANCH="main"
PHP_FPM_SERVICE="php8.4-fpm"
TEX_COMPILE_DIR="/opt/tex-compile"
TEX_COMPILE_USER="texcompile"
TEX_COMPILE_SERVICE="tex-compile"
TEX_COMPILE_SRC_REL="tools/tex-compile-vps/app"
TEX_NGINX_SRC_REL="tools/tex-compile-vps/nginx"
TEX_NGINX_DST="/etc/nginx/sites-available/tex-compile.conf"
APP_SYSTEMD_SRC_REL="tools/systemd"
APP_SYSTEMD_DST_DIR="/etc/systemd/system"
SELF_PATH="/usr/local/bin/pantedu-deploy.sh"
SELF_SRC_REL="tools/webhook/deploy.sh"
# git ops girano come pantedu (owner repo, ha la SSH deploy key in /home/pantedu/.ssh).
# Lo script gira come root (per systemctl reload), quindi delega via sudo -u.
GIT_USER="pantedu"
git_as_owner() { sudo -u "$GIT_USER" git -C "$REPO_DIR" "$@"; }

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [deploy] $*"; }
warn() { echo "[$(ts)] [deploy] [WARN] $*" >&2; }

# Phase 25.O: trap EXIT garantisce log esito deterministico anche su
# SIGTERM / set -e abort / errore mid-flight. Inoltre rilascia flock.
DEPLOY_START_TS=$(date +%s)
trap '
    DEPLOY_END_TS=$(date +%s)
    DURATION=$((DEPLOY_END_TS - DEPLOY_START_TS))
    EXIT_CODE=$?
    if [[ $EXIT_CODE -eq 0 ]]; then
        log "=== deploy END OK (duration ${DURATION}s) ==="
    else
        warn "=== deploy END FAIL exit=${EXIT_CODE} (duration ${DURATION}s) ==="
    fi
' EXIT

log "=== deploy start ==="

# Stash local changes? — no, master_vps deve essere pulito sul VPS.
# Drift prevention: reset --hard sovrascrive ogni edit accidentale.

OLD_HEAD=$(git_as_owner rev-parse HEAD)
log "old HEAD: $OLD_HEAD"

git_as_owner fetch --all --prune --quiet
git_as_owner reset --hard "origin/$BRANCH" --quiet

NEW_HEAD=$(git_as_owner rev-parse HEAD)
log "new HEAD: $NEW_HEAD"

# Version marker per l'endpoint /version (www-data NON legge .git/refs:
# pantedu:pantedu 0660). Scritto in storage/ che subito sotto diventa g+w
# www-data. Idempotente.
echo "$NEW_HEAD" > "$REPO_DIR/storage/version.txt" 2>/dev/null || true

# ═════════════════════════════════════════════════════════════════════
# Phase 25.R.5.2 follow-up — TUTTI i perm fix DEVONO girare PRIMA del
# check noop. `git_as_owner reset --hard` (eseguito sopra) resetta i
# file checked-out a 0660 pantedu:pantedu: senza i fix successivi
# www-data riceve "Permission denied" su bootstrap.php/.env/index.php.
# Storia incidents: nodo `aa2eb6d7` (cleanup KMS placeholder) ha
# triggerato un deploy che ha appiattito i perms → login 500 a cascata.
# Idempotenti, sempre safe: girare anche in noop garantisce la
# self-healing dopo qualunque manipolazione manuale dei perms.
# ═════════════════════════════════════════════════════════════════════

# 1) .env (versionato) leggibile da www-data
if [[ -f "$REPO_DIR/.env" ]]; then
    chgrp www-data "$REPO_DIR/.env" 2>/dev/null || true
    chmod 0640 "$REPO_DIR/.env" 2>/dev/null || true
fi

# 2) storage/ writable da www-data (upload runtime + log scrivibili)
chgrp -R www-data "$REPO_DIR/storage" 2>/dev/null || true
chmod -R g+w "$REPO_DIR/storage" 2>/dev/null || true

# 3) Source dirs PHP/JS/CSS/template leggibili da www-data (PHP-FPM).
# Phase 25.P.2 + Q.3 — git crea file 0660 pantedu:pantedu → 403/500
# se www-data non vede bootstrap.php, routes/web.php, views/, ecc.
# NB: include img + wasm — gli asset statici (es. img/topbar/*.svg) serviti da
# nginx alias devono essere leggibili da www-data, altrimenti 403 (Phase: fix
# icone topbar vscode/overleaf 403). Estensioni estese a immagini/font/wasm.
# NB2: include schemas/ — gli schema JSON risdoc (es. schemas/risdoc/*.json)
# sono letti da PHP (TemplateController::schema, ExportController). Senza chgrp
# git li ricrea pantedu:pantedu 0660 → www-data non li legge → /schema 200 vuoto
# → "Unexpected end of JSON input" nei modelli istituzionali (2026-05-25).
for d in app routes database public config tools js views css img wasm schemas; do
    [[ -d "$REPO_DIR/$d" ]] || continue
    chgrp -R www-data "$REPO_DIR/$d" 2>/dev/null || true
    find "$REPO_DIR/$d" -type f \( -name '*.php' -o -name '*.html' -o -name '*.sql' -o -name '*.yaml' -o -name '*.yml' -o -name '*.json' -o -name '*.css' -o -name '*.js' -o -name '*.svg' -o -name '*.png' -o -name '*.jpg' -o -name '*.jpeg' -o -name '*.webp' -o -name '*.gif' -o -name '*.ico' -o -name '*.woff' -o -name '*.woff2' -o -name '*.ttf' -o -name '*.wasm' \) -exec chmod 0644 {} + 2>/dev/null || true
    find "$REPO_DIR/$d" -type d -exec chmod 0755 {} + 2>/dev/null || true
done

# 3.bis) Root-level files leggibili da www-data (index.php legacy entry
# point + .env config + README/SECURITY/NOTICE per OSS). Phase S2 Fase 2:
# index.php in root resta entry point legacy (HomeController serve via
# Response::file). Senza fix: 500 Permission denied su /var/www/pantedu/index.php.
find "$REPO_DIR" -maxdepth 1 -type f \( -name '*.php' -o -name '*.md' -o -name '*.json' -o -name '.gitignore' -o -name '.gitattributes' \) -exec chgrp www-data {} + 2>/dev/null || true
find "$REPO_DIR" -maxdepth 1 -type f \( -name '*.php' -o -name '*.md' -o -name '*.json' -o -name '.gitignore' \) -exec chmod 0644 {} + 2>/dev/null || true

# 3.ter) SAFETY-NET — tutti i file TRACCIATI leggibili da www-data.
# Il loop §3 elenca dir+estensioni specifiche: i file fuori lista restano
# pantedu:pantedu 0660 → "Permission denied" a runtime. Incident 2026-06-02:
# i markdown in docs/ (letti da TrustPagesController per le pagine legali +
# informativa privacy) non erano coperti → tutte le trust page renderizzate
# VUOTE. Questo garantisce other-read su OGNI file versionato, a prescindere
# da dir/estensione, evitando il ricorrere del problema. Idempotente.
git -C "$REPO_DIR" ls-files -z 2>/dev/null | xargs -0 -r chmod a+r 2>/dev/null || true
find "$REPO_DIR" -type d -not -path '*/.git/*' -exec chmod a+rx {} + 2>/dev/null || true
# Ri-restringe .env DOPO il catch-all (contiene configurazione).
if [[ -f "$REPO_DIR/.env" ]]; then
    chgrp www-data "$REPO_DIR/.env" 2>/dev/null || true
    chmod 0640 "$REPO_DIR/.env" 2>/dev/null || true
fi

log "perms fixed (pre-noop): .env, storage/, app/, routes/, database/, public/, config/, tools/, js/, views/, css/, root-level"

if [[ "$OLD_HEAD" == "$NEW_HEAD" ]]; then
    log "no changes (already up to date)"
    log "=== deploy end (noop) ==="
    exit 0
fi

# Phase 25.R follow-up — Pre-deploy snapshot Hetzner Cloud (no-op se token
# non configurato in /etc/pantedu/hetzner-api.env). Permette rollback
# rapido in caso deploy rompa qualcosa.
if [[ -x /usr/local/sbin/hetzner_snapshot.sh ]]; then
    log "creating pre-deploy snapshot (Hetzner API)..."
    /usr/local/sbin/hetzner_snapshot.sh "pre-deploy-$NEW_HEAD" 2>&1 | sed 's/^/[snapshot] /' || warn "snapshot skipped/failed (non-fatal)"
fi

# composer install solo se composer.lock è cambiato fra OLD e NEW
if git_as_owner diff --name-only "$OLD_HEAD" "$NEW_HEAD" | grep -q '^composer\.\(json\|lock\)$'; then
    log "composer dependencies changed → install --no-dev"
    sudo -u "$GIT_USER" composer --working-dir="$REPO_DIR" install --no-dev --no-interaction --optimize-autoloader 2>&1 | sed 's/^/[composer] /'

    # 2026-05-25 incident — composer install crea i file vendor/ con l'umask
    # restrittivo di $GIT_USER (0600/0700) → www-data (PHP-FPM) riceve
    # "Permission denied" su vendor/composer/autoload_real.php → 500 site-wide.
    # Il perms-fix generale (sopra) NON copre vendor/, e gira comunque PRIMA
    # del composer install. Sanare qui i perms di vendor/ dopo ogni install.
    log "fix perms vendor/ (leggibile da www-data)"
    chgrp -R www-data "$REPO_DIR/vendor" 2>/dev/null || true
    find "$REPO_DIR/vendor" -type d -exec chmod 0755 {} + 2>/dev/null || true
    find "$REPO_DIR/vendor" -type f -exec chmod 0644 {} + 2>/dev/null || true
fi

# Phase 25.Q.4 — Sync nginx vhost config se diff con installato.
# Single source of truth: infra/nginx/pantedu.eu.conf.
# Test syntax con nginx -t prima di applicare; rollback su fail.
NGINX_SRC="$REPO_DIR/infra/nginx/pantedu.eu.conf"
NGINX_DST="/etc/nginx/sites-available/pantedu.eu.conf"
if [[ -f "$NGINX_SRC" ]] && [[ -f "$NGINX_DST" ]]; then
    if ! cmp -s "$NGINX_SRC" "$NGINX_DST"; then
        log "nginx config diff detected → testing + applying"
        cp "$NGINX_SRC" "${NGINX_DST}.new"
        # Test syntax con il nuovo file simbolicamente (sostituendolo temp)
        BACKUP="${NGINX_DST}.bak-$(date +%Y%m%d-%H%M%S)"
        cp "$NGINX_DST" "$BACKUP"
        mv "${NGINX_DST}.new" "$NGINX_DST"
        if nginx -t > /tmp/nginx-test.log 2>&1; then
            systemctl reload nginx
            log "nginx config applied + reloaded (backup: $BACKUP)"
        else
            # Rollback
            mv "$BACKUP" "$NGINX_DST"
            log "nginx config TEST FAILED — rollback applied. Log:"
            cat /tmp/nginx-test.log | sed 's/^/[nginx-test] /' | tee -a "$LOG_FILE"
        fi
    else
        log "nginx config: no diff, skip"
    fi
fi

# G22.S15.bis Fase 5+ — Refactor codes lowercase legacy → uppercase canonico
# (sc→SCI, ar→ART, ling→LIN + classe combinata sc1s→SCI1S etc).
# Aggiorna DB (~12 tabelle), JSON contract content, filename rename
# storage_objects.storage_key. Idempotente: re-run = 0 modifiche.
if [[ -f "$REPO_DIR/tools/refactor_codes_to_uppercase.php" ]]; then
    log "run refactor_codes_to_uppercase --commit (idempotente)"
    if sudo -u "$GIT_USER" php "$REPO_DIR/tools/refactor_codes_to_uppercase.php" --commit 2>&1 | sed 's/^/[refactor] /'; then
        log "refactor codes OK"
    else
        log "WARN: refactor_codes exit non-zero (deploy continua)"
    fi
fi

# G22.S15.bis Fase 5+ — Cleanup curriculum legacy (SOFT, idempotente).
# Tutto in tools/cleanup_curriculum.php: drop duplicati orfani, fix label,
# migrate legacy NULL → primary istituto, ALTER NOT NULL. No-op se schema
# gia' modernizzato. Eseguito PRIMA delle migration cosi' migration 037+
# trovano schema coerente.
if [[ -f "$REPO_DIR/tools/cleanup_curriculum.php" ]]; then
    log "run cleanup_curriculum.php --commit (idempotente)"
    if sudo -u "$GIT_USER" php "$REPO_DIR/tools/cleanup_curriculum.php" --commit 2>&1 | sed 's/^/[curriculum] /'; then
        log "curriculum cleanup OK"
    else
        log "WARN: cleanup_curriculum exit non-zero (deploy continua)"
    fi
fi

# Phase 25.O — pre-migrate DB snapshot (safety net per rollback rapido).
# Snapshot solo se mysqldump funziona (auth via /root/.my.cnf su Debian Trixie).
# Retain ultimi 5 (~3MB totali, costo trascurabile).
#
# 2026-05-23 fix: --defaults-file esplicito. Sotto systemd-deploy.service,
# mysqldump non leggeva auto-magic $HOME/.my.cnf (env scrubbed?) → fallback
# anonymous "using password: NO" → Access denied. Passando il path esplicito
# il problema scompare; sotto shell interattiva il comportamento è invariato.
PRE_DEPLOY_DIR="/var/backups/pantedu/pre-deploy"
mkdir -p "$PRE_DEPLOY_DIR"
chmod 700 "$PRE_DEPLOY_DIR"
SNAP_FILE="${PRE_DEPLOY_DIR}/db-pre-deploy-$(date +%Y%m%d_%H%M%S).sql.gz"
MYSQL_DEFAULTS_FILE="/root/.my.cnf"
log "pre-deploy DB snapshot → $SNAP_FILE"
if [[ -r "$MYSQL_DEFAULTS_FILE" ]]; then
    if mysqldump --defaults-file="$MYSQL_DEFAULTS_FILE" --single-transaction --quick --routines --triggers --events pantedu 2>/dev/null | gzip > "$SNAP_FILE"; then
        SNAP_SIZE=$(du -h "$SNAP_FILE" | awk '{print $1}')
        log "  ✓ DB snapshot $SNAP_SIZE"
        # Retain ultimi 5
        ls -t "$PRE_DEPLOY_DIR"/db-pre-deploy-*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm
    else
        warn "pre-deploy DB snapshot FAILED (auth issue?). Deploy continua."
        rm -f "$SNAP_FILE"
    fi
else
    warn "pre-deploy DB snapshot SKIPPED: $MYSQL_DEFAULTS_FILE non leggibile. Hetzner snapshot copre comunque."
    rm -f "$SNAP_FILE"
fi

# G22.S15.bis Fase 5 — DB migrations: esegui SEMPRE `php tools/migrate.php`.
# Lo script è idempotente (salta migration già applicate via tabella
# migrations_log). Non-fatale: WARN ma deploy continua se fallisce.
# Always-run perchè il check git-diff fallirebbe quando migration è stata
# committata in un deploy precedente (script vecchio senza questo step).
if [[ -f "$REPO_DIR/tools/migrate.php" ]]; then
    log "run php tools/migrate.php (idempotente)"
    if sudo -u "$GIT_USER" php "$REPO_DIR/tools/migrate.php" 2>&1 | sed 's/^/[migrate] /'; then
        log "migrations checked OK"
    else
        warn "migrate.php exit non-zero — rollback DB con: zcat $SNAP_FILE | mysql pantedu"
    fi
fi

# G22.S15.bis Fase 5 — verifica CA bundle: il GitHub sync usa curl HTTPS
# verso api.github.com, fallisce se /etc/ssl/certs è vuoto/stale.
# Test rapido: handshake con api.github.com. Se fallisce → install
# ca-certificates (idempotente, no-op se già current).
if ! curl -fs --max-time 5 -o /dev/null https://api.github.com 2>/dev/null; then
    log "CA bundle check failed → install/update ca-certificates"
    DEBIAN_FRONTEND=noninteractive apt-get install -qy ca-certificates 2>&1 | sed 's/^/[apt] /' || true
    update-ca-certificates 2>&1 | sed 's/^/[ca] /' || true
fi

# 2026-05-24 — CSS bundle build (efficient + scalable).
# Concat ricorsivo main.css + 37 @import nested → css/main.bundle.css.
# Risolve CF cache di @import nested URLs: 1 file = 1 URL = 1 cache-bust.
# Idempotente: re-run sempre safe, regenera bundle ad ogni deploy.
# Non-fatal: WARN + deploy continua se fallisce (fallback main.css con @import).
if [[ -f "$REPO_DIR/tools/build-css-bundle.php" ]]; then
    log "build CSS bundle (concat ricorsivo @import)"
    if sudo -u "$GIT_USER" php "$REPO_DIR/tools/build-css-bundle.php" 2>&1 | sed 's/^/[css-bundle] /'; then
        chgrp www-data "$REPO_DIR/css/main.bundle.css" 2>/dev/null || true
        chmod 0644 "$REPO_DIR/css/main.bundle.css" 2>/dev/null || true
        # 2026-05-24 fix — touch mtime per cache-bust deterministic (build-css-bundle
        # rispetta source mtimes ma se nessun source @import cambiato il bundle
        # non viene riscritto → PHP filemtime() restituisce vecchio valore →
        # browser cache vecchio bundle. Touch forza mtime fresca = nuova URL
        # cache-bust `?v=NEW` = CF cache miss + browser re-fetch.
        touch "$REPO_DIR/css/main.bundle.css" 2>/dev/null || true
    else
        warn "CSS bundle build failed — fallback main.css con @import nested"
    fi
fi

# 2026-05-24 — Cloudflare cache purge opzionale per main.bundle.css.
# Attiva via /etc/pantedu-deploy.env con CF_API_TOKEN + CF_ZONE_ID.
# Senza creds, no-op (cache-bust query `?v=mtime` gestisce comunque update).
if [[ -f /etc/pantedu-deploy.env ]]; then
    # shellcheck source=/dev/null
    source /etc/pantedu-deploy.env
fi
# 2026-05-26 — strip CR: se /etc/pantedu-deploy.env ha line-ending CRLF, le var
# contengono un \r finale → header "Authorization: Bearer <token>\r" malformato
# → CF risponde "Invalid format for Authorization header" e il purge falliva
# SILENZIOSAMENTE (non-fatal). Sintomo: asset stale dopo deploy nonostante "OK".
CF_API_TOKEN="${CF_API_TOKEN//$'\r'/}"
CF_ZONE_ID="${CF_ZONE_ID//$'\r'/}"
if [[ -n "${CF_API_TOKEN:-}" ]] && [[ -n "${CF_ZONE_ID:-}" ]]; then
    # purge_everything: i moduli ESM RAW (/js/components/risdoc/index.js e i suoi
    # import nested es. fm-risdoc-section-header.js) sono serviti senza ?v=mtime,
    # quindi CF li teneva 24h stale dopo deploy. Il purge mirato ai soli CSS NON
    # li copriva. purge_everything è semplice e affidabile per questo sito.
    log "purge Cloudflare cache (purge_everything — CSS bundle + JS raw moduli)"
    curl -sS -X POST \
        "https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/purge_cache" \
        -H "Authorization: Bearer ${CF_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --max-time 10 \
        --data '{"purge_everything":true}' \
        2>&1 | sed 's/^/[cf-purge] /' | tail -3 || warn "CF purge failed (non-fatal)"
fi

# 2026-05-24 — Image optimization (sharp → WebP/AVIF responsive).
# Genera public/img/optimized/* da img/sources/*.png con -85-89% size reduction.
# Idempotente: re-run safe ad ogni deploy, sharp auto-skip se non installato.
if [[ -f "$REPO_DIR/tools/build/optimize-images.mjs" ]] && [[ -d "$REPO_DIR/img/sources" ]]; then
    if command -v npm >/dev/null 2>&1; then
        log "build optimized images (sharp WebP/AVIF)"
        cd "$REPO_DIR" && sudo -u "$GIT_USER" npm run build:images 2>&1 | sed 's/^/[images] /' || warn "image build failed (non-fatal)"
        chgrp -R www-data "$REPO_DIR/public/img/optimized" 2>/dev/null || true
    fi
fi

# 2026-05-24 (Fase 1 perf optim) — Vite build production con code-splitting.
# Output: public/build/assets/*.{HASH}.js + manifest.json.
# I PHP template (Fase 2) leggono il manifest per servire chunks hashati
# invece di bootstrap.js raw (60+ ES import nested = waterfall su Slow 3G).
#
# Idempotente: re-run safe ad ogni deploy (emptyOutDir: true).
# Non-fatal: WARN + deploy continua se fallisce → fallback a bootstrap.dist.js
# (Vite manifest assente → ViteManifest::url() restituisce raw path).
#
# bundle-budget enforcement: blocca deploy se bundle gzip > budget definiti
# in tools/ci/bundle-budget.mjs (bootstrap ≤ 600 KB, risdoc ≤ 300 KB).
if [[ -f "$REPO_DIR/package.json" ]] && command -v npm >/dev/null 2>&1; then
    if grep -q '"build"' "$REPO_DIR/package.json"; then
        log "npm ci (install devDependencies per vite build)"
        # --no-audit/--no-fund: skip output rumoroso, --prefer-offline: usa cache
        if cd "$REPO_DIR" && sudo -u "$GIT_USER" npm ci --no-audit --no-fund --prefer-offline 2>&1 | tail -5 | sed 's/^/[npm-ci] /'; then
            log "npm ci OK"
            log "vite build (chunks hashati + manifest)"
            if sudo -u "$GIT_USER" npm run build 2>&1 | tail -20 | sed 's/^/[vite] /'; then
                chgrp -R www-data "$REPO_DIR/public/build" 2>/dev/null || true
                log "vite build OK"
                # Bundle budget enforcement (non-fatal warn — non blocca deploy,
                # solo segnala regressione. Promuovere a fatal dopo 2 settimane stabile)
                log "bundle-budget check"
                sudo -u "$GIT_USER" npm run bundle:budget 2>&1 | sed 's/^/[budget] /' || warn "bundle budget violation (non-fatal)"
            else
                warn "vite build failed — fallback bootstrap.dist.js raw"
            fi
        else
            warn "npm ci failed — skip vite build"
        fi
    fi
fi

# 2026-05-24 — JS cache-bust dist (bootstrap.dist.js con ?v=mtime nei imports).
# Risolve browser ES module cache stale: il browser cache by URL, sub-imports
# del bootstrap.js (75 dependencies nested) erano serviti da disk cache anche
# dopo cache-bust del entry point. Aggiungendo ?v=mtime ad ogni import path,
# ogni modifica di un sub-module invalida automaticamente la sua cache browser.
if [[ -f "$REPO_DIR/tools/build-js-cache-bust.php" ]]; then
    log "build JS cache-bust dist (75 import statements)"
    if sudo -u "$GIT_USER" php "$REPO_DIR/tools/build-js-cache-bust.php" 2>&1 | sed 's/^/[js-cb] /'; then
        chgrp www-data "$REPO_DIR/js/modules/bootstrap.dist.js" 2>/dev/null || true
        chmod 0644 "$REPO_DIR/js/modules/bootstrap.dist.js" 2>/dev/null || true
    else
        warn "JS cache-bust build failed — fallback bootstrap.js (sub-imports senza cache-bust)"
    fi
fi

# G22.S15.bis Fase 5 — drawio webapp self-hosted: install/update se la
# versione richiesta in tools/install-drawio.sh e' diversa da quella
# corrente in public/drawio-app/.drawio-version. Idempotente.
if [[ -f "$REPO_DIR/tools/install-drawio.sh" ]]; then
    log "run drawio webapp install (idempotente)"
    # Pre-check: serve almeno uno fra unzip/python3/jar per estrarre il .war
    if ! command -v unzip >/dev/null 2>&1; then
        log "    unzip non installato → installo (richiesto da drawio webapp)"
        DEBIAN_FRONTEND=noninteractive apt-get install -qy unzip 2>&1 | sed 's/^/[apt] /' || true
    fi
    # Run install (cattura output completo cosi' loggiamo eventuali errori)
    DRAWIO_LOG="/tmp/install-drawio.$$.log"
    if sudo -u "$GIT_USER" bash "$REPO_DIR/tools/install-drawio.sh" >"$DRAWIO_LOG" 2>&1; then
        log "drawio webapp OK"
        sed 's/^/[drawio] /' "$DRAWIO_LOG"
    else
        log "ERROR: install-drawio.sh exit $? — output completo:"
        sed 's/^/[drawio-err] /' "$DRAWIO_LOG"
        log "WARN: deploy continua, ma /drawio-app/ ritornera' 404"
    fi
    rm -f "$DRAWIO_LOG"
fi

# ── Phase 25.H — Ensure GeoIP databases (Country + ASN) ───────────────────
# Scarica i .mmdb mancanti in storage/geoip/ (db-ip Lite free).
# Idempotente: skippa se file esiste. Re-download manuale: rimuovere file.
# Append WAF_GEOIP_*_DB in .env.local se mancanti (config Bootstrap legge env).
GEOIP_DIR="$REPO_DIR/storage/geoip"
mkdir -p "$GEOIP_DIR"

ensure_geoip_db() {
    local name="$1" url_base="$2" env_key="$3"
    local dest="$GEOIP_DIR/${name}.mmdb"
    if [[ -f "$dest" ]]; then
        log "geoip $name: present ($(du -h "$dest" | awk '{print $1}'))"
    else
        # Prova mese corrente, fallback a precedente (db-ip cycle)
        local ym=$(date -u +%Y-%m) ym_prev
        ym_prev=$(date -u -d "1 month ago" +%Y-%m 2>/dev/null || date -u +%Y-%m)
        for try_ym in "$ym" "$ym_prev"; do
            local u="${url_base}-${try_ym}.mmdb.gz"
            log "geoip $name: download $u"
            if curl -sfL "$u" -o "${dest}.gz" 2>/dev/null; then
                gunzip -f "${dest}.gz" && break
            else
                log "  $u → 404, retry"
                rm -f "${dest}.gz"
            fi
        done
        if [[ -f "$dest" ]]; then
            chown "$GIT_USER:www-data" "$dest"
            chmod 644 "$dest"
            log "  installed: $(du -h "$dest" | awk '{print $1}')"
        else
            log "  WARN: download $name failed (entrambi i mesi 404)"
        fi
    fi
    # Append env var se mancante. 2026-05-23 fix: .env.local può avere
    # attributo +i (immutable) come hardening contro tamper accidentale —
    # tee fallisce con "Operation not permitted". Detect e log gentile,
    # niente errore: l'admin deve fare manualmente chattr -i, append, +i.
    local env_file="$REPO_DIR/.env.local"
    if [[ -f "$env_file" ]] && ! grep -q "^${env_key}=" "$env_file" && [[ -f "$dest" ]]; then
        if lsattr "$env_file" 2>/dev/null | awk '{print $1}' | grep -q 'i'; then
            log "  SKIP append ${env_key}: $env_file è immutable (chattr +i)."
            log "       Manuale: sudo chattr -i $env_file && echo '${env_key}=${dest}' >> $env_file && sudo chattr +i $env_file"
        else
            echo "${env_key}=${dest}" | sudo -u "$GIT_USER" tee -a "$env_file" >/dev/null
            log "  appended ${env_key} to .env.local"
        fi
    fi
}

ensure_geoip_db "dbip-country-lite" "https://download.db-ip.com/free/dbip-country-lite" "WAF_GEOIP_DB"
ensure_geoip_db "dbip-asn-lite"     "https://download.db-ip.com/free/dbip-asn-lite"     "WAF_GEOIP_ASN_DB"

# ── Phase 25.I — primo sync threat-intel se tabelle vuote ───────────
# Evita di attendere primo timer cron. Idempotente: count > 0 → skip.
if [[ -f "$REPO_DIR/tools/waf/sync_threat_intel.php" ]]; then
    TI_COUNT=$(sudo -u "$GIT_USER" php -r "
        require '$REPO_DIR/app/bootstrap.php';
        try {
            \$pdo = App\Core\Database::connection();
            \$c = (int)\$pdo->query('SELECT COUNT(*) FROM waf_threat_ips')->fetchColumn();
            echo \$c;
        } catch (Throwable \$e) { echo '0'; }
    " 2>/dev/null || echo "0")
    if [[ "${TI_COUNT:-0}" -eq 0 ]]; then
        log "threat-intel tables vuote → primo sync (asn+spamhaus+tor+x4b, ~30s)"
        # CrowdSec skip (richiede API key signup admin via /admin/waf/threat-intel)
        for src in asn spamhaus tor x4b; do
            sudo -u "$GIT_USER" php "$REPO_DIR/tools/waf/sync_threat_intel.php" --source="$src" 2>&1 | sed "s/^/[ti-$src] /" || log "WARN: ti sync $src failed"
        done
    else
        log "threat-intel: $TI_COUNT IP gia' presenti, skip primo sync"
    fi
fi

# 2026-05-24 deploy fix: reload era insufficiente per clearare stat cache
# (realpath_cache_ttl 120s per ogni FPM worker individuale). Workers pooled
# mantenevano vecchi filemtime() values per CSS bundle anche dopo build →
# PHP serviva URL `?v=OLD_MTIME` → browser/CF cache hit content stantio.
# Restart kill+respawn workers = clean stat cache garantito.
log "restart $PHP_FPM_SERVICE (kill workers per clean stat cache)"
systemctl restart "$PHP_FPM_SERVICE"

# Phase 25.O — Health check post-reload (rileva 5xx subito).
# Bypass geo-block forzando IP locale + Host header.
sleep 2  # PHP-FPM workers ramp-up
HEALTH_URL="https://127.0.0.1/login"
HEALTH_CODE=$(curl -sk -o /dev/null -w '%{http_code}' \
    -H 'Host: pantedu.eu' \
    -H 'X-Forwarded-For: 127.0.0.1' \
    --max-time 10 \
    "$HEALTH_URL" 2>/dev/null || echo "000")
case "$HEALTH_CODE" in
    2[0-9][0-9]|3[0-9][0-9]|403)
        # 2xx OK, 3xx redirect, 403 = WAF geo-block (atteso da localhost VPS) — tutti OK
        log "health check: HTTP $HEALTH_CODE ✓"
        ;;
    4[0-9][0-9])
        log "health check: HTTP $HEALTH_CODE (client error, NOT critical)"
        ;;
    5[0-9][0-9])
        warn "health check: HTTP $HEALTH_CODE (SERVER ERROR — PHP-FPM o app broken)"
        warn "  rollback consigliato: cd $REPO_DIR && git_as_owner reset --hard $OLD_HEAD"
        ;;
    000)
        warn "health check: connection failed (nginx/PHP-FPM down?)"
        ;;
    *)
        warn "health check: HTTP $HEALTH_CODE (unexpected)"
        ;;
esac

# ── Step 5: tex-compile (Python FastAPI) ───────────────────────────────────
# /opt/tex-compile NON è un git repo: copiamo solo i .py modificati da
# tools/tex-compile-vps/app/ → /opt/tex-compile/app/, fix ownership, restart.
# rilevamento cambi via git diff: se uno qualsiasi dei file in
# TEX_COMPILE_SRC_REL/ è cambiato fra OLD_HEAD e NEW_HEAD → sync + restart.
TEX_CHANGES=$(git_as_owner diff --name-only "$OLD_HEAD" "$NEW_HEAD" | grep "^${TEX_COMPILE_SRC_REL}/" || true)
if [[ -n "$TEX_CHANGES" ]]; then
    log "tex-compile sources changed → sync + restart"
    log "changed files:"
    while IFS= read -r f; do log "  $f"; done <<< "$TEX_CHANGES"

    if [[ -d "$TEX_COMPILE_DIR/app" ]]; then
        # rsync solo *.py (no __pycache__, no .pyc) per evitare residui
        rsync -a --include='*.py' --exclude='*' \
            "$REPO_DIR/$TEX_COMPILE_SRC_REL/" "$TEX_COMPILE_DIR/app/" 2>&1 | sed 's/^/[rsync] /'
        chown -R "$TEX_COMPILE_USER:$TEX_COMPILE_USER" "$TEX_COMPILE_DIR/app/"
        # invalida bytecode caches per evitare conflitti import
        rm -rf "$TEX_COMPILE_DIR/app/__pycache__"

        # requirements.txt: se cambiato, pip install in venv
        if echo "$TEX_CHANGES" | grep -q '^'"$TEX_COMPILE_SRC_REL"'/requirements\.txt$'; then
            log "tex-compile requirements changed → pip install"
            cp "$REPO_DIR/$TEX_COMPILE_SRC_REL/requirements.txt" "$TEX_COMPILE_DIR/requirements.txt"
            chown "$TEX_COMPILE_USER:$TEX_COMPILE_USER" "$TEX_COMPILE_DIR/requirements.txt"
            sudo -u "$TEX_COMPILE_USER" "$TEX_COMPILE_DIR/venv/bin/pip" install -q -r "$TEX_COMPILE_DIR/requirements.txt" 2>&1 | sed 's/^/[pip] /'
        fi

        log "restart $TEX_COMPILE_SERVICE"
        systemctl restart "$TEX_COMPILE_SERVICE"
        # smoke test health (non-fatale: deploy procede ma logga warning)
        sleep 2
        if curl -sf -m 5 http://127.0.0.1:8001/health >/dev/null 2>&1; then
            log "tex-compile health OK"
        else
            log "WARN: tex-compile health check failed dopo restart"
        fi
    else
        log "WARN: $TEX_COMPILE_DIR/app non esiste, skip tex-compile sync"
    fi
fi

# ── Step 5b: sync storage/templates/{risdoc,verifiche} → /var/lib/pantedu-data
# 2026-05-28 — il microservizio tex-compile (uvicorn :8001) E il PHP API
# (TemplateFileAdapter per verifiche) leggono i file texCommon da
# /var/lib/pantedu-data/storage/templates/{risdoc,verifiche}/. Il deploy
# aggiornava solo /var/www → modifiche restavano invisibili (bug intestazione
# risdoc + bug "editor verifiche file vuoto" risolti 2026-05-28).
# Idempotente: rsync sincronizza solo i file effettivamente cambiati.
for tdir in risdoc verifiche; do
    SRC="$REPO_DIR/storage/templates/$tdir/"
    DST="/var/lib/pantedu-data/storage/templates/$tdir/"
    [[ -d "$SRC" ]] || continue
    TDIR_CHANGES=$(git_as_owner diff --name-only "$OLD_HEAD" "$NEW_HEAD" | grep "^storage/templates/$tdir/" || true)
    if [[ -n "$TDIR_CHANGES" ]] || [[ ! -d "$DST" ]]; then
        log "storage/templates/$tdir/ sync /var/www → /var/lib"
        [[ -d "$DST" ]] || mkdir -p "$DST"
        rsync -a --delete-after \
            --exclude='*.bak' --exclude='*.tmp' --exclude='__pycache__' \
            "$SRC" "$DST" 2>&1 | sed "s/^/[tdir-sync-$tdir] /"
        # I file devono essere leggibili dall'utente texcompile (servizio uvicorn).
        chown -R "$TEX_COMPILE_USER:$TEX_COMPILE_USER" "$DST" 2>/dev/null || \
            chgrp -R www-data "$DST" 2>/dev/null || true
        log "  sync $tdir OK"
    fi
done

# ── Step 6: nginx tex-compile config sync ──────────────────────────────────
# Se tools/tex-compile-vps/nginx/tex-compile.conf è cambiato → install,
# nginx -t (validation), reload nginx. nginx -t fallisce → ROLLBACK al backup
# precedente per evitare di lasciare nginx con config rotto.
NGINX_CHANGES=$(git_as_owner diff --name-only "$OLD_HEAD" "$NEW_HEAD" | grep "^${TEX_NGINX_SRC_REL}/" || true)
if [[ -n "$NGINX_CHANGES" ]]; then
    log "tex-compile nginx config changed → install + validate + reload"
    NGINX_SRC="$REPO_DIR/$TEX_NGINX_SRC_REL/tex-compile.conf"
    if [[ -f "$NGINX_SRC" ]]; then
        BACKUP_FILE="${TEX_NGINX_DST}.bak.$(date +%Y%m%d_%H%M%S)"
        # Backup pre-install (idempotente: se invariato, install = no-op grazie -p)
        if [[ -f "$TEX_NGINX_DST" ]]; then
            cp -p "$TEX_NGINX_DST" "$BACKUP_FILE"
            log "  backup: $BACKUP_FILE"
        fi
        # Preserve server_name / certbot SSL paths del file in produzione:
        # il file in repo ha "tex.tuosito.it" placeholder. Sostituiamo
        # leggendo il valore attuale dalla copia su disco.
        CURRENT_SERVERNAME=$(grep -E '^\s*server_name\s+' "$TEX_NGINX_DST" 2>/dev/null | head -1 | awk '{print $2}' | tr -d ';' || echo "")
        if [[ -n "$CURRENT_SERVERNAME" && "$CURRENT_SERVERNAME" != "tex.tuosito.it" ]]; then
            log "  preserve server_name: $CURRENT_SERVERNAME"
            sed "s|tex\.tuosito\.it|$CURRENT_SERVERNAME|g" "$NGINX_SRC" > "${TEX_NGINX_DST}.new"
        else
            cp "$NGINX_SRC" "${TEX_NGINX_DST}.new"
        fi
        chmod 644 "${TEX_NGINX_DST}.new"
        chown root:root "${TEX_NGINX_DST}.new"
        mv "${TEX_NGINX_DST}.new" "$TEX_NGINX_DST"
        # Validate config; rollback su errore.
        if nginx -t 2>&1 | sed 's/^/[nginx-t] /'; then
            log "  nginx config OK → reload"
            systemctl reload nginx 2>&1 | sed 's/^/[nginx] /' || true
        else
            log "  ERROR: nginx -t FAILED → rollback al backup"
            if [[ -f "$BACKUP_FILE" ]]; then
                cp -p "$BACKUP_FILE" "$TEX_NGINX_DST"
                nginx -t 2>&1 | sed 's/^/[nginx-t-rollback] /' || true
                log "  rollback completed (config attuale = backup)"
            fi
        fi
    else
        log "WARN: $NGINX_SRC non esiste, skip nginx sync"
    fi
fi

# ── Step 7: pantedu systemd units sync ───────────────────────────────────
# 2026-05-24 — refactor da "diff-gated" a "always-idempotent". Prima girava
# solo se SYSTEMD_CHANGES != "", quindi un file aggiunto a tools/systemd/ in
# un commit lontano (deploy gia' fatto pre-add) restava per sempre non
# installato. Caso reale: pantedu-tikz-prewarm.{timer,service} in repo dal
# commit iniziale ma MAI installati su VPS (cron notturno mancante per mesi).
#
# Nuovo approccio:
#   1. Calcola hash file repo vs file installato — se identici, no-op puro
#   2. Se hash divergono O file installato manca → install + flag reload
#   3. enable --now idempotente sempre (timer attivo = no-op systemctl)
#   4. daemon-reload SOLO se almeno un file e' stato installato/aggiornato

SYSTEMD_RELOAD_NEEDED=0
SYSTEMD_INSTALLED_COUNT=0
SYSTEMD_NEW_COUNT=0
for unit in "$REPO_DIR/$APP_SYSTEMD_SRC_REL"/*.service "$REPO_DIR/$APP_SYSTEMD_SRC_REL"/*.timer; do
    [[ -f "$unit" ]] || continue
    unit_name=$(basename "$unit")
    dst="$APP_SYSTEMD_DST_DIR/$unit_name"
    if [[ ! -f "$dst" ]]; then
        install -m 644 -o root -g root "$unit" "$dst"
        log "  installed (new) $unit_name"
        SYSTEMD_RELOAD_NEEDED=1
        SYSTEMD_INSTALLED_COUNT=$((SYSTEMD_INSTALLED_COUNT + 1))
        SYSTEMD_NEW_COUNT=$((SYSTEMD_NEW_COUNT + 1))
    elif ! cmp -s "$unit" "$dst"; then
        install -m 644 -o root -g root "$unit" "$dst"
        log "  updated $unit_name"
        SYSTEMD_RELOAD_NEEDED=1
        SYSTEMD_INSTALLED_COUNT=$((SYSTEMD_INSTALLED_COUNT + 1))
    fi
done

if [[ $SYSTEMD_RELOAD_NEEDED -eq 1 ]]; then
    log "systemd units changed (${SYSTEMD_INSTALLED_COUNT} installed, ${SYSTEMD_NEW_COUNT} new) → daemon-reload"
    systemctl daemon-reload
fi

# Enable + start dei .timer — idempotente (no-op se gia' attivo).
# Gira SEMPRE: cosi' un timer aggiunto al repo ma non ancora enabled lo
# diventa anche se questo deploy non ha toccato il file.
for timer in "$REPO_DIR/$APP_SYSTEMD_SRC_REL"/*.timer; do
    [[ -f "$timer" ]] || continue
    timer_name=$(basename "$timer")
    # Verifica enabled state per evitare log noise sui timer gia' attivi.
    state=$(systemctl is-enabled "$timer_name" 2>/dev/null || echo "missing")
    if [[ "$state" != "enabled" && "$state" != "static" && "$state" != "alias" ]]; then
        log "  enable + start $timer_name (was: $state)"
        systemctl enable --now "$timer_name" 2>&1 | sed "s/^/[systemd-$timer_name] /" || true
    fi
done

# ── Step 7b: auto-deploy infrastructure sync (Phase 25.R.21) ───────────────
# Se cambiati i file dell'auto-deploy (Path unit, trigger script, PHP-FPM
# drop-in, install script), ri-esegui install_auto_deploy.sh per riallineare
# /etc/systemd/system/ e /usr/local/bin/pantedu-deploy-trigger.sh.
AUTO_DEPLOY_CHANGES=$(git_as_owner diff --name-only "$OLD_HEAD" "$NEW_HEAD" | grep -E "^(tools/systemd/(pantedu-deploy\.(path|service)|pantedu-deploy-trigger\.sh|php8\.4-fpm\.service\.d/pantedu-auto-deploy\.conf)|tools/webhook/install_auto_deploy\.sh)$" || true)
if [[ -n "$AUTO_DEPLOY_CHANGES" ]]; then
    log "auto-deploy infra changed → re-running install_auto_deploy.sh"
    bash "$REPO_DIR/tools/webhook/install_auto_deploy.sh" 2>&1 | sed 's/^/[auto-deploy] /' || log "[WARN] auto-deploy install failed (non-fatal)"
fi

# ── Step 7c: hetzner_snapshot.sh sync ──────────────────────────────────────
# Se cambiato nel repo, ri-installa /usr/local/sbin/hetzner_snapshot.sh.
# Lo script è usato dal pre-deploy snapshot Hetzner Cloud (vedi sopra).
HETZNER_SNAP_SRC="$REPO_DIR/tools/webhook/hetzner_snapshot.sh"
HETZNER_SNAP_DST="/usr/local/sbin/hetzner_snapshot.sh"
if git_as_owner diff --name-only "$OLD_HEAD" "$NEW_HEAD" | grep -q "^tools/webhook/hetzner_snapshot\.sh$"; then
    if [[ -f "$HETZNER_SNAP_SRC" ]]; then
        log "hetzner_snapshot.sh changed → install to $HETZNER_SNAP_DST"
        install -m 700 -o root -g root "$HETZNER_SNAP_SRC" "$HETZNER_SNAP_DST"
    fi
fi

# ── Step 8: self-update ────────────────────────────────────────────────────
# Se questo stesso file è cambiato, ricopialo in /usr/local/bin/. La nuova
# versione girerà al PROSSIMO deploy (non in questo, per evitare race).
if git_as_owner diff --name-only "$OLD_HEAD" "$NEW_HEAD" | grep -q "^${SELF_SRC_REL}$"; then
    if [[ -f "$REPO_DIR/$SELF_SRC_REL" ]]; then
        log "self-update: $SELF_SRC_REL → $SELF_PATH (apply su prossimo run)"
        install -m 755 -o root -g root "$REPO_DIR/$SELF_SRC_REL" "$SELF_PATH"
    fi
fi

log "=== deploy end (deployed $NEW_HEAD) ==="
