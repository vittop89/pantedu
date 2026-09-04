<?php

declare(strict_types=1);

/**
 * Attiva per ogni docente le classi che gli sono gia' state assegnate.
 *
 * COSA ERA ROTTO
 *   L'incarico si scrive in `teacher_sections`, ma i selettori del docente
 *   leggono le SUE righe di `curriculum_entries`. Due tabelle diverse:
 *   l'amministratore assegnava la 1A e il docente continuava a trovare il
 *   selettore Classe vuoto, senza che niente dicesse perche'.
 *
 *   Da oggi l'attivazione segue l'incarico (TeacherSectionService::assign).
 *   Questo sistema gli incarichi assegnati prima.
 *
 * COME
 *   Per ogni incarico copia dal vocabolario dell'istituto la voce indirizzo e
 *   la voce classe, come farebbe l'attivazione normale. Non inventa niente:
 *   se l'ancora non c'e', quella riga resta scoperta e viene elencata a parte
 *   — vuol dire che l'incarico punta a una classe che la scuola non ha in
 *   catalogo, ed e' una cosa da guardare, non da creare d'ufficio.
 *
 * USO
 *   php tools/curriculum/backfill_incarichi.php
 *   php tools/curriculum/backfill_incarichi.php --apply
 *   php tools/curriculum/backfill_incarichi.php --institute=108 --apply
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\CurriculumLookup;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$apply = in_array('--apply', $argv, true);
$soloIstituto = 0;
foreach ($argv as $a) {
    if (preg_match('/^--institute=(\d+)$/', $a, $m)) {
        $soloIstituto = (int)$m[1];
    }
}

$pdo = Database::connection();

$sql = 'SELECT ts.user_id, ts.institute_id, ts.indirizzo, ts.classe,
               u.username, COALESCE(i.name, i.code) AS istituto
          FROM teacher_sections ts
          JOIN users u      ON u.id = ts.user_id
          JOIN institutes i ON i.id = ts.institute_id'
     . ($soloIstituto > 0 ? ' WHERE ts.institute_id = ?' : '')
     . ' ORDER BY istituto, u.username, ts.indirizzo, ts.classe';

$st = $pdo->prepare($sql);
$st->execute($soloIstituto > 0 ? [$soloIstituto] : []);
$incarichi = $st->fetchAll(PDO::FETCH_ASSOC);

if ($incarichi === []) {
    echo "Nessun incarico da esaminare.\n";
    exit(0);
}

// Cosa possiede gia' ogni docente, per non riproporre righe che ci sono.
$possiede = static function (int $uid, int $iid, string $kind, string $code) use ($pdo): bool {
    $q = $pdo->prepare(
        'SELECT 1 FROM curriculum_entries
          WHERE kind = ? AND code = ? AND institute_id = ? AND owner_user_id = ? LIMIT 1'
    );
    $q->execute([$kind, $code, $iid, $uid]);
    return (bool)$q->fetchColumn();
};
$ancora = static function (int $iid, string $kind, string $code) use ($pdo): bool {
    $q = $pdo->prepare(
        'SELECT 1 FROM curriculum_entries
          WHERE kind = ? AND code = ? AND institute_id = ? AND owner_user_id IS NULL LIMIT 1'
    );
    $q->execute([$kind, $code, $iid]);
    return (bool)$q->fetchColumn();
};

$daFare   = [];
$scoperti = [];
foreach ($incarichi as $r) {
    $uid = (int)$r['user_id'];
    $iid = (int)$r['institute_id'];
    foreach ([['indirizzi', (string)$r['indirizzo']], ['classi', (string)$r['classe']]] as [$kind, $code]) {
        if ($possiede($uid, $iid, $kind, $code)) {
            continue;
        }
        if (!$ancora($iid, $kind, $code)) {
            $scoperti[] = sprintf('%s @ %s — %s %s non e\' in catalogo', $r['username'], $r['istituto'], $kind, $code);
            continue;
        }
        $daFare[] = ['uid' => $uid, 'iid' => $iid, 'kind' => $kind, 'code' => $code,
                     'chi' => (string)$r['username'], 'dove' => (string)$r['istituto']];
    }
}

printf("%d incarichi esaminati.\n\n", count($incarichi));

if ($daFare === []) {
    echo "Ogni docente ha gia' le voci dei suoi incarichi.\n";
} else {
    printf("%d voci da attivare:\n", count($daFare));
    foreach ($daFare as $d) {
        printf("  %-22s %-28s %-10s %s\n", $d['chi'], mb_substr($d['dove'], 0, 26), $d['kind'], $d['code']);
    }
}

if ($scoperti !== []) {
    echo "\nIncarichi che puntano fuori dal catalogo della scuola (non toccati):\n";
    foreach (array_unique($scoperti) as $s) {
        echo '  ' . $s . "\n";
    }
}

if ($daFare === []) {
    exit(0);
}

if (!$apply) {
    echo "\nANTEPRIMA (nessuna modifica). Per scrivere: --apply\n";
    exit(0);
}

$fatte = 0;
foreach ($daFare as $d) {
    if (CurriculumLookup::ensureEntryForTeacher($d['kind'], $d['uid'], $d['code'], $d['iid']) !== null) {
        $fatte++;
    }
}
printf("\nFATTO — %d voci attivate.\n", $fatte);
