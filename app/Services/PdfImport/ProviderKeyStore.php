<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Core\Config;
use App\Services\Crypto\TeacherCryptoService;

/**
 * Phase PDF-Import — store runtime di chiavi API + override modello (UI popup).
 *
 * PRIVATO PER DOCENTE: ogni docente ha le PROPRIE chiavi, non visibili/usabili da
 * altri. File per-docente `storage/config/pdf-import/teacher-{tid}/keys.json`,
 * il cui contenuto è CIFRATO con la KEK del docente (TeacherCryptoService,
 * AES-256-GCM — stesso schema delle sessioni; il worker può decifrare). La CHIAVE
 * non torna mai al client (status mascherato); il modello sì (non è un segreto).
 *
 * Formato (in chiaro, prima della cifratura): { "<provider>": {key,model}, ... }
 * Precedenza nel ProviderRouter: store del docente → .env (Config, fallback install).
 */
final class ProviderKeyStore
{
    private ?string $override;
    private TeacherCryptoService $crypto;

    public function __construct(?string $path = null, ?TeacherCryptoService $crypto = null)
    {
        $this->override = $path; // solo per i test
        $this->crypto = $crypto ?? new TeacherCryptoService();
    }

    private function path(): string
    {
        return $this->override ?? PdfImportContext::configPath('keys.json');
    }

    /** @return array<string,array{key?:string,model?:string}> */
    private function all(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $raw = json_decode((string)@file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }
        // Contenuto cifrato (busta {v,ct,iv,tag,kv}) → decifra con la KEK docente.
        if (($raw['v'] ?? null) === 1 && isset($raw['ct'])) {
            try {
                $plain = $this->crypto->decrypt(PdfImportContext::teacherId(), [
                    'ciphertext' => base64_decode((string)$raw['ct']),
                    'iv'         => base64_decode((string)$raw['iv']),
                    'tag'        => base64_decode((string)$raw['tag']),
                    'kv'         => (int)$raw['kv'],
                ]);
                $raw = json_decode($plain, true);
            } catch (\Throwable) {
                return [];
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $provider => $entry) {
            if (is_string($entry)) {
                $out[$provider] = ['key' => $entry]; // legacy: solo chiave
            } elseif (is_array($entry)) {
                $out[$provider] = $entry;
            }
        }
        return $out;
    }

    public function get(string $provider): ?string
    {
        $k = (string)($this->all()[$provider]['key'] ?? '');
        return $k !== '' ? $k : null;
    }

    public function getModel(string $provider): ?string
    {
        $m = (string)($this->all()[$provider]['model'] ?? '');
        return $m !== '' ? $m : null;
    }

    /** Salva chiave (obbligatoria) ed eventuale override modello. */
    public function set(string $provider, string $key, ?string $model = null): void
    {
        $provider = strtolower(trim($provider));
        $key = trim($key);
        if ($key === '') {
            $this->clear($provider);
            return;
        }
        $data = $this->all();
        $entry = $data[$provider] ?? [];
        $entry['key'] = $key;
        $model = $model !== null ? trim($model) : null;
        if ($model !== null && $model !== '') {
            $entry['model'] = $model;
        }
        $data[$provider] = $entry;
        $this->persist($data);
    }

    public function clear(string $provider): void
    {
        $data = $this->all();
        unset($data[strtolower(trim($provider))]);
        $this->persist($data);
    }

    /** Stato per la UI: chiave mascherata + modello override (se presente). */
    public function status(): array
    {
        $out = [];
        foreach ($this->all() as $provider => $entry) {
            $key = (string)($entry['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$provider] = [
                'configured' => true,
                'masked'     => self::mask($key),
                'model'      => (string)($entry['model'] ?? ''),
            ];
        }
        return $out;
    }

    private function persist(array $data): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $plain = (string)json_encode($data, JSON_UNESCAPED_SLASHES);
        // Cifra con la KEK del docente (busta {v,ct,iv,tag,kv}); prod = fail-closed,
        // dev senza KMS = plaintext (all() lo rilegge, niente 'ct').
        try {
            $env = $this->crypto->encrypt(PdfImportContext::teacherId(), $plain);
            $payload = (string)json_encode([
                'v'   => 1,
                'ct'  => base64_encode($env['ciphertext']),
                'iv'  => base64_encode($env['iv']),
                'tag' => base64_encode($env['tag']),
                'kv'  => (int)$env['kv'],
            ]);
        } catch (\Throwable $e) {
            if (($_ENV['KMS_MASTER_KEY'] ?? '') !== '') {
                throw $e; // prod: non salvare mai chiavi in chiaro
            }
            $payload = $plain;
        }
        @file_put_contents($path, $payload, LOCK_EX);
        @chmod($path, 0600);
    }

    private static function mask(string $key): string
    {
        $len = strlen($key);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        return '••••' . substr($key, -4);
    }
}
