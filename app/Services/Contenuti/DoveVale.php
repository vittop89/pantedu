<?php

declare(strict_types=1);

namespace App\Services\Contenuti;

use App\Core\Database;
use App\Services\Audit\ActivityLogger;
use InvalidArgumentException;
use PDO;

/**
 * ADR-037, fase 2 — «Dove vale»: le pubblicazioni di un contenuto, scelte dal
 * suo docente.
 *
 * Una pubblicazione è il contenuto in una scuola, in una terna, con uno stato
 * (`content_publications`). La principale viene dalla riga e la muove chi
 * muove la riga; le altre le aggiunge, le toglie e le cambia di stato il
 * docente, da qui. Una mappa pubblicata in 2A e in 2B della stessa scuola, o
 * al Esempio e al Musicale, è un contenuto solo: si corregge una volta.
 *
 * GLI INVARIANTI (ADR-037)
 *   S1  solo il proprietario tocca le pubblicazioni del suo contenuto; per chi
 *       non lo è il contenuto «non esiste», così un id non rivela niente;
 *   S2  si pubblica solo in una scuola a cui il docente è collegato;
 *   S3  indirizzo, classe e materia sono voci DI QUELLA scuola, attive e
 *       spuntate dal docente, e la classe, se ha un corso, ha quel corso. Gli
 *       id arrivano dal client e non si credono: si rileggono qui;
 *   S6  ogni aggiunta, rimozione e cambio di stato va a registro con gli id,
 *       mai con il contenuto.
 *
 * Gli errori sono codici brevi (`InvalidArgumentException::getMessage()`), che
 * il controller traduce in 400/404 e il modale in una frase.
 */
final class DoveVale
{
    /**
     * Quante pubblicazioni al più per contenuto (domanda aperta 3 dell'ADR).
     * Quaranta sono tutte le sezioni di un corso quinquennale con otto
     * sezioni: oltre, l'elenco non si legge più, e più probabilmente si sta
     * pubblicando per errore.
     */
    public const LIMITE = 40;

    public const STATI = ['draft', 'published', 'archived'];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Il contenuto e le sue pubblicazioni, con i nomi per mostrarle.
     *
     * @return array{
     *   contenuto: array{id:int,title:string,visibility:string,publish_scope:string},
     *   pubblicazioni: list<array{id:int,scuola_id:int,scuola:string,indirizzo:?string,classe:?string,materia:?string,
     *     indirizzo_id:?int,classe_id:?int,materia_id:?int,principale:bool,origine:string,stato:string,archivio:bool}>,
     *   limite: int
     * }
     */
    public function elenco(int $contenuto, int $docente): array
    {
        $riga = $this->contenutoDelDocente($contenuto, $docente);
        $st = $this->db()->prepare(
            'SELECT p.id, p.institute_id, COALESCE(i.name, i.code) AS scuola,
                    ci.code AS indirizzo, cc.code AS classe, cm.code AS materia,
                    p.indirizzo_id, p.classe_id, p.subject_id,
                    p.is_primary, p.origine, p.visibility, p.archive_visible
               FROM content_publications p
               JOIN institutes i ON i.id = p.institute_id
               LEFT JOIN curriculum_entries ci ON ci.id = p.indirizzo_id
               LEFT JOIN curriculum_entries cc ON cc.id = p.classe_id
               LEFT JOIN curriculum_entries cm ON cm.id = p.subject_id
              WHERE p.teacher_content_id = ?
              ORDER BY p.is_primary DESC, scuola, ci.code, cc.code'
        );
        $st->execute([$contenuto]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'scuola_id'    => (int)$r['institute_id'],
                'scuola'       => (string)$r['scuola'],
                'indirizzo'    => $r['indirizzo'] !== null ? (string)$r['indirizzo'] : null,
                'classe'       => $r['classe'] !== null ? (string)$r['classe'] : null,
                'materia'      => $r['materia'] !== null ? (string)$r['materia'] : null,
                'indirizzo_id' => $r['indirizzo_id'] !== null ? (int)$r['indirizzo_id'] : null,
                'classe_id'    => $r['classe_id'] !== null ? (int)$r['classe_id'] : null,
                'materia_id'   => $r['subject_id'] !== null ? (int)$r['subject_id'] : null,
                'principale'   => (int)$r['is_primary'] === 1,
                'origine'      => (string)$r['origine'],
                'stato'        => (string)$r['visibility'],
                'archivio'     => (int)$r['archive_visible'] === 1,
            ];
        }
        return [
            'contenuto' => [
                'id'            => (int)$riga['id'],
                'title'         => (string)$riga['title'],
                'visibility'    => (string)$riga['visibility'],
                'publish_scope' => (string)$riga['publish_scope'],
            ],
            'pubblicazioni' => $out,
            'limite'        => self::LIMITE,
        ];
    }

    /**
     * I posti in cui il docente può pubblicare: le sue scuole, e in ognuna le
     * voci che ha spuntato (ADR-035). È la catena scuola → indirizzo → classe
     * → materia del modale.
     *
     * @return list<array{id:int,nome:string,indirizzi:list<array{id:int,code:string,label:string}>,
     *   classi:list<array{id:int,code:string,label:string,indirizzo:?string}>,
     *   materie:list<array{id:int,code:string,label:string}>}>
     */
    public function luoghi(int $docente): array
    {
        $st = $this->db()->prepare(
            'SELECT i.id AS scuola, COALESCE(i.name, i.code) AS nome,
                    ce.id, ce.kind, ce.code, ce.label, ce.indirizzo
               FROM teacher_institutes ti
               JOIN institutes i ON i.id = ti.institute_id AND i.active = 1
               LEFT JOIN curriculum_entries ce ON ce.institute_id = i.id AND ce.active = 1
                     AND EXISTS (SELECT 1 FROM curriculum_teacher ct
                                  WHERE ct.curriculum_id = ce.id AND ct.user_id = ti.user_id AND ct.active = 1)
              WHERE ti.user_id = ?
              ORDER BY nome, i.id, ce.kind, ce.code'
        );
        $st->execute([$docente]);
        $scuole = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = (int)$r['scuola'];
            $scuole[$s] ??= ['id' => $s, 'nome' => (string)$r['nome'], 'indirizzi' => [], 'classi' => [], 'materie' => []];
            if ($r['id'] === null) {
                continue;
            }
            $voce = ['id' => (int)$r['id'], 'code' => (string)$r['code'], 'label' => (string)$r['label']];
            match ((string)$r['kind']) {
                'indirizzi' => $scuole[$s]['indirizzi'][] = $voce,
                'classi'    => $scuole[$s]['classi'][] = $voce + ['indirizzo' => $r['indirizzo'] !== null ? (string)$r['indirizzo'] : null],
                'materie'   => $scuole[$s]['materie'][] = $voce,
                default     => null,
            };
        }
        return array_values($scuole);
    }

    /**
     * Aggiunge una pubblicazione del docente. Torna l'id.
     *
     * @throws InvalidArgumentException non_trovato · stato_non_valido · scuola_non_tua ·
     *   voce_non_valida · voce_non_spuntata · classe_di_un_altro_corso ·
     *   gia_pubblicato · troppe_pubblicazioni
     */
    public function aggiungi(
        int $contenuto,
        int $docente,
        int $scuola,
        int $indirizzo,
        int $classe,
        int $materia,
        string $stato = 'draft',
        bool $archivio = false,
    ): int {
        $this->contenutoDelDocente($contenuto, $docente);
        if (!\in_array($stato, self::STATI, true)) {
            throw new InvalidArgumentException('stato_non_valido');
        }
        // ADR-028 — pubblicare in più posti è la visibilità «più classi»: un
        // Istituto che la limita per un profilo di docente la limita anche qui
        // (in SINGLE la politica è permissiva).
        if (!(new \App\Services\TeacherCapabilityPolicy($this->pdo))->visibilityAllowed($docente, 'classes')) {
            throw new InvalidArgumentException('non_consentito');
        }
        $this->verificaLuogo($docente, $scuola, $indirizzo, $classe, $materia);

        $db = $this->db();
        $st = $db->prepare('SELECT COUNT(*) FROM content_publications WHERE teacher_content_id = ?');
        $st->execute([$contenuto]);
        if ((int)$st->fetchColumn() >= self::LIMITE) {
            throw new InvalidArgumentException('troppe_pubblicazioni');
        }
        $st = $db->prepare(
            'SELECT 1 FROM content_publications
              WHERE teacher_content_id = ? AND institute_id = ? AND indirizzo_id <=> ? AND classe_id <=> ?
              LIMIT 1'
        );
        $st->execute([$contenuto, $scuola, $indirizzo, $classe]);
        if ($st->fetchColumn()) {
            throw new InvalidArgumentException('gia_pubblicato');
        }

        $db->prepare(
            'INSERT INTO content_publications
                (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id,
                 is_primary, origine, visibility, archive_visible)
             VALUES (?, ?, ?, ?, ?, 0, "docente", ?, ?)'
        )->execute([$contenuto, $scuola, $indirizzo, $classe, $materia, $stato, $archivio ? 1 : 0]);
        $id = (int)$db->lastInsertId();

        ActivityLogger::event('pubblicazione_aggiunta', 'teacher_content', (string)$contenuto, [
            'pubblicazione' => $id,
            'istituto'      => $scuola,
            'indirizzo'     => $indirizzo,
            'classe'        => $classe,
            'materia'       => $materia,
            'stato'         => $stato,
        ]);
        return $id;
    }

    /**
     * Toglie una pubblicazione del docente. La principale non si toglie: si
     * sposta con la riga.
     *
     * @throws InvalidArgumentException non_trovato · principale_non_si_toglie
     */
    public function togli(int $pubblicazione, int $docente): void
    {
        $p = $this->pubblicazioneDelDocente($pubblicazione, $docente);
        $this->db()->prepare('DELETE FROM content_publications WHERE id = ? AND origine = "docente"')
            ->execute([$pubblicazione]);
        ActivityLogger::event('pubblicazione_tolta', 'teacher_content', (string)$p['contenuto'], [
            'pubblicazione' => $pubblicazione,
            'istituto'      => $p['istituto'],
        ]);
    }

    /**
     * Cambia lo stato (e, se dato, «visibile anche dopo l'anno») di una
     * pubblicazione del docente. Lo stato della principale segue la visibilità
     * del documento, che si sceglie nel modale.
     *
     * @throws InvalidArgumentException non_trovato · principale_non_si_toglie · stato_non_valido
     */
    public function impostaStato(int $pubblicazione, int $docente, string $stato, ?bool $archivio = null): void
    {
        if (!\in_array($stato, self::STATI, true)) {
            throw new InvalidArgumentException('stato_non_valido');
        }
        $p = $this->pubblicazioneDelDocente($pubblicazione, $docente);
        $colonne = 'visibility = ?';
        $args = [$stato];
        if ($archivio !== null) {
            $colonne .= ', archive_visible = ?';
            $args[] = $archivio ? 1 : 0;
        }
        $args[] = $pubblicazione;
        $this->db()->prepare("UPDATE content_publications SET {$colonne} WHERE id = ? AND origine = 'docente'")
            ->execute($args);
        ActivityLogger::event('pubblicazione_stato', 'teacher_content', (string)$p['contenuto'], [
            'pubblicazione' => $pubblicazione,
            'da'            => $p['stato'],
            'a'             => $stato,
        ] + ($archivio !== null ? ['archivio' => $archivio] : []));
    }

    /**
     * S2 e S3 su un posto: la scuola è del docente; indirizzo, classe e
     * materia sono voci attive di quella scuola, spuntate da lui; la classe,
     * se ha un corso, ha quello. Pubblica perché la usa anche la copia.
     *
     * @throws InvalidArgumentException scuola_non_tua · voce_non_valida · voce_non_spuntata · classe_di_un_altro_corso
     */
    public function verificaLuogo(int $docente, int $scuola, int $indirizzo, int $classe, int $materia): void
    {
        $db = $this->db();
        $st = $db->prepare('SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ? LIMIT 1');
        $st->execute([$docente, $scuola]);
        if (!$st->fetchColumn()) {
            throw new InvalidArgumentException('scuola_non_tua');
        }
        $st = $db->prepare(
            'SELECT ce.id, ce.kind, ce.code, ce.indirizzo, ce.institute_id, ce.active,
                    EXISTS (SELECT 1 FROM curriculum_teacher ct
                             WHERE ct.curriculum_id = ce.id AND ct.user_id = ? AND ct.active = 1) AS spuntata
               FROM curriculum_entries ce
              WHERE ce.id IN (?, ?, ?)'
        );
        $st->execute([$docente, $indirizzo, $classe, $materia]);
        $voci = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $voci[(int)$r['id']] = $r;
        }
        foreach ([[$indirizzo, 'indirizzi'], [$classe, 'classi'], [$materia, 'materie']] as [$id, $kind]) {
            $v = $voci[$id] ?? null;
            if ($v === null || $v['kind'] !== $kind || (int)$v['institute_id'] !== $scuola || (int)$v['active'] !== 1) {
                throw new InvalidArgumentException('voce_non_valida');
            }
            if ((int)$v['spuntata'] !== 1) {
                throw new InvalidArgumentException('voce_non_spuntata');
            }
        }
        $corso = $voci[$classe]['indirizzo'];
        if ($corso !== null && $corso !== '' && strcasecmp((string)$corso, (string)$voci[$indirizzo]['code']) !== 0) {
            throw new InvalidArgumentException('classe_di_un_altro_corso');
        }
    }

    /** @return array{id:int|string,title:string,visibility:string,publish_scope:string} */
    private function contenutoDelDocente(int $contenuto, int $docente): array
    {
        $st = $this->db()->prepare(
            'SELECT id, teacher_id, title, visibility, publish_scope FROM teacher_content_data WHERE id = ? LIMIT 1'
        );
        $st->execute([$contenuto]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['teacher_id'] !== $docente || $docente <= 0) {
            throw new InvalidArgumentException('non_trovato');
        }
        return $r;
    }

    /** @return array{contenuto:int,istituto:int,stato:string} */
    private function pubblicazioneDelDocente(int $pubblicazione, int $docente): array
    {
        $st = $this->db()->prepare(
            'SELECT p.teacher_content_id, p.institute_id, p.origine, p.visibility, d.teacher_id
               FROM content_publications p
               JOIN teacher_content_data d ON d.id = p.teacher_content_id
              WHERE p.id = ? LIMIT 1'
        );
        $st->execute([$pubblicazione]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['teacher_id'] !== $docente || $docente <= 0) {
            throw new InvalidArgumentException('non_trovato');
        }
        if ((string)$r['origine'] !== 'docente') {
            throw new InvalidArgumentException('principale_non_si_toglie');
        }
        return ['contenuto' => (int)$r['teacher_content_id'], 'istituto' => (int)$r['institute_id'], 'stato' => (string)$r['visibility']];
    }
}
