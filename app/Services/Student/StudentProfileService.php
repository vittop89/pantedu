<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Core\Database;

/**
 * Punto unico per l'acquisizione e l'uso dei dati di SCOPE dello studente
 * (istituto + indirizzo + classe). Centralizza la derivazione di `course`
 * e fornisce lo scope autoritativo (da DB) usato per ancorare la visibilità
 * dei contenuti all'ACCOUNT registrato (non ai parametri URL).
 *
 * Le colonne users.indirizzo/users.classe sono aggiunte dalla migration 091;
 * institute_id esiste da Phase 13.
 */
final class StudentProfileService
{
    /** Compone il course canonico "{indirizzo}.{classe}" (null se incompleto). */
    public static function course(?string $indirizzo, ?string $classe): ?string
    {
        $i = trim((string)$indirizzo);
        $c = trim((string)$classe);
        return ($i !== '' && $c !== '') ? "{$i}.{$c}" : null;
    }

    /**
     * Scope studente dal DB (autoritativo).
     *
     * @return array{institute_id:?int,indirizzo:?string,classe:?string}|null
     *         null se l'utente non esiste / DB non disponibile.
     */
    public function scopeForUser(int $userId): ?array
    {
        if ($userId <= 0 || !Database::isAvailable()) {
            return null;
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT institute_id, indirizzo, classe FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $norm = static fn($v): ?string => ($v !== null && (string)$v !== '') ? (string)$v : null;
            return [
                'institute_id' => $row['institute_id'] !== null ? (int)$row['institute_id'] : null,
                'indirizzo'    => $norm($row['indirizzo'] ?? null),
                'classe'       => $norm($row['classe'] ?? null),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
