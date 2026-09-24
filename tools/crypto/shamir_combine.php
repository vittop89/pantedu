<?php

declare(strict_types=1);

/**
 * Phase 25.R.24 — Shamir Secret Sharing: recovery del segreto da K share su N.
 *
 * Uso interattivo (consigliato):
 *   php tools/crypto/shamir_combine.php
 *   → chiede share uno alla volta (input nascosto). Stop con riga vuota.
 *
 * Uso CLI:
 *   php tools/crypto/shamir_combine.php --share="FSS1:1:abc..." --share="FSS1:3:def..."
 *
 * Output:
 *   Il segreto plaintext ricostruito.
 *   ATTENZIONE: appare su STDOUT. Eseguire in ambiente sicuro.
 *
 * Se le share sono insufficienti o sbagliate: errore integrity tag mismatch.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\Crypto\ShamirSecretSharing;

$shares = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--share=(FSS1:\d+:[0-9a-fA-F]+)$/', $arg, $m)) {
        $shares[] = $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php shamir_combine.php [--share=FSS1:N:HEX ...]\n";
        echo "  --share=FSS1:idx:hex  Una share. Ripeti per ogni share.\n";
        echo "                        Se assente: chiede via stdin.\n";
        exit(0);
    }
}

if (empty($shares)) {
    echo "Incolla le share una alla volta (formato FSS1:idx:hex).\n";
    echo "Riga VUOTA per finire.\n\n";
    $i = 1;
    while (true) {
        echo "Share #{$i}: ";
        $line = trim((string)fgets(STDIN));
        if ($line === '') break;
        if (!preg_match('/^FSS1:\d+:[0-9a-fA-F]+$/', $line)) {
            fwrite(STDERR, "  ERROR: formato non valido. Atteso FSS1:N:hex...\n");
            continue;
        }
        $shares[] = $line;
        $i++;
    }
}

if (count($shares) < 2) {
    fwrite(STDERR, "ERROR: servono almeno 2 share.\n");
    exit(1);
}

$svc = new ShamirSecretSharing();
try {
    $secret = $svc->combine($shares);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Probabili cause: share insufficienti (sotto threshold), share corrotte, share di splits diversi.\n");
    exit(2);
}

$fp = substr(hash('sha256', $secret), 0, 16);
echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  SECRET RECOVERY OK\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Share usate:         " . count($shares) . "\n";
echo "  Secret length:       " . strlen($secret) . " bytes\n";
echo "  Secret SHA-256 (16): {$fp}\n";
echo "  Ricostruito:         " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";
echo "SEGRETO:\n";
echo $secret . "\n\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "Verifica fingerprint contro quello del documento custodia:\n";
echo "  atteso: <inserisci qui fingerprint del documento notarile>\n";
echo "  attuale: {$fp}\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "ATTENZIONE: clear scrollback terminal dopo lettura. Esempio:\n";
echo "  Linux: clear && printf '\\033[3J'\n";
echo "  Windows: cls\n";
