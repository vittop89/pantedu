<?php

declare(strict_types=1);

/**
 * Mette le etichette a contenuti scelti per id, e/o li sposta di sezione.
 *
 * PERCHE'
 *   La pagina /area-docente/da-categorizzare fa lo stesso lavoro a gruppi, ed
 *   e' li' che va fatto di norma. Questo serve ai casi decisi altrove: due
 *   contenuti omonimi che vanno in corsi diversi, o un blocco finito nella
 *   sezione sbagliata all'import — dove il gruppo non e' il criterio giusto e
 *   gli id si conoscono uno per uno.
 *
 * COSA NON FA
 *   Non sovrascrive: indirizzo, classe e materia si mettono solo dove sono
 *   vuoti, come nella pagina. La sezione invece si cambia anche se c'e' gia' —
 *   spostare e' proprio l'operazione richiesta, e non ha senso farlo solo
 *   verso il vuoto.
 *
 * USO
 *   php tools/contenuti/etichetta.php --teacher=superadmin --ids=89 \
 *       --indirizzo=SCI --classe=1
 *   php tools/contenuti/etichetta.php --teacher=superadmin \
 *       --ids=108,109,110 --sezione=bes --apply
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$apply = in_array('--apply', $argv, true);
$opt   = ['teacher' => '', 'ids' => '', 'indirizzo' => '', 'classe' => '', 'materia' => '', 'sezione' => ''];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    }
}
$ids = array_values(array_filter(array_map('intval', explode(',', $opt['ids'])), static fn(int $i): bool => $i > 0));
if ($opt['teacher'] === '' || $ids === []) {
    fwrite(STDERR, "Servono --teacher=<username> e --ids=1,2,3\n");
    exit(1);
}
if ($opt['indirizzo'] === '' && $opt['classe'] === '' && $opt['materia'] === '' && $opt['sezione'] === '') {
    fwrite(STDERR, "Serve almeno una fra --indirizzo, --classe, --materia, --sezione.\n");
    exit(1);
}

$pdo = Database::connection();
$st  = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([$opt['teacher']]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) {
    fwrite(STDERR, "Docente '{$opt['teacher']}' inesistente.\n");
    exit(1);
}

// Le voci di curriculum si risolvono per codice, e devono essere DEL docente:
// e' lo stesso vincolo della pagina, e impedisce di scrivere un id qualunque.
$voce = static function (string $kind, string $code) use ($pdo, $uid): ?array {
    if ($code === '') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT c.id, c.label, COALESCE(i.name, i.code) AS istituto
           FROM curriculum_entries c JOIN institutes i ON i.id = c.institute_id
          WHERE c.kind = ? AND c.code = ? AND c.owner_user_id = ? AND c.active = 1
          LIMIT 1'
    );
    $st->execute([$kind, $code, $uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        fwrite(STDERR, "Voce $kind '$code' non esiste fra quelle di questo docente.\n");
        exit(1);
    }
    return $r;
};

$ind = $voce('indirizzi', $opt['indirizzo']);
$cls = $voce('classi', $opt['classe']);
$mat = $voce('materie', $opt['materia']);

$sez = null;
if ($opt['sezione'] !== '') {
    $st = $pdo->prepare('SELECT id, label FROM sidebar_sections WHERE section_key = ? ORDER BY id LIMIT 1');
    $st->execute([$opt['sezione']]);
    $sez = $st->fetch(PDO::FETCH_ASSOC);
    if ($sez === false) {
        fwrite(STDERR, "Sezione '{$opt['sezione']}' inesistente.\n");
        exit(1);
    }
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare(
    "SELECT d.id, d.title, d.content_subtype AS tipo, s.label AS sezione,
            ci.code AS ind, cc.code AS cls, cm.code AS mat
       FROM teacher_content_data d
       LEFT JOIN sidebar_sections s    ON s.id  = d.section_id
       LEFT JOIN curriculum_entries ci ON ci.id = d.indirizzo_id
       LEFT JOIN curriculum_entries cc ON cc.id = d.classe_id
       LEFT JOIN curriculum_entries cm ON cm.id = d.subject_id
      WHERE d.teacher_id = ? AND d.id IN ($ph) ORDER BY d.id"
);
$st->execute(array_merge([$uid], $ids));
$righe = $st->fetchAll(PDO::FETCH_ASSOC);

if (count($righe) !== count($ids)) {
    $trovati = array_column($righe, 'id');
    $mancanti = array_diff($ids, array_map('intval', $trovati));
    fwrite(STDERR, 'Non sono di questo docente (o non esistono): ' . implode(', ', $mancanti) . "\n");
    exit(1);
}

printf("%d contenuti di %s:\n", count($righe), $opt['teacher']);
foreach ($righe as $r) {
    printf("  %-6s %-10s %-40s ora: ind=%-5s cls=%-5s mat=%-5s sez=%s\n",
        $r['id'], $r['tipo'], mb_substr((string)$r['title'], 0, 38),
        $r['ind'] ?? '-', $r['cls'] ?? '-', $r['mat'] ?? '-', $r['sezione'] ?? '-');
}

echo "\nSi mette:\n";
foreach ([['indirizzo', $ind], ['classe', $cls], ['materia', $mat]] as [$nome, $v]) {
    if ($v !== null) {
        printf("  %-10s %s (%s)  — solo dove e' vuoto\n", $nome, $v['label'], $v['istituto']);
    }
}
if ($sez !== null) {
    printf("  %-10s %s  — anche dove c'e' gia'\n", 'sezione', $sez['label']);
}

if (!$apply) {
    echo "\nANTEPRIMA (nessuna modifica). Per scrivere: --apply\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $campi = 0;
    foreach ([['indirizzo_id', $ind], ['classe_id', $cls], ['subject_id', $mat]] as [$colonna, $v]) {
        if ($v === null) {
            continue;
        }
        $upd = $pdo->prepare(
            "UPDATE teacher_content_data SET $colonna = ?
              WHERE teacher_id = ? AND $colonna IS NULL AND id IN ($ph)"
        );
        $upd->execute(array_merge([$v['id'], $uid], $ids));
        $campi += $upd->rowCount();
    }
    if ($sez !== null) {
        $upd = $pdo->prepare(
            "UPDATE teacher_content_data SET section_id = ?
              WHERE teacher_id = ? AND id IN ($ph)"
        );
        $upd->execute(array_merge([$sez['id'], $uid], $ids));
        $campi += $upd->rowCount();
    }
    $pdo->commit();
    printf("\nFATTO — %d campi scritti.\n", $campi);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Niente scritto: ' . $e->getMessage() . "\n");
    exit(1);
}
