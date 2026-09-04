<?php

/**
 * tos_gate_preview.php — chi viene bloccato accendendo TOS_ENFORCE.
 *
 * Da eseguire PRIMA di mettere `TOS_ENFORCE=true`. Elenca gli utenti
 * teacher/admin che al primo accesso finirebbero sul form di accettazione,
 * così l'attivazione è una decisione informata e non una sorpresa.
 *
 * Sui docenti approvati prima del fix al flusso di registrazione la spunta
 * ToS/AUP NON è recuperabile: `RegistrationService::historyRow()` conservava
 * solo id/username/email/ruolo/azione/attore/timestamp, scartando
 * `tos_accepted_at`, `ip` e `user_agent`. Un backfill "d'ufficio" scriverebbe
 * quindi una riga senza IP né User-Agent, cioè una prova che i documenti
 * stessi dicono di raccogliere. Meglio farli passare una volta dal form:
 * dieci secondi a testa, e il registro torna a dire il vero.
 *
 * Uso:
 *   php tools/legal/tos_gate_preview.php
 *   php tools/legal/tos_gate_preview.php --csv > blocco.csv
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Gdpr\TosAcceptanceService;

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}

$csv = \in_array('--csv', $argv, true);
$pdo = Database::connection();
$svc = new TosAcceptanceService($pdo);

$effective = $svc->effectiveVersions();
$pending = $svc->pendingVersions();

$stmt = $pdo->prepare(
    "SELECT u.id, u.username, u.email, u.role, u.created_at,
            (SELECT MAX(accepted_at) FROM user_tos_acceptance WHERE user_id = u.id) AS last_accepted
     FROM users u
     WHERE u.role IN ('teacher','admin')
     ORDER BY u.username"
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$blocked = [];
foreach ($rows as $r) {
    if (!$svc->hasAccepted((int)$r['id'])) {
        $blocked[] = $r;
    }
}

if ($csv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'username', 'email', 'role', 'created_at', 'last_accepted']);
    foreach ($blocked as $r) {
        fputcsv($out, [
            $r['id'], $r['username'], $r['email'], $r['role'],
            $r['created_at'], $r['last_accepted'] ?? '',
        ]);
    }
    fclose($out);
    exit(0);
}

echo "Versioni vincolanti ora: ToS {$effective['tos']} · AUP {$effective['aup']}\n";
if ($pending !== []) {
    echo "In preavviso:\n";
    foreach ($pending as $p) {
        printf(
            "  %s %s — in vigore dal %s (fra %d giorni)%s\n",
            strtoupper((string)$p['doc_type']),
            (string)$p['version'],
            date('d/m/Y', strtotime((string)$p['effective_from'])),
            (int)$p['days_remaining'],
            $p['is_substantial'] ? '' : ' [non sostanziale, nessun preavviso dovuto]'
        );
    }
}

$total = \count($rows);
$n = \count($blocked);
echo "\nUtenti teacher/admin: $total — verrebbero bloccati: $n\n";

if ($n === 0) {
    echo "Nessuno. TOS_ENFORCE=true si può attivare senza impatto.\n";
    exit(0);
}

echo str_repeat('-', 72) . "\n";
printf("%-6s %-24s %-22s %s\n", 'ID', 'USERNAME', 'ULTIMA ACCETTAZIONE', 'RUOLO');
echo str_repeat('-', 72) . "\n";
foreach ($blocked as $r) {
    printf(
        "%-6s %-24s %-22s %s\n",
        (string)$r['id'],
        substr((string)$r['username'], 0, 24),
        $r['last_accepted'] !== null ? (string)$r['last_accepted'] : 'mai',
        (string)$r['role']
    );
}
echo str_repeat('-', 72) . "\n";

$never = \count(array_filter($blocked, static fn(array $r) => $r['last_accepted'] === null));
if ($never > 0) {
    echo "\n$never utenti non hanno MAI una riga in user_tos_acceptance.\n";
    echo "Se sono stati approvati prima del fix al flusso di registrazione, la\n";
    echo "loro spunta è andata persa all'approvazione e non è ricostruibile:\n";
    echo "dovranno passare una volta dal form. Avvisali prima di accendere il flag.\n";
}
