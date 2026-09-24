<?php

declare(strict_types=1);

/**
 * URL di studio con contenuti REALI per un docente, in JSON, per la suite E2E.
 *
 *   php tools/dev/e2e_urls.php [username]
 *
 * Stampa {"eser_url": "/studio/esercizio/SCI/2/MAT/2.0", "eser_ids_url": "...?ids=53",
 *         "verif_list_url": "/studio/verifica/SCI/2/MAT", "verif_url": "...", "mappa_url": "...",
 *         "verif_rm_url": "...", "eser_related_url": "..."}
 * scegliendo, per ogni tipo, la terna+argomento con piu' righe in teacher_content.
 * Lo usa tests/e2e/global-setup.js per riempire FM_E2E_ESER_URL e simili: le spec
 * non dipendono piu' da URL scritte a mano che nel DB locale possono essere vuote.
 * Le chiavi assenti (docente senza contenuti di quel tipo) restano fuori dal JSON.
 *
 * Chiavi aggiunte il 2026-09-05:
 *   verif_rm_list_url / verif_rm_url  verifica con almeno un gruppo RM (tabelle
 *                                     vero/falso o a scelta) nel contratto;
 *   eser_related_url                  esercizio il cui titolo coincide con
 *                                     l'argomento di una verifica della stessa
 *                                     materia (la «verifica correlata» che la
 *                                     modalita' verifica carica in #type_verAll).
 * Gli argomenti lasciati dai test (prova*, ZZ*, test*) vengono scartati quando
 * esiste un'alternativa.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$username = (string)($argv[1] ?? 'docente.uno');
if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile\n");
    exit(1);
}
$pdo = Database::connection();
$st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([$username]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) {
    fwrite(STDERR, "utente non trovato: $username\n");
    exit(1);
}

// Indirizzi, classi e materie stanno tutti in curriculum_entries (kind, code):
// gli id nelle righe di teacher_content sono FK a quella tabella.
$codes = [];
foreach ($pdo->query('SELECT id, code FROM curriculum_entries') as $r) {
    $codes[(int)$r['id']] = (string)$r['code'];
}

$isLeftover = static fn(string $topic): bool => (bool)preg_match('/^(prova|zz|test)/i', trim($topic));

// ADR-037, fase 1 (2026-09-13) — un contenuto sta nella scuola in cui è
// pubblicato. Le pagine si scelgono in UNA scuola del docente — quella dove ha
// più contenuti, a parità la prima — e la fixture del docente riporta la
// sessione lì a ogni prova (`scuola_id` qui sotto). Prima la scoperta sommava
// le terne per sigle fra le scuole: sul database di sviluppo sceglieva una
// verifica del 108 mentre la sessione stava nel 106, e la pagina di studio,
// che adesso guarda la scuola, non la mostrava più.
$conPubblicazioni = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_publications'"
)->fetchColumn();
$scuola = 0;
if ($conPubblicazioni) {
    $st = $pdo->prepare(
        'SELECT p.institute_id FROM content_publications p
           JOIN teacher_content_data d ON d.id = p.teacher_content_id
           JOIN teacher_institutes ti ON ti.user_id = d.teacher_id AND ti.institute_id = p.institute_id
          WHERE d.teacher_id = ? AND p.is_primary = 1
          GROUP BY p.institute_id
          ORDER BY COUNT(*) DESC, p.institute_id
          LIMIT 1'
    );
    $st->execute([$uid]);
    $scuola = (int)$st->fetchColumn();
}
if ($scuola <= 0) {
    $st = $pdo->prepare('SELECT institute_id FROM teacher_institutes WHERE user_id = ? ORDER BY institute_id LIMIT 1');
    $st->execute([$uid]);
    $scuola = (int)$st->fetchColumn();
}
$nellaScuola = ($scuola > 0 && $conPubblicazioni)
    ? ' AND EXISTS (SELECT 1 FROM content_publications p WHERE p.teacher_content_id = teacher_content.id AND p.is_primary = 1 AND p.institute_id = ' . $scuola . ')'
    : '';

$url = static function (string $type, string $i, string $c, string $s, ?string $topic = null): string {
    $base = sprintf('/studio/%s/%s/%s/%s', rawurlencode($type), rawurlencode($i), rawurlencode($c), rawurlencode($s));
    return $topic === null ? $base : $base . '/' . rawurlencode($topic);
};

$rows = $pdo->prepare(
    'SELECT content_type, indirizzo_id, classe_id, subject_id, subject_code, topic, title,
            COUNT(*) AS n, MIN(id) AS first_id,
            SUM(metadata_json LIKE \'%"RM"%\') AS rm
       FROM teacher_content WHERE teacher_id = ? AND topic IS NOT NULL AND topic <> \'\'' . $nellaScuola . '
      GROUP BY content_type, indirizzo_id, classe_id, subject_id, subject_code, topic, title
      ORDER BY n DESC, first_id ASC'
);
$rows->execute([$uid]);
$all = [];
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $i = $codes[(int)$r['indirizzo_id']] ?? '';
    $c = $codes[(int)$r['classe_id']] ?? '';
    $s = $codes[(int)$r['subject_id']] ?? '';
    if ($i === '' || $c === '' || $s === '') {
        continue;
    }
    $all[] = [
        'type'     => (string)$r['content_type'],
        'i'        => $i,
        'c'        => $c,
        's'        => $s,
        'subject'  => (string)($r['subject_code'] ?? $s),
        'topic'    => (string)$r['topic'],
        'title'    => (string)$r['title'],
        'n'        => (int)$r['n'],
        'id'       => (int)$r['first_id'],
        'rm'       => (int)$r['rm'],
        'leftover' => $isLeftover((string)$r['topic']),
    ];
}

// Per tipo: terna con piu' contenuti (somma delle righe), poi argomento con piu' righe;
// i residui dei test contano solo se non c'e' altro.
$pick = static function (array $items, callable $ok) {
    $real = array_values(array_filter($items, static fn($x) => !$x['leftover'] && $ok($x)));
    $cand = $real !== [] ? $real : array_values(array_filter($items, $ok));
    if ($cand === []) {
        return null;
    }
    $byTerna = [];
    foreach ($cand as $x) {
        $byTerna["{$x['i']}/{$x['c']}/{$x['s']}"] = ($byTerna["{$x['i']}/{$x['c']}/{$x['s']}"] ?? 0) + $x['n'];
    }
    arsort($byTerna);
    $terna = array_key_first($byTerna);
    $inTerna = array_values(array_filter($cand, static fn($x) => "{$x['i']}/{$x['c']}/{$x['s']}" === $terna));
    usort($inTerna, static fn($a, $b) => [$b['n'], $a['id']] <=> [$a['n'], $b['id']]);
    return $inTerna[0];
};

$out = ['user' => $username];
if ($scuola > 0) {
    $out['scuola_id'] = $scuola;
}

$eser = $pick($all, static fn($x) => $x['type'] === 'esercizio');
if ($eser !== null) {
    $out['eser_list_url'] = $url('esercizio', $eser['i'], $eser['c'], $eser['s']);
    $out['eser_url']      = $url('esercizio', $eser['i'], $eser['c'], $eser['s'], $eser['topic']);
    $out['eser_ids_url']  = $out['eser_url'] . '?ids=' . $eser['id'];
}
$verif = $pick($all, static fn($x) => $x['type'] === 'verifica');
if ($verif !== null) {
    $out['verif_list_url'] = $url('verifica', $verif['i'], $verif['c'], $verif['s']);
    $out['verif_url']      = $url('verifica', $verif['i'], $verif['c'], $verif['s'], $verif['topic']);
}
$mappa = $pick($all, static fn($x) => $x['type'] === 'mappa');
if ($mappa !== null) {
    $out['mappa_list_url'] = $url('mappa', $mappa['i'], $mappa['c'], $mappa['s']);
    $out['mappa_url']      = $url('mappa', $mappa['i'], $mappa['c'], $mappa['s'], $mappa['topic']);
}

// Verifica con gruppo RM: preferisce «Sistemi lineari» (misto vero/falso e scelta
// singola, usata dalle spec storiche), altrimenti la prima con RM.
$rmAll = array_values(array_filter($all, static fn($x) => $x['type'] === 'verifica' && $x['rm'] > 0 && !$x['leftover']));
usort($rmAll, static fn($a, $b) => [(int)(stripos($b['topic'], 'sistemi lineari') !== false), $b['n']] <=> [(int)(stripos($a['topic'], 'sistemi lineari') !== false), $a['n']]);
if ($rmAll !== []) {
    $rm = $rmAll[0];
    $out['verif_rm_list_url'] = $url('verifica', $rm['i'], $rm['c'], $rm['s']);
    $out['verif_rm_url']      = $url('verifica', $rm['i'], $rm['c'], $rm['s'], $rm['topic']);
    $out['verif_rm_topic']    = $rm['topic'];
}

// Esercizio con verifica correlata: stessa materia, titolo dell'esercizio =
// argomento della verifica (e' cosi' che /api/study/related-verifiche.html abbina).
$verifTopics = [];
foreach ($all as $x) {
    if ($x['type'] === 'verifica') {
        $verifTopics[$x['subject'] . '|' . mb_strtolower(trim($x['topic']))] = true;
    }
}
$related = array_values(array_filter($all, static fn($x) => $x['type'] === 'esercizio' && !$x['leftover']
    && isset($verifTopics[$x['subject'] . '|' . mb_strtolower(trim($x['title']))])));
usort($related, static fn($a, $b) => [(int)(stripos($a['title'], 'sistemi lineari') !== false), $a['n']] <=> [(int)(stripos($b['title'], 'sistemi lineari') !== false), $b['n']]);
$related = array_reverse($related);
if ($related !== []) {
    $rel = $related[0];
    $out['eser_related_url']   = $url('esercizio', $rel['i'], $rel['c'], $rel['s'], $rel['topic']);
    $out['eser_related_title'] = $rel['title'];
}

// Coppia esercizio↔verifica condivisibile nel pool: stessa materia, titolo
// dell'esercizio = argomento della verifica, source_type non protetto dal
// blocco copyright (book_textbook / mixed, Phase 25.P.3). Piu' una mappa.
$pair = $pdo->prepare(
    'SELECT e.id AS eid, v.id AS vid, e.title, e.topic, e.indirizzo, e.classe, e.subject_code
       FROM teacher_content e
       JOIN teacher_content v ON v.teacher_id = e.teacher_id AND v.content_type = \'verifica\'
            AND v.subject_code = e.subject_code AND v.topic = e.title
      WHERE e.teacher_id = ? AND e.content_type = \'esercizio\' AND e.indirizzo_id IS NOT NULL
        AND (e.source_type IS NULL OR e.source_type NOT IN (\'book_textbook\', \'mixed\'))
        AND (v.source_type IS NULL OR v.source_type NOT IN (\'book_textbook\', \'mixed\'))
        AND e.title NOT LIKE \'%importata%\'
      ORDER BY e.id LIMIT 1'
);
$pair->execute([$uid]);
if ($p = $pair->fetch(PDO::FETCH_ASSOC)) {
    $out['share_eser_id']  = (int)$p['eid'];
    $out['share_verif_id'] = (int)$p['vid'];
    $out['share_title']    = (string)$p['title'];
    $out['share_topic']    = (string)$p['topic'];
    $out['share_ind']      = (string)$p['indirizzo'];
    $out['share_cls']      = (string)$p['classe'];
    $out['share_subject']  = (string)$p['subject_code'];
}
// La mappa deve avere il blob cifrato su disco: il recover dal pool lo clona
// (storage/maps_enc/{teacher}/{ulid}.bin) e senza file risponde map_blob_not_found.
$map = $pdo->prepare(
    'SELECT id, title, map_blob_path FROM teacher_content WHERE teacher_id = ? AND content_type = \'mappa\'
        AND indirizzo_id IS NOT NULL AND (source_type IS NULL OR source_type NOT IN (\'book_textbook\', \'mixed\'))
        AND title NOT LIKE \'%importata%\' ORDER BY id'
);
$map->execute([$uid]);
$blobStore = new \App\Services\Maps\MapBlobStore();
foreach ($map->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $rel = (string)($m['map_blob_path'] ?? '');
    if ($rel === '' || !$blobStore->exists($rel)) {
        continue;
    }
    $out['share_mappa_id']    = (int)$m['id'];
    $out['share_mappa_title'] = (string)$m['title'];
    break;
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
