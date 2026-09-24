<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * RegistrationPolicy — ADR-028 Fase 1: classi ammesse all'iscrizione.
 *
 * Allowlist di coppie (indirizzo, classe) per la registrazione studente.
 * Trasversale (vale anche in modo SINGLE). Semantica:
 *   - nessuna riga  → nessuna restrizione (tutte ammesse, retrocompat);
 *   - >= 1 riga     → SOLO le coppie elencate sono ammesse.
 *
 * Fail-open su DB non disponibile (non bloccare la registrazione per un errore
 * infrastrutturale): se la query fallisce, isClassAllowed ritorna true.
 */
final class RegistrationPolicy
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Esistono restrizioni configurate? (tabella non vuota)
     */
    public function hasRestrictions(?int $instituteId = null): bool
    {
        try {
            // Per uno specifico istituto: contano sia le righe di quell'istituto
            // sia quelle globali (institute_id IS NULL).
            if ($instituteId === null) {
                $sql = 'SELECT 1 FROM registration_allowed_classes WHERE institute_id IS NULL LIMIT 1';
                $stmt = $this->db()->prepare($sql);
                $stmt->execute([]);
            } else {
                $sql = 'SELECT 1 FROM registration_allowed_classes WHERE institute_id = ? OR institute_id IS NULL LIMIT 1';
                $stmt = $this->db()->prepare($sql);
                $stmt->execute([$instituteId]);
            }
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * La coppia (indirizzo, classe) è ammessa all'iscrizione?
     * Se non ci sono restrizioni → true. Fail-open su errore DB.
     */
    public function isClassAllowed(string $indirizzo, string $classe, ?int $instituteId = null): bool
    {
        $indirizzo = trim($indirizzo);
        $classe    = trim($classe);
        try {
            if (!$this->hasRestrictions($instituteId)) {
                return true;
            }
            // Se l'utente non specifica indirizzo/classe ma ci sono restrizioni,
            // il dato è obbligatorio → nega.
            if ($indirizzo === '' || $classe === '') {
                return false;
            }
            // Match: riga specifica per l'istituto OPPURE riga globale (NULL).
            if ($instituteId === null) {
                $sql = 'SELECT 1 FROM registration_allowed_classes
                        WHERE indirizzo = ? AND classe = ? AND institute_id IS NULL LIMIT 1';
                $params = [$indirizzo, $classe];
            } else {
                $sql = 'SELECT 1 FROM registration_allowed_classes
                        WHERE indirizzo = ? AND classe = ? AND (institute_id = ? OR institute_id IS NULL) LIMIT 1';
                $params = [$indirizzo, $classe, $instituteId];
            }
            $stmt = $this->db()->prepare($sql);
            $stmt->execute($params);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return true; // fail-open
        }
    }

    /**
     * @return list<array{id:int,indirizzo:string,classe:string,institute_id:?int}>
     */
    public function all(?int $instituteId = null): array
    {
        try {
            $sql = 'SELECT id, institute_id, indirizzo, classe FROM registration_allowed_classes WHERE '
                 . ($instituteId === null ? 'institute_id IS NULL' : 'institute_id = ?')
                 . ' ORDER BY indirizzo, classe';
            $stmt = $this->db()->prepare($sql);
            $stmt->execute($instituteId === null ? [] : [$instituteId]);
            return array_map(static fn($r) => [
                'id'           => (int)$r['id'],
                'institute_id' => $r['institute_id'] !== null ? (int)$r['institute_id'] : null,
                'indirizzo'    => (string)$r['indirizzo'],
                'classe'       => (string)$r['classe'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Tutte le righe, di ogni istituto (per la tabella admin).
     * @return list<array{id:int,indirizzo:string,classe:string,institute_id:?int}>
     */
    public function allAny(): array
    {
        try {
            $stmt = $this->db()->query(
                'SELECT id, institute_id, indirizzo, classe FROM registration_allowed_classes ORDER BY institute_id, indirizzo, classe'
            );
            return array_map(static fn($r) => [
                'id'           => (int)$r['id'],
                'institute_id' => $r['institute_id'] !== null ? (int)$r['institute_id'] : null,
                'indirizzo'    => (string)$r['indirizzo'],
                'classe'       => (string)$r['classe'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    public function add(string $indirizzo, string $classe, ?int $instituteId = null, ?string $by = null): bool
    {
        $indirizzo = trim($indirizzo);
        $classe    = trim($classe);
        if ($indirizzo === '' || $classe === '' || strlen($indirizzo) > 64 || strlen($classe) > 32) {
            return false;
        }
        try {
            $stmt = $this->db()->prepare(
                'INSERT IGNORE INTO registration_allowed_classes (institute_id, indirizzo, classe, created_by)
                 VALUES (?, ?, ?, ?)'
            );
            return $stmt->execute([$instituteId, $indirizzo, $classe, $by]);
        } catch (Throwable) {
            return false;
        }
    }

    public function remove(int $id): bool
    {
        try {
            return $this->db()->prepare('DELETE FROM registration_allowed_classes WHERE id = ?')->execute([$id]);
        } catch (Throwable) {
            return false;
        }
    }
}
