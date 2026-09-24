<?php

declare(strict_types=1);

namespace App\Support\Sources;

use App\Support\Storage\StorageFactory;

/**
 * Dove sta il registro delle fonti di un docente, e come si legge.
 *
 * Il registro è un file per docente, nella sua casa dei file privati
 * (`institutes/{casa}/private/{tid}/sources.registry.json`, con la casa data
 * da TeacherContextResolver::privateFilesInstituteId), scritto da
 * `StudySourcesController`. Chi deve solo sapere quali fonti il docente ha
 * già — per esempio per dire «questo libro del catalogo è già fra le tue
 * fonti» — lo legge da qui, senza rifare il percorso a mano, e con la casa,
 * non con l'istituto attivo (23/9/2026, A-7).
 */
final class SourcesRegistryStore
{
    public static function chiave(int $teacherId, int $instituteId): string
    {
        return "institutes/$instituteId/private/$teacherId/sources.registry.json";
    }

    /**
     * Le fonti del registro; lista vuota se il registro manca o non si legge.
     *
     * @return list<array<string,mixed>>
     */
    public static function leggi(int $teacherId, int $instituteId): array
    {
        try {
            $bytes = StorageFactory::default()->get(self::chiave($teacherId, $instituteId));
        } catch (\Throwable) {
            return [];
        }
        $data = json_decode((string)$bytes, true);
        if (!is_array($data) || !is_array($data['sources'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($data['sources'] as $r) {
            if (is_array($r) && (string)($r['key'] ?? '') !== '') {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Gli ISBN e le chiavi già nel registro, per riconoscere un libro preso.
     *
     * @return array{isbn:array<string,true>,key:array<string,true>}
     */
    public static function giaPresenti(int $teacherId, int $instituteId): array
    {
        $isbn = [];
        $key  = [];
        foreach (self::leggi($teacherId, $instituteId) as $r) {
            $k = (string)($r['key'] ?? '');
            if ($k !== '') {
                $key[$k] = true;
            }
            $i = preg_replace('/[^0-9Xx]/', '', (string)($r['isbn'] ?? ''));
            if ($i !== null && $i !== '') {
                $isbn[strtoupper($i)] = true;
            }
        }
        return ['isbn' => $isbn, 'key' => $key];
    }
}
