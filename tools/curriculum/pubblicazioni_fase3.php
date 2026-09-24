<?php

declare(strict_types=1);

/**
 * ADR-037, fase 3 — la misura, prima di portare le verifiche nelle
 * pubblicazioni.
 *
 * COSA CONTA (sola lettura, niente si scrive)
 *   1. Verifiche (varianti, cioè righe di verifica_documents_data) con una
 *      scuola dedotta dalle etichette e senza; quante sono condivise con i
 *      colleghi (shared_with_pool).
 *   2. La cifra che decide lo stato iniziale: quante verifiche vede OGGI
 *      ogni studente con account da /api/study/verifica/list, con la regola
 *      di oggi (condivise, di un docente della sua scuola, sezione VERIFICHE,
 *      il suo indirizzo e la sua classe o il suo anno). La condivisione fra
 *      colleghi faceva da pubblicazione agli studenti (il punto aperto di
 *      ADR-032): se lo stato iniziale non la eredita, sono queste le righe
 *      che uno studente smette di vedere.
 *   3. Le credenziali di classe non vedono verifiche oggi (lista vuota per
 *      costruzione): non c'è niente da contare.
 *
 * USO
 *   php tools/curriculum/pubblicazioni_fase3.php
 *   php tools/curriculum/pubblicazioni_fase3.php --json
 *
 *   Nessun nome di persona nell'uscita: gli studenti sono numerati.
 */

$base = getenv('PANTEDU_ROOT') ?: dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';

foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
App\Core\Config::load($base . '/app/Config');

use App\Core\Database;
use App\Domain\ClassCode;

$opts = getopt('', ['json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDERR, "Uso: php tools/curriculum/pubblicazioni_fase3.php [--json]\n");
    exit(0);
}
if (!Database::isAvailable()) {
    fwrite(STDERR, "Database non disponibile.\n");
    exit(2);
}
$pdo = Database::connection();

$scuolaDellaRiga = 'COALESCE(
        (SELECT institute_id FROM curriculum_entries WHERE id = v.materia_id),
        (SELECT institute_id FROM curriculum_entries WHERE id = v.classe_id),
        (SELECT institute_id FROM curriculum_entries WHERE id = v.indirizzo_id))';

$verifiche = $pdo->query(
    "SELECT COUNT(*) AS righe,
            SUM({$scuolaDellaRiga} IS NOT NULL) AS con_scuola,
            SUM({$scuolaDellaRiga} IS NULL) AS senza_scuola,
            SUM(v.shared_with_pool = 1) AS condivise,
            SUM(v.shared_with_pool = 1 AND {$scuolaDellaRiga} IS NOT NULL) AS condivise_con_scuola,
            COUNT(DISTINCT COALESCE(v.batch_id, CONCAT('riga-', v.id))) AS pacchetti
       FROM verifica_documents_data v"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$studenti = $pdo->query(
    "SELECT id, institute_id, indirizzo, classe FROM users
      WHERE role = 'student' AND deleted_at IS NULL
      ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$perStudente = [];
$n = 0;
foreach ($studenti as $s) {
    $n++;
    $scuola = (int)($s['institute_id'] ?? 0);
    $indirizzo = (string)($s['indirizzo'] ?? '');
    $classi = ClassCode::covering((string)($s['classe'] ?? ''));
    $visibili = 0;
    if ($scuola > 0 && $indirizzo !== '' && $classi !== []) {
        $in = implode(',', array_fill(0, count($classi), '?'));
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM verifica_documents vd
               JOIN teacher_institutes ti ON ti.user_id = vd.teacher_id AND ti.institute_id = ?
              WHERE vd.shared_with_pool = 1 AND vd.fm_db_section = 'VERIFICHE'
                AND vd.indirizzo = ? AND vd.classe IN ({$in})"
        );
        $st->execute([$scuola, $indirizzo, ...$classi]);
        $visibili = (int)$st->fetchColumn();
    }
    $perStudente[] = [
        'studente'          => $n,
        'ha_scuola_e_classe' => $scuola > 0 && $indirizzo !== '' && $classi !== [],
        'verifiche_visibili_oggi' => $visibili,
    ];
}

$esito = [
    'verifiche' => array_map('intval', $verifiche),
    'studenti_con_account' => count($studenti),
    'per_studente' => $perStudente,
    'righe_visibili_oggi_a_qualche_studente' => array_sum(array_column($perStudente, 'verifiche_visibili_oggi')),
];

if (isset($opts['json'])) {
    echo json_encode($esito, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

echo "Verifiche (righe = varianti)\n";
foreach ($esito['verifiche'] as $k => $v) {
    printf("  %-22s %d\n", $k, $v);
}
printf("Studenti con account: %d\n", $esito['studenti_con_account']);
foreach ($perStudente as $p) {
    printf(
        "  studente %d: %s, verifiche visibili oggi %d\n",
        $p['studente'],
        $p['ha_scuola_e_classe'] ? 'con scuola e classe' : 'senza scuola o classe',
        $p['verifiche_visibili_oggi']
    );
}
printf("Righe visibili oggi a qualche studente (somma): %d\n", $esito['righe_visibili_oggi_a_qualche_studente']);
