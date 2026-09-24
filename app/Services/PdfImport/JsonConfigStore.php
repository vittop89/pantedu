<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

/**
 * Phase PDF-Import — base per gli store di config PER-DOCENTE su file JSON.
 *
 * Centralizza il boilerplate condiviso (path lazy per-docente via PdfImportContext,
 * lettura/decodifica, scrittura atomica con mkdir 0700 + chmod 0600). Le sottoclassi
 * dichiarano solo il nome file e la propria API (get/set/status).
 *
 * NB: ProviderKeyStore NON estende questa base perché cifra il contenuto
 * (TeacherCryptoService); LlmCache neppure (è una dir di blob, non un singolo JSON).
 */
abstract class JsonConfigStore
{
    private ?string $override;

    public function __construct(?string $path = null)
    {
        $this->override = $path; // solo per i test; runtime = path per-docente
    }

    /** Nome file dentro la dir config (es. "operations.json"). */
    abstract protected function fileName(): string;

    /** File di override PERSONALE del docente. */
    private function teacherPath(): string
    {
        return $this->override ?? PdfImportContext::configPath($this->fileName());
    }

    /** File del PRESET GLOBALE condiviso. */
    private function globalPath(): string
    {
        return PdfImportContext::globalConfigPath($this->fileName());
    }

    /** Dove si SCRIVE: preset globale (tab admin) o override del docente. */
    private function writeTarget(): string
    {
        return ($this->override === null && PdfImportContext::writeGlobal())
            ? $this->globalPath()
            : $this->teacherPath();
    }

    private static function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $d = json_decode((string)@file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }

    /**
     * Config da MOSTRARE (get/status). Sul tab GLOBALE (admin) = solo il preset
     * globale (così si edita pulito). Sul tab personale = preset globale come base
     * + override del docente che VINCE per chiave.
     */
    protected function readAll(): array
    {
        if ($this->override === null && PdfImportContext::writeGlobal()) {
            return self::readFile($this->globalPath());
        }
        return array_merge(self::readFile($this->globalPath()), self::readFile($this->teacherPath()));
    }

    /**
     * Solo il file su cui si SCRIVE (globale per admin, personale per docente).
     * Per il read-modify-write delle set(): così l'override del docente contiene
     * SOLO i suoi cambiamenti e gli aggiornamenti del preset continuano a fluire.
     */
    protected function readOwn(): array
    {
        return self::readFile($this->writeTarget());
    }

    protected function persist(array $data): void
    {
        $path = $this->writeTarget();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $json = (string)json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        @file_put_contents($path, $json, LOCK_EX);
        @chmod($path, 0600);
    }
}
