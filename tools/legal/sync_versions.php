<?php

/**
 * sync_versions.php — allinea docs/legal/versions.json a legal_document_versions.
 *
 * Il registro JSON è versionato in git ed è la fonte di verità; il DB è una
 * proiezione. Questo script applica il registro, calcola il checksum del .md
 * corrispondente e segnala le divergenze.
 *
 * Non cancella nulla: una versione rimossa dal JSON resta a DB (è un fatto
 * storico — qualcuno l'ha accettata, e riscrivere il passato di un registro
 * di consensi non è un'operazione legittima).
 *
 * Uso:
 *   php tools/legal/sync_versions.php            # dry-run
 *   php tools/legal/sync_versions.php --apply
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}

$apply = \in_array('--apply', $argv, true);
$root = \dirname(__DIR__, 2);
$registryPath = $root . '/docs/legal/versions.json';

if (!is_file($registryPath)) {
    fwrite(STDERR, "Registro non trovato: $registryPath\n");
    exit(1);
}
$registry = json_decode((string)file_get_contents($registryPath), true);
if (!is_array($registry) || !isset($registry['documents']) || !is_array($registry['documents'])) {
    fwrite(STDERR, "Registro malformato: $registryPath\n");
    exit(1);
}

$pdo = Database::connection();

$existing = $pdo->query(
    'SELECT doc_type, version, published_at, effective_from, is_substantial, checksum, summary
     FROM legal_document_versions'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byKey = [];
foreach ($existing as $row) {
    $byKey[$row['doc_type'] . '@' . $row['version']] = $row;
}

$insert = $pdo->prepare(
    'INSERT INTO legal_document_versions
        (doc_type, version, published_at, effective_from, is_substantial, checksum, summary)
     VALUES (:t, :v, :p, :e, :s, :c, :sum)'
);
$update = $pdo->prepare(
    'UPDATE legal_document_versions
        SET published_at = :p, effective_from = :e, is_substantial = :s,
            checksum = :c, summary = :sum
      WHERE doc_type = :t AND version = :v'
);

$added = 0;
$changed = 0;
$unchanged = 0;

foreach ($registry['documents'] as $docType => $doc) {
    if (!is_array($doc) || !is_array($doc['versions'] ?? null)) {
        continue;
    }
    $mdPath = $root . '/' . ltrim((string)($doc['file'] ?? ''), '/');
    $checksum = is_file($mdPath) ? hash_file('sha256', $mdPath) : null;

    $versionList = array_values($doc['versions']);
    $lastIndex = \count($versionList) - 1;

    foreach ($versionList as $i => $v) {
        $version = (string)($v['version'] ?? '');
        if ($version === '') {
            continue;
        }
        $published = (string)($v['published_at'] ?? '') . ' 00:00:00';
        $effective = (string)($v['effective_from'] ?? '') . ' 00:00:00';
        $substantial = (int)(bool)($v['substantial'] ?? true);
        $summary = isset($v['summary']) ? (string)$v['summary'] : null;

        // Il checksum vale solo per la versione corrente: il .md sul disco è
        // quella, non le precedenti.
        $isLatest = $i === $lastIndex;
        $sum = $isLatest ? $checksum : ($byKey["$docType@$version"]['checksum'] ?? null);

        $args = [
            ':t' => $docType, ':v' => $version, ':p' => $published,
            ':e' => $effective, ':s' => $substantial, ':c' => $sum, ':sum' => $summary,
        ];

        $prev = $byKey["$docType@$version"] ?? null;
        if ($prev === null) {
            echo ($apply ? '[ADD] ' : '[DRY][ADD] ')
                . "$docType $version — in vigore dal " . substr($effective, 0, 10) . "\n";
            if ($apply) {
                $insert->execute($args);
            }
            $added++;
            continue;
        }

        // `summary` va confrontato come gli altri campi: è il testo che finisce
        // nell'email di preavviso e nel form di accettazione, quindi correggerlo
        // nel JSON senza propagarlo a DB significa continuare a spedire la
        // versione vecchia.
        $differs = $prev['published_at'] !== $published
            || $prev['effective_from'] !== $effective
            || (int)$prev['is_substantial'] !== $substantial
            || ($prev['summary'] ?? null) !== $summary
            || ($sum !== null && $prev['checksum'] !== $sum);

        if ($differs) {
            echo ($apply ? '[UPD] ' : '[DRY][UPD] ') . "$docType $version\n";
            if ($prev['checksum'] !== null && $sum !== null && $prev['checksum'] !== $sum) {
                echo "        il contenuto del .md è cambiato senza cambio di versione\n";
            }
            if ($apply) {
                $update->execute($args);
            }
            $changed++;
        } else {
            $unchanged++;
        }
    }
}

echo sprintf(
    "\n%s — aggiunte: %d, aggiornate: %d, invariate: %d\n",
    $apply ? 'APPLY' : 'DRY-RUN',
    $added,
    $changed,
    $unchanged
);
if (!$apply && ($added > 0 || $changed > 0)) {
    echo "Per applicare: php tools/legal/sync_versions.php --apply\n";
}
