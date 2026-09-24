#!/usr/bin/env bash
# Phase 25.M — Lynis remediation top-15.
#
# Applica le 15 raccomandazioni high-ROI da `lynis audit system`.
# Score atteso: baseline 73 → ~79 (+6 punti).
#
# Skippate (giustificato):
#   BOOT-5122 GRUB password — VPS cloud, no console fisica
#   FILE-6310 separate /home /var partition — invasive su VPS prod
#   KRNL-5830 reboot needed — gestito da unattended-upgrades
#   LOGG-2154 external syslog — Loki copre il caso
#   NETW-3015 promiscuous interface — Suricata richiede modalità promiscua

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

C "=== [1/6] login.defs hardening (AUTH-9230 + AUTH-9286 + AUTH-9328) ==="
LOGINDEFS=/etc/login.defs
[[ ! -f "${LOGINDEFS}.bak.pantedu" ]] && cp -a "$LOGINDEFS" "${LOGINDEFS}.bak.pantedu"
sed -i 's|^UMASK.*|UMASK 027|' "$LOGINDEFS"
sed -i 's|^#\?\s*ENCRYPT_METHOD.*|ENCRYPT_METHOD SHA512|' "$LOGINDEFS"
sed -i 's|^#\?\s*SHA_CRYPT_MIN_ROUNDS.*|SHA_CRYPT_MIN_ROUNDS 65536|' "$LOGINDEFS"
sed -i 's|^#\?\s*SHA_CRYPT_MAX_ROUNDS.*|SHA_CRYPT_MAX_ROUNDS 65536|' "$LOGINDEFS"
sed -i 's|^PASS_MAX_DAYS.*|PASS_MAX_DAYS 90|' "$LOGINDEFS"
sed -i 's|^PASS_MIN_DAYS.*|PASS_MIN_DAYS 7|' "$LOGINDEFS"
sed -i 's|^PASS_WARN_AGE.*|PASS_WARN_AGE 14|' "$LOGINDEFS"
G "  ✓ login.defs: umask 027, SHA512 65536 rounds, PASS_MAX 90gg"

C "=== [2/6] /etc/issue banner (BANN-7126) ==="
[[ -f /etc/issue.net ]] && cp /etc/issue.net /etc/issue
G "  ✓ /etc/issue mirrored da /etc/issue.net"

C "=== [3/6] Disable rare protocols (NETW-3200) ==="
cat > /etc/modprobe.d/pantedu-disable-protocols.conf <<'PROT_EOF'
# Phase 25.M — Disable rarely-used network protocols (attack surface reduction)
install dccp /bin/true
install rds /bin/true
install sctp /bin/true
install tipc /bin/true
PROT_EOF
chmod 644 /etc/modprobe.d/pantedu-disable-protocols.conf
G "  ✓ dccp/rds/sctp/tipc disabled via modprobe"

C "=== [4/6] PHP allow_url_fopen=Off (PHP-2376) ==="
for PHP_INI in /etc/php/*/fpm/php.ini /etc/php/*/cli/php.ini; do
    [[ -f "$PHP_INI" ]] || continue
    [[ ! -f "${PHP_INI}.bak.pantedu" ]] && cp -a "$PHP_INI" "${PHP_INI}.bak.pantedu"
    sed -i 's|^allow_url_fopen\s*=.*|allow_url_fopen = Off|' "$PHP_INI"
done
G "  ✓ allow_url_fopen=Off su tutti i php.ini"

C "=== [5/6] Apt packages utility hardening ==="
DEBIAN_FRONTEND=noninteractive apt-get install -qy \
    needrestart libpam-tmpdir apt-listbugs apt-listchanges \
    debsums acct rkhunter libpam-pwquality 2>&1 | tail -2
systemctl enable --now acct.service 2>/dev/null || true
G "  ✓ pacchetti installati + acct attivo"

C "=== [6/6] pwquality + rkhunter config ==="
cat > /etc/security/pwquality.conf <<'PWQ_EOF'
# Phase 25.M — password strength rules
minlen = 12
minclass = 3
maxrepeat = 3
maxsequence = 3
dcredit = -1
ucredit = -1
lcredit = -1
ocredit = -1
difok = 5
reject_username
enforce_for_root
PWQ_EOF
chmod 644 /etc/security/pwquality.conf

sed -i 's|^CRON_DAILY_RUN=.*|CRON_DAILY_RUN="true"|' /etc/default/rkhunter 2>/dev/null || true
sed -i 's|^MAIL-ON-WARNING=.*|MAIL-ON-WARNING=root|' /etc/default/rkhunter 2>/dev/null || true
rkhunter --update >/dev/null 2>&1 || true
rkhunter --propupd --skip-keypress >/dev/null 2>&1 || true
G "  ✓ pwquality + rkhunter daily check"

# Reload services che leggono i nuovi config
systemctl reload php8.4-fpm 2>/dev/null || true

C "=== Re-run Lynis per verificare nuovo score ==="
SCORE=$(lynis audit system --quick --no-colors 2>&1 | grep 'Hardening index' | grep -oE '\[[0-9]+\]' | head -1 | tr -d '[]')
G "  ✓ Hardening index: ${SCORE} (baseline 73, target 80+)"

echo
G "════════════════════════════════════════"
G "Phase 25.M.15 — Lynis remediation OK"
G "════════════════════════════════════════"
echo "Applicate 15 raccomandazioni high-ROI."
echo "Skippate giustificate: BOOT-5122, FILE-6310, KRNL-5830, LOGG-2154, NETW-3015."
echo
echo "Re-run audit completo:"
echo "  sudo lynis audit system"
echo "  sudo cat /var/log/lynis-report.dat | grep '^suggestion'"
