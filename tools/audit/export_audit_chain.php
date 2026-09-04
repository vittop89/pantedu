<?php

declare(strict_types=1);

/**
 * Impronta giornaliera dei registri append-only, e sua verifica.
 *
 * PERCHE'
 *   Vedi App\Services\Audit\AuditChain. Qui sta la parte con il database e
 *   i file: ogni esecuzione prende, per ogni registro, le righe con id oltre
 *   l'ultimo gia' coperto, le raggruppa in blocchi di BLOCK righe, calcola
 *   l'impronta di ogni blocco concatenata con quella del blocco precedente,
 *   e scrive:
 *
 *     <storage>/audit-chain/state.json          ultimo id e impronta per registro
 *     <storage>/audit-chain/chain-YYYYMMDD.json  i blocchi del giorno
 *     <storage>/audit-chain/heads.log            una riga per registro e giorno
 *
 *   La cartella <storage> e' quella che il backup cifrato notturno porta
 *   fuori dal server: l'impronta viaggia con i dati, in un archivio che chi
 *   amministra il server non puo' riscrivere se il bucket remoto ha l'object
 *   lock (proprieta' del bucket, da attivare una volta; non di questo script).
 *
 * PERCHE' A BLOCCHI
 *   La purga per conservazione cancella le righe piu' vecchie, cioe' un
 *   prefisso della catena. Un'unica impronta per giorno diventerebbe
 *   inverificabile alla prima purga. Un blocco invece si verifica da solo:
 *   se le sue righe ci sono tutte, l'impronta deve tornare; se ne manca una
 *   parte, o e' la purga — che consuma blocchi interi, vecchi, per data — o
 *   e' una cancellazione, e la data del blocco dice quale delle due.
 *
 * USO
 *   php tools/audit/export_audit_chain.php                 # nuovo segmento
 *   php tools/audit/export_audit_chain.php --verify=FILE   # ricalcola i blocchi di un segmento
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Audit\AuditChain;

/** Registri coperti; tutti con chiave primaria `id` crescente. */
const TABLES = [
    'privileged_access_log',
    'crypto_access_log',
    'crypto_custody_events',
    'consent_audit',
    'content_action_log',
    'audit_activity_log',
    'teacher_recovery_audit',
];
const BLOCK = 500;

$opts   = getopt('', ['verify::']);
$verify = $opts['verify'] ?? null;

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}
$pdo = Database::connection();
$dir = rtrim((string)Config::get('app.paths.storage'), '/\\') . '/audit-chain';
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Impossibile creare $dir\n");
    exit(1);
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Hash delle righe con id in [$from, $to], in ordine di id.
 * @return array{hashes:list<string>, first_id:int, last_id:int}
 */
function rangeHashes(PDO $pdo, string $table, int $from, int $to): array
{
    $st = $pdo->prepare("SELECT * FROM `$table` WHERE id >= ? AND id <= ? ORDER BY id");
    $st->execute([$from, $to]);
    $hashes = [];
    $first  = 0;
    $last   = 0;
    while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
        $hashes[] = AuditChain::rowHash($row);
        $id = (int)$row['id'];
        $first = $first === 0 ? $id : $first;
        $last  = $id;
    }
    return ['hashes' => $hashes, 'first_id' => $first, 'last_id' => $last];
}

// ── Verifica ──────────────────────────────────────────────────────────────
if ($verify !== null && $verify !== false) {
    $file = (string)$verify;
    if (!is_file($file)) {
        fwrite(STDERR, "File non trovato: $file\n");
        exit(1);
    }
    $seg = json_decode((string)file_get_contents($file), true);
    if (!is_array($seg) || !isset($seg['tables']) || !is_array($seg['tables'])) {
        fwrite(STDERR, "Segmento non valido.\n");
        exit(1);
    }
    $bad = 0;
    printf("Verifica di %s (generato il %s)\n", basename($file), (string)($seg['generated_at'] ?? '?'));
    foreach ($seg['tables'] as $table => $s) {
        if (!tableExists($pdo, (string)$table)) {
            printf("  %-24s assente\n", $table);
            continue;
        }
        $ok = 0;
        $skipped = 0;
        $diverge = 0;
        foreach ((array)($s['blocks'] ?? []) as $b) {
            $r = rangeHashes($pdo, (string)$table, (int)$b['from_id'], (int)$b['to_id']);
            $n = count($r['hashes']);
            if ($n < (int)$b['count']) {
                // Righe in meno: purga per conservazione, o cancellazione. La
                // data del blocco rispetto alla conservazione del registro
                // distingue le due: la stampa la lascia a chi legge.
                $skipped++;
                printf("  %-24s blocco %d..%d: %d righe su %d — non verificabile (purga o cancellazione)\n",
                    $table, (int)$b['from_id'], (int)$b['to_id'], $n, (int)$b['count']);
                continue;
            }
            $head = AuditChain::fold((string)$b['prev_head'], $r['hashes']);
            if ($n === (int)$b['count'] && $head === (string)$b['head']) {
                $ok++;
            } else {
                $diverge++;
                printf("  %-24s blocco %d..%d: DIVERGE (righe %d/%d, impronta %s)\n",
                    $table, (int)$b['from_id'], (int)$b['to_id'], $n, (int)$b['count'], substr($head, 0, 16));
            }
        }
        printf("  %-24s blocchi ok %d, non verificabili %d, divergenti %d\n", $table, $ok, $skipped, $diverge);
        $bad += $diverge;
    }
    echo $bad === 0 ? "Nessun blocco diverge.\n" : "$bad blocchi divergono: righe alterate o cancellate.\n";
    exit($bad === 0 ? 0 : 2);
}

// ── Nuovo segmento ────────────────────────────────────────────────────────
$statePath = "$dir/state.json";
$state     = is_file($statePath) ? (json_decode((string)file_get_contents($statePath), true) ?: []) : [];
$now       = date('c');
$segment   = ['generated_at' => $now, 'block_size' => BLOCK, 'tables' => []];
$logLines  = [];

foreach (TABLES as $table) {
    if (!tableExists($pdo, $table)) {
        continue;
    }
    $prev     = $state[$table] ?? ['last_id' => 0, 'head' => AuditChain::GENESIS];
    $lastId   = (int)$prev['last_id'];
    $head     = (string)$prev['head'];
    $blocks   = [];
    $total    = 0;

    $st = $pdo->prepare("SELECT * FROM `$table` WHERE id > ? ORDER BY id");
    $st->execute([$lastId]);
    $hashes = [];
    $from   = 0;
    $to     = 0;
    $flush = static function () use (&$hashes, &$from, &$to, &$head, &$blocks, &$total): void {
        if ($hashes === []) {
            return;
        }
        $newHead  = AuditChain::fold($head, $hashes);
        $blocks[] = ['from_id' => $from, 'to_id' => $to, 'count' => count($hashes), 'prev_head' => $head, 'head' => $newHead];
        $total   += count($hashes);
        $head     = $newHead;
        $hashes   = [];
        $from     = 0;
    };
    while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
        $id = (int)$row['id'];
        $from = $from === 0 ? $id : $from;
        $to   = $id;
        $hashes[] = AuditChain::rowHash($row);
        if (count($hashes) >= BLOCK) {
            $flush();
        }
    }
    $flush();
    $newLast = $blocks === [] ? $lastId : (int)end($blocks)['to_id'];

    $segment['tables'][$table] = [
        'from_id' => $lastId,
        'to_id'   => $newLast,
        'count'   => $total,
        'head'    => $head,
        'blocks'  => $blocks,
    ];
    $state[$table] = ['last_id' => $newLast, 'head' => $head, 'updated_at' => $now];
    $logLines[]    = sprintf('%s %-24s to_id=%-8d rows=%-6d blocks=%-3d head=%s', $now, $table, $newLast, $total, count($blocks), $head);
    printf("  %-24s righe nuove %6d in %3d blocchi  fino a id %-8d  impronta %s\n", $table, $total, count($blocks), $newLast, substr($head, 0, 16));
}

$json = json_encode($segment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
// Data e ora nel nome: un secondo giro nello stesso giorno non deve
// sovrascrivere i blocchi del primo.
$file = sprintf('%s/chain-%s.json', $dir, date('Ymd-His'));
if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
    fwrite(STDERR, "Scrittura del segmento fallita.\n");
    exit(1);
}
$tmp = $statePath . '.tmp';
file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
rename($tmp, $statePath);
file_put_contents("$dir/heads.log", implode("\n", $logLines) . "\n", FILE_APPEND | LOCK_EX);
echo "Segmento scritto: $file\n";
