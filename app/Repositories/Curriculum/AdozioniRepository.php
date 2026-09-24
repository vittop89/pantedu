<?php

declare(strict_types=1);

namespace App\Repositories\Curriculum;

use App\Core\Database;
use App\Domain\ClassCode;
use PDO;

/**
 * Il catalogo dei libri in adozione di un istituto (`adozioni_libri`, ADR-036).
 *
 * Una riga per (istituto, anno scolastico, classe, disciplina, ISBN), come
 * nel dataset MIUR. Il docente lo interroga per le classi e le materie che ha
 * spuntato; l'amministratore lo alimenta con l'import delle adozioni o a
 * mano, per un libro che nel dataset non c'è.
 */
final class AdozioniRepository
{
    /** I campi che un inserimento accetta, con la lunghezza massima. */
    private const CAMPI = [
        'anno_scolastico' => 9,
        'indirizzo'       => 16,
        'classe'          => 16,
        'materia'         => 16,
        'disciplina'      => 255,
        'isbn'            => 20,
        'titolo'          => 255,
        'sottotitolo'     => 255,
        'autori'          => 255,
        'editore'         => 128,
        'volume'          => 64,
    ];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Inserisce un libro; ritorna l'id, o 0 se la riga c'era già (stessa
     * chiave: istituto, anno, classe, ISBN, disciplina).
     *
     * @param array<string,mixed> $riga
     */
    public function inserisci(int $instituteId, array $riga, string $origine = 'miur'): int
    {
        $v = [];
        foreach (self::CAMPI as $campo => $max) {
            $val = $riga[$campo] ?? null;
            $val = $val === null ? null : trim((string)$val);
            $v[$campo] = ($val === null || $val === '') ? null : mb_substr($val, 0, $max);
        }
        foreach (['anno_scolastico', 'classe', 'disciplina', 'isbn', 'titolo'] as $obbligatorio) {
            if ($v[$obbligatorio] === null) {
                throw new \InvalidArgumentException('adozione_' . $obbligatorio . '_mancante');
            }
        }
        $prezzo = isset($riga['prezzo']) && $riga['prezzo'] !== ''
            ? (float)str_replace(',', '.', (string)$riga['prezzo'])
            : null;
        $st = $this->db()->prepare(
            'INSERT IGNORE INTO adozioni_libri
                (institute_id, anno_scolastico, indirizzo, classe, materia, disciplina, isbn,
                 titolo, sottotitolo, autori, editore, volume, prezzo,
                 nuova_adozione, da_acquistare, consigliato, origine)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $instituteId,
            $v['anno_scolastico'],
            $v['indirizzo'] !== null ? strtoupper($v['indirizzo']) : null,
            strtoupper((string)$v['classe']),
            $v['materia'] !== null ? strtoupper($v['materia']) : null,
            $v['disciplina'],
            $v['isbn'],
            $v['titolo'],
            $v['sottotitolo'],
            $v['autori'],
            $v['editore'],
            $v['volume'],
            $prezzo,
            (int)!empty($riga['nuova_adozione']),
            (int)!empty($riga['da_acquistare']),
            (int)!empty($riga['consigliato']),
            $origine,
        ]);
        return $st->rowCount() > 0 ? (int)$this->db()->lastInsertId() : 0;
    }

    public function elimina(int $id, int $instituteId): bool
    {
        $st = $this->db()->prepare('DELETE FROM adozioni_libri WHERE id = ? AND institute_id = ?');
        $st->execute([$id, $instituteId]);
        return $st->rowCount() > 0;
    }

    /** @return array<string,mixed>|null */
    public function trova(int $id): ?array
    {
        $st = $this->db()->prepare('SELECT * FROM adozioni_libri WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function conta(int $instituteId): int
    {
        $st = $this->db()->prepare('SELECT COUNT(*) FROM adozioni_libri WHERE institute_id = ?');
        $st->execute([$instituteId]);
        return (int)$st->fetchColumn();
    }

    /**
     * I libri dell'istituto per un insieme di classi e materie.
     *
     * Le classi si danno con i codici del vocabolario: un anno secco («2»)
     * prende tutte le sezioni di quell'anno, una sezione («2A») solo se stessa
     * (App\Domain\ClassCode). Con `$materie` vuoto si prendono tutte le
     * discipline; le righe senza sigla di materia entrano solo così, perché
     * non c'è una materia a cui agganciarle.
     *
     * @param  list<string> $classi
     * @param  list<string> $materie
     * @return list<array<string,mixed>>
     */
    public function perClassiEMaterie(int $instituteId, array $classi, array $materie = [], ?string $annoScolastico = null): array
    {
        $sql = 'SELECT * FROM adozioni_libri WHERE institute_id = ?';
        $args = [$instituteId];
        if ($annoScolastico !== null && $annoScolastico !== '') {
            $sql .= ' AND anno_scolastico = ?';
            $args[] = $annoScolastico;
        }
        $st = $this->db()->prepare($sql . ' ORDER BY anno_scolastico DESC, classe, disciplina, titolo');
        $st->execute($args);

        $classiVolute = array_values(array_unique(array_map(
            static fn(string $c): string => ClassCode::normalize($c),
            array_filter($classi, static fn($c) => is_string($c) && trim($c) !== '')
        )));
        $materieVolute = [];
        foreach ($materie as $m) {
            if (is_string($m) && trim($m) !== '') {
                $materieVolute[strtoupper(trim($m))] = true;
            }
        }

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($classiVolute !== [] && !self::classeCoperta((string)$r['classe'], $classiVolute)) {
                continue;
            }
            if ($materieVolute !== []) {
                $mat = $r['materia'] !== null ? strtoupper((string)$r['materia']) : '';
                if ($mat === '' || !isset($materieVolute[$mat])) {
                    continue;
                }
            }
            $out[] = $r;
        }
        return $out;
    }

    /** L'anno scolastico più recente presente nel catalogo dell'istituto, o null. */
    public function annoPiuRecente(int $instituteId): ?string
    {
        $st = $this->db()->prepare(
            'SELECT MAX(anno_scolastico) FROM adozioni_libri WHERE institute_id = ?'
        );
        $st->execute([$instituteId]);
        $v = $st->fetchColumn();
        return $v !== false && $v !== null && (string)$v !== '' ? (string)$v : null;
    }

    /**
     * «2A» è coperta da «2A» e da «2»; «2» da «2» soltanto. La stessa regola
     * dei contenuti (piano classi, A).
     *
     * @param list<string> $classiVolute già normalizzate
     */
    private static function classeCoperta(string $classeLibro, array $classiVolute): bool
    {
        $libro = ClassCode::normalize($classeLibro);
        foreach ($classiVolute as $voluta) {
            if (ClassCode::covers($voluta, $libro)) {
                return true;
            }
        }
        return false;
    }

    /**
     * La proposta di fonte per il registro del docente, nella forma che
     * `/area-docente/fonti` usa: `key`, `book`, `volume` («Vol.2 - EDITORE»,
     * che SourcesRegistry sa separare), `authors`, più `isbn` per riconoscere
     * un libro già preso.
     *
     * @param  array<string,mixed> $libro
     * @return array{key:string,book:string,volume:string,authors:string,isbn:string,editore:string}
     */
    public static function propostaDiFonte(array $libro): array
    {
        $titolo = trim((string)($libro['titolo'] ?? ''));
        $sotto  = trim((string)($libro['sottotitolo'] ?? ''));
        $book   = $sotto !== '' ? "$titolo — $sotto" : $titolo;
        $vol    = trim((string)($libro['volume'] ?? ''));
        $ed     = strtoupper(trim((string)($libro['editore'] ?? '')));
        $volParte = $vol !== '' && !preg_match('/^vol/i', $vol) ? "Vol.$vol" : $vol;
        $volume = $ed !== '' ? trim($volParte === '' ? $ed : "$volParte - $ed") : $volParte;
        $slug = static function (string $s): string {
            $s = strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT', $s));
            $s = (string)preg_replace('/[^a-z0-9]+/', '_', $s);
            return trim($s, '_');
        };
        $key = $slug($titolo . ' ' . ($vol !== '' ? 'vol ' . $vol : '') . ' ' . $ed);
        return [
            'key'     => $key !== '' ? mb_substr($key, 0, 64) : 'libro_' . (int)($libro['id'] ?? 0),
            'book'    => $book,
            'volume'  => $volume,
            'authors' => trim((string)($libro['autori'] ?? '')),
            'isbn'    => trim((string)($libro['isbn'] ?? '')),
            'editore' => $ed,
        ];
    }
}
