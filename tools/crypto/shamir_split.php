<?php

declare(strict_types=1);

/**
 * Phase 25.R.24 — Shamir Secret Sharing: split KMS_MASTER_KEY (o altro segreto)
 * in N share di cui K servono per ricostruirlo.
 *
 * Uso interattivo (consigliato):
 *   php tools/crypto/shamir_split.php
 *   → chiede il segreto da stdin (NO eco), genera 5 share threshold 3
 *
 * Uso CLI con args (script automatici):
 *   php tools/crypto/shamir_split.php --secret=<hex> --threshold=3 --n=5
 *   → output 5 share una per riga su stdout
 *
 * SICUREZZA:
 *   - Mai loggare il segreto in file o output esteso
 *   - Mai salvare share in DB / git
 *   - Distribuire share a custodi DIVERSI (no email plain — usare PEC/notaio/USB)
 *   - Cancellare buffer terminal dopo lettura share
 *
 * CUSTODI consigliati per pantedu KMS:
 *   1 = Vittorio Pantaleo (laptop dev — Password Safe locale)
 *   2 = Notaio (busta sigillata fisica + atto deposito)
 *   3 = Avvocato/fiduciario di fiducia
 *   4 = Cassetta sicurezza banca (stampa cartacea)
 *   5 = Backup online cifrato separato (Cryptomator vault DIVERSO da OneDrive)
 *
 * RECOVERY: usare shamir_combine.php con almeno 3 share dei 5.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\Crypto\ShamirSecretSharing;

// Parse args
$threshold = 3;
$n = 5;
$secret = null;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--threshold=(\d+)$/', $arg, $m)) {
        $threshold = (int)$m[1];
    } elseif (preg_match('/^--n=(\d+)$/', $arg, $m)) {
        $n = (int)$m[1];
    } elseif (preg_match('/^--secret=(.+)$/', $arg, $m)) {
        $secret = $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php shamir_split.php [--secret=<value>] [--threshold=N] [--n=N]\n";
        echo "  --secret=<value>   Segreto (hex/string). Se assente: chiede via stdin nascosto.\n";
        echo "  --threshold=N      Min share per recovery (default 3).\n";
        echo "  --n=N              Total share generati (default 5).\n";
        exit(0);
    }
}

if ($secret === null) {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: usa input semplice (no readline secret prompt)
        echo "Inserisci segreto (visibile su Windows — ATTENZIONE clear terminal dopo): ";
        $secret = trim((string)fgets(STDIN));
    } else {
        // Unix: stty -echo per nascondere input
        echo "Inserisci segreto (input nascosto): ";
        system('stty -echo');
        $secret = trim((string)fgets(STDIN));
        system('stty echo');
        echo "\n";
    }
}

if ($secret === '') {
    fwrite(STDERR, "ERROR: segreto vuoto.\n");
    exit(1);
}

$svc = new ShamirSecretSharing();
try {
    $shares = $svc->split($secret, $threshold, $n);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(2);
}

$secretFingerprint = substr(hash('sha256', $secret), 0, 16);
echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  SHAMIR SECRET SHARING — PANTEDU KMS DISTRIBUTION\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Threshold:           {$threshold} su {$n}\n";
echo "  Secret length:       " . strlen($secret) . " bytes\n";
echo "  Secret SHA-256 (16): {$secretFingerprint}\n";
echo "  Generato:            " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

foreach ($shares as $i => $share) {
    $idx = $i + 1;
    echo "── SHARE #{$idx} (consegna al custode #{$idx}) ────────────────────\n";
    echo "{$share}\n\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  ISTRUZIONI CUSTODI:\n";
echo "  1. Verifica fingerprint sopra: {$secretFingerprint}\n";
echo "  2. Custodisci la TUA share in modo SICURO (offline preferred).\n";
echo "  3. Mai condividere via email plain. Usa: USB cifrato + consegna a mano,\n";
echo "     PEC, notaio per deposito, cassetta sicurezza.\n";
echo "  4. Per recovery: contattare data controller (Vittorio Pantaleo).\n";
echo "     Servono almeno {$threshold} share su {$n}.\n";
echo "═══════════════════════════════════════════════════════════════\n";

// Sicurezza: zero memoria (best-effort, PHP non garantisce)
$secret = str_repeat("\x00", strlen($secret));
unset($secret);
