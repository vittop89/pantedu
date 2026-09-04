<?php

declare(strict_types=1);

/**
 * Cancella contenuti scelti per id, dopo averne salvato una copia.
 *
 * PRIMA DI CANCELLARE, SALVA
 *   Ogni riga viene scritta in un file JSON — tutte le colonne, piu' il
 *   contract se c'e'. Non e' zelo: e' successo di perdere l'archiviazione di
 *   184 contenuti per una cancellazione fatta con troppa fiducia, e da allora
 *   qui dentro non si cancella niente senza una via di ritorno.
 *
 *   Il file va indicato con --dump=<percorso>. Senza, non si parte.
 *
 * COSA CANCELLA
 *   Solo la riga in `teacher_content_data`, come fa l'applicazione: il file
 *   contract sullo storage resta dov'e'. E' voluto — un contenuto cancellato
 *   per errore si ricostruisce da li'.
 *
 * COSA NON FA
 *   Non cancella niente che non sia del docente indicato, e nemmeno un id in
 *   piu' di quelli elencati: niente filtri, niente "tutti quelli che...".
 *   L'elenco lo scrive una persona.
 *
 * USO
 *   php tools/contenuti/elimina.php --teacher=superadmin \
 *       --ids=100,101 --dump=/var/tmp/prima.json
 *   ... --apply   per cancellare davvero
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\TeacherContentRepository;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$apply = in_array('--apply', $argv, true);
$opt   = ['teacher' => '', 'ids' => '', 'dump' => ''];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    }
}
$ids = array_values(array_filter(array_map('intval', explode(',', $opt['ids'])), static fn(int $i): bool => $i > 0));
if ($opt['teacher'] === '' || $ids === [] || $opt['dump'] === '') {
    fwrite(STDERR, "Servono --teacher=<username>, --ids=1,2,3 e --dump=<file.json>\n");
    exit(1);
}
if (is_file($opt['dump'])) {
    fwrite(STDERR, "Il file {$opt['dump']} esiste gia': scegline un altro, non lo sovrascrivo.\n");
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

$ph = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare("SELECT * FROM teacher_content_data WHERE teacher_id = ? AND id IN ($ph) ORDER BY id");
$st->execute(array_merge([$uid], $ids));
$righe = $st->fetchAll(PDO::FETCH_ASSOC);

if (count($righe) !== count($ids)) {
    $mancanti = array_diff($ids, array_map('intval', array_column($righe, 'id')));
    fwrite(STDERR, 'Non sono di questo docente (o non esistono): ' . implode(', ', $mancanti) . "\n");
    exit(1);
}

printf("%d contenuti di %s da cancellare:\n", count($righe), $opt['teacher']);
foreach ($righe as $r) {
    $meta = json_decode((string)($r['metadata_json'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];
    printf("  %-6s %-10s %-42s item=%-4s contract=%s\n",
        $r['id'], $r['content_subtype'], mb_substr((string)$r['title'], 0, 40),
        $meta['stats']['item_count'] ?? '-',
        isset($meta['contract_key']) ? 'resta sullo storage' : 'nessuno');
}

if (!$apply) {
    echo "\nANTEPRIMA (nessuna modifica). Per cancellare: --apply\n";
    exit(0);
}

// La copia si scrive PRIMA, e se non riesce non si cancella niente.
$scritti = file_put_contents(
    $opt['dump'],
    json_encode(['salvato_il' => date('c'), 'docente' => $opt['teacher'], 'righe' => $righe],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
if ($scritti === false || $scritti === 0) {
    fwrite(STDERR, "Non sono riuscito a scrivere la copia in {$opt['dump']}: non cancello niente.\n");
    exit(1);
}
printf("\nCopia salvata in %s (%d byte).\n", $opt['dump'], $scritti);

$repo = new TeacherContentRepository();
$fatti = 0;
foreach ($righe as $r) {
    if ($repo->delete((int)$r['id'], $uid)) {
        $fatti++;
    }
}
printf("FATTO — %d contenuti cancellati.\n", $fatti);
