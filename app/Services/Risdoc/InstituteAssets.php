<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Config;
use App\Core\Database;
use Throwable;

/**
 * Dati e logo dell'Istituto del docente, per l'intestazione dei documenti.
 *
 * PERCHE' (2026-09-04)
 *   L'intestazione dei modelli risdoc — nome, sede, contatti, logo — era
 *   scritta nel file di stile condiviso e nell'immagine `logo_scuola.png`
 *   del repository: un Istituto preciso incorporato nel software. I modelli
 *   sono invece generici: l'intestazione deve dire l'Istituto che il docente
 *   ha dichiarato nel profilo, e il logo dev'essere un file dell'istanza,
 *   caricato per quell'Istituto, non un asset del codice.
 *
 * DOVE
 *   Logo: <storage>/risdoc/istituti/<institute_id>.png — fuori dal repo,
 *   dentro la cartella che il backup porta via. Dati testuali: dalla tabella
 *   `institutes` (nome, citta'); il resto — indirizzo, telefono, PEC, CF —
 *   lo aggiunge chi amministra l'istanza con l'override istituzionale del
 *   file texCommon/risdoc-istituto.tex, o il docente con il proprio.
 */
final class InstituteAssets
{
    /** @return array{id:int, code:string, name:string, city:?string}|null */
    public static function instituteFor(int $teacherId): ?array
    {
        if ($teacherId <= 0) {
            return null;
        }
        try {
            $st = Database::connection()->prepare(
                'SELECT i.id, i.code, COALESCE(NULLIF(i.header_label, \'\'), i.name) AS name, i.city
                   FROM teacher_institutes ti
                   JOIN institutes i ON i.id = ti.institute_id
                  WHERE ti.user_id = ? AND i.active = 1
                  ORDER BY ti.created_at, i.id
                  LIMIT 1'
            );
            $st->execute([$teacherId]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            return is_array($r)
                ? ['id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'city' => $r['city'] !== null ? (string)$r['city'] : null]
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function logoDir(): string
    {
        return rtrim((string)Config::get('app.paths.storage'), '/\\') . '/risdoc/istituti';
    }

    /** Percorso assoluto del logo dell'Istituto del docente, se caricato. */
    public static function logoPathFor(int $teacherId): ?string
    {
        $inst = self::instituteFor($teacherId);
        if ($inst === null) {
            return null;
        }
        $p = self::logoDir() . '/' . $inst['id'] . '.png';
        return is_file($p) ? $p : null;
    }

    /**
     * Intestazione completa dell'Istituto del docente, se l'istanza ne ha una:
     * <storage>/risdoc/istituti/<institute_id>.tex, con le \renewcommand* dei
     * dati (indirizzo, telefono, PEC, CF) che la tabella `institutes` non ha.
     * Vale per tutti i docenti di quell'Istituto sull'istanza, e per nessun
     * altro: e' il posto giusto per cio' che riguarda una scuola sola.
     */
    public static function headerTexFor(int $teacherId): ?string
    {
        $inst = self::instituteFor($teacherId);
        if ($inst === null) {
            return null;
        }
        $p = self::logoDir() . '/' . $inst['id'] . '.tex';
        if (!is_file($p)) {
            return null;
        }
        $body = (string)file_get_contents($p);
        return trim($body) === '' ? null : $body;
    }
}
