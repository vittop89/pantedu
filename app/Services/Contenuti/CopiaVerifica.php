<?php

declare(strict_types=1);

namespace App\Services\Contenuti;

use App\Core\Database;
use App\Repositories\VerificaDocumentRepository;
use App\Services\Audit\ActivityLogger;
use App\Services\Crypto\EncryptedBlobStore;
use App\Services\Sharing\SharedContentPolicy;
use App\Support\PdoTransactionRunner;
use App\Support\TransactionRunner;
use App\Support\Ulid;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * La copia di una verifica, in un posto scelto: due strade, un nucleo solo.
 *
 * - **«Duplica in…»** (ADR-037, fase 3): il docente copia una sua verifica, per
 *   adattarla a un'altra classe o scuola. La copia è sua.
 * - **«Recupera» dal pool** (14/9/2026): un collega copia nel proprio account
 *   una verifica che il proprietario ha condiviso con lui. Fino a quel giorno il
 *   pulsante mandava l'id della verifica all'API dei contenuti, che recuperava
 *   un altro contenuto con lo stesso numero, o niente.
 *
 * Si copia la versione da cui si parte con tutte le sue varianti (il pacchetto,
 * `batch_id`; una verifica senza pacchetto è una riga sola). Le altre versioni
 * con lo stesso titolo restano dove sono: la copia è il punto di partenza di
 * una verifica nuova, non un archivio.
 *
 * COME SI COPIA (invariante S5)
 *   - ogni file del TEX e il PDF si rileggono dal deposito cifrato con la
 *     chiave del proprietario e si riscrivono in blob nuovi con la chiave di chi
 *     riceve la copia, versione corrente: nessun percorso di blob è condiviso con
 *     l'originale, così cancellare l'una non rompe l'altra. I file che nel
 *     pacchetto originale erano un blob solo per più varianti restano uno solo
 *     nella copia (la cancellazione del pacchetto li raccoglie insieme);
 *   - le etichette sono gli id del posto scelto, già verificati (S2, S3): non si
 *     risolvono le sigle nella scuola attiva;
 *   - `source_type` (la classificazione per il diritto d'autore) passa alla
 *     copia: il blocco di condivisione vale anche per lei;
 *   - il collegamento a Drive e la condivisione con i colleghi no: la copia
 *     nasce privata, con la principale in bozza nel posto scelto;
 *   - la selezione salvata (`selection_json`) prende le sigle del posto, così
 *     rigenerare il pacchetto lo fa per la classe nuova. Nel recupero gli
 *     esercizi di partenza (`exercise_ids`) non passano: sono contenuti del
 *     proprietario, non di chi recupera.
 *
 * Il legame con l'originale va a registro (S6). Le verifiche non hanno una
 * colonna come `source_content_id` dei contenuti.
 */
final class CopiaVerifica
{
    public function __construct(
        private ?VerificaDocumentRepository $docs = null,
        private ?EncryptedBlobStore $store = null,
        private ?DoveVale $doveVale = null,
        private ?PDO $pdo = null,
        private ?TransactionRunner $tx = null,
        private ?SharedContentPolicy $policy = null,
    ) {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * «Duplica in…»: una copia di una verifica del docente, per lui.
     *
     * @return list<int> gli id delle varianti della copia
     * @throws InvalidArgumentException non_trovato · (i codici di DoveVale::verificaLuogo) · copia_non_riuscita
     */
    public function duplica(int $verifica, int $docente, int $scuola, int $indirizzo, int $classe, int $materia): array
    {
        $originale = ($this->docs ??= new VerificaDocumentRepository())->find($verifica);
        if (!$originale || $docente <= 0 || (int)$originale['teacher_id'] !== $docente) {
            throw new InvalidArgumentException('non_trovato');
        }
        ($this->doveVale ?? new DoveVale($this->pdo))->verificaLuogo($docente, $scuola, $indirizzo, $classe, $materia);

        $ids = $this->copia($originale, $docente, $scuola, $indirizzo, $classe, $materia, static fn(int $n): string => $n === 1 ? ' (copia)' : " (copia {$n})", true);
        ActivityLogger::event('verifica_duplicata', 'verifica_documents', (string)$ids[0], [
            'originale' => $verifica,
            'varianti'  => $ids,
            'istituto'  => $scuola,
            'classe'    => $classe,
        ]);
        return $ids;
    }

    /**
     * «Recupera» dal pool: una copia, nell'account di chi recupera, di una
     * verifica che un collega gli ha condiviso. Per chi non può leggerla la
     * verifica «non esiste»: stessa regola del pool e della lettura fra colleghi
     * (SharedContentPolicy::canReadContent: una scuola in comune in cui è
     * pubblicata, e condivisa o con un grant).
     *
     * @return list<int> gli id delle varianti della copia
     * @throws InvalidArgumentException non_trovato · propria · copyright_block · (i codici di DoveVale::verificaLuogo) · copia_non_riuscita
     */
    public function recupera(int $verifica, int $attore, int $scuola, int $indirizzo, int $classe, int $materia): array
    {
        $originale = ($this->docs ??= new VerificaDocumentRepository())->find($verifica);
        if (!$originale || $attore <= 0) {
            throw new InvalidArgumentException('non_trovato');
        }
        $proprietario = (int)$originale['teacher_id'];
        if ($proprietario === $attore) {
            throw new InvalidArgumentException('propria');
        }
        $policy = $this->policy ??= new SharedContentPolicy();
        if (!$policy->canReadContent($attore, 'verifica_documents', $verifica, $proprietario, (int)($originale['shared_with_pool'] ?? 0) === 1)) {
            throw new InvalidArgumentException('non_trovato');
        }
        // Una verifica tratta dal libro di testo non si condivide, e quindi non
        // si recupera, anche se un tempo è stata condivisa (art. 70-bis).
        foreach ($policy->righeCondivise('verifica_documents', $verifica) as $riga) {
            if ($policy->shareBlockReason('verifica_documents', $riga) !== null) {
                throw new InvalidArgumentException('copyright_block');
            }
        }
        ($this->doveVale ?? new DoveVale($this->pdo))->verificaLuogo($attore, $scuola, $indirizzo, $classe, $materia);

        $nome = $this->nomeDi($proprietario);
        $ids = $this->copia($originale, $attore, $scuola, $indirizzo, $classe, $materia, static fn(int $n): string => $n === 1 ? " (importata da {$nome})" : " (importata da {$nome}, {$n})", false);
        ActivityLogger::event('verifica_recuperata', 'verifica_documents', (string)$ids[0], [
            'originale'    => $verifica,
            'proprietario' => $proprietario,
            'varianti'     => $ids,
            'istituto'     => $scuola,
            'classe'       => $classe,
        ]);
        return $ids;
    }

    /**
     * Il nucleo: copia il pacchetto di `$originale` per `$destinatario`.
     *
     * @param array<string,mixed>   $originale
     * @param callable(int): string $suffisso il suffisso del titolo al tentativo n
     * @param bool                  $stessoDocente false = recupero: gli esercizi di partenza non passano
     * @return list<int>
     */
    private function copia(array $originale, int $destinatario, int $scuola, int $indirizzo, int $classe, int $materia, callable $suffisso, bool $stessoDocente): array
    {
        $docs = $this->docs ??= new VerificaDocumentRepository();
        $proprietario = (int)$originale['teacher_id'];
        $pacchetto = (string)($originale['batch_id'] ?? '');
        $varianti = $pacchetto !== '' ? $docs->listForBatch($proprietario, $pacchetto) : [$originale];
        $varianti = array_values(array_filter($varianti, static fn(array $v): bool => (int)$v['teacher_id'] === $proprietario));
        if ($varianti === []) {
            throw new InvalidArgumentException('non_trovato');
        }

        $codici = $this->codici([$indirizzo, $classe, $materia]);
        $titolo = $this->titoloLibero($destinatario, $materia, VerificaDocumentRepository::titoloBase((string)$originale['title']), $varianti, $suffisso);
        $nuovoPacchetto = Ulid::generate();
        $store = $this->store ??= new EncryptedBlobStore('verifiche_enc');

        $scritti = [];
        /** @var array<string,array{blob_path:string,blob_kv:int}> $nuovoBlob vecchio percorso → nuovo */
        $nuovoBlob = [];
        $ricopia = function (string $vecchio) use ($store, $proprietario, $destinatario, &$scritti, &$nuovoBlob): array {
            if (!isset($nuovoBlob[$vecchio])) {
                $percorso = $store->put($destinatario, $store->get($proprietario, $vecchio), Ulid::generate());
                $scritti[] = $percorso;
                $nuovoBlob[$vecchio] = ['blob_path' => $percorso, 'blob_kv' => $store->readKv($percorso)];
            }
            return $nuovoBlob[$vecchio];
        };

        $righe = [];
        try {
            foreach ($varianti as $v) {
                $manifest = [];
                $dimensione = 0;
                foreach ((\is_array($v['tex_files'] ?? null) ? $v['tex_files'] : []) as $f) {
                    if (!\is_array($f) || empty($f['path']) || empty($f['blob_path'])) {
                        continue;
                    }
                    $blob = $ricopia((string)$f['blob_path']);
                    $manifest[] = [
                        'path'      => (string)$f['path'],
                        'blob_path' => $blob['blob_path'],
                        'blob_kv'   => $blob['blob_kv'],
                        'sha256'    => isset($f['sha256']) ? (string)$f['sha256'] : null,
                        'size'      => isset($f['size']) ? (int)$f['size'] : null,
                    ];
                    $dimensione += isset($f['size']) ? (int)$f['size'] : 0;
                }
                $unico = null;
                if ($manifest === [] && !empty($v['tex_blob_path'])) {
                    $unico = $ricopia((string)$v['tex_blob_path']);
                }
                $pdf = !empty($v['pdf_blob_path']) ? $ricopia((string)$v['pdf_blob_path']) : null;
                $variante = (string)($v['variant'] ?? '');
                $righe[] = [
                    'dati' => [
                        'teacher_id'     => $destinatario,
                        'materia_id'     => $materia,
                        'indirizzo_id'   => $indirizzo,
                        'classe_id'      => $classe,
                        'title'          => $variante !== '' ? "{$titolo} — {$variante}" : $titolo,
                        'fm_db_section'  => (string)($v['fm_db_section'] ?? 'VERIFICHE'),
                        'batch_id'       => $pacchetto !== '' ? $nuovoPacchetto : null,
                        'variant'        => $variante,
                        'version_label'  => $v['version_label'] ?? null,
                        'exercise_ids'   => $stessoDocente && \is_array($v['exercise_ids'] ?? null) ? $v['exercise_ids'] : [],
                        'selection_json' => $this->selezioneNelPosto($v['selection_json'] ?? null, $codici, $indirizzo, $classe, $materia),
                        'tex_files'      => $manifest !== [] ? $manifest : null,
                        'tex_blob_path'  => $unico['blob_path'] ?? null,
                        'tex_blob_kv'    => $unico['blob_kv'] ?? null,
                        'tex_size'       => $manifest !== [] ? $dimensione : ($v['tex_size'] ?? null),
                        'tex_sha256'     => $v['tex_sha256'] ?? null,
                        'source_type'    => $v['source_type'] ?? null,
                    ],
                    'pdf' => $pdf !== null ? [
                        'blob_path' => $pdf['blob_path'],
                        'blob_kv'   => $pdf['blob_kv'],
                        'size'      => (int)($v['pdf_size'] ?? 0),
                        'filename'  => (string)($v['pdf_filename'] ?? 'verifica.pdf'),
                    ] : null,
                ];
            }

            $ids = ($this->tx ?? new PdoTransactionRunner($this->pdo))->run(function () use ($docs, $righe): array {
                $ids = [];
                foreach ($righe as $r) {
                    $id = $docs->create($r['dati']);
                    if ($r['pdf'] !== null) {
                        $docs->attachPdf($id, $r['pdf']['blob_path'], $r['pdf']['blob_kv'], $r['pdf']['size'], $r['pdf']['filename']);
                    }
                    $ids[] = $id;
                }
                return $ids;
            });
        } catch (Throwable $e) {
            // Una copia a metà (varianti senza file, file senza riga) sarebbe
            // peggio di nessuna copia: si tolgono i blob scritti e si dice.
            foreach ($scritti as $percorso) {
                try {
                    $store->delete($percorso);
                } catch (Throwable) {
                    // il raccoglitore dei blob orfani lo troverà
                }
            }
            throw new InvalidArgumentException('copia_non_riuscita', 0, $e);
        }
        return $ids;
    }

    /**
     * Un titolo base che il destinatario non ha già in quella materia per
     * nessuna delle varianti: la chiave unica (docente, materia, titolo,
     * variante, versione) non ammette doppioni.
     *
     * @param list<array<string,mixed>> $varianti
     * @param callable(int): string     $suffisso
     */
    private function titoloLibero(int $docente, int $materia, string $base, array $varianti, callable $suffisso): string
    {
        $base = $base !== '' ? mb_substr($base, 0, 180) : 'Verifica';
        $st = $this->db()->prepare(
            'SELECT 1 FROM verifica_documents WHERE teacher_id = ? AND materia_id = ? AND title = ? LIMIT 1'
        );
        for ($n = 1; $n <= 50; $n++) {
            $candidato = $base . $suffisso($n);
            $libero = true;
            foreach ($varianti as $v) {
                $variante = (string)($v['variant'] ?? '');
                $st->execute([$docente, $materia, $variante !== '' ? "{$candidato} — {$variante}" : $candidato]);
                if ($st->fetchColumn()) {
                    $libero = false;
                    break;
                }
            }
            if ($libero) {
                return $candidato;
            }
        }
        return $base . $suffisso(1) . ' ' . bin2hex(random_bytes(3));
    }

    /** Il nome del proprietario per il titolo della copia recuperata; mai l'email. */
    private function nomeDi(int $utente): string
    {
        $st = $this->db()->prepare(
            "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username) FROM users WHERE id = ? LIMIT 1"
        );
        $st->execute([$utente]);
        $nome = trim((string)$st->fetchColumn());
        return $nome !== '' ? mb_substr($nome, 0, 60) : 'un collega';
    }

    /**
     * La selezione salvata con le sigle del posto nuovo; invariata se non si
     * legge come JSON.
     *
     * @param array<int,string> $codici
     */
    private function selezioneNelPosto(mixed $json, array $codici, int $indirizzo, int $classe, int $materia): ?string
    {
        if (!\is_string($json) || $json === '') {
            return null;
        }
        $sel = json_decode($json, true);
        if (!\is_array($sel)) {
            return $json;
        }
        $sel['iis'] = $codici[$indirizzo] ?? ($sel['iis'] ?? null);
        $sel['cls'] = $codici[$classe] ?? ($sel['cls'] ?? null);
        $sel['mater'] = $codici[$materia] ?? ($sel['mater'] ?? null);
        return (string)json_encode($sel, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function codici(array $ids): array
    {
        $st = $this->db()->prepare('SELECT id, code FROM curriculum_entries WHERE id IN (?, ?, ?)');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = (string)$r['code'];
        }
        return $out;
    }
}
