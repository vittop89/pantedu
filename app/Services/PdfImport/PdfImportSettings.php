<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Core\Config;

/**
 * Phase PDF-Import — impostazioni booleane runtime (UI), con default da config.
 *
 * Permette di abilitare/disabilitare dalla UI le passate automatiche (argomento,
 * difficoltà, traduzione) senza editare .env. Se una chiave non è stata mai
 * impostata, vale il default di config (pdf_import.<key>).
 *
 * File: storage/config/pdf_import_settings.json — { "auto_topics": false, ... }
 */
final class PdfImportSettings extends JsonConfigStore
{
    /** Chiavi gestite (= config flag) → etichetta. */
    public const KEYS = [
        'auto_difficulty'  => 'Difficoltà automatica',
        'auto_topics'      => 'Argomento automatico',
        'auto_translation' => 'Traduzione automatica',
    ];

    protected function fileName(): string
    {
        return 'settings.json';
    }

    public function getBool(string $key, ?bool $default = null): bool
    {
        $all = $this->readAll();
        if (array_key_exists($key, $all)) {
            return (bool)$all[$key];
        }
        return $default ?? (bool)Config::get("pdf_import.$key", true);
    }

    public function setBool(string $key, bool $value): void
    {
        if (!array_key_exists($key, self::KEYS)) {
            throw new \RuntimeException('invalid_setting');
        }
        $all = $this->readOwn(); // solo il file su cui scriviamo (preset o override)
        $all[$key] = $value;
        $this->persist($all);
    }

    /** @return array<string,bool> stato effettivo di tutte le chiavi. */
    public function status(): array
    {
        $out = [];
        foreach (self::KEYS as $k => $_) {
            $out[$k] = $this->getBool($k);
        }
        return $out;
    }
}
