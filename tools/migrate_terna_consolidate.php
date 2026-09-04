<?php

declare(strict_types=1);

/**
 * ADR-030 — Consolidamento documenti per-terna duplicati.
 *
 * Trova i gruppi di teacher_content "stesso documento a terne diverse"
 * (stesso teacher_id + section_id + topic, terne indirizzo/classe/materia
 * diverse), e li fonde in UN solo documento `terna_scoped`:
 *   - struttura canonica = riga più recente del gruppo;
 *   - i valori dei campi 🔗 di OGNI riga vengono mappati PER POSIZIONE sulle
 *     chiavi canoniche (i fork hanno strutture identiche ma id generati
 *     indipendentemente → zip per ordine di traversata) e salvati in
 *     ternaStore[terna];
 *   - le altre righe del gruppo vengono ARCHIVIATE.
 *
 * USO:
 *   php tools/migrate_terna_consolidate.php              # dry-run: stampa il piano
 *   php tools/migrate_terna_consolidate.php --apply      # esegue
 *   php tools/migrate_terna_consolidate.php --teacher=77 # limita a un docente
 *
 * SEMPRE backup DB prima di --apply.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Risdoc\Pt\TernaBinding;

$apply       = \in_array('--apply', $argv, true);
$teacherOnly = null;
foreach ($argv as $a) {
    if (\preg_match('/^--teacher=(\d+)$/', $a, $m)) {
        $teacherOnly = (int)$m[1];
    }
}

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}

$pdo = Database::connection();
$repo = new TeacherContentRepository();

// Candidati: documenti risdoc non archiviati.
$sql = "SELECT id, teacher_id, section_id, topic, title, visibility, updated_at
        FROM teacher_content_data
        WHERE content_subtype = 'document' AND visibility <> 'archived'";
$params = [];
if ($teacherOnly !== null) {
    $sql .= " AND teacher_id = ?";
    $params[] = $teacherOnly;
}
$sql .= " ORDER BY teacher_id, section_id, topic, updated_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cands = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Raggruppa per (teacher_id, section_id, topic).
$groups = [];
foreach ($cands as $r) {
    $key = $r['teacher_id'] . '|' . ($r['section_id'] ?? '') . '|' . ($r['topic'] ?? '');
    $groups[$key][] = $r;
}

$plan = [];
foreach ($groups as $key => $rows) {
    if (\count($rows) < 2) {
        continue; // niente da consolidare
    }
    // Carica il body_pt + codici di ogni riga (find decifra body_pt).
    $loaded = [];
    foreach ($rows as $r) {
        $full = $repo->find((int)$r['id']);
        if (!$full) {
            continue;
        }
        $meta = \is_array($full['metadata'] ?? null) ? $full['metadata'] : [];
        if (TernaBinding::isTernaScoped($meta)) {
            continue 2; // gruppo già (parzialmente) terna_scoped → skip prudente
        }
        $bp = $meta['body_pt'] ?? null;
        $ind = $full['indirizzo'] ?? '';
        $cls = $full['classe'] ?? '';
        $subj = $full['subject_code'] ?? '';
        if (!\is_array($bp) || $ind === '' || $cls === '' || $subj === '') {
            continue;
        }
        if (!TernaBinding::hasLinkedFields($bp)) {
            continue;
        }
        $loaded[] = [
            'id' => (int)$r['id'], 'teacher_id' => (int)$r['teacher_id'],
            'terna' => "{$ind}/{$cls}/{$subj}", 'body_pt' => $bp,
            'updated_at' => $r['updated_at'], 'title' => $r['title'],
            'meta' => $meta,
        ];
    }
    // Servono ≥2 terne DISTINTE con campi 🔗.
    $ternas = array_unique(array_column($loaded, 'terna'));
    if (\count($loaded) < 2 || \count($ternas) < 2) {
        continue;
    }
    // Canonica = la più recente (prima per updated_at DESC).
    $canon = $loaded[0];
    $canonBlocks = $canon['body_pt'];
    TernaBinding::ensureIds($canonBlocks);
    $canonOrdered = TernaBinding::orderedLinked($canonBlocks);
    $canonKeys = array_column($canonOrdered, 'key');

    // Costruisci lo store zippando per posizione.
    $store = [];
    $skips = [];
    foreach ($loaded as $row) {
        $srcOrdered = TernaBinding::orderedLinked($row['body_pt']);
        if (\count($srcOrdered) !== \count($canonKeys)) {
            $skips[] = "{$row['terna']} (campi 🔗 {$row['id']}=" . \count($srcOrdered) . " ≠ canonica=" . \count($canonKeys) . ")";
            continue;
        }
        $delta = [];
        foreach ($canonKeys as $i => $k) {
            if ($k === null) {
                continue;
            }
            $delta[$k] = $srcOrdered[$i]['value'];
        }
        $store[$row['terna']] = $delta;
    }
    if (\count($store) < 2) {
        continue; // dopo gli skip non resta abbastanza da fondere
    }

    // body_pt canonico = struttura con 🔗 azzerati + ternaStore.
    [$structOnly] = TernaBinding::extract($canonBlocks, $canon['terna'], []); // azzera inline 🔗
    $structOnly = TernaBinding::attachStore($structOnly, $store);

    $archiveIds = [];
    foreach ($loaded as $row) {
        if ($row['id'] !== $canon['id']) {
            $archiveIds[] = $row['id'];
        }
    }

    $plan[] = [
        'group' => $key, 'canonId' => $canon['id'], 'teacher' => $canon['teacher_id'],
        'ternas' => array_keys($store), 'archive' => $archiveIds, 'skips' => $skips,
        'newMeta' => ['body_pt' => $structOnly] + ['terna_scoped' => true] + $canon['meta'],
    ];
}

// Stampa piano.
echo "=== ADR-030 consolidamento per-terna — " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
if (!$plan) {
    echo "Nessun gruppo consolidabile trovato.\n";
    exit(0);
}
foreach ($plan as $p) {
    echo "• doc canonico #{$p['canonId']} (teacher {$p['teacher']}) → terna_scoped\n";
    echo "    terne unite: " . implode(', ', $p['ternas']) . "\n";
    echo "    archivia: " . (empty($p['archive']) ? '(nessuno)' : implode(', ', $p['archive'])) . "\n";
    if (!empty($p['skips'])) {
        echo "    SKIP (struttura non allineata): " . implode('; ', $p['skips']) . "\n";
    }
}
if (!$apply) {
    echo "\nDry-run: nessuna modifica. Rilancia con --apply per eseguire (backup DB prima!).\n";
    exit(0);
}

// Esegui.
$done = 0;
foreach ($plan as $p) {
    $meta = $p['newMeta'];
    $meta['terna_scoped'] = true;
    $repo->update((int)$p['canonId'], (int)$p['teacher'], ['metadata' => $meta]);
    foreach ($p['archive'] as $aid) {
        // teacher_id nel WHERE = defense-in-depth simmetrica a repo->update().
        $pdo->prepare("UPDATE teacher_content_data SET visibility='archived' WHERE id=? AND teacher_id=?")
            ->execute([$aid, (int)$p['teacher']]);
    }
    $done++;
    echo "✓ consolidato #{$p['canonId']} (+" . \count($p['archive']) . " archiviati)\n";
}
echo "\nFatto: $done gruppi consolidati.\n";
