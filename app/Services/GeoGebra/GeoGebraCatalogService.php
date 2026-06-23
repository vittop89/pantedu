<?php

declare(strict_types=1);

namespace App\Services\GeoGebra;

use RuntimeException;

/**
 * G22.S15.bis Fase 4 — Catalogo personale GeoGebra per docente.
 *
 * Storage: storage/objects/teachers/{teacherId}/geogebra-catalog.json
 *   {
 *     "items": [
 *       {
 *         "id": "01XX...",            // ULID univoco
 *         "label": "Funzione exp",
 *         "ggb_b64": "UEsD...",       // file .ggb base64 (stato GeoGebra completo)
 *         "svg_cached": "<svg>...</svg>",
 *         "ts": 1715...,
 *       }, ...
 *     ]
 *   }
 *
 * Niente layer admin: il catalogo è 100% personale (stateless: ogni docente
 * costruisce il suo). L'utente può "Salva nel catalogo" da modale GeoGebra,
 * "Carica dal catalogo" mostra la lista con thumb SVG, "Elimina" rimuove.
 */
final class GeoGebraCatalogService
{
    private const TEACHER_DIR_REL = 'storage/objects/teachers';
    private const CATALOG_FILE    = 'geogebra-catalog.json';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 3)), '/');
    }

    private function teacherDir(int $teacherId): string
    {
        if ($teacherId <= 0) {
            throw new RuntimeException('invalid_teacher_id');
        }
        $dir = $this->basePath . '/' . self::TEACHER_DIR_REL . '/' . $teacherId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function catalogFile(int $teacherId): string
    {
        return $this->teacherDir($teacherId) . '/' . self::CATALOG_FILE;
    }

    /** Crockford Base32 ULID (timestamp-ordered, 26 char). */
    private function generateUlid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int)(microtime(true) * 1000);
        $timePart = '';
        for ($i = 9; $i >= 0; $i--) {
            $timePart = $alphabet[$time % 32] . $timePart;
            $time = intdiv($time, 32);
        }
        $rand = '';
        for ($i = 0; $i < 16; $i++) {
            $rand .= $alphabet[random_int(0, 31)];
        }
        return $timePart . $rand;
    }

    /** Ritorna catalogo "leggero" (no ggb_b64) per la lista UI. */
    public function listLight(int $teacherId): array
    {
        $items = $this->getCatalog($teacherId);
        $light = [];
        foreach ($items as $it) {
            $light[] = [
                'id'         => (string)($it['id'] ?? ''),
                'label'      => (string)($it['label'] ?? ''),
                'svg_cached' => (string)($it['svg_cached'] ?? ''),
                'ts'         => (int)($it['ts'] ?? 0),
            ];
        }
        return $light;
    }

    /** Ritorna l'item completo (incluso ggb_b64) per ricarica nello editor. */
    public function getItem(int $teacherId, string $id): ?array
    {
        $items = $this->getCatalog($teacherId);
        foreach ($items as $it) {
            if (is_array($it) && (string)($it['id'] ?? '') === $id) {
                return $it;
            }
        }
        return null;
    }

    /** Upsert: se $id passato e trovato → update, altrimenti create con nuovo ULID. */
    public function saveItem(int $teacherId, string $label, string $ggbB64, string $svgCached = '', string $id = ''): array
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('label_missing');
        }
        if ($ggbB64 === '') {
            throw new RuntimeException('ggb_missing');
        }
        if (strlen($ggbB64) > 4 * 1024 * 1024) {  // max ~4MB base64 (= ~3MB binary)
            throw new RuntimeException('ggb_too_large');
        }
        if (strlen($svgCached) > 2 * 1024 * 1024) {
            throw new RuntimeException('svg_too_large');
        }

        $items = $this->getCatalog($teacherId);
        $now = time();
        if ($id !== '') {
            $found = -1;
            foreach ($items as $i => $it) {
                if (is_array($it) && (string)($it['id'] ?? '') === $id) {
                    $found = $i;
                    break;
                }
            }
            if ($found >= 0) {
                $items[$found]['label']      = $label;
                $items[$found]['ggb_b64']    = $ggbB64;
                $items[$found]['svg_cached'] = $svgCached;
                $items[$found]['ts']         = $now;
                $this->writeCatalog($teacherId, $items);
                return ['id' => $id, 'label' => $label, 'created' => false];
            }
            // id passato ma non trovato → crea con quell'id (idempotent client-side)
        } else {
            $id = $this->generateUlid();
        }
        $items[] = [
            'id' => $id, 'label' => $label,
            'ggb_b64' => $ggbB64, 'svg_cached' => $svgCached,
            'ts' => $now,
        ];
        $this->writeCatalog($teacherId, $items);
        return ['id' => $id, 'label' => $label, 'created' => true];
    }

    public function deleteItem(int $teacherId, string $id): bool
    {
        if ($id === '') {
            throw new RuntimeException('id_missing');
        }
        $items = $this->getCatalog($teacherId);
        $before = count($items);
        $items = array_values(array_filter($items, static fn($it) =>
            !(is_array($it) && (string)($it['id'] ?? '') === $id)));
        if (count($items) === $before) {
            return false;
        }
        $this->writeCatalog($teacherId, $items);
        return true;
    }

    /** @return list<array> */
    private function getCatalog(int $teacherId): array
    {
        $file = $this->catalogFile($teacherId);
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $items = $data['items'] ?? $data;
        if (!is_array($items)) {
            return [];
        }
        return array_values($items);
    }

    private function writeCatalog(int $teacherId, array $items): void
    {
        $file = $this->catalogFile($teacherId);
        $payload = ['items' => array_values($items), 'ts' => time(), 'version' => 1];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('cannot_write_catalog');
        }
        @chmod($file, 0664);
    }
}
