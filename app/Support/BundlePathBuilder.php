<?php

declare(strict_types=1);

namespace App\Support;

/**
 * G22.S20 v2.C2 — Single source of truth per la costruzione dei path
 * usati da TUTTI i sync target (Drive, Local, GitHub, Import).
 *
 * Garantisce che lo stesso content del docente venga sincronizzato sotto
 * la STESSA struttura di cartelle in ogni target. Path canonical:
 *
 *   {institute_code}/{indirizzo}/{classe}/{materia}/{section}/{file}
 *
 *   sezione = 'mappe' | 'verifiche' | 'esercizi' | 'documenti'
 *   institute_code = code della tabella `institutes` (es. MIUR-ESEMPIO...)
 *   indirizzo/classe/materia = code canonical (SCI/CLA/ART/AFM/LIN, 1-5+1b...)
 *
 * Segmenti NULL → "general" (placeholder per content non scoped).
 *
 * Sanitize:
 *   - Rimuove control chars + Windows reserved (< > : " / \ | ? *)
 *   - Trim dot/space leading/trailing
 *   - Fallback "general" se segment vuoto
 *
 * Convenzioni nomi file:
 *   - mappe:     {title}.{ext}  (ext da map_mime)
 *   - verifiche: {materia}-{slug}-{token}-{kind}.tex (cfr. G19.49)
 *   - esercizi:  {title}.json   (wrapper con db_row+contract)
 *   - documenti: {title}.json   (wrapper con body_html plaintext)
 */
final class BundlePathBuilder
{
    /**
     * Sanitize segmento path: Windows/macOS/Linux compatibile.
     * Necessario perché nomi tipo "Funzioni: f(x)?" rompono FS Access write.
     */
    public static function sanitizeSegment(?string $s, string $fallback = 'general'): string
    {
        if ($s === null) {
            return $fallback;
        }
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', '', $s) ?? $s;
        $s = preg_replace('#[<>:"/\\\\|?*]+#u', '_', $s) ?? $s;
        $s = trim($s, '. ');
        return $s !== '' ? $s : $fallback;
    }

    /**
     * Costruisce il path della cartella per un content del docente.
     *
     * @param string      $instituteCode es. "MIUR-ESEMPIO-COMUNE ESEMPIO-ART"
     * @param string|null $indirizzo     es. "SCI" (canon UPPER) o NULL
     * @param string|null $classe        es. "2"
     * @param string|null $materia       es. "MAT"
     * @param string      $section       "mappe" | "verifiche" | "esercizi" | "documenti"
     *
     * @return string es. "MIUR-ESEMPIO/SCI/2/MAT/mappe"
     */
    public static function folderPath(
        string $instituteCode,
        ?string $indirizzo,
        ?string $classe,
        ?string $materia,
        string $section
    ): string {
        return implode('/', [
            self::sanitizeSegment($instituteCode),
            self::sanitizeSegment($indirizzo),
            self::sanitizeSegment($classe),
            self::sanitizeSegment($materia),
            self::sanitizeSegment($section, $section),
        ]);
    }

    /**
     * Path completo per una mappa (concept map drawio).
     */
    public static function mapPath(
        string $instituteCode,
        ?string $indirizzo,
        ?string $classe,
        ?string $subjectCode,
        string $title,
        string $mapMime
    ): string {
        $ext = match ($mapMime) {
            'application/xml'  => '.drawio',
            'application/pdf'  => '.pdf',
            'image/png'        => '.png',
            'image/jpeg'       => '.jpg',
            'text/html'        => '.html',
            default            => '',
        };
        $folder = self::folderPath($instituteCode, $indirizzo, $classe, $subjectCode, 'mappe');
        return $folder . '/' . self::sanitizeSegment($title) . $ext;
    }

    /**
     * Path completo per una verifica (TEX o PDF).
     *
     * @param string $filename già con extension (.tex / .pdf) e variant
     * @param string $versionFolder es. "v0-15_05_2026-SOL_NOR_DSA_DIS" o ''
     * @param string $titleSlug stripped del suffisso variant
     */
    public static function verificaPath(
        string $instituteCode,
        ?string $indirizzo,
        ?string $classe,
        ?string $materia,
        string $titleSlug,
        string $versionFolder,
        string $filename
    ): string {
        $folder = self::folderPath($instituteCode, $indirizzo, $classe, $materia, 'verifiche');
        $folder .= '/' . self::sanitizeSegment($titleSlug);
        if ($versionFolder !== '') {
            $folder .= '/' . self::sanitizeSegment($versionFolder);
        }
        return $folder . '/' . $filename; // filename già sanitized dal builder
    }

    /**
     * Path completo per un esercizio standalone (wrapper JSON).
     */
    public static function esercizioPath(
        string $instituteCode,
        ?string $indirizzo,
        ?string $classe,
        ?string $subjectCode,
        string $title
    ): string {
        $folder = self::folderPath($instituteCode, $indirizzo, $classe, $subjectCode, 'esercizi');
        return $folder . '/' . self::sanitizeSegment($title) . '.json';
    }

    /**
     * Path completo per un documento (wrapper JSON).
     */
    public static function documentoPath(
        string $instituteCode,
        ?string $indirizzo,
        ?string $classe,
        ?string $subjectCode,
        string $title
    ): string {
        $folder = self::folderPath($instituteCode, $indirizzo, $classe, $subjectCode, 'documenti');
        return $folder . '/' . self::sanitizeSegment($title) . '.json';
    }

    /**
     * Path completo per un modello/template docente.
     *
     * @param string $kind "texCommon" | "risdoc" | "tikz" | "drawio"
     * @param string $relPath path relativo dentro la sezione (es. "matematica/foo.xml")
     */
    public static function modelloPath(
        string $instituteCode,
        string $kind,
        string $relPath
    ): string {
        $base = self::sanitizeSegment($instituteCode) . '/modelli/' . self::sanitizeSegment($kind);
        // relPath può contenere subdir (separatori già `/`): sanitize segmento-per-segmento
        $parts = array_map(fn($p) => self::sanitizeSegment($p, $p), explode('/', $relPath));
        return $base . '/' . implode('/', $parts);
    }
}
