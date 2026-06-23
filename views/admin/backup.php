<?php
/**
 * Phase 25.R follow-up — Pannello centralizzato /admin/backup (super_admin).
 *
 * Layout: 3 fm-tile overview in cima (status sintetico), sezioni dettaglio
 * espandibili sotto (details/summary).
 *
 * @var array|null $lastColdBackup
 * @var array|null $lastB2Verified
 * @var array<string,int> $historyCounts
 * @var bool $coldBackupStale
 * @var int|null $coldBackupDaysAgo
 * @var string $csrf
 * @var array|null $flash
 * @var array $user
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '💾 Backup & Disaster Recovery';
$page_subtitle = 'Stato 3 layer backup (Hetzner snapshot · B2 daily · cold HDD mensile).';
$breadcrumb    = [['label' => 'Backup']];
include __DIR__ . '/_partials/page_head.php';
?>

<?php if (!empty($flash)): ?>
    <div class="fm-alert fm-alert--<?= ($flash['type'] ?? '') === 'error' ? 'danger' : 'success' ?>">
        <?= $h($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     OVERVIEW: 3 tile affiancati con status sintetico
     ═══════════════════════════════════════════════════════ -->
<section class="fm-grid fm-grid--3 fm-mb-6" >

    <!-- Tile 1: Hetzner Snapshot -->
    <div class="fm-tile">
        <h3>📦 Hetzner Cloud snapshot</h3>
        <div class="fm-big">1 snapshot</div>
        <p class="fm-muted fm-text-em-md fm-mt-1 fm-mb-0" >
            Retention attuale · ~€1.40/mese
        </p>
        <p class="fm-text-em-md fm-mt-2 fm-mb-0">
            <strong>Trigger</strong>: auto pre-deploy<br>
            <strong>Recovery</strong>: 5-10 min via console Hetzner
        </p>
    </div>

    <!-- Tile 2: Backblaze B2 -->
    <div class="fm-tile">
        <h3>☁ Backblaze B2 (encrypted)</h3>
        <?php if ($lastB2Verified): ?>
            <div class="fm-text-em-xl"><?= $h($lastB2Verified['occurred_at']) ?></div>
            <p class="fm-muted fm-text-em-md fm-mt-1 fm-mb-0" >Ultima verifica integrità</p>
        <?php else: ?>
            <div class="fm-big">Daily cron</div>
            <p class="fm-muted fm-text-em-md fm-mt-1 fm-mb-0" >Verifica integrità mai eseguita</p>
        <?php endif; ?>
        <p class="fm-text-em-md fm-mt-2 fm-mb-0">
            <strong>Frequenza</strong>: daily ~€0.20/mese<br>
            <strong>History</strong>: <?= (int)($historyCounts['b2_backup_verified'] ?? 0) ?> verifiche
        </p>
    </div>

    <!-- Tile 3: Cold backup HDD -->
    <div class="fm-tile<?= $coldBackupStale ? ' fm-tile--alert' : '' ?>">
        <h3>💾 Cold backup HDD esterno <?= $coldBackupStale ? '⚠️' : '' ?></h3>
        <?php if ($lastColdBackup): ?>
            <div class="fm-big"><?= $coldBackupDaysAgo === 0 ? 'oggi' : $coldBackupDaysAgo . 'g fa' ?></div>
            <p class="fm-muted fm-text-em-md fm-mt-1 fm-mb-0" ><?= $h($lastColdBackup['occurred_at']) ?></p>
        <?php else: ?>
            <div class="fm-big">— mai</div>
            <p class="fm-muted fm-text-em-md fm-mt-1 fm-mb-0" >Esegui il primo cold backup</p>
        <?php endif; ?>
        <p class="fm-text-em-md fm-mt-2 fm-mb-0">
            <strong>Air-gapped</strong>: ✅ quando staccato<br>
            <strong>History</strong>: <?= (int)($historyCounts['cold_backup_completed'] ?? 0) ?> backup totali
        </p>
    </div>
</section>

<?php if ($coldBackupStale): ?>
    <div class="fm-alert fm-alert--warn">
        ⚠️ <strong>Cold backup non aggiornato</strong>
        <?php if ($lastColdBackup): ?>(ultimo <?= $coldBackupDaysAgo ?> giorni fa)<?php else: ?>(mai eseguito)<?php endif; ?>.
        Procedi con la sezione "Esegui cold backup" sotto.
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     DETTAGLI: 3 sezioni espandibili
     ═══════════════════════════════════════════════════════ -->

<details<?= $lastColdBackup ? '' : ' open' ?>>
    <summary class="fm-collapsible-summary">
        📦 Snapshot Hetzner Cloud — dettagli + CLI manuale
    </summary>
    <div class="fm-card fm-mt-2" >
        <p>Snapshot completi del VPS (disk image 80 GB). Creati automaticamente
        pre-deploy via <code>tools/webhook/deploy.sh</code> + <code>hetzner_snapshot.sh</code>.
        Rotation automatica (retention 1) → costo <strong>~€1.40/mese</strong>.</p>

        <p><strong>Manual snapshot via SSH:</strong></p>
        <pre class="fm-row-detail__pre">/usr/local/sbin/hetzner_snapshot.sh manual-$(date +%Y%m%d)</pre>
        <p class="fm-muted fm-text-em-md" >
            Rispetta la retention configurata. Per cambiarla: edita
            <code>/etc/pantedu/hetzner-api.env</code> → <code>SNAPSHOT_RETENTION_COUNT</code>.
            <strong>Costo per snapshot</strong>: €0.017446/GB × 80GB = €1.40/mese.
        </p>
        <p>
            <a href="https://console.hetzner.cloud" target="_blank" rel="noopener"
               class="fm-btn fm-btn--ghost fm-btn--sm">🌐 Console Hetzner → Snapshots</a>
        </p>
    </div>
</details>

<details>
    <summary class="fm-collapsible-summary">
        ☁ Backup Backblaze B2 — dettagli + form verifica
    </summary>
    <div class="fm-card fm-mt-2" >
        <p>Backup completi cifrati GPG → bucket <code>pantedu-backup-vps</code>
        region eu-central-003 (Amsterdam). Retention 90g rotating.
        Costo: <strong>~€0.20/mese</strong> (size variabile).</p>

        <p><strong>Cron daily</strong>: <code>encrypted_backup.sh</code> (03:00) +
        <code>ship_audit_logs.sh</code> (03:30).</p>

        <h4 class="fm-mt-5">✅ Registra verifica integrità B2</h4>
        <p class="fm-muted fm-text-em-lg" >Raccomandata ogni 6 mesi: scarica un backup B2,
        verifica SHA-256 contro manifest, decifrare a campione.</p>

        <form method="POST" action="/admin/backup/b2-verified" class="fm-form-grid">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <label class="fm-form-fullrow">
                <span class="fm-form-label-text">SHA-256 del backup verificato</span>
                <input type="text" name="sha256" maxlength="64"
                       placeholder="64 hex chars dell'ultimo backup B2 verificato"
                       class="fm-w-full">
            </label>
            <label class="fm-form-fullrow">
                <span class="fm-form-label-text">Note</span>
                <textarea name="notes" rows="2" class="fm-w-full"
                          placeholder="Es. download + restore parziale DB testato OK"></textarea>
            </label>
            <div class="fm-form-actions">
                <button type="submit" class="fm-btn fm-btn--primary">✓ Registra verifica</button>
            </div>
        </form>
    </div>
</details>

<details<?= $coldBackupStale ? ' open' : '' ?>>
    <summary class="fm-collapsible-summary">
        💾 Cold backup HDD esterno — procedura + form conferma
    </summary>
    <div class="fm-card fm-mt-2" >
        <p>Quinta copia <strong>air-gapped</strong> (HDD scollegato quando non in uso).
        Procedura manuale mensile via PowerShell script. Zero costo ricorrente.
        HDD esterno acquisto una tantum (~€40-80 per 1TB USB 3.0).</p>

        <h4 class="fm-mt-5">📋 Prerequisiti sul PC che esegue lo script</h4>
        <ul class="fm-mt-2 fm-mb-4 fm-pl-em-lg fm-lh-loose">
            <li><strong>Sistema operativo</strong>: Windows 10/11 con PowerShell 5.1+ (preinstallato)</li>
            <li><strong>Repo pantedu clonato</strong>: <code>git clone</code> su path locale (default <code>C:\Users\vitto\progetti_vscode\pantedu</code>)</li>
            <li><strong>SSH client</strong> + chiave privata <code>id_ed25519</code> configurata per accedere a <code>pantedu-vps</code> (vedi <code>~/.ssh/config</code>)</li>
            <li><strong>rclone CLI</strong> con remote <code>b2-pantedu</code> configurato (application key Backblaze B2 — backup di sicurezza nel Password Safe)</li>
            <li><strong>git CLI</strong> nel PATH (per leggere il commit HEAD corrente nel manifest)</li>
            <li><strong>HDD esterno USB</strong> con almeno 10 GB liberi</li>
        </ul>

        <p class="fm-info-banner fm-mt-4" >
            📄 <strong>Vedi il sorgente dello script su GitHub</strong>:
            <a href="https://github.com/vittop89/pantedu/blob/master_vps/tools/admin/cold_backup.ps1"
               target="_blank" rel="noopener">
                tools/admin/cold_backup.ps1 →
            </a>
            <br>
            <small class="fm-muted">Code review · audit DPO · recovery rapido senza <code>git clone</code> completo.</small>
        </p>

        <h4 class="fm-mt-6">🚀 Esegui cold backup</h4>
        <ol class="fm-mt-2 fm-mb-4 fm-pl-em-lg fm-lh-loose">
            <li>
                Connetti l'HDD esterno al laptop dev. Annota la <strong>lettera del drive</strong>
                (es. <code>E:</code>, <code>F:</code>) o il <strong>label fisico</strong>.
            </li>
            <li>
                Apri PowerShell sul laptop dev ed esegui:
                <pre class="fm-row-detail__pre fm-my-1 fm-select-all" >cd C:\Users\vitto\progetti_vscode\pantedu
powershell -ExecutionPolicy Bypass -File tools\admin\cold_backup.ps1</pre>
                <button type="button" class="fm-btn fm-btn--sm fm-btn--ghost">📋 Copia comando</button>
                <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){
                    navigator.clipboard.writeText('cd C:\\\\Users\\\\vitto\\\\progetti_vscode\\\\pantedu\npowershell -ExecutionPolicy Bypass -File tools\\\\admin\\\\cold_backup.ps1');
                    this.textContent = '✓ Copiato';
                    setTimeout(()=>this.textContent='📋 Copia comando', 2000);
                })</script>
            </li>
            <li>
                Lo script aprirà <strong>Esplora Risorse</strong> per scegliere la cartella
                di destinazione. Naviga al drive esterno e seleziona o crea
                <code>pantedu-backups</code>.
            </li>
            <li>
                Lo script scaricherà gli ultimi backup B2 + snapshot <code>.env.local</code> dal VPS
                (~5-15 minuti a seconda della size). Stamperà il <strong>manifest SHA-256</strong>.
            </li>
            <li>
                Compila il form sotto per registrare il completamento.
            </li>
        </ol>

        <h4 class="fm-mt-6">✓ Conferma backup completato</h4>
        <form method="POST" action="/admin/backup/cold-completed" class="fm-form-grid">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <label>
                <span class="fm-form-label-text">Etichetta HDD *</span>
                <input type="text" name="hdd_label" required maxlength="160"
                       placeholder='es. "WD My Passport 1TB PANTEDU-COLD-01"'
                       class="fm-w-full">
            </label>
            <label>
                <span class="fm-form-label-text">Size totale (MB)</span>
                <input type="number" name="size_mb" min="0"
                       placeholder="es. 8200"
                       class="fm-w-full">
            </label>
            <label class="fm-form-fullrow">
                <span class="fm-form-label-text">SHA-256 del manifest</span>
                <input type="text" name="sha256" maxlength="64"
                       placeholder="hex 64 chars stampato dallo script"
                       class="fm-w-full">
            </label>
            <label class="fm-form-fullrow">
                <span class="fm-form-label-text">Note</span>
                <textarea name="notes" rows="3" maxlength="2000" class="fm-w-full"
                          placeholder="Es. 'Backup mese maggio, riposto in cassetta UniCredit Lecco'"></textarea>
            </label>
            <div class="fm-form-actions">
                <button type="submit" class="fm-btn fm-btn--primary">✓ Registra cold backup</button>
            </div>
        </form>
    </div>
</details>

<!-- ═══ Link al registro completo ═══ -->
<section class="fm-info-banner">
    📜 Tutti gli eventi backup (cold/B2/snapshot) sono registrati nello stesso registro chain-of-custody del KMS.
    <a href="/admin/crypto-status" data-full-reload>Apri Crypto Status & Custody log →</a>
</section>

</div><?php /* /.fm-card aperto da page_head */ ?>
