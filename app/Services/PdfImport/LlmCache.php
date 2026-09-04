<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Core\Config;

/**
 * Phase PDF-Import — cache su file delle risposte LLM (estrazione).
 *
 * Evita di ri-pagare la stessa chiamata vision quando si ri-estrae lo stesso PDF
 * con lo stesso modello/prompt (utile nei test e nelle ri-estrazioni). Chiave =
 * hash(modello | system prompt | hash immagine). Abilitabile/disabilitabile
 * (un file-flag) dall'UI: di default OFF (così non restituisce risultati stantii
 * a chi cambia spesso modello/prompt).
 */
final class LlmCache
{
    /** Cache + flag PRIVATI del docente corrente. */
    private function dir(): string
    {
        $storage = (string)Config::get('app.paths.storage', '');
        return $storage . '/cache/pdf-import/teacher-' . PdfImportContext::teacherId();
    }

    private function flag(): string
    {
        return PdfImportContext::configPath('cache.on');
    }

    public function enabled(): bool
    {
        return is_file($this->flag());
    }

    public function setEnabled(bool $on): void
    {
        $flag = $this->flag();
        if ($on) {
            $d = dirname($flag);
            if (!is_dir($d)) {
                @mkdir($d, 0700, true);
            }
            @file_put_contents($flag, '1');
            @chmod($flag, 0600);
        } else {
            @unlink($flag);
        }
    }

    public static function key(string ...$parts): string
    {
        return hash('sha256', implode('|', $parts));
    }

    private const TTL = 30 * 86400; // 30 giorni

    public function get(string $key): ?string
    {
        $f = $this->dir() . '/' . $key . '.json';
        if (is_file($f) && (time() - (int)@filemtime($f)) < self::TTL) {
            $c = @file_get_contents($f);
            return $c === false ? null : $c;
        }
        return null;
    }

    public function put(string $key, string $val): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($dir . '/' . $key . '.json', $val, LOCK_EX);
        $this->prune($dir);
    }

    /** Rimuove i file scaduti (TTL) per non far crescere la dir all'infinito. */
    private function prune(string $dir): void
    {
        $now = time();
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (($now - (int)@filemtime($f)) >= self::TTL) {
                @unlink($f);
            }
        }
    }
}
