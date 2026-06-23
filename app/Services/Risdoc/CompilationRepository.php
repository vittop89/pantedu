<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
use PDO;

/**
 * Repository per risdoc_compilations.
 *
 * Ogni compilazione è un'istanza valorizzata del template legata al
 * docente loggato + `compilation_key` (slug dei campi del
 * .dynamic-selector-container: classe/sezione/indirizzo/disciplina).
 * UPSERT per sovrascrivere salvataggi precedenti con stessa chiave.
 */
final class CompilationRepository
{
    /** Upsert: insert o update se esiste già (teacher_id+template_id+compilation_key). */
    public function save(int $teacherId, int $templateId, string $compilationKey, string $label, ?string $classe, ?string $sezione, ?string $indirizzo, ?string $disciplina, string $dataJson): int
    {
        // Fase D — solo FK ids (varchar dropped)
        $L = \App\Support\CurriculumLookup::class;
        $indId = $indirizzo !== null && $indirizzo !== ''
            ? $L::idFromCodeForTeacher('indirizzi', (string)$indirizzo, $teacherId) : null;
        $clsId = $classe !== null && $classe !== ''
            ? $L::idFromCodeForTeacher('classi', (string)$classe, $teacherId) : null;
        $stmt = Database::connection()->prepare('INSERT INTO risdoc_compilations_data
                (teacher_id, template_id, compilation_key, label,
                 classe_id, sezione, indirizzo_id, disciplina, data_json)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                label=VALUES(label),
                classe_id=VALUES(classe_id),
                sezione=VALUES(sezione),
                indirizzo_id=VALUES(indirizzo_id),
                disciplina=VALUES(disciplina),
                data_json=VALUES(data_json)');
        $stmt->execute([
            $teacherId, $templateId, $compilationKey, $label,
            $clsId, $sezione ?: null, $indId, $disciplina ?: null,
            $dataJson,
        ]);
        $lastId = (int)Database::connection()->lastInsertId();
        if ($lastId > 0) {
            return $lastId;
        }
        // Su UPDATE path, lastInsertId=0: lookup via unique key.
        $q = Database::connection()->prepare('SELECT id FROM risdoc_compilations
             WHERE teacher_id=? AND template_id=? AND compilation_key=? LIMIT 1');
        $q->execute([$teacherId, $templateId, $compilationKey]);
        return (int)$q->fetchColumn();
    }

    /** Lista compilazioni del docente per un template, ordinate per updated_at desc.
     *  Filtri opzionali per matchare il contesto corrente del form. */
    public function listByTeacher(int $teacherId, int $templateId, ?string $classe = null, ?string $sezione = null, ?string $indirizzo = null, ?string $disciplina = null): array
    {
        $sql = 'SELECT id, compilation_key, label, classe, sezione, indirizzo,
                       disciplina, created_at, updated_at
                FROM risdoc_compilations
                WHERE teacher_id=? AND template_id=?';
        $args = [$teacherId, $templateId];
        // Filtri opzionali: match stretto se passato, ignora se null.
        if ($classe !== null) {
            $sql .= ' AND classe = ?';
            $args[] = $classe;
        }
        if ($sezione !== null) {
            $sql .= ' AND sezione = ?';
            $args[] = $sezione;
        }
        if ($indirizzo !== null) {
            $sql .= ' AND indirizzo = ?';
            $args[] = $indirizzo;
        }
        if ($disciplina !== null) {
            $sql .= ' AND disciplina = ?';
            $args[] = $disciplina;
        }
        $sql .= ' ORDER BY updated_at DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $teacherId, int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, template_id, compilation_key, label, classe, sezione,
                    indirizzo, disciplina, data_json, created_at, updated_at
             FROM risdoc_compilations
             WHERE teacher_id=? AND id=? LIMIT 1');
        $stmt->execute([$teacherId, $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function delete(int $teacherId, int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM risdoc_compilations_data WHERE teacher_id=? AND id=?');
        $stmt->execute([$teacherId, $id]);
        return $stmt->rowCount() > 0;
    }
}
