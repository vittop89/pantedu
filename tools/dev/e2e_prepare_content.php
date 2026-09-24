<?php

declare(strict_types=1);

/**
 * Fixture per la suite E2E: rende condivisibili i contenuti scoperti da
 * tools/dev/e2e_urls.php (coppia esercizio/verifica usata dalla catena di
 * condivisione G22.S22–S25).
 *
 * Un contenuto si può condividere solo se la sua fonte è classificata come
 * «personale» (SharedContentPolicy). La classificazione nasce dai quesiti del
 * contratto: se il contratto è vuoto (dump legacy) il contenuto resta «non
 * classificato» e la condivisione è bloccata. Qui, per i contenuti indicati e
 * SOLO se il contratto non ha quesiti, si aggiunge un gruppo con un quesito
 * personale e si salva: il salvataggio ricalcola source_type = personal.
 * Idempotente: al secondo giro trova il quesito e non tocca nulla.
 *
 * Uso: php tools/dev/e2e_prepare_content.php <username> <id,id,...>
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Repositories\Contract\ContractRepository;

$username = (string)($argv[1] ?? 'docente.uno');
$ids = array_values(array_filter(array_map('intval', explode(',', (string)($argv[2] ?? ''))), fn(int $n): bool => $n > 0));

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile\n");
    exit(1);
}
$pdo = Database::connection();
$st  = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([$username]);
$tid = (int)$st->fetchColumn();
if ($tid <= 0) {
    fwrite(STDERR, "utente {$username} assente\n");
    exit(1);
}

$repo = ContractRepository::default();
$rowSt = $pdo->prepare('SELECT id, content_type, source_type FROM teacher_content WHERE id = ? AND teacher_id = ? LIMIT 1');
// Istituto del docente (serve solo se il contenuto non ha ancora un contratto).
$instituteId = 0;
try {
    $instSt = $pdo->prepare('SELECT institute_id FROM teacher_institutes WHERE teacher_id = ? ORDER BY id LIMIT 1');
    $instSt->execute([$tid]);
    $instituteId = (int)$instSt->fetchColumn();
} catch (\Throwable) {
    // tabella assente o schema diverso: il fallback resta 0
}
$out = [];
foreach ($ids as $id) {
    $rowSt->execute([$id, $tid]);
    $row = $rowSt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        $out[$id] = 'non del docente';
        continue;
    }
    $agg = $repo->loadForTeacher($id, $tid);
    if (!$agg) {
        $repo->createEmptyShellForNewContent($id, $instituteId);
        $agg = $repo->loadForTeacher($id, $tid);
    }
    if (!$agg) {
        $out[$id] = 'senza contratto';
        continue;
    }
    $cls = $agg->classifyShareability();
    if ($cls['items_total'] > 0) {
        $out[$id] = ($cls['source_type'] ?? 'non classificato') . ' (' . $cls['items_total'] . ' quesiti)';
        continue;
    }
    $agg->appendGroup([
        'kind'  => 'problem-group',
        'type'  => 'type_Collect',
        'title' => 'Quesito di prova (fixture E2E)',
        'intro' => 'Risolvi.',
        'items' => [[
            'origin'         => 'personal',
            'source'         => '',
            'difficulty'     => 0,
            'tags'           => [],
            'category_label' => '',
            'category_color' => null,
            'color'          => 'white',
            'question'       => [['type' => 'text', 'content' => 'Calcola 2 + 2.']],
            'justification'  => [],
            'body_html'      => '',
            'solution'       => [['type' => 'text', 'content' => '4']],
        ]],
    ]);
    $agg->bumpVersion();
    $repo->save($agg);
    $after = $repo->loadForTeacher($id, $tid);
    $out[$id] = 'quesito personale aggiunto → ' . (($after ? $after->classifyShareability()['source_type'] : null) ?? 'non classificato');
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
