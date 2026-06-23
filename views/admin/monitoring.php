<?php
/**
 * Phase 25.R follow-up — /admin/monitoring (super_admin).
 *
 * Iframe Grafana protetto da auth_request nginx → SSO via sessione pantedu.
 *
 * @var array $user
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '📊 Monitoring & metrics';
$page_subtitle = 'Grafana embedded — SSO via sessione pantedu super_admin (nessun login Grafana richiesto).';
$breadcrumb    = [['label' => 'Monitoring']];
include __DIR__ . '/_partials/page_head.php';
?>

<div class="fm-info-banner fm-mb-4" >
    🔒 <strong>Sicurezza</strong>: Grafana gira su <code>127.0.0.1:3000</code> (non esposto a internet).
    Accesso solo via questo iframe: nginx fa <code>auth_request</code> verso pantedu prima di proxy.
    Logout pantedu → iframe perde accesso istantaneamente.
    <br>
    <small class="fm-muted">
        Auth proxy: nginx → PHP <code>/auth/grafana-gate</code> (verifica super_admin) → Grafana con header
        <code>X-WEBAUTH-USER</code>. Vedi <a href="https://github.com/vittop89/pantedu/blob/main/app/Controllers/GrafanaGateController.php" target="_blank" rel="noopener">GrafanaGateController.php</a>.
    </small>
</div>

<div class="fm-card fm-monitoring-frame">
    <iframe src="/grafana/?kiosk=tv"
            title="Grafana embedded"
            sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-downloads"
            referrerpolicy="same-origin"
            loading="lazy"></iframe>
</div>

<div class="fm-info-banner fm-mt-4" >
    🔗 <strong>Apri Grafana in scheda separata</strong>:
    <a href="/grafana/" target="_blank" rel="noopener">/grafana/ →</a>
    (utile per dashboard a schermo intero o per workflow multi-tab).
</div>

<details class="fm-ops-cheatsheet fm-mt-4" >
    <summary class="fm-cursor-pointer fm-fw-700 fm-p-2 fm-summary-toggle">
        🛠️ Ops cheatsheet — comandi admin frequenti (copy-paste in terminale locale via <code>ssh pantedu-vps</code>)
    </summary>

    <div class="fm-py-3 fm-px-1 fm-text-13">
        <p class="fm-muted fm-mt-1 fm-mb-3" >
            Tutti gli snippet assumono <code>ssh pantedu-vps</code> funzionante. Se non hai SSH in PATH:
            <code>Add-WindowsCapability -Online -Name OpenSSH.Client~~~~0.0.1.0</code> (PS admin).
            Per evitare passphrase prompt ad ogni comando:
            <code>Get-Service ssh-agent | Set-Service -StartupType Automatic; Start-Service ssh-agent; ssh-add $env:USERPROFILE\.ssh\id_ed25519</code>.
        </p>

        <h4 class="fm-my-3 fm-mb-1">🔑 Grafana admin password</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "grafana cli --homepath /usr/share/grafana --config /etc/grafana/grafana.ini admin reset-admin-password 'NUOVA_PASSWORD'"</code></pre>

        <h4 class="fm-my-3 fm-mb-1">🚫 Fail2ban — unban IP</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "fail2ban-client unban 1.2.3.4"</code></pre>
        <p class="fm-muted fm-mt-1 fm-text-xs" >Unban tutti: <code>fail2ban-client unban --all</code>. Status jail: <code>fail2ban-client status pantedu-waf</code>.</p>

        <h4 class="fm-my-3 fm-mb-1">♻️ Restart servizi</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "systemctl restart php8.4-fpm"</code></pre>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "systemctl reload nginx"</code></pre>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "systemctl restart grafana-server loki promtail"</code></pre>

        <h4 class="fm-my-3 fm-mb-1">📋 Log recenti</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "tail -50 /var/log/nginx/error.log"</code></pre>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "tail -50 /var/lib/pantedu-data/storage/logs/php_errors.log"</code></pre>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "tail -50 /var/log/pantedu-deploy.log"</code></pre>

        <h4 class="fm-my-3 fm-mb-1">🗑️ Cancellare un file/dir</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "rm -i /percorso/file"</code></pre>
        <p class="fm-muted fm-mt-1 fm-text-xs" >
            <code>-i</code> chiede conferma. Per dir ricorsiva: <code>rm -rIv /percorso/dir</code>.
            ⚠️ NON usare <code>rm -rf</code> su path importanti senza essere certi.
            <code>.env.local</code> è immutable: prima <code>chattr -i</code>, poi rm, poi <code>chattr +i</code>.
        </p>

        <h4 class="fm-my-3 fm-mb-1">💾 Spazio disco</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "df -h / /var/lib /var/log; echo; du -sh /var/lib/pantedu-data /var/www/pantedu /var/log/* 2>/dev/null | sort -h | tail -15"</code></pre>

        <h4 class="fm-my-3 fm-mb-1">🔍 Cerca errore in tutti i log (ultimo 24h)</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "journalctl --since '24 hours ago' -p err --no-pager | tail -100"</code></pre>

        <h4 class="fm-my-3 fm-mb-1">🔐 Verifica certificati TLS</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "certbot certificates"</code></pre>

        <h4 class="fm-my-3 fm-mb-1">🚀 Deploy manuale (skip webhook)</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "sudo -u pantedu git -C /var/www/pantedu pull --ff-only origin main && /usr/local/bin/pantedu-deploy.sh"</code></pre>

        <h4 class="fm-my-3 fm-mb-1">📦 Backup DB on-demand</h4>
        <pre class="fm-codeblock-dark"><code data-fm-copy>ssh pantedu-vps "mysqldump --single-transaction --quick --routines --triggers --events pantedu | gzip > /var/backups/pantedu/manual-$(date +%Y%m%d_%H%M%S).sql.gz && ls -lh /var/backups/pantedu/ | tail -3"</code></pre>
    </div>
</details>

<script>
// Click su <code data-fm-copy> -> copia testo + flash conferma.
document.querySelectorAll('.fm-ops-cheatsheet code[data-fm-copy]').forEach(el => {
    el.style.cursor = 'pointer';
    el.title = 'Click per copiare';
    el.addEventListener('click', async () => {
        const txt = el.textContent || '';
        try {
            await navigator.clipboard.writeText(txt);
            const prev = el.style.background;
            el.style.background = '#1f5e3a';
            setTimeout(() => { el.style.background = prev; }, 350);
        } catch (e) {
            // Fallback per browser senza Clipboard API
            const r = document.createRange();
            r.selectNode(el);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(r);
            document.execCommand('copy');
        }
    });
});
</script>

</div><?php /* /.fm-card aperto da page_head */ ?>
