<?php

declare(strict_types=1);

namespace App\Services\Contenuti;

use App\Core\Database;
use App\Repositories\Contract\ContractRepository;
use App\Repositories\TeacherContentRepository;
use App\Services\Audit\ActivityLogger;
use App\Services\Audit\ContentActionLogger;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use InvalidArgumentException;
use PDO;

/**
 * ADR-037, fase 2 — «Duplica in…»: una copia indipendente di un contenuto del
 * docente, in un posto che sceglie lui.
 *
 * La differenza con una pubblicazione in più: una pubblicazione è lo stesso
 * contenuto in un altro posto, e si corregge una volta; una copia è un
 * contenuto nuovo, che da lì in poi vive per conto suo. Serve quando la
 * mappa della 2A del Esempio va adattata per la 3B del Musicale.
 *
 * COME SI COPIA (invariante S5)
 *   - il corpo e i metadati si leggono con il repository, che li decifra in
 *     memoria, e si scrivono con la creazione normale, che li cifra con la
 *     chiave e la versione correnti: le colonne cifrate non si copiano mai;
 *   - il contratto di un esercizio o di una verifica si copia in un file
 *     nuovo, con la scuola di arrivo nello scope;
 *   - il disegno di una mappa si rilegge e si riscrive nel deposito cifrato;
 *     il collegamento a Drive no: la copia non è il file di Drive;
 *   - `source_type` (la classificazione per il diritto d'autore) passa alla
 *     copia: il blocco di condivisione di un contenuto protetto vale anche
 *     per la copia, che resta del docente;
 *   - `source_content_id` punta all'originale, come per il recupero dal pool.
 *
 * La copia nasce in bozza, con la principale nel posto scelto. Il posto passa
 * dagli stessi controlli di una pubblicazione (S2, S3: DoveVale).
 */
final class CopiaIndipendente
{
    public function __construct(
        private ?TeacherContentRepository $contenuti = null,
        private ?DoveVale $doveVale = null,
        private ?PDO $pdo = null,
        private ?ContractRepository $contratti = null,
    ) {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    private function contenuti(): TeacherContentRepository
    {
        return $this->contenuti ??= new TeacherContentRepository();
    }

    /**
     * @return int id della copia
     * @throws InvalidArgumentException non_trovato · illeggibile · (i codici di DoveVale::verificaLuogo) ·
     *   copia_non_riuscita
     */
    public function duplica(int $contenuto, int $docente, int $scuola, int $indirizzo, int $classe, int $materia): int
    {
        $repo = $this->contenuti();
        $originale = $repo->find($contenuto);
        if (!$originale || (int)($originale['teacher_id'] ?? 0) !== $docente || $docente <= 0) {
            throw new InvalidArgumentException('non_trovato');
        }
        if (!empty($originale['_crypto_error'])) {
            throw new InvalidArgumentException('illeggibile');
        }
        ($this->doveVale ?? new DoveVale($this->pdo))->verificaLuogo($docente, $scuola, $indirizzo, $classe, $materia);

        $codici = $this->codici([$indirizzo, $classe, $materia]);
        $tipo = (string)$originale['content_type'];
        $metadati = is_array($originale['metadata'] ?? null) ? $originale['metadata'] : [];
        unset($metadati['contract_key']);

        try {
            $nuovo = $repo->create([
                'teacher_id'   => $docente,
                'content_type' => $tipo,
                'section_id'   => isset($originale['section_id']) ? (int)$originale['section_id'] : null,
                'subject_code' => $codici[$materia],
                'indirizzo'    => $codici[$indirizzo],
                'classe'       => $codici[$classe],
                // Gli id già verificati: la creazione non deve risolvere i
                // codici nella scuola attiva, che può essere un'altra.
                'subject_id'   => $materia,
                'indirizzo_id' => $indirizzo,
                'classe_id'    => $classe,
                'topic'        => (string)($originale['topic'] ?? ''),
                'title'        => $this->titoloLibero($docente, $tipo, (string)($originale['title'] ?? '')),
                'body_html'    => isset($originale['body_html']) ? (string)$originale['body_html'] : null,
                'metadata'     => $metadati,
                'visibility'   => 'draft',
            ]);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('copia_non_riuscita', 0, $e);
        }

        $this->db()->prepare(
            'UPDATE teacher_content_data SET source_content_id = ?, source_type = ?, shared_with_pool = 0 WHERE id = ? AND teacher_id = ?'
        )->execute([$contenuto, $originale['source_type'] ?? null, $nuovo, $docente]);

        try {
            if ($tipo === 'mappa' && !empty($originale['map_blob_path'])) {
                $deposito = new MapBlobStore(new TeacherCryptoService());
                $percorso = $deposito->put($docente, $deposito->get($docente, (string)$originale['map_blob_path']));
                $this->db()->prepare('UPDATE teacher_content_data SET map_blob_path = ?, map_mime = ? WHERE id = ? AND teacher_id = ?')
                    ->execute([$percorso, (string)($originale['map_mime'] ?? 'application/xml'), $nuovo, $docente]);
            }
            if (TeacherContentRepository::formatOf($tipo) === 'exercise') {
                ($this->contratti ?? ContractRepository::default())->copiaPerNuovoContenuto($contenuto, $nuovo, $scuola);
            }
        } catch (\Throwable $e) {
            // Una copia a metà (riga senza disegno o senza contratto) sarebbe
            // peggio di nessuna copia: si toglie e si dice.
            $repo->delete($nuovo, $docente);
            throw new InvalidArgumentException('copia_non_riuscita', 0, $e);
        }

        ContentActionLogger::log(ContentActionLogger::ACTION_CLONED_FROM, $docente, $nuovo, $tipo, [
            'source_content_id' => $contenuto,
            'source_teacher_id' => $docente,
            'via'               => 'duplica',
        ]);
        ActivityLogger::event('contenuto_duplicato', 'teacher_content', (string)$nuovo, [
            'originale' => $contenuto,
            'istituto'  => $scuola,
            'classe'    => $classe,
        ]);
        return $nuovo;
    }

    /**
     * Un titolo che il docente non ha già per quel tipo: la chiave unica
     * (docente, tipo, titolo) non ammette due «Frazioni».
     */
    private function titoloLibero(int $docente, string $tipo, string $titolo): string
    {
        $base = trim($titolo) !== '' ? trim($titolo) : 'Senza titolo';
        $st = $this->db()->prepare(
            'SELECT 1 FROM teacher_content_data WHERE teacher_id = ? AND content_subtype = ? AND title = ? LIMIT 1'
        );
        for ($n = 1; $n <= 50; $n++) {
            $candidato = mb_substr($base, 0, 230) . ($n === 1 ? ' (copia)' : " (copia {$n})");
            $st->execute([$docente, $tipo, $candidato]);
            if (!$st->fetchColumn()) {
                return $candidato;
            }
        }
        return mb_substr($base, 0, 200) . ' (copia ' . bin2hex(random_bytes(3)) . ')';
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
