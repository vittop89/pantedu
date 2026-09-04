#!/usr/bin/env bash
# Phase 25.K.9 — MariaDB audit plugin + secure baseline.
#
# Setup:
# 1. Enable server_audit plugin (MariaDB native) — log all queries da
#    user app + filter sensitive
# 2. Hardening config: bind-address localhost, secure_file_priv,
#    skip-symbolic-links, sql_mode strict
# 3. NO tablespace encryption (richiede master key + restart + impatto
#    perf). Lo skippiamo per ora — app gia' cifra row-level via
#    TeacherCryptoService AEAD.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

# ──────────────────────────────────────────────────────────────
# 1. Audit plugin server_audit (MariaDB native, no extra install)
# ──────────────────────────────────────────────────────────────
C "=== [1/3] Enable MariaDB server_audit plugin ==="

# Conf drop-in per audit
cat > /etc/mysql/mariadb.conf.d/99-pantedu-audit.cnf <<'EOF'
# Phase 25.K.9 — MariaDB server_audit + hardening (Debian Trixie compatible).
# v2 minimal — rimosso symbolic-links/secure_file_priv (gestiti da default Debian).

[mariadb]
# Plugin server_audit (file .so in /usr/lib/mysql/plugin/, già presente)
plugin_load_add = server_audit
server_audit_logging = ON
server_audit_events = CONNECT,QUERY_DDL,QUERY_DCL,TABLE
server_audit_file_path = /var/log/mysql/server_audit.log
server_audit_file_rotate_size = 100M
server_audit_file_rotations = 9
# Escludi user root locale per ridurre noise (debian-start, mysql_upgrade)
server_audit_excl_users = root

# Bind localhost only (no remote MySQL)
bind-address = 127.0.0.1

# SQL mode strict (no silent truncation)
sql_mode = STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION

# Connection limits
max_connections = 100
max_connect_errors = 100

# No LOAD DATA LOCAL (no client local file read)
local_infile = OFF
EOF

# Crea audit log file con permessi corretti (mysql:mysql 640)
mkdir -p /var/log/mysql
touch /var/log/mysql/server_audit.log
chown mysql:mysql /var/log/mysql/server_audit.log
chmod 640 /var/log/mysql/server_audit.log

# Pre-flight: verifica plugin disponibile
if mysql -e "SHOW PLUGINS;" 2>/dev/null | grep -qi "server_audit"; then
    G "  ✓ server_audit plugin già caricato"
else
    C "  Restart mariadb per caricare plugin…"
    systemctl restart mariadb 2>/dev/null || systemctl restart mysql
    sleep 2
fi

if systemctl is-active --quiet mariadb 2>/dev/null || systemctl is-active --quiet mysql; then
    G "  ✓ MariaDB attivo"
else
    R "  ✗ MariaDB non parte → rollback config audit"
    rm -f /etc/mysql/mariadb.conf.d/99-pantedu-audit.cnf
    systemctl restart mariadb 2>/dev/null || systemctl restart mysql
    exit 2
fi

# Verifica audit log creato
if [[ -f /var/log/mysql/server_audit.log ]]; then
    G "  ✓ /var/log/mysql/server_audit.log creato"
    chown mysql:adm /var/log/mysql/server_audit.log 2>/dev/null || true
    chmod 640 /var/log/mysql/server_audit.log
fi

# ──────────────────────────────────────────────────────────────
# 2. logrotate audit
# ──────────────────────────────────────────────────────────────
C "=== [2/3] logrotate per audit ==="
cat > /etc/logrotate.d/mariadb-server_audit <<'EOF'
/var/log/mysql/server_audit.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0640 mysql adm
    postrotate
        mysql -e "SET GLOBAL server_audit_file_rotate_now = ON" 2>/dev/null || true
    endscript
}
EOF
G "  ✓ logrotate (30gg retention)"

# ──────────────────────────────────────────────────────────────
# 3. Verifica + cleanup user grants (no remote root)
# ──────────────────────────────────────────────────────────────
C "=== [3/3] Verifica user grants (no remote root) ==="

REMOTE_ROOT=$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1','%h')" 2>/dev/null || echo 0)
if [[ "$REMOTE_ROOT" -gt 0 ]]; then
    W "  ⚠️ root user con host remoto trovato (${REMOTE_ROOT}). Verifica manuale!"
    mysql -e "SELECT User, Host FROM mysql.user WHERE User='root'"
else
    G "  ✓ root solo localhost"
fi

# Anonymous users (best practice mysql_secure_installation)
ANON=$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE User=''" 2>/dev/null || echo 0)
if [[ "$ANON" -gt 0 ]]; then
    W "  ⚠️ Anonymous user trovati ($ANON). Rimuovi: mysql_secure_installation"
else
    G "  ✓ no anonymous user"
fi

# ──────────────────────────────────────────────────────────────
echo
G "════════════════════════════════════════"
G "Phase 25.K.9 — MariaDB audit + hardening"
G "════════════════════════════════════════"
echo "Audit log: /var/log/mysql/server_audit.log"
echo "Tail real-time:"
echo "  sudo tail -f /var/log/mysql/server_audit.log"
echo
echo "Query stats audit (es. top users):"
echo "  awk -F, '{print \$3}' /var/log/mysql/server_audit.log | sort | uniq -c | sort -rn | head"
echo
echo "Verify hardening:"
echo "  mysql -e \"SHOW VARIABLES LIKE 'bind_address'\""
echo "  mysql -e \"SHOW VARIABLES LIKE 'secure_file_priv'\""
echo "  mysql -e \"SHOW VARIABLES LIKE 'sql_mode'\""
echo
echo "NOTE: Tablespace encryption at-rest NON abilitato (richiede setup"
echo "key + downtime). App pantedu già cifra contenuti sensibili"
echo "row-level via TeacherCryptoService (AEAD AES-256-GCM)."
echo "Tier 4 future: encryption a tablespace level se compliance richiede."
