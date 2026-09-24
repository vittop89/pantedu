<?php

declare(strict_types=1);

/**
 * SOLO PER IL DATABASE DI SVILUPPO — porta le etichette dei contenuti di un
 * docente dal vocabolario di una scuola a quello di un'altra, per codice.
 *
 * PERCHE' ESISTE
 *   Il database di sviluppo viene da un dump in cui 37 verifiche del docente di
 *   prova hanno le etichette dell'istituto 108, mentre tutti gli altri suoi
 *   contenuti stanno nel 106; in produzione stanno tutti nel 106 (fase 0 di
 *   ADR-037, 13/9/2026). Finché i contenuti si trovavano per sigle, la pagina
 *   di studio del 106 le mostrava lo stesso. Da ADR-037 un contenuto sta nella
 *   scuola in cui è pubblicato, e la suite end-to-end, che sceglie le pagine in
 *   una scuola sola, non trova più verifiche con figure accanto agli esercizi.
 *
 * NON E' «SPOSTA DI SCUOLA»
 *   Fra due scuole un contenuto non si sposta: si pubblica anche nell'altra o
 *   se ne fa una copia (ADR-037). Questo strumento ripara un dato di sviluppo,
 *   e per questo si rifiuta di scrivere se l'ambiente non è di sviluppo.
 *
 * USO
 *   php tools/dev/e2e_allinea_scuola.php --docente=<username> --da=108 --a=106
 *   php tools/dev/e2e_allinea_scuola.php --docente=<username> --da=108 --a=106 --apply
 *
 *   Senza --apply elenca e basta. Con --apply, in una transazione. I trigger
 *   della migrazione 117 portano le pubblicazioni dietro alle etichette.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$opts = getopt('', ['docente:', 'da:', 'a:', 'apply']);
$docente = is_string($opts['docente'] ?? null) ? (string)$opts['docente'] : '';
$da = (int)($opts['da'] ?? 0);
$a  = (int)($opts['a'] ?? 0);
$apply = isset($opts['apply']);
if ($docente === '' || $da <= 0 || $a <= 0 || $da === $a) {
    fwrite(STDERR, "Uso: php tools/dev/e2e_allinea_scuola.php --docente=<username> --da=<id> --a=<id> [--apply]\n");
    exit(1);
}
// Un'installazione vera tiene i dati fuori dal repository (PANTEDU_DATA_PATH):
// è la stessa prova di tools/ops/diagnostica.php, che non si fida di APP_ENV
// perché vale 'production' anche in locale.
$installazioneVera = !str_starts_with(
    (string)\App\Core\Config::get('app.paths.data_base'),
    rtrim((string)\App\Core\Config::get('app.paths.base'), '/\\'),
);
if ($apply && $installazioneVera) {
    fwrite(STDERR, "Rifiutato: questo strumento scrive solo su un database di sviluppo.\n");
    exit(1);
}
if (!Database::isAvailable()) {
    fwrite(STDERR, "Database non disponibile.\n");
    exit(2);
}
$pdo = Database::connection();
$st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([$docente]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) {
    fwrite(STDERR, "Docente non trovato.\n");
    exit(1);
}

/** Voce equivalente nell'altra scuola: stesso kind, stesso codice e, per un anno, stesso corso (ADR-042). */
$equivalente = $pdo->prepare(
    'SELECT b.id FROM curriculum_entries x
       JOIN curriculum_entries b ON b.kind = x.kind AND b.code = x.code AND b.corso_anno <=> x.corso_anno AND b.institute_id = ?
      WHERE x.id = ? AND x.institute_id = ?'
);
$righe = $pdo->prepare(
    'SELECT d.id, d.content_subtype, d.title, d.indirizzo_id, d.classe_id, d.subject_id
       FROM teacher_content d
      WHERE d.teacher_id = ?
        AND (EXISTS (SELECT 1 FROM curriculum_entries e WHERE e.id = d.subject_id AND e.institute_id = ?)
          OR EXISTS (SELECT 1 FROM curriculum_entries e WHERE e.id = d.classe_id AND e.institute_id = ?)
          OR EXISTS (SELECT 1 FROM curriculum_entries e WHERE e.id = d.indirizzo_id AND e.institute_id = ?))
      ORDER BY d.id'
);
$righe->execute([$uid, $da, $da, $da]);
$daSpostare = [];
$senza = [];
foreach ($righe->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $nuovi = [];
    $ok = true;
    foreach (['indirizzo_id', 'classe_id', 'subject_id'] as $col) {
        if ($r[$col] === null) {
            $nuovi[$col] = null;
            continue;
        }
        $equivalente->execute([$a, (int)$r[$col], $da]);
        $id = $equivalente->fetchColumn();
        if ($id === false) {
            $ok = false;
            break;
        }
        $nuovi[$col] = (int)$id;
    }
    if ($ok) {
        $daSpostare[(int)$r['id']] = $nuovi;
    } else {
        $senza[] = (int)$r['id'];
    }
}

printf("contenuti con etichette dell'istituto %d: %d; con equivalenti nell'istituto %d: %d; senza: %d%s\n",
    $da, count($daSpostare) + count($senza), $a, count($daSpostare), count($senza),
    $senza !== [] ? ' (' . implode(', ', array_slice($senza, 0, 20)) . ')' : '');

if (!$apply) {
    echo "Prova a secco: niente scritto. Aggiungi --apply per applicare.\n";
    exit(0);
}
// ADR-037, fase 4c-2: il posto e' la pubblicazione principale; la riga cambia
// solo updated_at.
$upd = $pdo->prepare('UPDATE teacher_content_data SET updated_at = NOW() WHERE id = ? AND teacher_id = ?');
$pdo->beginTransaction();
try {
    foreach ($daSpostare as $id => $n) {
        \App\Support\PostoPrincipale::contenuto($pdo, $id, $n['indirizzo_id'], $n['classe_id'], $n['subject_id']);
        $upd->execute([$id, $uid]);
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Fallito, niente scritto: ' . $e->getMessage() . "\n");
    exit(1);
}
printf("Applicato: %d contenuti ora hanno le etichette dell'istituto %d.\n", count($daSpostare), $a);
