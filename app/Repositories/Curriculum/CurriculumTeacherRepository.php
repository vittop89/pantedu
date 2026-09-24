<?php

declare(strict_types=1);

namespace App\Repositories\Curriculum;

use App\Core\Database;
use PDO;

/**
 * La relazione docente↔voce del vocabolario (`curriculum_teacher`, ADR-035).
 *
 * Il vocabolario è dell'istituto: una riga per (kind, code, istituto) in
 * `curriculum_entries`. Il docente non possiede voci, le SPUNTA: una riga qui
 * dice che quella voce sta nei suoi menù, se è attiva, se i suoi contenuti in
 * quella voce vanno nel pool dei colleghi, e con quale etichetta lui vuole
 * vederla. Tutto ciò che prima stava sulla copia per docente sta qui, sulla
 * relazione — che è dove stanno gli attributi di una relazione.
 *
 * Qui c'è solo SQL su questa tabella e il join con il vocabolario. Chi decide
 * (il docente può attivare solo voci che la scuola ha) sta nei servizi.
 */
final class CurriculumTeacherRepository
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /** Spunta la voce per il docente; se era spenta la riaccende. Idempotente. */
    public function attiva(int $curriculumId, int $userId): void
    {
        $this->db()->prepare(
            'INSERT INTO curriculum_teacher (curriculum_id, user_id, active)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE active = 1'
        )->execute([$curriculumId, $userId]);
    }

    /** Toglie la spunta senza cancellare la riga: i contenuti puntano alla voce, non alla relazione. */
    public function disattiva(int $curriculumId, int $userId): bool
    {
        $st = $this->db()->prepare(
            'UPDATE curriculum_teacher SET active = 0 WHERE curriculum_id = ? AND user_id = ?'
        );
        $st->execute([$curriculumId, $userId]);
        return $st->rowCount() > 0;
    }

    /** Cancella la relazione. La voce della scuola resta. */
    public function rimuovi(int $curriculumId, int $userId): bool
    {
        $st = $this->db()->prepare(
            'DELETE FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ?'
        );
        $st->execute([$curriculumId, $userId]);
        return $st->rowCount() > 0;
    }

    /**
     * Aggiorna gli attributi della relazione, creandola se manca.
     *
     * @param array{active?:bool,shared_with_pool?:bool,label_override?:?string} $patch
     */
    public function aggiorna(int $curriculumId, int $userId, array $patch): void
    {
        $sets = [];
        $vals = [];
        if (array_key_exists('active', $patch)) {
            $sets[] = 'active = ?';
            $vals[] = (int)$patch['active'];
        }
        if (array_key_exists('shared_with_pool', $patch)) {
            $sets[] = 'shared_with_pool = ?';
            $vals[] = (int)$patch['shared_with_pool'];
        }
        if (array_key_exists('label_override', $patch)) {
            $sets[] = 'label_override = ?';
            $vals[] = $patch['label_override'];
        }
        $this->attiva($curriculumId, $userId);
        if ($sets === []) {
            return;
        }
        $vals[] = $curriculumId;
        $vals[] = $userId;
        $this->db()->prepare(
            'UPDATE curriculum_teacher SET ' . implode(', ', $sets)
            . ' WHERE curriculum_id = ? AND user_id = ?'
        )->execute($vals);
    }

    /** Il docente ha questa voce (attiva, se richiesto)? */
    public function esiste(int $curriculumId, int $userId, bool $soloAttive = false): bool
    {
        $st = $this->db()->prepare(
            'SELECT 1 FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ?'
            . ($soloAttive ? ' AND active = 1' : '') . ' LIMIT 1'
        );
        $st->execute([$curriculumId, $userId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * La relazione di un docente su una voce, o null.
     *
     * @return array{active:bool,shared_with_pool:bool,label_override:?string}|null
     */
    public function trova(int $curriculumId, int $userId): ?array
    {
        $st = $this->db()->prepare(
            'SELECT active, shared_with_pool, label_override
               FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ? LIMIT 1'
        );
        $st->execute([$curriculumId, $userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        return [
            'active'           => (bool)$r['active'],
            'shared_with_pool' => (bool)$r['shared_with_pool'],
            'label_override'   => $r['label_override'] !== null ? (string)$r['label_override'] : null,
        ];
    }

    /**
     * Le voci spuntate da un docente, con i dati della scuola e quelli della
     * relazione. Etichetta: l'override del docente, altrimenti quella della
     * scuola (`label_istituto` la porta comunque).
     *
     * @return list<array<string,mixed>>
     */
    public function perDocente(int $userId, ?int $instituteId = null, ?string $kind = null, bool $soloAttive = false): array
    {
        $sql = "SELECT ce.id, ce.kind, ce.institute_id, ce.code,
                       COALESCE(ct.label_override, ce.label) AS label,
                       ce.label AS label_istituto, ct.label_override,
                       ce.grp, ce.indirizzo, ce.origine,
                       ct.active, ct.shared_with_pool, ct.created_at AS attivata_il,
                       COALESCE(i.name, '') AS institute_name,
                       COALESCE(i.code, '') AS institute_code
                  FROM curriculum_teacher ct
                  JOIN curriculum_entries ce ON ce.id = ct.curriculum_id
                  LEFT JOIN institutes i ON i.id = ce.institute_id
                 WHERE ct.user_id = ? AND ct.sospesa_dalla_scuola = 0";
        // ADR-041 — una spunta sospesa dalla modalità delle sezioni non è del
        // docente da gestire: non la vede e non la riaccende.
        $args = [$userId];
        if ($instituteId !== null) {
            $sql .= ' AND ce.institute_id = ?';
            $args[] = $instituteId;
        }
        if ($kind !== null) {
            $sql .= ' AND ce.kind = ?';
            $args[] = $kind;
        }
        if ($soloAttive) {
            $sql .= ' AND ct.active = 1 AND ce.active = 1';
        }
        $sql .= ' ORDER BY ce.kind, ce.institute_id, label';
        $st = $this->db()->prepare($sql);
        $st->execute($args);
        /** @var list<array<string,mixed>> $rows */
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Quanti docenti hanno spuntato ogni voce di un istituto: per il pannello
     * del catalogo, dove una voce con dieci docenti sopra non si cancella a
     * cuor leggero.
     *
     * @return array<int,int> curriculum_id → docenti
     */
    public function docentiPerVoce(int $instituteId): array
    {
        $st = $this->db()->prepare(
            'SELECT ct.curriculum_id, COUNT(*) AS n
               FROM curriculum_teacher ct
               JOIN curriculum_entries ce ON ce.id = ct.curriculum_id
              WHERE ce.institute_id = ?
              GROUP BY ct.curriculum_id'
        );
        $st->execute([$instituteId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['curriculum_id']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Le voci che i colleghi dello stesso istituto condividono nel pool,
     * escluso il docente che guarda. Solo voci attive da entrambe le parti.
     *
     * @return list<array{id:int,code:string,label:string,owner_user_id:int,owner_name:string}>
     */
    public function condiviseDaiColleghi(int $instituteId, int $teacherId, string $kind = 'materie'): array
    {
        $st = $this->db()->prepare(
            "SELECT ce.id, ce.code, COALESCE(ct.label_override, ce.label) AS label, ct.user_id,
                    COALESCE(u.first_name, u.username, '') AS owner_first,
                    COALESCE(u.last_name, '') AS owner_last
               FROM curriculum_teacher ct
               JOIN curriculum_entries ce ON ce.id = ct.curriculum_id
               JOIN users u ON u.id = ct.user_id
               JOIN teacher_institutes ti ON ti.user_id = ct.user_id AND ti.institute_id = ce.institute_id
              WHERE ce.kind = ? AND ce.institute_id = ?
                AND ct.shared_with_pool = 1 AND ct.active = 1 AND ce.active = 1
                AND ct.user_id <> ?
              ORDER BY label, owner_last"
        );
        $st->execute([$kind, $instituteId, $teacherId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim(($r['owner_first'] ?? '') . ' ' . ($r['owner_last'] ?? ''));
            $out[] = [
                'id'            => (int)$r['id'],
                'code'          => (string)$r['code'],
                'label'         => (string)$r['label'],
                'owner_user_id' => (int)$r['user_id'],
                'owner_name'    => $name !== '' ? $name : ('user#' . $r['user_id']),
            ];
        }
        return $out;
    }
}
