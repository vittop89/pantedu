<?php
/**
 * Phase 25.R follow-up — Generatore ZIP del pacchetto compliance per DPO.
 *
 * Esporta in un unico ZIP tutti i documenti che il DPO scolastico (o un
 * legal advisor) richiede tipicamente:
 *   - Informativa privacy (markdown + HTML render)
 *   - Registro trattamenti Art. 30
 *   - DPIA
 *   - Data breach runbook
 *   - Authority cooperation procedure
 *   - DPA template (compilabile)
 *   - ToS docente
 *   - AUP
 *   - Takedown procedure
 *   - ADR-006 (envelope encryption — design crypto)
 *   - ADR-007 (GDPR compliance — design quadro)
 *   - ADR-014 (KMS strategy — deferral motivato)
 *   - Sub-processor list (snapshot da DB)
 *   - Data breach register (snapshot da DB, opzionale anonymized)
 *   - Architettura riassuntiva (1 pagina)
 *
 * Output: storage/dpo-packets/dpo-packet-YYYYMMDD-HHMMSS.zip
 *
 * Uso:
 *   php tools/admin/generate_dpo_packet.php             # default output
 *   php tools/admin/generate_dpo_packet.php --include-data-breach  # include incident register
 *
 * Endpoint web associato (super-admin): /admin/dpo-packet/download
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;

// ─── Parse argv ─────────────────────────────────────────────────
$includeDataBreach = in_array('--include-data-breach', $argv, true);
$outputDir = dirname(__DIR__, 2) . '/storage/dpo-packets';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true)) {
    fwrite(STDERR, "ERRORE: impossibile creare $outputDir\n");
    exit(1);
}
$timestamp = date('Ymd_His');
$zipPath = "$outputDir/dpo-packet-$timestamp.zip";

echo "=== Generazione pacchetto DPO ===\n";
echo "Output: $zipPath\n\n";

// ─── Apri ZIP ─────────────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "ERRORE: impossibile creare ZIP\n");
    exit(1);
}

$base = dirname(__DIR__, 2);

// ─── Documenti da includere (path → nome dentro ZIP) ──────────
$documents = [
    // Privacy / GDPR
    'docs/privacy/informativa.md'                          => '01_informativa_privacy.md',
    'docs/privacy/registro-trattamenti.md'                 => '02_registro_trattamenti_art30.md',
    'docs/privacy/dpia.md'                                 => '03_dpia.md',
    'docs/privacy/data_breach_runbook.md'                  => '04_data_breach_runbook.md',
    // Security operations
    'docs/security/operations/authority-cooperation.md'    => '05_authority_cooperation.md',
    'docs/security/operations/incident-response.md'        => '06_incident_response.md',
    'docs/security/operations/kms-recovery.md'             => '07_kms_recovery.md',
    // Legal
    'docs/legal/tos_docente.md'                            => '08_tos_docente.md',
    'docs/legal/aup.md'                                    => '09_acceptable_use_policy.md',
    'docs/legal/takedown_procedure.md'                     => '10_takedown_procedure.md',
    'docs/legal/dpa_template.md'                           => '11_dpa_template.md',
    // Architecture decisions
    'wiki/decisions/ADR-006-envelope-encryption.md'        => '12_ADR-006_envelope_encryption.md',
    'wiki/decisions/ADR-007-gdpr-compliance.md'            => '13_ADR-007_gdpr_compliance.md',
    'wiki/decisions/ADR-014-kms-strategy.md'               => '14_ADR-014_kms_strategy.md',
];

$included = 0;
$missing = [];
foreach ($documents as $relPath => $zipName) {
    $absPath = "$base/$relPath";
    if (!is_file($absPath)) {
        $missing[] = $relPath;
        continue;
    }
    $zip->addFile($absPath, "documents/$zipName");
    echo "  ✓ $zipName\n";
    $included++;
}

echo "\n";
if ($missing) {
    echo "ATTENZIONE: file mancanti (non aggiunti al pacchetto):\n";
    foreach ($missing as $m) {
        echo "  ⚠️  $m\n";
    }
    echo "\n";
}

// ─── Snapshot DB: sub-processor list ──────────────────────────
try {
    $rows = Database::connection()
        ->query('SELECT name, service_description, country, extra_eu_transfer, transfer_safeguards, dpa_signed, dpa_url, contact_email, active FROM subprocessors ORDER BY active DESC, name')
        ->fetchAll(PDO::FETCH_ASSOC);
    $csv = "name,service_description,country,extra_eu_transfer,transfer_safeguards,dpa_signed,dpa_url,contact_email,active\n";
    foreach ($rows as $r) {
        $csv .= sprintf(
            "\"%s\",\"%s\",\"%s\",%d,\"%s\",%d,\"%s\",\"%s\",%d\n",
            str_replace('"', '""', (string)$r['name']),
            str_replace('"', '""', (string)$r['service_description']),
            str_replace('"', '""', (string)$r['country']),
            (int)$r['extra_eu_transfer'],
            str_replace('"', '""', (string)($r['transfer_safeguards'] ?? '')),
            (int)$r['dpa_signed'],
            str_replace('"', '""', (string)($r['dpa_url'] ?? '')),
            str_replace('"', '""', (string)($r['contact_email'] ?? '')),
            (int)$r['active']
        );
    }
    $zip->addFromString('snapshots/subprocessors.csv', $csv);
    echo "  ✓ snapshots/subprocessors.csv (" . count($rows) . " righe)\n";
} catch (Throwable $e) {
    echo "  ⚠️  snapshots/subprocessors.csv: " . $e->getMessage() . "\n";
}

// ─── Snapshot DB: data breach register (opzionale, anonymized) ─
if ($includeDataBreach) {
    try {
        $rows = Database::connection()
            ->query('SELECT id, occurred_at, detected_at, severity, affected_users_count, status, notified_garante_at, garante_ref, closed_at FROM data_breach_incidents ORDER BY detected_at DESC LIMIT 100')
            ->fetchAll(PDO::FETCH_ASSOC);
        $csv = "id,occurred_at,detected_at,severity,affected_users_count,status,notified_garante_at,garante_ref,closed_at\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                "%d,\"%s\",\"%s\",\"%s\",%s,\"%s\",\"%s\",\"%s\",\"%s\"\n",
                (int)$r['id'],
                (string)$r['occurred_at'],
                (string)$r['detected_at'],
                (string)$r['severity'],
                $r['affected_users_count'] !== null ? (int)$r['affected_users_count'] : '',
                (string)$r['status'],
                (string)($r['notified_garante_at'] ?? ''),
                (string)($r['garante_ref'] ?? ''),
                (string)($r['closed_at'] ?? '')
            );
        }
        $zip->addFromString('snapshots/data_breach_register.csv', $csv);
        echo "  ✓ snapshots/data_breach_register.csv (" . count($rows) . " righe)\n";
    } catch (Throwable $e) {
        echo "  ⚠️  data_breach_register: " . $e->getMessage() . "\n";
    }
}

// ─── Riassunto architettura (auto-generato) ───────────────────
$archSummary = <<<MD
# Architettura pantedu — riassunto per DPO

## Stack tecnico

| Layer | Tecnologia |
|-------|-----------|
| Hosting | Hetzner Cloud, datacenter Nuremberg (DE, EU) |
| Backup storage | Backblaze B2, region eu-central-003 Amsterdam (NL, EU) |
| Runtime | PHP 8.4 + nginx |
| Database | MySQL 8 / MariaDB |
| TLS | Let's Encrypt, HSTS 1 anno |

## Misure tecniche Art. 32 GDPR

| Misura | Implementazione |
|--------|-----------------|
| Cifratura at-rest | AES-256-GCM envelope encryption, per-teacher KEK |
| Cifratura in transit | HTTPS obbligatorio, HSTS, SameSite cookies |
| Pseudonimizzazione | IP + User-Agent SHA-256 hash in audit log |
| Crypto-shredding O(1) | DELETE 1 row teacher_keys → tutti i body indecifrabili (Art. 17) |
| Backup cifrati | Backblaze B2 con Object Lock retention |
| Audit log immutabile | privileged_access_log + crypto_access_log (REVOKE UPDATE/DELETE) |
| Rate limiting | 10/min/IP login, 60/min teacher API |
| WAF + IDS | CrowdSec community + custom WafMiddleware |
| Geo restriction | non-IT traffic filtering applicativo |
| Cloud Firewall | Hetzner CF restrict SSH a IP italiano |
| File integrity monitoring | AIDE daily check |
| Memory hardening | ptrace_scope=3, chattr +i sui secrets |

## Diritti interessati (Art. 15-22)

Endpoint self-service in produzione:
- `GET /me/export-data` — Art. 20 portabilità (JSON)
- `POST /me/profile` — Art. 16 rettifica
- `POST /me/request-deletion` — Art. 17 oblio (30g cooling-off)
- `GET /me/consents` — Art. 7 gestione consensi
- `POST /me/consents/revoke` — Art. 7 §3 revoca
- `/dpo-contact` — contatto DPO (form pubblico)

## Conservazione dati

| Dato | Retention |
|------|-----------|
| Account docente attivo | Durata utilizzo |
| Account inattivo > 730g | Anonimizzazione + crypto-shredding |
| Registrazioni pending | 30g |
| Access log | 365g |
| Privileged access log | 1825g (5 anni) |
| Backup DB | 90g rotating |
| consent_audit | Permanente |

## Sub-processor

Vedi `snapshots/subprocessors.csv` per lista corrente.

## Versionamento

Pacchetto generato il: {timestamp}
Versione app: vedi git log master_vps
Per la versione web pubblica: https://beta.pantedu.eu/security
MD;
$archSummary = str_replace('{timestamp}', date('Y-m-d H:i:s') . ' CEST', $archSummary);
$zip->addFromString('00_README_architettura.md', $archSummary);
echo "  ✓ 00_README_architettura.md (auto-generato)\n";

// ─── README pacchetto ─────────────────────────────────────────
$readme = <<<TXT
PACCHETTO COMPLIANCE PANTEDU — DPO REVIEW
============================================

Generato:    {timestamp}
Versione:    Phase 25.R.5.3
Destinatario: DPO istituto scolastico, legal advisor, audit privacy

CONTENUTO:
  00_README_architettura.md       Riassunto tecnico 1 pagina
  documents/                      Documenti policy + ADR
    01-04  Privacy/GDPR (informativa, registro, DPIA, breach runbook)
    05-07  Security operations (authority cooperation, IR, KMS recovery)
    08-11  Legal (ToS, AUP, takedown, DPA template)
    12-14  ADR architettura (envelope, GDPR design, KMS strategy)
  snapshots/                      Dati live dal sistema
    subprocessors.csv             Lista responsabili esterni corrente
    [data_breach_register.csv]    Se incluso con --include-data-breach

PER DPO:
  Iniziare leggendo 00_README_architettura.md per overview, poi
  documenti 01-04 (privacy/GDPR), poi misure tecniche in 05-07,
  poi i legal in 08-11. Gli ADR (12-14) sono per audit più
  tecnici.

CONTATTI:
  Data Controller / DPO interno pantedu: {{OPERATORE_NOME}}
  Email:                                   operatore@example.net
  Pagina pubblica privacy:                 https://beta.pantedu.eu/privacy/informativa
  Pagina pubblica sicurezza:               https://beta.pantedu.eu/security

LICENZA USO:
  Documenti interni pantedu, condivisibili con DPO scolastico,
  legal advisor, Garante Privacy, autorità giudiziaria su richiesta.
  NON pubblicare integralmente online (vedi versione pubblica
  /security e /privacy/informativa).
TXT;
$readme = str_replace('{timestamp}', date('Y-m-d H:i:s') . ' CEST', $readme);
$zip->addFromString('README.txt', $readme);
echo "  ✓ README.txt\n";

// ─── Chiudi ZIP ─────────────────────────────────────────────────
$zip->close();

$size = filesize($zipPath);
$sizeKb = round($size / 1024, 1);
echo "\n=== Pacchetto generato ===\n";
echo "File:  $zipPath\n";
echo "Size:  {$sizeKb} KB\n";
echo "Files inclusi: $included documenti + snapshot + README\n";
if ($missing) {
    echo "WARNING: " . count($missing) . " documenti non trovati (vedi sopra)\n";
}
echo "\nConsegnare al DPO via:\n";
echo "  - email cifrata\n";
echo "  - pendrive USB\n";
echo "  - link condiviso (es. nextcloud privato, NON Google Drive)\n";
