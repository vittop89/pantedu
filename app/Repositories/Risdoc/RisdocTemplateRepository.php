<?php

declare(strict_types=1);

namespace App\Repositories\Risdoc;

use App\Core\Database;
use PDO;

/**
 * Accesso ai dati dei template risdoc lato amministrazione: catalogo con i
 * conteggi, visibilita' per docente, ambito di visibilita', collaboratori,
 * utenti dello staff e override in deriva.
 *
 * Estratto da Admin\RisdocAdminController il 2026-09-04 (revisione
 * architetturale, intervento P6): SQL copiato tale e quale. Le validazioni
 * (regex di category/num_arg, whitelist dei campi) restano nel controller;
 * qui `updateFields()` accetta solo colonne note per non fidarsi del
 * chiamante sul nome della colonna.
 */
final class RisdocTemplateRepository
{
    /** Colonne modificabili da updateMeta (whitelist in doppio: controller e repository). */
    private const META_COLUMNS = ['argomento', 'num_arg', 'category'];

    private function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * Catalogo completo con i conteggi per il pannello (visibili, collaboratori,
     * override, in deriva, modifiche in attesa). G22.S26 — owner_id rimossa
     * (migration 047).
     *
     * @return list<array<string,mixed>>
     */
    public function listWithCounts(): array
    {
        return $this->pdo()->query("
            SELECT t.id, t.code, t.category, t.num_arg, t.argomento, t.discipline,
                   t.source_hash,
                   -- 21/9/2026 — chi vede il modello si legge nell'elenco, non
                   -- solo aprendo il pannello: è la prima cosa che serve
                   -- all'amministratore che deve decidere.
                   t.visibility_scope, t.scope_institute_id, t.scope_indirizzo, t.scope_classe,
                   (SELECT COUNT(*) FROM risdoc_template_visibility v WHERE v.template_id=t.id AND v.visible=1) AS visible_count,
                   (SELECT COUNT(*) FROM risdoc_template_collaborators c WHERE c.template_id=t.id) AS collab_count,
                   (SELECT COUNT(*) FROM risdoc_teacher_overrides o WHERE o.template_id=t.id) AS override_count,
                   (SELECT COUNT(*) FROM risdoc_teacher_overrides o WHERE o.template_id=t.id AND o.source_version != t.source_hash) AS drift_count,
                   (SELECT COUNT(*) FROM risdoc_template_pending_changes pc WHERE pc.template_id=t.id AND pc.status='pending') AS pending_count
            FROM risdoc_templates t
            ORDER BY t.category, t.num_arg
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $tmpl = $this->pdo()->prepare('SELECT * FROM risdoc_templates WHERE id=?');
        $tmpl->execute([$id]);
        $t = $tmpl->fetch(PDO::FETCH_ASSOC);
        return $t ?: null;
    }

    public function exists(int $id): bool
    {
        $exists = $this->pdo()->prepare('SELECT 1 FROM risdoc_templates WHERE id=? LIMIT 1');
        $exists->execute([$id]);
        return (bool)$exists->fetchColumn();
    }

    public function codeExists(string $code): bool
    {
        $chk = $this->pdo()->prepare('SELECT 1 FROM risdoc_templates WHERE code=? LIMIT 1');
        $chk->execute([$code]);
        return (bool)$chk->fetchColumn();
    }

    /**
     * Istituti attivi, per l'ambito di visibilità «istituto» del pannello.
     *
     * @return list<array{id:int,name:string}>
     */
    public function istitutiAttivi(): array
    {
        $righe = $this->pdo()->query(
            'SELECT id, name FROM institutes WHERE active = 1 ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(
            static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
            $righe,
        );
    }

    public function instituteExists(int $instituteId): bool
    {
        $instChk = $this->pdo()->prepare('SELECT 1 FROM institutes WHERE id=? LIMIT 1');
        $instChk->execute([$instituteId]);
        return (bool)$instChk->fetchColumn();
    }

    /**
     * Visibilita' per docente (username incluso), per username.
     *
     * @return list<array<string,mixed>>
     */
    public function visibilityOf(int $templateId): array
    {
        $vis = $this->pdo()->prepare('SELECT v.teacher_id, u.username, v.visible FROM risdoc_template_visibility v JOIN users u ON u.id=v.teacher_id WHERE v.template_id=? ORDER BY u.username');
        $vis->execute([$templateId]);
        return $vis->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Collaboratori (G22.S26: con requires_review), per username.
     *
     * @return list<array<string,mixed>>
     */
    public function collaboratorsOf(int $templateId): array
    {
        $collab = $this->pdo()->prepare('SELECT c.teacher_id, u.username, c.requires_review, c.invited_at FROM risdoc_template_collaborators c JOIN users u ON u.id=c.teacher_id WHERE c.template_id=? ORDER BY u.username');
        $collab->execute([$templateId]);
        return $collab->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Imposta la visibilita' del template per i docenti dati (upsert);
     * restituisce quante righe ha toccato.
     *
     * @param list<int|string> $teacherIds
     */
    public function setVisibility(int $templateId, array $teacherIds, int $visible, ?int $grantedBy): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO risdoc_template_visibility (template_id, teacher_id, visible, granted_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE visible=VALUES(visible), granted_by=VALUES(granted_by), granted_at=CURRENT_TIMESTAMP');
        $n = 0;
        foreach ($teacherIds as $tid) {
            $stmt->execute([$templateId, (int)$tid, $visible, $grantedBy]);
            $n++;
        }
        return $n;
    }

    public function setVisibilityScope(int $templateId, string $scope, ?int $instituteId, ?string $indirizzo, ?string $classe): void
    {
        $stmt = $this->pdo()->prepare('UPDATE risdoc_templates
             SET visibility_scope=?, scope_institute_id=?, scope_indirizzo=?, scope_classe=?
             WHERE id=?');
        $stmt->execute([$scope, $instituteId, $indirizzo, $classe, $templateId]);
    }

    /**
     * Aggiorna i metadati editabili (argomento, num_arg, category).
     *
     * @param array<string, string> $fields colonna => valore, gia' validato
     */
    public function updateFields(int $templateId, array $fields): void
    {
        $set = [];
        $args = [];
        foreach ($fields as $col => $val) {
            if (!in_array($col, self::META_COLUMNS, true)) {
                throw new \InvalidArgumentException('colonna non ammessa: ' . $col);
            }
            $set[] = $col . ' = ?';
            $args[] = $val;
        }
        if (!$set) {
            return;
        }
        $args[] = $templateId;
        $stmt = $this->pdo()->prepare('UPDATE risdoc_templates SET ' . implode(', ', $set) . ' WHERE id = ?');
        $stmt->execute($args);
    }

    /** Rinomina una partizione (category) per tutti i suoi template; righe toccate. */
    public function renameCategory(string $from, string $to): int
    {
        $stmt = $this->pdo()->prepare('UPDATE risdoc_templates SET category = ? WHERE category = ?');
        $stmt->execute([$to, $from]);
        return $stmt->rowCount();
    }

    /**
     * Crea un template amministrativo (Phase 24.57): sorgente `db://admin`,
     * file `{code}.pt`, visibilita' `public`. Restituisce l'id.
     */
    public function createAdminTemplate(string $code, string $category, string $numArg, string $argomento, string $bodyPt, string $sourceHash): int
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO risdoc_templates
               (code, category, num_arg, argomento, discipline,
                source_dir, html_file, body_pt, source_hash, visibility_scope)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code, $category, $numArg, $argomento,
            'db://admin', $code . '.pt', $bodyPt, $sourceHash, 'public',
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Aggiunge (o aggiorna il flag di revisione di) un collaboratore. */
    public function addCollaborator(int $templateId, int $teacherId, ?int $invitedBy, int $requiresReview): void
    {
        $this->pdo()->prepare(
            'INSERT INTO risdoc_template_collaborators
                (template_id, teacher_id, invited_by, requires_review)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE requires_review = VALUES(requires_review)'
        )->execute([$templateId, $teacherId, $invitedBy, $requiresReview]);
    }

    public function setCollaboratorReview(int $templateId, int $teacherId, int $requiresReview): void
    {
        $this->pdo()->prepare(
            'UPDATE risdoc_template_collaborators
                SET requires_review = ?
              WHERE template_id = ? AND teacher_id = ?'
        )->execute([$requiresReview, $templateId, $teacherId]);
    }

    public function removeCollaborator(int $templateId, int $teacherId): void
    {
        $this->pdo()->prepare('DELETE FROM risdoc_template_collaborators WHERE template_id=? AND teacher_id=?')
            ->execute([$templateId, $teacherId]);
    }

    /**
     * Utenti dello staff (docenti, amministratori, collaboratori, super-admin) attivi.
     *
     * @return list<array<string,mixed>>
     */
    public function staffUsers(): array
    {
        // 21/9/2026 — `is_super_admin` serve al pannello dei permessi: chi lo
        // è vede e modifica tutto a prescindere dalle spunte, e mostrargli tre
        // caselle vuote raccontava il contrario.
        return $this->pdo()->query("SELECT id, username, first_name, last_name, role, is_super_admin
               FROM users
             WHERE role IN ('teacher','administrator') AND active=1
             ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Override dei docenti la cui versione sorgente non coincide piu' con
     * l'hash del template (drift).
     *
     * @return list<array<string,mixed>>
     */
    public function driftedOverrides(): array
    {
        return $this->pdo()->query("
            SELECT o.id, o.teacher_id, u.username, o.template_id, t.code, o.kind, o.relative_path,
                   o.source_version, t.source_hash, o.updated_at
            FROM risdoc_teacher_overrides o
            JOIN risdoc_templates t ON t.id=o.template_id
            JOIN users u            ON u.id=o.teacher_id
            WHERE o.source_version != t.source_hash
            ORDER BY o.updated_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}
