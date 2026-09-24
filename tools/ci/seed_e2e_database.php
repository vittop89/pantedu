<?php

declare(strict_types=1);

/**
 * Semina un database VUOTO con il minimo che la suite end-to-end si aspetta di
 * trovare (2026-09-07).
 *
 *   PANTEDU_SEED_CI=1 php tools/ci/seed_e2e_database.php
 *
 * La suite non crea utenti né istituti: li cerca, e crea solo i contenuti che
 * le servono, cancellandoli alla fine (docs/ops/e2e-runner-prerequisiti.md).
 * Finché quel minimo vive solo nel dump di sviluppo, la suite non può girare in
 * integrazione continua né sulla macchina di chi arriva nuovo. Questo script è
 * quel minimo, scritto una volta:
 *
 *   - due istituti, perché un test verifica che le classi di uno non si
 *     sommino a quelle dell'altro (per questo hanno classi diverse);
 *   - tre utenti: due docenti (il secondo serve ai test di isolamento: senza,
 *     non si può verificare che le cose di uno non si vedano dall'altro) e un
 *     amministratore, che è anche super-amministratore (`is_super_admin`);
 *   - il primo docente collegato a entrambi gli istituti, il secondo al primo;
 *   - le voci di curriculum, con la terna di riferimento SCI / 2 / MAT che
 *     `tests/e2e/support/env.ts` usa quando il setup non scopre niente.
 *
 * Sulle voci di curriculum: il vocabolario è dell'istituto — una voce per
 * (kind, code, institute_id), ADR-035 — e i docenti lo spuntano in
 * `curriculum_teacher`. Qui si creano tutte e due le cose; agli esercizi del
 * catalogo comune si agganciano le voci dell'istituto.
 *
 * Le password arrivano dall'ambiente e non hanno bisogno di essere segrete: il
 * database è usa-e-getta e si genera a ogni giro.
 *
 * Prima di questo script vanno eseguiti, in quest'ordine:
 *   mysql < database/schema.sql
 *   php tools/migrate.php
 *
 * Dopo, `php tools/dev/e2e_prepare_users.php` registra l'accettazione dei
 * Termini di servizio, senza la quale ogni richiesta finisce su /tos-acceptance.
 *
 * GUARDIA: gira solo con PANTEDU_SEED_CI=1 e mai con APP_ENV=production.
 * Crea utenti con password note: non deve avvicinarsi a un database vero.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

// ── Guardia ───────────────────────────────────────────────────────────────
if (($_ENV['PANTEDU_SEED_CI'] ?? getenv('PANTEDU_SEED_CI')) !== '1') {
    fwrite(STDERR, "RIFIUTO: manca PANTEDU_SEED_CI=1.\n");
    fwrite(STDERR, "Questo script crea utenti con password note: si esegue solo su un database usa-e-getta.\n");
    exit(2);
}
if ((string)($_ENV['APP_ENV'] ?? '') === 'production') {
    fwrite(STDERR, "RIFIUTO: APP_ENV=production.\n");
    exit(2);
}
if (!Config::get('database.enabled') || !Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile: controlla le chiavi DB_* dell'ambiente.\n");
    exit(1);
}

$pdo = Database::connection();

/** Legge una variabile d'ambiente, con un ripiego. */
$env = static function (string $name, string $default = ''): string {
    $v = (string)($_ENV[$name] ?? getenv($name) ?: '');
    return $v !== '' ? $v : $default;
};

$teacherUser  = $env('E2E_TEACHER_USER', 'docente.uno');
$teacherPass  = $env('E2E_TEACHER_PASS');
$teacher2User = $env('E2E_TEACHER2_USER', 'docente.due');
$teacher2Pass = $env('E2E_TEACHER2_PASS');
$adminUser    = $env('FM_E2E_ADMIN_USERNAME', 'admin');
$adminPass    = $env('FM_E2E_ADMIN_PASSWORD');

foreach ([
    'E2E_TEACHER_PASS' => $teacherPass,
    'E2E_TEACHER2_PASS' => $teacher2Pass,
    'FM_E2E_ADMIN_PASSWORD' => $adminPass,
] as $nome => $valore) {
    if ($valore === '') {
        fwrite(STDERR, "Manca la password in $nome.\n");
        exit(1);
    }
}

// ── 1. Due istituti ───────────────────────────────────────────────────────
$istituti = [
    ['E2E-UNO', 'Istituto di prova uno', 'Milano', 'Lombardia'],
    ['E2E-DUE', 'Istituto di prova due', 'Lecco', 'Lombardia'],
];
$insIst = $pdo->prepare(
    'INSERT INTO institutes (code, name, city, region, active) VALUES (?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1'
);
$selIst = $pdo->prepare('SELECT id FROM institutes WHERE code = ? LIMIT 1');
$idIstituto = [];
foreach ($istituti as [$code, $name, $city, $region]) {
    $insIst->execute([$code, $name, $city, $region]);
    $selIst->execute([$code]);
    $idIstituto[$code] = (int)$selIst->fetchColumn();
}
echo '[1] Istituti: ' . implode(', ', array_keys($idIstituto)) . " ✓\n";

// ── 2. I tre utenti ───────────────────────────────────────────────────────
/**
 * Crea o aggiorna un utente e ne restituisce l'id.
 *
 * `is_super_admin` è una colonna a parte dal ruolo: `Auth::role()` risponde
 * 'super_admin' guardando quella, e le rotte di amministrazione delle risorse
 * la pretendono. Senza, l'amministratore riceve 403 dove dovrebbe entrare.
 *
 * @param array{0:string,1:string,2:string,3:string,4:string,5:bool} $dati
 *        username, password, ruolo, nome, cognome, super-amministratore
 */
$creaUtente = static function (\PDO $pdo, array $dati): int {
    [$username, $password, $ruolo, $nome, $cognome, $super] = $dati;
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $st = $pdo->prepare(
        "INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                            status, active, is_super_admin, approved_at)
         VALUES (:u, :r, :n, :c, :e, :h, 'approved', 1, :s, NOW())
         ON DUPLICATE KEY UPDATE
             role = VALUES(role), password_hash = VALUES(password_hash),
             status = 'approved', active = 1, is_super_admin = VALUES(is_super_admin)"
    );
    $st->execute([
        ':u' => $username,
        ':r' => $ruolo,
        ':n' => $nome,
        ':c' => $cognome,
        ':e' => $username . '@e2e.invalid',
        ':h' => $hash,
        ':s' => $super ? 1 : 0,
    ]);
    $sel = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $sel->execute([$username]);
    return (int)$sel->fetchColumn();
};

$idDocente  = $creaUtente($pdo, [$teacherUser,  $teacherPass,  'teacher',       'Docente', 'Uno',       false]);
$idDocente2 = $creaUtente($pdo, [$teacher2User, $teacher2Pass, 'teacher',       'Docente', 'Due',       false]);
$idAdmin    = $creaUtente($pdo, [$adminUser,    $adminPass,    'administrator', 'Ammini',  'Stratore',  true]);
echo "[2] Utenti: $teacherUser ($idDocente), $teacher2User ($idDocente2), $adminUser ($idAdmin) ✓\n";

// ── 3. Chi lavora in quale istituto ───────────────────────────────────────
// Il primo docente sta in tutti e due: senza, il test che verifica che le
// classi di un istituto non si sommino a quelle dell'altro non ha niente da
// confrontare.
$insTi = $pdo->prepare(
    'INSERT INTO teacher_institutes (user_id, institute_id, role_at_inst)
     VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role_at_inst = VALUES(role_at_inst)'
);
foreach ($idIstituto as $idIst) {
    $insTi->execute([$idDocente, $idIst, 'docente']);
}
$insTi->execute([$idDocente2, $idIstituto['E2E-UNO'], 'docente']);
echo "[3] Il primo docente è in tutti e due gli istituti, il secondo nel primo ✓\n";

// ── 4. Voci di curriculum ─────────────────────────────────────────────────
// Le due liste sono diverse di proposito: le classi del secondo istituto non
// devono comparire quando si guarda il primo.
$vociPerIstituto = [
    // Le classi dell'istituto principale sono gli anni «1»..«5» e nient'altro:
    // un test verifica che fra le scelte non tornino le sigle vecchie («1s»,
    // «2b»), un altro che non siano più di cinque. Dal 15/9/2026 (ADR-042) un
    // anno appartiene a un corso: cinque per lo scientifico, cinque per
    // l'artistico.
    'E2E-UNO' => [
        ['indirizzi', 'SCI', 'Scientifico'],
        ['indirizzi', 'ART', 'Artistico'],
        ['classi',    '1',   'Prima',   'SCI'],
        ['classi',    '2',   'Seconda', 'SCI'],
        ['classi',    '3',   'Terza',   'SCI'],
        ['classi',    '4',   'Quarta',  'SCI'],
        ['classi',    '5',   'Quinta',  'SCI'],
        ['classi',    '1',   'Prima',   'ART'],
        ['classi',    '2',   'Seconda', 'ART'],
        ['classi',    '3',   'Terza',   'ART'],
        ['classi',    '4',   'Quarta',  'ART'],
        ['classi',    '5',   'Quinta',  'ART'],
        ['materie',   'MAT', 'Matematica'],
        ['materie',   'FIS', 'Fisica'],
    ],
    // Il secondo istituto ha sezioni sue: servono a verificare che non si
    // sommino a quelle del primo quando si cambia istituto.
    'E2E-DUE' => [
        ['indirizzi', 'SCI', 'Scientifico'],
        ['classi',    '1A',  'Prima A'],
        ['classi',    '2A',  'Seconda A'],
        ['materie',   'MAT', 'Matematica'],
    ],
];

$insCur = $pdo->prepare(
    'INSERT INTO curriculum_entries (kind, code, label, institute_id, indirizzo, active, origine)
     VALUES (:k, :c, :l, :i, :ind, 1, "istituto")
     ON DUPLICATE KEY UPDATE label = VALUES(label), indirizzo = VALUES(indirizzo), active = 1'
);
// ADR-035 — il vocabolario è della scuola; i docenti lo SPUNTANO
// (curriculum_teacher), non ne hanno una copia. ADR-042 — un anno si trova
// con il suo corso (`corso_anno`, vuoto per tutto il resto).
$selCur = $pdo->prepare(
    'SELECT id FROM curriculum_entries
      WHERE kind = ? AND code = ? AND institute_id = ? AND corso_anno = ? LIMIT 1'
);
$insSpunta = $pdo->prepare(
    'INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)
     ON DUPLICATE KEY UPDATE active = 1'
);
$create = 0;
foreach ($vociPerIstituto as $codiceIstituto => $voci) {
    $idIst = $idIstituto[$codiceIstituto];
    // Chi la spunta: ogni docente che lavora nell'istituto.
    $docenti = [$idDocente];
    if ($codiceIstituto === 'E2E-UNO') {
        $docenti[] = $idDocente2;
    }
    foreach ($voci as $voce) {
        [$kind, $code, $label] = $voce;
        $corso = $voce[3] ?? null;
        $insCur->execute([':k' => $kind, ':c' => $code, ':l' => $label, ':i' => $idIst, ':ind' => $corso]);
        $create++;
        $selCur->execute([$kind, $code, $idIst, $kind === 'classi' && preg_match('/^[1-9]$/', $code) === 1 ? (string)$corso : '']);
        $idVoce = (int)$selCur->fetchColumn();
        foreach ($docenti as $idProprietario) {
            $insSpunta->execute([$idVoce, $idProprietario]);
        }
    }
}
// ADR-041 — gli istituti partono da «solo incaricati»: una sezione la usa il
// docente che ne ha l'incarico. Le sezioni del secondo istituto le spunta il
// docente di prova, quindi ne ha l'incarico, come in produzione dove ogni
// sezione spuntata è coperta da un incarico (misurato il 14/9/2026).
$insIncarico = $pdo->prepare(
    "INSERT IGNORE INTO teacher_sections (user_id, institute_id, indirizzo, classe, note)
     VALUES (?, ?, 'SCI', ?, 'semina della suite end-to-end')"
);
foreach ($vociPerIstituto['E2E-DUE'] as [$kind, $code]) {
    if ($kind === 'classi' && preg_match('/^[1-9][A-Z0-9]+$/', $code) === 1) {
        $insIncarico->execute([$idDocente, $idIstituto['E2E-DUE'], $code]);
    }
}
// ADR-043 — anche un anno si usa con l'incarico: i due docenti di prova hanno
// quello di ogni anno che spuntano nell'istituto principale, nel suo corso.
$insIncaricoAnno = $pdo->prepare(
    "INSERT IGNORE INTO teacher_sections (user_id, institute_id, indirizzo, classe, note)
     VALUES (?, ?, ?, ?, 'semina della suite end-to-end')"
);
foreach ($vociPerIstituto['E2E-UNO'] as $voce) {
    if ($voce[0] === 'classi' && preg_match('/^[1-9]$/', $voce[1]) === 1 && isset($voce[3])) {
        foreach ([$idDocente, $idDocente2] as $chi) {
            $insIncaricoAnno->execute([$chi, $idIstituto['E2E-UNO'], $voce[3], $voce[1]]);
        }
    }
}
// Un indirizzo che la scuola ha e il docente no: il modulo «aggiungi
// indirizzo» del profilo compare solo se resta qualcosa da scegliere, perché
// gli indirizzi li definisce la scuola e il docente sceglie fra quelli
// (`js/entries/area-docente-profilo.js`).
$insCur->execute([
    ':k' => 'indirizzi',
    ':c' => 'CLA',
    ':l' => 'Classico',
    ':i' => $idIstituto['E2E-UNO'],
    ':ind' => null,
]);
$create++;

echo "[4] Voci di curriculum: $create ✓\n";

// ── 5. Modelli dell'Istituto ──────────────────────────────────────────────
// «L'Istituto dev'essere popolato con i modelli TeX: senza, l'area delle
// risorse docente non ha niente da provare» (docs/ops/e2e-runner-prerequisiti).
// Questi sono segnaposto dichiarati: servono a far esistere l'elenco, le sue
// categorie e un sorgente da aprire, non a contenere didattica. `source_dir`
// è relativo alla radice del progetto (TemplateResolver), non ai dati
// d'istanza: i file si scrivono lì.
$radice = \dirname(__DIR__, 2);
$modelli = [
    ['MODELLI', '0.0', 'Piano annuale'],
    ['MODELLI', '1.0', 'Programmazione di dipartimento'],
    ['MODELLI', '2.0', 'Verbale di consiglio'],
    ['MODELLI', '3.0', 'Relazione finale'],
    ['MODELLI', '4.0', 'Piano di lavoro individuale'],
    ['MODELLI', '5.0', 'Scheda di valutazione'],
    ['RISORSE', '1.1', 'Griglia di correzione'],
    ['RISORSE', '1.2', 'Rubrica di valutazione'],
    ['RISORSE', '1.3', 'Tabella dei descrittori'],
    ['RISORSE', '1.4', 'Modulo di recupero'],
    ['ALTRO',   '2.1', 'Comunicazione alle famiglie'],
    ['ALTRO',   '2.2', 'Autorizzazione uscita didattica'],
    ['ALTRO',   '2.3', 'Verbale di riunione'],
    ['ALTRO',   '2.4', 'Elenco materiali'],
    ['BES',     '3.1', 'Piano didattico personalizzato'],
    ['STRCOMP', '4.1', 'Strumenti compensativi'],
];

$insMod = $pdo->prepare(
    "INSERT INTO risdoc_templates
        (code, category, num_arg, argomento, discipline, source_dir, html_file,
         tex_file, schema_path, source_hash, visibility_scope)
     VALUES (:code, :cat, :num, :arg, NULL, :dir, :html, :tex, :schema, :hash, 'public')
     ON DUPLICATE KEY UPDATE argomento = VALUES(argomento), category = VALUES(category),
         schema_path = VALUES(schema_path)"
);

$scritti = 0;
foreach ($modelli as [$categoria, $num, $titolo]) {
    $cartella = 'storage/templates/risdoc/' . $categoria;
    $base     = $num . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $titolo);
    $html     = $base . '.html';
    $tex      = $base . '.tex';

    $assoluta = $radice . '/' . $cartella;
    if (!is_dir($assoluta) && !mkdir($assoluta, 0775, true) && !is_dir($assoluta)) {
        fwrite(STDERR, "Non riesco a creare $assoluta\n");
        exit(1);
    }
    $corpoHtml = '<section data-fm-template="' . $base . '"><h1>' . $titolo . '</h1>'
        . "<p>Modello di prova per la suite end-to-end.</p></section>\n";
    $corpoTex = "% Modello di prova per la suite end-to-end.\n"
        . '\section*{' . $titolo . "}\n";
    file_put_contents($assoluta . '/' . $html, $corpoHtml);
    file_put_contents($assoluta . '/' . $tex, $corpoTex);

    // Lo schema dei campi: la scheda del modello vista dall'amministrazione lo
    // pretende (`schema_path`), ed è da lì che si indirizzano le modifiche.
    $schemaRelativo = 'schemas/risdoc/' . strtolower($base) . '.json';
    $schemaAssoluto = $radice . '/' . $schemaRelativo;
    if (!is_dir(\dirname($schemaAssoluto))) {
        mkdir(\dirname($schemaAssoluto), 0775, true);
    }
    file_put_contents($schemaAssoluto, (string)json_encode([
        '$id' => strtolower($base),
        'title' => $titolo,
        'category' => $categoria,
        'body_class' => 'fm-studio-risdoc',
        'sections' => [
            ['type' => 'header', 'title' => $titolo, 'selectors' => ['classe', 'indirizzo', 'disciplina']],
            ['type' => 'text-section', 'title' => 'Contenuto', 'field' => 'contenuto'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $insMod->execute([
        ':code' => 'risdoc/' . $categoria . '/' . $base,
        ':cat'  => $categoria,
        ':num'  => $num,
        ':arg'  => $titolo,
        ':dir'  => $cartella,
        ':html' => $html,
        ':tex'  => $tex,
        ':schema' => $schemaRelativo,
        ':hash' => hash('sha256', $corpoHtml . $corpoTex),
    ]);
    $scritti++;
}
echo "[5] Modelli dell'Istituto: $scritti (con sorgente HTML e TeX su disco) ✓\n";

// ── 6. Il catalogo degli esercizi ─────────────────────────────────────────
// `exercises` è il catalogo comune, diverso dai contenuti del docente: da lì
// pescano la ricerca pubblica, l'elenco degli argomenti di una terna e il
// riquadro «esercizi» dello studio. Le factory dei test creano `teacher_content`,
// non queste righe: se il catalogo è vuoto, quelle pagine non hanno niente da
// mostrare e i test lo dicono («la terna ha degli esercizi»).
$selVoce = $pdo->prepare(
    'SELECT id FROM curriculum_entries
      WHERE kind = ? AND code = ? AND institute_id = ? AND corso_anno = ? LIMIT 1'
);
/** @return int|null — un anno con il suo corso (ADR-042) */
$idVoce = static function (string $kind, string $code, int $idIst, string $corso = '') use ($selVoce): ?int {
    $selVoce->execute([$kind, $code, $idIst, $kind === 'classi' && preg_match('/^[1-9]$/', $code) === 1 ? $corso : '']);
    $id = (int)$selVoce->fetchColumn();
    return $id > 0 ? $id : null;
};

$catalogo = [
    ['SCI', '2', 'MAT', 'Equazioni di primo grado', 'Equazione con parentesi', 1],
    ['SCI', '2', 'MAT', 'Equazioni di primo grado', 'Equazione fratta', 2],
    ['SCI', '2', 'MAT', 'Disequazioni', 'Disequazione intera', 1],
    ['SCI', '2', 'MAT', 'Sistemi lineari', 'Sistema con due incognite', 3],
    ['SCI', '1', 'FIS', 'Cinematica', 'Moto rettilineo uniforme', 1],
    ['ART', '2', 'MAT', 'Geometria', 'Aree dei poligoni', 2],
];
// `exercises` è una vista che unisce `exercises_data` alle voci di
// curriculum: i codici di indirizzo, classe e materia arrivano dal join, e si
// scrive nella tabella sotto.
$insEser = $pdo->prepare(
    'INSERT INTO exercises_data
        (indirizzo_id, classe_id, materia_id, topic, title, difficulty,
         body_html, solution_html, source)
     VALUES (:ii, :ci, :mi, :topic, :title, :dif, :body, :sol, :src)'
);
$gia = (int)$pdo->query('SELECT COUNT(*) FROM exercises_data')->fetchColumn();
$eserciziCreati = 0;
if ($gia === 0) {
    $idUno = $idIstituto['E2E-UNO'];
    foreach ($catalogo as [$ind, $cls, $mat, $topic, $titolo, $dif]) {
        $insEser->execute([
            ':ii' => $idVoce('indirizzi', $ind, $idUno),
            ':ci' => $idVoce('classi', $cls, $idUno, $ind),
            ':mi' => $idVoce('materie', $mat, $idUno),
            ':topic' => $topic,
            ':title' => $titolo,
            ':dif' => $dif,
            ':body' => '<p>Testo di prova per la suite end-to-end.</p>',
            ':sol' => '<p>Soluzione di prova.</p>',
            ':src' => 'mmb_v1_ed3',
        ]);
        $eserciziCreati++;
    }
}
echo "[6] Catalogo degli esercizi: $eserciziCreati ✓\n";

// ── 7. Le fonti da cui viene un quesito ───────────────────────────────────
// Il selettore dell'origine legge un registro personale del docente, che vive
// nell'archivio dei file e non nel database (`StudySourcesController`). Su
// un'installazione nuova non c'è, e il ripiego punta a un file di deploy che
// nel repository non esiste: l'elenco arriva vuoto.
$registro = ['sources' => [
    ['key' => 'mmb_v1_ed3', 'book' => 'Manuale di base', 'volume' => 'Volume 1 - EDIZIONI DI PROVA', 'year' => '2026'],
    ['key' => 'fis_bien_v2', 'book' => 'Fisica del biennio', 'volume' => 'Volume 2 - EDIZIONI DI PROVA', 'year' => '2026'],
]];
$archivio = \App\Support\Storage\StorageFactory::default();
foreach ([$idDocente, $idDocente2] as $idUtente) {
    $archivio->put(
        'institutes/' . $idIstituto['E2E-UNO'] . '/private/' . $idUtente . '/sources.registry.json',
        (string)json_encode($registro, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'application/json'
    );
}
echo "[7] Registro delle fonti per i due docenti ✓\n";

// ── 8. La biblioteca dei modelli TikZ ─────────────────────────────────────
// Vive in un file d'istanza, non nel database (`TikzElementsService::INDEX_REL`),
// e non è versionata: su un'installazione nuova la biblioteca è vuota e il menu
// TeX dell'editor non ha gruppi da aprire.
//
// 2026-09-14 — nei dati d'istanza, non nella radice del progetto. Finché la
// suite girava su `php -S` con `PANTEDU_DATA_PATH` uguale al repository le due
// cose coincidevano; contro l'immagine del rilascio i dati sono una cartella
// montata, ed è lì che l'applicazione legge (`app.paths.data_base`).
$percorsoTikz = rtrim((string)Config::get('app.paths.data_base', $radice), '/')
    . '/' . \App\Services\TikzElementsService::INDEX_REL;
if (!is_dir(\dirname($percorsoTikz))) {
    mkdir(\dirname($percorsoTikz), 0775, true);
}
if (!is_file($percorsoTikz)) {
    $disegno = "% Modello di prova per la suite end-to-end.\n"
        . "\\begin{tikzpicture}\n  \\draw (0,0) -- (2,1);\n\\end{tikzpicture}\n";
    file_put_contents($percorsoTikz, (string)json_encode([
        'gruppo-FISICA' => [
            ['label' => 'cinematica di prova', 'content' => $disegno],
            ['label' => 'forze di prova', 'content' => $disegno],
        ],
        'gruppo-MATEMATICA' => [
            ['label' => 'retta di prova', 'content' => $disegno],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "[8] Biblioteca dei modelli TikZ: due gruppi ✓\n";
} else {
    echo "[8] Biblioteca dei modelli TikZ: già presente ✓\n";
}

echo "\nSeme posato. Ora: php tools/dev/e2e_prepare_users.php\n";
