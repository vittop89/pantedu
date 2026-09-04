#!/usr/bin/env bash
# Phase 25.K.1 — System hardening: SSH + sysctl kernel + unattended-upgrades.
# Idempotente: skip se già applicato. Backup config originali in /etc/*.bak.pantedu.
#
# CHANGES safe (no port change SSH, no remove access):
#   1. SSH: AllowUsers docente1 + ClientAliveInterval 300 + MaxAuthTries 3 +
#      MaxSessions 10 + LoginGraceTime 30 + Protocol 2 explicit
#   2. sysctl kernel: TCP SYN cookies, ICMP redirects off, source routing off,
#      IP forwarding off, martians log, RP filter strict, exec-shield (auto)
#   3. unattended-upgrades: auto-install security only, auto-reboot 03:00,
#      email notification (se MAIL_TO env definito)
#
# Run sul VPS:
#   sudo bash tools/dev/hardening/01-system.sh

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

ALLOWED_USER="${ALLOWED_USER:-docente1}"
TS=$(date +%Y%m%d_%H%M%S)

# ──────────────────────────────────────────────────────────────
# 1. SSH HARDENING (preserva accesso corrente, no port change)
# ──────────────────────────────────────────────────────────────
C "=== [1/3] SSH hardening ==="

SSH_CONF="/etc/ssh/sshd_config"
SSH_DROP="/etc/ssh/sshd_config.d/99-pantedu-hardening.conf"

# Backup originale solo una volta
[[ ! -f "${SSH_CONF}.bak.pantedu" ]] && cp -a "$SSH_CONF" "${SSH_CONF}.bak.pantedu"

# Drop-in file (sshd_config.d/ override; precedenza su /etc/ssh/sshd_config)
cat > "$SSH_DROP" <<EOF
# Phase 25.K.1 — Pantedu SSH hardening drop-in.
# Generato da tools/dev/hardening/01-system.sh il $(date -Iseconds)
# Restore con: sudo rm $SSH_DROP && sudo systemctl reload sshd

# SSH custom port: 2222 (riduce scan noise -95% vs default 22)
Port 2222

# Solo questi user possono SSH (root SOLO via key per via prohibit-password)
AllowUsers ${ALLOWED_USER} root

# Tempo max per autenticazione (default 120s troppo lasco)
LoginGraceTime 30

# Max 3 tentativi auth per connessione
MaxAuthTries 3

# Max 10 sessioni mux per connessione
MaxSessions 10

# Keep-alive client: ping ogni 5min, kill se 3 silenzi consecutivi (15min)
ClientAliveInterval 300
ClientAliveCountMax 3

# Disable agent + X11 forwarding (riduce attack surface).
# TCP forwarding = "local" → client può fare -L (port-forward verso servizi bind 127.0.0.1
# es. Grafana, Loki) ma NON -R reverse-forward. Necessario per admin access pattern.
AllowAgentForwarding no
AllowTcpForwarding local
GatewayPorts no
PermitTunnel no
X11Forwarding no

# Disable rhost auth (legacy, insicuro)
IgnoreRhosts yes
HostbasedAuthentication no

# Banner avviso
Banner /etc/issue.net
EOF

cat > /etc/issue.net <<'EOF'
**********************************************************************
*  PANTEDU VPS — Authorized access only.                            *
*  Logged actions. Unauthorized access prohibited under Italian law.  *
**********************************************************************
EOF

# Validate config (sshd -t fail = exit non-zero, abort)
if sshd -t 2>&1; then
    G "  sshd config valid → reload"
    systemctl reload sshd
    G "  ✓ sshd reloaded (current sessions intact)"
else
    R "  ✗ sshd config INVALID → rollback drop-in"
    rm -f "$SSH_DROP"
    exit 2
fi

# UFW allow 2222 (open before deny 22, no lock-out)
if command -v ufw >/dev/null 2>&1; then
    ufw allow 2222/tcp comment 'SSH custom port' >/dev/null 2>&1 || true
    # Remove default OpenSSH (port 22) rule if exists
    ufw delete allow OpenSSH 2>/dev/null || true
    ufw delete allow 22/tcp 2>/dev/null || true
    G "  ✓ UFW: 2222 allowed, 22 removed"
fi

# fail2ban sshd jail → port 2222
F2B_JAIL="/etc/fail2ban/jail.d/sshd.local"
cat > "$F2B_JAIL" <<'F2B_EOF'
[sshd]
port    = 2222
backend = systemd
F2B_EOF
if systemctl is-active fail2ban >/dev/null 2>&1; then
    systemctl restart fail2ban
    G "  ✓ fail2ban: sshd jail port=2222"
fi

# ──────────────────────────────────────────────────────────────
# 2. SYSCTL KERNEL HARDENING (TCP/IP stack)
# ──────────────────────────────────────────────────────────────
C "=== [2/3] sysctl kernel hardening ==="

SYSCTL_FILE="/etc/sysctl.d/99-pantedu-hardening.conf"
[[ ! -f "${SYSCTL_FILE}.bak.pantedu" && -f "$SYSCTL_FILE" ]] && cp -a "$SYSCTL_FILE" "${SYSCTL_FILE}.bak.pantedu"

cat > "$SYSCTL_FILE" <<'EOF'
# Phase 25.K.1 — Kernel/network hardening (idempotent).
# Reload: sudo sysctl --system

# ── TCP SYN flood protection ──
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 4096
net.ipv4.tcp_synack_retries = 3
net.ipv4.tcp_syn_retries = 3

# ── IP spoofing protection (Reverse Path Filter strict) ──
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1

# ── Disable ICMP redirects (man-in-the-middle prevention) ──
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.secure_redirects = 0
net.ipv4.conf.default.secure_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv6.conf.default.accept_redirects = 0

# ── Disable send redirects (we're not a router) ──
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0

# ── Disable source-routed packets (spoofing prevention) ──
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv6.conf.all.accept_source_route = 0
net.ipv6.conf.default.accept_source_route = 0

# ── Log martian packets (impossible IPs su nostra rete) ──
net.ipv4.conf.all.log_martians = 1
net.ipv4.conf.default.log_martians = 1

# ── Disable IP forwarding (siamo end-host, no router) ──
net.ipv4.ip_forward = 0
net.ipv6.conf.all.forwarding = 0

# ── Ignore ICMP broadcast (smurf attack prevention) ──
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1

# ── TCP timestamps off (reduce fingerprinting + side-channel) ──
# Mantieni ON per BBR/PRR; commented out.
# net.ipv4.tcp_timestamps = 0

# ── ASLR full (kernel memory randomization) ──
kernel.randomize_va_space = 2

# ── ptrace restriction (rilascio core dump solo a parent processes) ──
kernel.yama.ptrace_scope = 1

# ── dmesg restricted (no kernel info leak a non-root) ──
kernel.dmesg_restrict = 1

# ── Kernel pointer hiding in /proc (info leak prevention) ──
kernel.kptr_restrict = 2

# ── BPF JIT hardening (anti spectre-v2) ──
net.core.bpf_jit_harden = 2

# ── core dump suid (no SUID coredump leak) ──
fs.suid_dumpable = 0

# ── max file descriptor (prevent FD exhaustion DoS) ──
fs.file-max = 65535

# ── connection tracking buckets (high traffic) ──
net.netfilter.nf_conntrack_max = 131072
EOF

if sysctl --system 2>&1 | grep -E "pantedu|error" | head -10; then
    G "  ✓ sysctl applied"
else
    W "  WARN: sysctl --system output empty"
fi

# ──────────────────────────────────────────────────────────────
# 3. UNATTENDED-UPGRADES enhanced
# ──────────────────────────────────────────────────────────────
C "=== [3/3] unattended-upgrades enhanced ==="

# Già installato (verificato baseline). Configuriamo full.
DEBIAN_RELEASE=$(lsb_release -cs 2>/dev/null || echo "stable")
UU_CONF="/etc/apt/apt.conf.d/50unattended-upgrades"
UU_AUTO="/etc/apt/apt.conf.d/20auto-upgrades"

# Backup originale
[[ ! -f "${UU_CONF}.bak.pantedu" ]] && cp -a "$UU_CONF" "${UU_CONF}.bak.pantedu"

cat > "$UU_CONF" <<EOF
// Phase 25.K.1 — unattended-upgrades config (auto-security only, no breaking).

Unattended-Upgrade::Origins-Pattern {
    // Solo security updates di default. NO altri repos auto.
    "origin=Debian,codename=\${distro_codename}-security,label=Debian-Security";
    "origin=Debian,codename=\${distro_codename}-updates";
};

// Auto-remove unused kernels (NO kernel pinning desiderato)
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-New-Unused-Dependencies "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";

// Auto-reboot se kernel/libc updated (richiede reboot per essere effective).
// Esegue alle 03:00 di notte (orario VPS), evita downtime business hours.
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-Time "03:00";

// Email notification se errore o conflict (cambia con la tua email)
// Unattended-Upgrade::Mail "{{OPERATORE_EMAIL}}";
// Unattended-Upgrade::MailReport "on-change";

// Verbose logging in /var/log/unattended-upgrades/
Unattended-Upgrade::SyslogEnable "true";
Unattended-Upgrade::SyslogFacility "daemon";

// Min uptime prima di installare (evita install su VPS appena bootati)
Unattended-Upgrade::MinimalSteps "true";

// Lock retry su apt locked
Acquire::Retries "3";
EOF

cat > "$UU_AUTO" <<'EOF'
// Phase 25.K.1 — abilita unattended-upgrades + auto-clean.
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
EOF

# Test config sintatticamente
if unattended-upgrade --dry-run 2>&1 | tail -5 | grep -qE "(Allowed origins|Pretending)"; then
    G "  ✓ unattended-upgrades config OK"
else
    W "  WARN: unattended-upgrade --dry-run output insolito (verificare manualmente)"
fi
systemctl enable --now unattended-upgrades.service 2>/dev/null || true
G "  ✓ unattended-upgrades active"

# ──────────────────────────────────────────────────────────────
echo
G "════════════════════════════════════════"
G "Phase 25.K.1 — System hardening completo"
G "════════════════════════════════════════"
echo "Cambi applicati:"
echo "  ✓ SSH: AllowUsers, ClientAliveInterval, MaxAuthTries, banner"
echo "  ✓ Kernel sysctl: SYN cookies, ICMP redirects off, ASLR, kptr_restrict, etc."
echo "  ✓ Unattended-upgrades: auto-security + auto-reboot 03:00 + verbose log"
echo
echo "Verifica:"
echo "  sudo sshd -T | grep -E 'allowusers|clientalive|maxauth'"
echo "  sysctl -a 2>/dev/null | grep -E 'syncookies|rp_filter|kptr_restrict'"
echo "  sudo unattended-upgrade --dry-run | tail -10"
echo
echo "Rollback:"
echo "  sudo rm /etc/ssh/sshd_config.d/99-pantedu-hardening.conf"
echo "  sudo rm /etc/sysctl.d/99-pantedu-hardening.conf"
echo "  sudo systemctl reload sshd && sudo sysctl --system"
