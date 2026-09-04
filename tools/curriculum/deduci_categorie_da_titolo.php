<?php

declare(strict_types=1);

/**
 * Propone le etichette mancanti guardando i contenuti gemelli del docente.
 *
 * DA DOVE VIENE L'IDEA
 *   I contenuti senza indirizzo non hanno perso niente: non l'hanno mai avuto.
 *   Un backup del 30 maggio 2026 mostra le stesse righe con la sola materia e
 *   nessuna classe — sono nati cosi', prima che l'archiviazione esistesse.
 *
 *   Ma non sono orfani: quasi tutti hanno un titolo identico a un contenuto
 *   dello stesso docente che invece e' archiviato. La verifica "Derivate" e la
 *   mappa "Derivate" sono lo stesso argomento visto da due parti, e la seconda
 *   sa dove va.
 *
 * LA REGOLA
 *   Stesso docente, stesso titolo. Se tutti i gemelli archiviati concordano su
 *   indirizzo e classe, la proposta e' quella. Se non concordano — "Frazioni
 *   algebriche" e' in ART 3 e in SCI 1 — non si sceglie: si elenca e decide chi
 *   insegna. Un argomento in due corsi e' normale, indovinare no.
 *
 *   I campi gia' pieni non si toccano mai, e si scrive solo verso voci di
 *   curriculum del docente stesso.
 *
 * USO
 *   php tools/curriculum/deduci_categorie_da_titolo.php --teacher=superadmin
 *   php tools/curriculum/deduci_categorie_da_titolo.php --teacher=superadmin --apply
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

$apply   = in_array('--apply', $argv, true);
$docente = '';
foreach ($argv as $a) {
    if (preg_match('/^--teacher=(.+)$/', $a, $m)) {
        $docente = $m[1];
    }
}
if ($docente === '') {
    fwrite(STDERR, "Serve --teacher=<username>.\n");
    exit(1);
}

$pdo = Database::connection();
$st  = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([$docente]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) {
    fwrite(STDERR, "Docente '$docente' inesistente.\n");
    exit(1);
}

// I gemelli: per ogni titolo, quali coppie (indirizzo, classe) usa il docente
// nei contenuti che ha gia' archiviato.
$st = $pdo->prepare(
    'SELECT d.title, d.indirizzo_id, d.classe_id, d.subject_id,
            ci.code AS ind, cc.code AS cls, cm.code AS mat, COUNT(*) n
       FROM teacher_content_data d
       LEFT JOIN curriculum_entries ci ON ci.id = d.indirizzo_id
       LEFT JOIN curriculum_entries cc ON cc.id = d.classe_id
       LEFT JOIN curriculum_entries cm ON cm.id = d.subject_id
      WHERE d.teacher_id = ? AND d.indirizzo_id IS NOT NULL AND d.classe_id IS NOT NULL
      GROUP BY d.title, d.indirizzo_id, d.classe_id, d.subject_id, ci.code, cc.code, cm.code'
);
$st->execute([$uid]);
$gemelli = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gemelli[(string)$r['title']][] = $r;
}

// I contenuti a cui manca qualcosa.
$st = $pdo->prepare(
    'SELECT id, title, content_subtype AS tipo, indirizzo_id, classe_id, subject_id
       FROM teacher_content_data
      WHERE teacher_id = ?
        AND (indirizzo_id IS NULL OR classe_id IS NULL OR subject_id IS NULL)
      ORDER BY title, id'
);
$st->execute([$uid]);
$incompleti = $st->fetchAll(PDO::FETCH_ASSOC);

$proposte = [];
$ambigui  = [];
$soli     = [];
foreach ($incompleti as $c) {
    $cand = $gemelli[(string)$c['title']] ?? [];
    if ($cand === []) {
        $soli[] = $c;
        continue;
    }
    // Concordano tutti? Le coppie distinte sono una sola?
    $coppie = [];
    foreach ($cand as $g) {
        $coppie[$g['indirizzo_id'] . ':' . $g['classe_id']] = $g;
    }
    if (count($coppie) > 1) {
        $ambigui[] = ['c' => $c, 'dove' => array_values($coppie)];
        continue;
    }
    $g = reset($coppie);
    $proposte[] = ['c' => $c, 'g' => $g];
}

printf("%d contenuti incompleti di %s.\n\n", count($incompleti), $docente);

if ($proposte !== []) {
    printf("%d con un gemello archiviato che concorda:\n", count($proposte));
    foreach ($proposte as $p) {
        $mette = [];
        if ($p['c']['indirizzo_id'] === null) { $mette[] = 'ind=' . $p['g']['ind']; }
        if ($p['c']['classe_id'] === null)    { $mette[] = 'cls=' . $p['g']['cls']; }
        if ($p['c']['subject_id'] === null && $p['g']['mat'] !== null) { $mette[] = 'mat=' . $p['g']['mat']; }
        printf("  %-6s %-10s %-40s → %s\n", $p['c']['id'], $p['c']['tipo'],
            mb_substr((string)$p['c']['title'], 0, 38),
            $mette === [] ? '(niente da mettere)' : implode(' ', $mette));
    }
}

if ($ambigui !== []) {
    printf("\n%d con gemelli in corsi diversi — decide chi insegna, non lo strumento:\n", count($ambigui));
    foreach ($ambigui as $a) {
        $dove = array_map(static fn(array $g): string => $g['ind'] . ' ' . $g['cls'], $a['dove']);
        printf("  %-6s %-40s → %s\n", $a['c']['id'],
            mb_substr((string)$a['c']['title'], 0, 38), implode('  |  ', $dove));
    }
}

if ($soli !== []) {
    printf("\n%d senza nessun gemello (modelli, prove, o roba unica):\n", count($soli));
    foreach ($soli as $c) {
        printf("  %-6s %-10s %s\n", $c['id'], $c['tipo'], mb_substr((string)$c['title'], 0, 46));
    }
}

$daScrivere = array_values(array_filter($proposte, static function (array $p): bool {
    return $p['c']['indirizzo_id'] === null
        || $p['c']['classe_id'] === null
        || ($p['c']['subject_id'] === null && $p['g']['subject_id'] !== null);
}));
if ($daScrivere === []) {
    echo "\nNiente da scrivere.\n";
    exit(0);
}
if (!$apply) {
    echo "\nANTEPRIMA (nessuna modifica). Per scrivere: --apply\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $campi = 0;
    foreach ($daScrivere as $p) {
        foreach ([
            'indirizzo_id' => $p['g']['indirizzo_id'],
            'classe_id'    => $p['g']['classe_id'],
            'subject_id'   => $p['g']['subject_id'],
        ] as $colonna => $valore) {
            if ($valore === null) {
                continue;
            }
            // "Solo se vuoto" nella WHERE: e' cio' che rende sicuro rilanciarlo.
            $upd = $pdo->prepare(
                "UPDATE teacher_content_data SET $colonna = ?
                  WHERE id = ? AND teacher_id = ? AND $colonna IS NULL"
            );
            $upd->execute([$valore, $p['c']['id'], $uid]);
            $campi += $upd->rowCount();
        }
    }
    $pdo->commit();
    printf("\nFATTO — %d campi riempiti su %d contenuti.\n", $campi, count($daScrivere));
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Niente scritto: " . $e->getMessage() . "\n");
    exit(1);
}
