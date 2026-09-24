<?php

declare(strict_types=1);

/**
 * Bonifica dei dati lasciati nel database di sviluppo dalla suite Playwright.
 *
 *   php tools/dev/e2e_cleanup_residues.php [--apply] [--user=docente.uno] [--days=0]
 *
 * Senza `--apply` elenca soltanto: è la modalità predefinita.
 *
 * Perché serve. Le spec riscritte sul refactoring E2E cancellano ciò che
 * creano, ma restano due sorgenti di accumulo:
 *   1. le spec storiche non ancora riscritte, che salvano verifiche con titoli
 *      fissi (TestNested, TestE2E_Lavatrice, PROOF-COPY di …) e non le tolgono;
 *   2. i giri interrotti a metà (chiusura del terminale, arresto della
 *      macchina), dove il teardown non arriva mai a girare.
 * Il 2026-09-07 il docente di test aveva 611 verifiche, 521 delle quali create
 * nelle ultime ventiquattro ore: il manifesto del pacchetto di sincronizzazione
 * andava in timeout e le pagine di studio chiedevano tante figure TikZ da farsi
 * rifiutare le richieste. Da qui la voce 37 del registro del debito.
 *
 * Sicurezze: agisce su un solo docente, riconosce i titoli per elenco esplicito
 * di prefissi (niente cancellazioni «tutto quello che c'è»), rifiuta di partire
 * se l'ambiente non è di sviluppo, e cancella passando dal servizio di dominio,
 * così spariscono anche i file cifrati e non solo le righe.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Verifica\VerificaDocumentService;

/** Prefissi dei titoli prodotti dalla suite: solo questi vengono toccati. */
const PREFISSI_DI_PROVA = [
    'e2e-',                 // factory del refactoring (tests/e2e/support/factories)
    'TestNested',
    'TestE2E_',
    'PROOF-COPY',
    'G16', 'G18', 'G19', 'G20', 'G21', 'G22', 'G23', 'G27',
    'MATH_', 'VF_', 'GGB_', 'VSC_',
    'E2E-SURGICAL',
    // Titoli scritti per esteso dalle spec storiche della generazione.
    'VERIFICA G16', 'VERIFICA G18', 'VERIFICA G19', 'VERIFICA G20',
    'VERIFICA G21', 'VERIFICA G22', 'VERIFICA TEST', 'VERIFICA PROVA',
    'Verifica di prova E2E',
];

$opzioni = getopt('', ['apply', 'user::', 'days::']);
$applica = isset($opzioni['apply']);
$username = is_string($opzioni['user'] ?? null) ? $opzioni['user'] : 'docente.uno';
$giorni = (int)($opzioni['days'] ?? 0); // 0 = senza limite di data

$ambiente = (string)(getenv('APP_ENV') ?: 'local');
if (in_array($ambiente, ['production', 'prod'], true)) {
    fwrite(STDERR, "Rifiuto di girare con APP_ENV={$ambiente}: strumento di sviluppo.\n");
    exit(2);
}
if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile: avvia MariaDB (XAMPP).\n");
    exit(1);
}

$pdo = Database::connection();
$st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([$username]);
$teacherId = (int)$st->fetchColumn();
if ($teacherId <= 0) {
    fwrite(STDERR, "Utente {$username} assente.\n");
    exit(1);
}

/** Condizione SQL sui prefissi, con i parametri da associare. */
$condizioni = [];
$parametri = [$teacherId];
foreach (PREFISSI_DI_PROVA as $prefisso) {
    $condizioni[] = 'title LIKE ?';
    $parametri[] = $prefisso . '%';
}
$doveTitolo = '(' . implode(' OR ', $condizioni) . ')';
$doveData = $giorni > 0 ? ' AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $giorni . ' DAY)' : '';

echo "Bonifica dei residui E2E — utente {$username} (id {$teacherId})\n";
echo $applica ? "MODALITÀ: cancellazione\n" : "MODALITÀ: sola elencazione (aggiungi --apply per cancellare)\n";
echo str_repeat('-', 72), "\n";

// ── Verifiche (verifica_documents) ────────────────────────────────────────
$sql = "SELECT id, title, variant, created_at FROM verifica_documents
        WHERE teacher_id = ? AND {$doveTitolo}{$doveData} ORDER BY id";
$st = $pdo->prepare($sql);
$st->execute($parametri);
$verifiche = $st->fetchAll(PDO::FETCH_ASSOC);

$totaleVerifiche = (int)$pdo->query('SELECT COUNT(*) FROM verifica_documents WHERE teacher_id = ' . $teacherId)->fetchColumn();
printf("verifiche di prova: %d su %d totali del docente\n", count($verifiche), $totaleVerifiche);

$perTitolo = [];
foreach ($verifiche as $riga) {
    $titolo = (string)($riga['title'] ?? '');
    $perTitolo[$titolo] = ($perTitolo[$titolo] ?? 0) + 1;
}
arsort($perTitolo);
foreach (array_slice($perTitolo, 0, 15, true) as $titolo => $quante) {
    printf("  %4d  %s\n", $quante, mb_substr($titolo, 0, 60));
}
if (count($perTitolo) > 15) {
    printf("  … e altri %d titoli\n", count($perTitolo) - 15);
}

$cancellate = 0;
$falliti = [];
if ($applica && $verifiche) {
    $servizio = new VerificaDocumentService();
    foreach ($verifiche as $riga) {
        $id = (int)$riga['id'];
        try {
            $servizio->deleteDoc($teacherId, $id);
            $cancellate++;
        } catch (\Throwable $e) {
            // Le varianti di uno stesso gruppo spariscono insieme: la seconda
            // chiamata non trova più la riga, e va bene così.
            if (!str_contains($e->getMessage(), 'not_found')) {
                $falliti[$id] = $e->getMessage();
            }
        }
    }
    printf("verifiche cancellate: %d (fallite: %d)\n", $cancellate, count($falliti));
    foreach (array_slice($falliti, 0, 5, true) as $id => $messaggio) {
        printf("  #%d: %s\n", $id, mb_substr($messaggio, 0, 100));
    }
}

// ── Contenuti del docente (teacher_content) ───────────────────────────────
// La copia di lavoro «PROOF-COPY di 1291» è una fixture: le spec dello studio
// non ancora riscritte ci lavorano sopra (tools/dev/setup_studio_eser_e2e.sh la
// prepara e gen_proof_contract.cjs le riscrive il contratto). Le verifiche con
// lo stesso titolo sono invece prodotti di quei test, e si cancellano.
$sql = "SELECT id, title, content_type FROM teacher_content
        WHERE teacher_id = ? AND {$doveTitolo}{$doveData}
          AND title NOT LIKE 'PROOF-COPY%' ORDER BY id";
$st = $pdo->prepare($sql);
$st->execute($parametri);
$contenuti = $st->fetchAll(PDO::FETCH_ASSOC);

$totaleContenuti = (int)$pdo->query('SELECT COUNT(*) FROM teacher_content WHERE teacher_id = ' . $teacherId)->fetchColumn();
printf("\ncontenuti di prova: %d su %d totali del docente\n", count($contenuti), $totaleContenuti);
foreach (array_slice($contenuti, 0, 15) as $riga) {
    printf("  #%-6d %-10s %s\n", $riga['id'], $riga['content_type'], mb_substr((string)$riga['title'], 0, 50));
}
if (count($contenuti) > 15) {
    printf("  … e altri %d contenuti\n", count($contenuti) - 15);
}

if ($applica && $contenuti) {
    // `teacher_content` è una vista: la cancellazione passa dal repository, che
    // conosce la tabella sottostante e scrive il registro delle operazioni.
    $repo = new TeacherContentRepository();
    $quanti = 0;
    $nonRiusciti = [];
    foreach ($contenuti as $riga) {
        $id = (int)$riga['id'];
        try {
            if ($repo->delete($id, $teacherId)) {
                $quanti++;
            } else {
                $nonRiusciti[$id] = 'rifiutata dal repository';
            }
        } catch (\Throwable $e) {
            $nonRiusciti[$id] = mb_substr($e->getMessage(), 0, 120);
        }
    }
    printf("contenuti cancellati: %d (non riusciti: %d)\n", $quanti, count($nonRiusciti));
    foreach ($nonRiusciti as $id => $messaggio) {
        printf("  #%d: %s\n", $id, $messaggio);
    }
}

echo str_repeat('-', 72), "\n";
echo $applica ? "Fatto.\n" : "Nessuna modifica: rilancia con --apply per cancellare.\n";
