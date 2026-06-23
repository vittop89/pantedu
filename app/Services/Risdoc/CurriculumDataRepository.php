<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;

/**
 * ADR-025 (B) — Repository per i dati curriculari risdoc (obiettivi/competenze/
 * abilità/conoscenze/programmi/minimi) come DATI ISTITUZIONALI dinamici.
 *
 * Tabella `risdoc_curriculum_data` (migration 067). Risoluzione a cascata:
 *   institute_id = N (override istituto)  →  institute_id = 0 (globale/seed).
 * Il chiamante (endpoint) applica poi il fallback al file statico se entrambe
 * mancano. I codici (dataset/indirizzo/classe/materia) sono CANONICI e dinamici
 * (da curriculum_entries) — nessuna mappa hardcoded.
 */
final class CurriculumDataRepository
{
    /**
     * Risolve le opzioni per (dataset, indirizzo, classe, materia): prima
     * l'override dell'istituto, poi la riga globale (0). Null se nessuna delle
     * due esiste (→ il chiamante fa fallback al file statico).
     *
     * @return array<int,mixed>|null  Array opzioni decodificato, o null.
     */
    public function find(int $instituteId, string $dataset, string $indirizzo, string $classe, string $materia): ?array
    {
        // Difensivo: se la tabella non esiste ancora (migration non applicata)
        // o errore DB → null → il chiamante fa fallback al file statico.
        try {
            $sql = 'SELECT body FROM risdoc_curriculum_data
                     WHERE dataset = ? AND indirizzo = ? AND classe = ? AND materia = ?
                       AND institute_id IN (?, 0)
                     ORDER BY institute_id DESC LIMIT 1'; // institute_id N prima di 0
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute([$dataset, $indirizzo, $classe, $materia, $instituteId]);
            $body = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        if ($body === false || $body === null) {
            return null;
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Upsert di una riga (institute_id specifico o 0 globale). */
    public function save(int $instituteId, string $dataset, string $indirizzo, string $classe, string $materia, array $body, ?int $updatedBy = null): void
    {
        $json = json_encode(array_values($body), JSON_UNESCAPED_UNICODE);
        $stmt = Database::connection()->prepare(
            'INSERT INTO risdoc_curriculum_data
                 (institute_id, dataset, indirizzo, classe, materia, body, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$instituteId, $dataset, $indirizzo, $classe, $materia, $json, $updatedBy]);
    }

    public function delete(int $instituteId, string $dataset, string $indirizzo, string $classe, string $materia): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM risdoc_curriculum_data
              WHERE institute_id = ? AND dataset = ? AND indirizzo = ? AND classe = ? AND materia = ?'
        );
        $stmt->execute([$instituteId, $dataset, $indirizzo, $classe, $materia]);
        return $stmt->rowCount() > 0;
    }

    /** True se esiste già una riga globale (institute_id=0) per questa chiave (per seed idempotente). */
    public function hasGlobal(string $dataset, string $indirizzo, string $classe, string $materia): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM risdoc_curriculum_data
              WHERE institute_id = 0 AND dataset = ? AND indirizzo = ? AND classe = ? AND materia = ? LIMIT 1'
        );
        $stmt->execute([$dataset, $indirizzo, $classe, $materia]);
        return (bool)$stmt->fetchColumn();
    }

    public function countGlobal(): int
    {
        return (int)Database::connection()
            ->query('SELECT COUNT(*) FROM risdoc_curriculum_data WHERE institute_id = 0')
            ->fetchColumn();
    }
}
