<?php

declare(strict_types=1);

/**
 * Assegna incarichi docente→sezione da riga di comando.
 *
 * PERCHE'
 *   Gli incarichi si danno da /admin/sections, ed e' li' che devono stare: e'
 *   una decisione della scuola, non un'operazione di manutenzione. Questo
 *   serve per i casi in cui le righe sono molte e note in anticipo — la prima
 *   configurazione di un istituto, o un ripristino — dove passare otto volte
 *   da un modulo e' solo occasione di sbagliarne una.
 *
 *   Passa dallo stesso servizio del pannello, quindi valgono gli stessi
 *   controlli: sigle ben formate, docente collegato all'istituto, e
 *   l'attivazione delle voci di curriculum che segue l'incarico.
 *
 * USO
 *   php tools/curriculum/assegna_incarichi.php --institute=106 \
 *       --teacher=superadmin --sezioni=SCI:1A,SCI:2A,AAA:3AR
 *   ... --apply   per scrivere
 *
 * NON REVOCA
 *   Aggiunge soltanto. Togliere un incarico si fa dal pannello, dove si vede
 *   cosa comporta per gli studenti di quella sezione.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\TeacherSectionService;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$apply    = in_array('--apply', $argv, true);
$istituto = 0;
$docente  = '';
$sezioni  = [];
foreach ($argv as $a) {
    if (preg_match('/^--institute=(\d+)$/', $a, $m)) {
        $istituto = (int)$m[1];
    } elseif (preg_match('/^--teacher=(.+)$/', $a, $m)) {
        $docente = $m[1];
    } elseif (preg_match('/^--sezioni=(.+)$/', $a, $m)) {
        foreach (explode(',', $m[1]) as $coppia) {
            $coppia = trim($coppia);
            if (preg_match('/^([A-Z]{2,6}):([1-9][A-Z0-9]{0,5})$/', $coppia, $c)) {
                $sezioni[] = [$c[1], $c[2]];
            } elseif ($coppia !== '') {
                fwrite(STDERR, "Coppia non valida: '$coppia' (forma attesa: INDIRIZZO:SEZIONE)\n");
                exit(1);
            }
        }
    }
}
if ($istituto <= 0 || $docente === '' || $sezioni === []) {
    fwrite(STDERR, "Servono --institute=<id>, --teacher=<username> e --sezioni=IND:SEZ,...\n");
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

// Che la sezione esista davvero nel catalogo della scuola: un incarico verso
// una sezione inventata non raggiunge nessuno e non se ne accorge nessuno.
$st = $pdo->prepare("SELECT code, indirizzo FROM curriculum_entries
                      WHERE kind = 'classi' AND owner_user_id IS NULL AND institute_id = ?");
$st->execute([$istituto]);
$catalogo = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $catalogo[(string)$r['code']] = (string)($r['indirizzo'] ?? '');
}

$st = $pdo->prepare('SELECT indirizzo, classe FROM teacher_sections WHERE user_id = ? AND institute_id = ?');
$st->execute([$uid, $istituto]);
$gia = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gia[$r['indirizzo'] . ':' . $r['classe']] = true;
}

$daFare  = [];
$problemi = [];
foreach ($sezioni as [$ind, $sez]) {
    if (!isset($catalogo[$sez])) {
        $problemi[] = "$ind:$sez — la sezione non e' a catalogo in questo istituto";
        continue;
    }
    if ($catalogo[$sez] !== '' && $catalogo[$sez] !== $ind) {
        $problemi[] = "$ind:$sez — a catalogo la sezione appartiene a {$catalogo[$sez]}";
        continue;
    }
    if (isset($gia["$ind:$sez"])) {
        continue;
    }
    $daFare[] = [$ind, $sez];
}

if ($problemi !== []) {
    echo "Non torna:\n";
    foreach ($problemi as $p) {
        echo '  ' . $p . "\n";
    }
    echo "\n";
}
if ($daFare === []) {
    echo $problemi === [] ? "Gli incarichi ci sono gia' tutti.\n" : "Niente da assegnare.\n";
    exit($problemi === [] ? 0 : 1);
}

printf("%d incarichi da assegnare a %s nell'istituto %d:\n", count($daFare), $docente, $istituto);
foreach ($daFare as [$ind, $sez]) {
    printf("  %-5s %s\n", $ind, $sez);
}

if (!$apply) {
    echo "\nANTEPRIMA (nessuna modifica). Per scrivere: --apply\n";
    exit($problemi === [] ? 0 : 1);
}

$svc = new TeacherSectionService($pdo);
$fatti = 0;
foreach ($daFare as [$ind, $sez]) {
    $svc->assign($uid, $istituto, $ind, $sez);
    $fatti++;
}
printf("\nFATTO — %d incarichi assegnati.\n", $fatti);
