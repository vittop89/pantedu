<?php

declare(strict_types=1);

/**
 * Promuove un istituto dal codice sintetico a quello MIUR vero.
 *
 * PERCHE' SERVE
 *   Prima del guard del 2026-09-02, l'iscrizione fabbricava un codice
 *   "MIUR-NOME-CITTA" quando la scuola scelta non ne portava uno. Una riga con
 *   quel codice non viene trovata ne' dall'anagrafica ne' dall'import delle
 *   adozioni: resta per sempre senza indirizzi, sezioni e materie. L'istituto
 *   108 e' nato cosi'. Il guard impedisce che ne nascano altre, questo strumento
 *   cura quelle che ci sono.
 *
 * PERCHE' NON BASTA UN UPDATE A MANO
 *   Il codice MIUR e' l'identificatore di una scuola reale. Scriverne uno
 *   sbagliato non da' errore: da' una scuola che sembra a posto e importa i
 *   dati di qualcun altro. Quindi qui il codice viene CERCATO nell'anagrafica
 *   caricata sul server, e senza corrispondenza non si scrive niente. La
 *   verifica la fa la macchina sui dati che ha, non chi lancia il comando
 *   sulla base di una somiglianza.
 *
 * COSA FA
 *   Passa da InstituteRepository::upsertCanonical, che e' la promozione gia'
 *   prevista e testata: stessa scuola (nome+citta'), codice reale in arrivo,
 *   codice sintetico esistente → la riga ADOTTA il codice nuovo. Nessuna riga
 *   nuova, nessun riferimento da spostare: id, utenti, docenti e contenuti
 *   restano dove sono.
 *
 * USO
 *   php tools/institutes/promote_code.php --institute=108 --to=XXSL000000 [--apply]
 *
 *   Senza --apply non tocca nulla.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\InstituteRepository;
use App\Services\MiurSchoolsService;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$opts = getopt('', ['institute:', 'to:', 'apply', 'force', 'help']);
if (isset($opts['help']) || !isset($opts['institute']) || !isset($opts['to'])) {
    fwrite(STDERR, "Uso: php tools/institutes/promote_code.php --institute=<id|codice> --to=<CODICE MIUR> [--apply]\n");
    fwrite(STDERR, "  --force  procede anche se l'anagrafica non conferma il codice (sconsigliato)\n");
    exit(isset($opts['help']) ? 0 : 1);
}
$target = trim((string)$opts['institute']);
$nuovo  = strtoupper(trim((string)$opts['to']));
$apply  = isset($opts['apply']);
$force  = isset($opts['force']);

$pdo  = Database::connection();
$repo = new InstituteRepository();

// ── la riga da promuovere ──────────────────────────────────────────────────
$st = ctype_digit($target)
    ? $pdo->prepare('SELECT * FROM institutes WHERE id = ? LIMIT 1')
    : $pdo->prepare('SELECT * FROM institutes WHERE UPPER(code) = ? LIMIT 1');
$st->execute([ctype_digit($target) ? (int)$target : strtoupper($target)]);
$inst = $st->fetch(PDO::FETCH_ASSOC);
if (!$inst) {
    fwrite(STDERR, "ABORT: nessun istituto con id/codice '$target'\n");
    exit(1);
}
$id      = (int)$inst['id'];
$vecchio = (string)$inst['code'];
$nome    = (string)$inst['name'];
$citta   = (string)($inst['city'] ?? '');

printf("Istituto %d — %s\n", $id, $nome);
printf("  citta':  %s\n", $citta !== '' ? $citta : '(non indicata)');
printf("  codice:  %s  (%s)\n", $vecchio, InstituteRepository::isRealMiurCode($vecchio) ? 'gia\' MIUR' : 'sintetico');
printf("  nuovo:   %s\n\n", $nuovo);

if (!InstituteRepository::isRealMiurCode($nuovo)) {
    fwrite(STDERR, "ABORT: '$nuovo' non e' un codice MIUR (due lettere di provincia + 8 alfanumerici)\n");
    exit(1);
}
if (InstituteRepository::isRealMiurCode($vecchio) && $vecchio !== $nuovo) {
    fwrite(STDERR, "ABORT: l'istituto ha gia' un codice MIUR ($vecchio). Cambiarlo non e' una promozione:\n");
    fwrite(STDERR, "       o e' un errore, o sono due scuole diverse. Decidere a mano.\n");
    exit(1);
}

// ── conferma dall'anagrafica ───────────────────────────────────────────────
// La parte che rende questo strumento diverso da un UPDATE: il codice deve
// corrispondere a una scuola vera, e il suo nome deve somigliare a quello che
// abbiamo a tabella. Se l'anagrafica non e' caricata non si indovina: si dice.
$svc = MiurSchoolsService::fromConfig();
$anagrafica = (bool)($svc->indexStatus()['exists'] ?? false);
if (!$anagrafica && !$force) {
    fwrite(STDERR, "ABORT: anagrafica scuole non caricata su questo server, quindi il codice\n");
    fwrite(STDERR, "       non e' verificabile. Caricala da /admin/institutes, oppure rilancia\n");
    fwrite(STDERR, "       con --force assumendoti la verifica.\n");
    exit(1);
}
if (!$anagrafica) {
    fwrite(STDERR, "ATTENZIONE: anagrafica assente, codice NON verificato (--force).\n\n");
}

/** @var array<string,mixed>|null $trovata */
$trovata = null;
foreach ($anagrafica ? $svc->search($nuovo, 20) : [] as $r) {
    if (strtoupper((string)($r['code'] ?? '')) === $nuovo) {
        $trovata = $r;
        break;
    }
}
if ($trovata === null && $anagrafica) {
    fwrite(STDERR, "ABORT: il codice $nuovo non risulta nell'anagrafica caricata.\n");
    fwrite(STDERR, "       Non si scrive un codice MIUR che i dati ministeriali non confermano.\n");
    if (!$force) {
        exit(1);
    }
    fwrite(STDERR, "       (--force: si procede lo stesso)\n\n");
}

$denomAnag = (string)($trovata['denom'] ?? '');
$cittaAnag = (string)($trovata['city'] ?? '');
$tipoAnag  = (string)($trovata['type'] ?? '');
if ($trovata !== null) {
    printf("Anagrafica MIUR dice:\n  %s\n  %s\n\n", $denomAnag, trim($cittaAnag . ' ' . $tipoAnag));
}

// Confronto morbido: le denominazioni ministeriali e quelle a tabella non
// coincidono mai alla lettera. Basta che la citta' torni e che qualche parola
// significativa del nome sia condivisa — il resto lo legge chi lancia.
$parole = static function (string $s): array {
    $s = strtoupper((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
    return array_values(array_filter(
        preg_split('/[^A-Z0-9]+/', $s) ?: [],
        static fn(string $w): bool => strlen($w) > 3
    ));
};
$comuni = $trovata === null ? [] : array_intersect($parole($nome), $parole($denomAnag));
$cittaOk = $citta === '' || $cittaAnag === ''
    || in_array(strtoupper($citta), array_map('strtoupper', $parole($cittaAnag)), true)
    || strcasecmp(trim($citta), trim($cittaAnag)) === 0;

if ($trovata !== null) {
    printf("Verifica:\n");
    printf("  parole in comune nel nome: %s\n", $comuni !== [] ? implode(', ', $comuni) : 'NESSUNA');
    printf("  citta' compatibile:        %s\n\n", $cittaOk ? 'si' : 'NO');
}

if ($trovata !== null && ($comuni === [] || !$cittaOk)) {
    fwrite(STDERR, "ABORT: la scuola dell'anagrafica non somiglia a quella a tabella.\n");
    fwrite(STDERR, "       Se sono davvero la stessa, rilancia con --force.\n");
    if (!$force) {
        exit(1);
    }
    fwrite(STDERR, "       (--force: si procede lo stesso)\n\n");
}

if (!$apply) {
    echo "ANTEPRIMA — nessuna modifica.\n";
    printf("Con --apply: l'istituto %d passerebbe da %s a %s, mantenendo id, utenti,\n", $id, $vecchio, $nuovo);
    echo "docenti collegati e contenuti. Da li' in poi l'import delle adozioni lo trova.\n";
    exit(0);
}

$risultato = $repo->upsertCanonical($nuovo, $nome, $citta !== '' ? $citta : null, $inst['region'] ?? null);
if ($risultato !== $id) {
    fwrite(STDERR, "ABORT: la promozione avrebbe toccato l'istituto $risultato invece di $id.\n");
    fwrite(STDERR, "       Non scrivo: probabile omonimia da risolvere a mano.\n");
    exit(1);
}

$check = $pdo->prepare('SELECT code FROM institutes WHERE id = ?');
$check->execute([$id]);
$ora = (string)$check->fetchColumn();
printf("%s — istituto %d: %s → %s\n", $ora === $nuovo ? 'FATTO' : 'NON APPLICATO', $id, $vecchio, $ora);
exit($ora === $nuovo ? 0 : 1);
