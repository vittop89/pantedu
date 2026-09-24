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

    /**
     * Sezioni passate note dello studente («2B», «1A»), dalla piu' recente:
     * le classi frequentate (piano classi, D). Si accumulano da sole a ogni
     * cambio di classe (recordClassChange); senza tabella o senza DB, vuoto.
     *
     * @return list<string>
     */
    public function storicoForUser(int $userId): array
    {
        if ($userId <= 0 || !Database::isAvailable()) {
            return [];
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT classe FROM student_class_history WHERE user_id = ? ORDER BY recorded_at DESC, id DESC'
            );
            $stmt->execute([$userId]);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $c) {
                $c = trim((string)$c);
                if ($c !== '') {
                    $out[] = $c;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Annota la classe che lo studente lascia: e' lo storico delle classi
     * frequentate, scritto dal sistema al cambio di classe, non chiesto a
     * nessuno. Idempotente per coppia (indirizzo, classe); un errore non
     * blocca il cambio, lo storico e' un aiuto.
     */
    public function recordClassChange(int $userId, ?int $instituteId, ?string $indirizzo, ?string $classe): void
    {
        $ind = trim((string)$indirizzo);
        $cls = trim((string)$classe);
        if ($userId <= 0 || $cls === '' || !Database::isAvailable()) {
            return;
        }
        try {
            Database::connection()->prepare(
                'INSERT IGNORE INTO student_class_history (user_id, institute_id, indirizzo, classe)
                 VALUES (?, ?, ?, ?)'
            )->execute([$userId, $instituteId, $ind !== '' ? $ind : null, $cls]);
        } catch (\Throwable) {
            // vedi sopra: lo storico non e' un vincolo
        }
    }
}
