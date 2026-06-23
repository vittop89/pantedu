<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

/**
 * Phase PDF-Import — contesto docente "ambient" per la config per-docente.
 *
 * Chiavi API, modelli, prompt, impostazioni e cache sono PRIVATI di ogni docente
 * (multi-tenant: un docente non vede/usa la config di un altro). Per non dover
 * passare il teacherId attraverso decine di firme, lo si imposta una volta a
 * inizio richiesta/worker e gli store lo leggono qui (path lazy).
 *
 * Sicurezza: nel WEB è una richiesta = un docente; nel WORKER si elabora UNA
 * sessione alla volta (sequenziale) e si reimposta prima di ciascuna → niente
 * race. Va SEMPRE impostato prima di usare gli store (altrimenti teacherId()=0).
 */
final class PdfImportContext
{
    private static int $teacherId = 0;
    private static bool $writeGlobal = false;

    public static function setTeacher(int $id, bool $writeGlobal = false): void
    {
        self::$teacherId = max(0, $id);
        self::$writeGlobal = $writeGlobal;
    }

    public static function teacherId(): int
    {
        return self::$teacherId;
    }

    /**
     * True quando si gestisce il PRESET GLOBALE (default per tutti i docenti) —
     * solo per admin, sul "tab globale". False = override personale del docente.
     * Le chiavi API restano SEMPRE personali (mai globali) a prescindere.
     */
    public static function writeGlobal(): bool
    {
        return self::$writeGlobal;
    }

    /** Path di un file di config PRIVATO del docente corrente (override). */
    public static function configPath(string $name): string
    {
        $storage = (string)\App\Core\Config::get('app.paths.storage', '');
        return $storage . '/config/pdf-import/teacher-' . self::teacherId() . '/' . $name;
    }

    /** Path del PRESET GLOBALE condiviso (default modelli/prompt/impostazioni). */
    public static function globalConfigPath(string $name): string
    {
        $storage = (string)\App\Core\Config::get('app.paths.storage', '');
        return $storage . '/config/pdf-import/_shared/' . $name;
    }

    /** Esegue $fn col contesto impostato su $teacherId, poi ripristina. */
    public static function withTeacher(int $teacherId, callable $fn): mixed
    {
        $prev = self::$teacherId;
        self::$teacherId = max(0, $teacherId);
        try {
            return $fn();
        } finally {
            self::$teacherId = $prev;
        }
    }
}
