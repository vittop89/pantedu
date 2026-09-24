<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use Closure;
use RuntimeException;

/**
 * CRUD dei modelli TikZ/LaTeX predefiniti: i «gruppi» del menù TeX
 * dell'editor, gestiti dall'amministratore in /admin/templates#tikz.
 *
 * La fonte è il JSON d'istanza `<PANTEDU_DATA_PATH>/storage/data/modelli_tikz_elements.json`
 * (fuori da git), con la forma che leggono tutti i consumatori — pagina
 * admin, dialoghi dell'editor, workspace e override dei docenti, export:
 *
 *   { "gruppo-<nome>": [ { "label": "…", "content": "…", "type": "tikz"|"latex" }, … ] }
 *
 * Gruppi ordinati per chiave ed elementi per etichetta (senza distinguere
 * le maiuscole), come li produceva il vecchio generatore dal template HTML.
 *
 * Fino al 2026-09-05 le tre operazioni riscrivevano
 * `views/admin/templates/modelli_tikz.php` e rigeneravano il JSON dal DOM;
 * il template non esiste più dal Phase 9z e ogni chiamata falliva con
 * `source_not_found` (debito 29). `modelli_tikz_traccia.json` (le «tracce»
 * con i riferimenti al libro) è letto dall'editor ma non ha una UI di
 * modifica e non viene toccato.
 *
 * Scrittura: lock esclusivo su un file `.lock` a parte (il JSON viene
 * sostituito con rename, quindi non può fare da lock), file temporaneo +
 * rename: due richieste concorrenti si serializzano e un crash a metà non
 * lascia un indice mezzo scritto. Un file assente vale «nessun modello»
 * (istanza nuova); un JSON illeggibile è un errore e non viene mai
 * sovrascritto.
 */
final class TikzElementsService
{
    public const INDEX_REL = 'storage/data/modelli_tikz_elements.json';

    private const TYPES        = ['tikz', 'latex'];
    private const GROUP_PREFIX = 'gruppo-';

    private string $basePath;

    /** @param string|null $basePath radice dei dati d'istanza (la cartella che contiene `storage/`). */
    public function __construct(?string $basePath = null)
    {
        $base = $basePath ?? (string) Config::get('app.paths.data_base', dirname(__DIR__, 2));
        $this->basePath = rtrim(str_replace('\\', '/', $base), '/');
    }

    public function indexFile(): string
    {
        return $this->basePath . '/' . self::INDEX_REL;
    }

    /**
     * Indice corrente. Le chiavi sconosciute di un elemento (es. `data` del
     * template filler) vengono conservate così come sono.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function readIndex(): array
    {
        $file = $this->indexFile();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('index_not_readable');
        }
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('index_invalid');
        }

        $out = [];
        foreach ($data as $group => $items) {
            if (!is_string($group) || !is_array($items)) {
                continue;
            }
            $list = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $element = $this->element(
                    (string) ($item['label'] ?? ''),
                    (string) ($item['content'] ?? ''),
                    (string) ($item['type'] ?? 'tikz'),
                );
                $list[] = $element + $item;
            }
            $out[$group] = $list;
        }
        return $out;
    }

    /**
     * Aggiunge un elemento. `$groupName` è il nome di un gruppo nuovo (il
     * prefisso `gruppo-` viene aggiunto se manca); se è vuoto si usa
     * `$existingGroup`. Se il gruppo esiste già l'elemento vi si accoda.
     *
     * @return array{group:string, label:string, created_group:bool}
     */
    public function createElement(string $groupName, string $existingGroup, string $elementType, string $label, string $code): array
    {
        $label = $this->cleanLabel($label);
        $this->assertCode($code);
        $this->assertType($elementType);
        $key = $this->groupKey(trim($groupName) !== '' ? $groupName : $existingGroup);

        return $this->withIndex(function (array &$index) use ($key, $elementType, $label, $code): array {
            $created = !isset($index[$key]);
            if (!$created) {
                $this->assertLabelFree($index[$key], $label);
            }
            $index[$key][] = $this->element($label, $code, $elementType);
            return ['group' => $key, 'label' => $label, 'created_group' => $created];
        });
    }

    /**
     * Elimina un elemento (cercato per etichetta) o un gruppo intero. Un
     * gruppo rimasto vuoto sparisce.
     *
     * @return array{group:string, deletedLabel:string, groupRemoved:bool}
     */
    public function deleteElement(string $groupName, string $elementLabel, bool $deleteWholeGroup): array
    {
        $key = trim($groupName);
        if ($key === '') {
            throw new RuntimeException('group_missing');
        }
        $label = trim($elementLabel);
        if (!$deleteWholeGroup && $label === '') {
            throw new RuntimeException('element_label_missing');
        }

        return $this->withIndex(function (array &$index) use ($key, $label, $deleteWholeGroup): array {
            if (!isset($index[$key])) {
                throw new RuntimeException('group_not_found');
            }
            if ($deleteWholeGroup) {
                unset($index[$key]);
                return ['group' => $key, 'deletedLabel' => $label, 'groupRemoved' => true];
            }
            $i = $this->findByLabel($index[$key], $label);
            if ($i === null) {
                throw new RuntimeException('element_not_found_in_group');
            }
            array_splice($index[$key], $i, 1);
            $removed = $index[$key] === [];
            if ($removed) {
                unset($index[$key]);
            }
            return ['group' => $key, 'deletedLabel' => $label, 'groupRemoved' => $removed];
        });
    }

    /**
     * Sostituisce un elemento (trovato per `$elementLabel` o, in mancanza,
     * per `$elementIndex` nel JSON). Con `$newGroupName` rinomina l'intero
     * gruppo; con `$moveToGroup` sposta il solo elemento in un gruppo che
     * deve già esistere (il gruppo di partenza sparisce se resta vuoto).
     *
     * @return array{group:string, originalGroup:string, renamed:bool, moved:bool, label:string}
     */
    public function editElement(
        string $groupName,
        int $elementIndex,
        string $newGroupName,
        string $moveToGroup,
        string $elementType,
        string $label,
        string $code,
        string $elementLabel = '',
    ): array {
        $key = trim($groupName);
        if ($key === '') {
            throw new RuntimeException('group_missing');
        }
        $label = $this->cleanLabel($label);
        $this->assertCode($code);
        $this->assertType($elementType);
        $elementLabel = trim($elementLabel);
        if ($elementLabel === '' && $elementIndex < 0) {
            throw new RuntimeException('invalid_element_index');
        }
        $renameTo = trim($newGroupName) !== '' ? $this->groupKey($newGroupName) : null;
        $moveTo   = trim($moveToGroup) !== '' ? $this->groupKey($moveToGroup) : null;

        return $this->withIndex(function (array &$index) use ($key, $elementIndex, $elementLabel, $renameTo, $moveTo, $elementType, $label, $code): array {
            if (!isset($index[$key])) {
                throw new RuntimeException('group_not_found');
            }
            $items = $index[$key];
            if ($elementLabel !== '') {
                $i = $this->findByLabel($items, $elementLabel);
                if ($i === null) {
                    throw new RuntimeException('element_label_not_found');
                }
            } else {
                $i = $elementIndex;
                if (!isset($items[$i])) {
                    throw new RuntimeException('element_index_out_of_range');
                }
            }
            $element = $this->element($label, $code, $elementType);

            if ($renameTo !== null && $renameTo !== $key) {
                if (isset($index[$renameTo])) {
                    throw new RuntimeException('target_group_exists');
                }
                $this->assertLabelFree($items, $label, $i);
                $items[$i] = $element;
                unset($index[$key]);
                $index[$renameTo] = $items;
                return ['group' => $renameTo, 'originalGroup' => $key, 'renamed' => true, 'moved' => false, 'label' => $label];
            }

            if ($moveTo !== null && $moveTo !== $key) {
                if (!isset($index[$moveTo])) {
                    throw new RuntimeException('target_group_not_found');
                }
                $this->assertLabelFree($index[$moveTo], $label);
                array_splice($items, $i, 1);
                if ($items === []) {
                    unset($index[$key]);
                } else {
                    $index[$key] = $items;
                }
                $index[$moveTo][] = $element;
                return ['group' => $moveTo, 'originalGroup' => $key, 'renamed' => false, 'moved' => true, 'label' => $label];
            }

            $this->assertLabelFree($items, $label, $i);
            $items[$i] = $element;
            $index[$key] = $items;
            return ['group' => $key, 'originalGroup' => $key, 'renamed' => false, 'moved' => false, 'label' => $label];
        });
    }

    // ───────────────────────── lettura/scrittura ─────────────────────────

    /**
     * Legge l'indice, lo passa (per riferimento) a `$mutate` e lo riscrive,
     * il tutto sotto lock esclusivo. Ritorna ciò che ritorna `$mutate`.
     *
     * @return array<string, mixed>
     */
    private function withIndex(Closure $mutate): array
    {
        $file = $this->indexFile();
        $dir  = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('index_dir_missing');
        }
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('index_lock_failed');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('index_lock_failed');
            }
            $index  = $this->readIndex();
            $result = $mutate($index);
            $this->writeIndex($index);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, list<array<string, mixed>>> $index */
    private function writeIndex(array $index): void
    {
        ksort($index, SORT_STRING);
        foreach ($index as &$items) {
            usort($items, static fn (array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']));
        }
        unset($items);

        $json = json_encode(
            $index === [] ? (object) [] : $index,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($json === false) {
            throw new RuntimeException('index_encode_failed');
        }

        $file = $this->indexFile();
        $tmp  = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('index_write_failed');
        }
        @chmod($tmp, 0664);
        if (!$this->replace($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('index_rename_failed');
        }
    }

    /** Su Windows il rename fallisce per un istante se un altro processo sta leggendo il file: tre tentativi. */
    private function replace(string $tmp, string $file): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (@rename($tmp, $file)) {
                return true;
            }
            usleep(50_000);
        }
        return false;
    }

    // ───────────────────────── validazione e ricerca ─────────────────────────

    private function cleanLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('label_missing');
        }
        // `|` separa gruppo ed etichetta nelle chiavi degli override docente.
        if (preg_match('/[\x00-\x1F\x7F|]/', $label) === 1) {
            throw new RuntimeException('invalid_label');
        }
        return $label;
    }

    private function assertCode(string $code): void
    {
        if (trim($code) === '') {
            throw new RuntimeException('code_missing');
        }
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('invalid_element_type');
        }
    }

    /** Chiave di gruppo: spazi normalizzati, prefisso `gruppo-` garantito, nome com'è scritto. */
    private function groupKey(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (str_starts_with($name, self::GROUP_PREFIX)) {
            $name = trim(substr($name, strlen(self::GROUP_PREFIX)));
        }
        if ($name === '') {
            throw new RuntimeException('group_missing');
        }
        if (preg_match('/[\x00-\x1F\x7F|]/', $name) === 1) {
            throw new RuntimeException('invalid_group_name');
        }
        return self::GROUP_PREFIX . $name;
    }

    /** @param list<array<string, mixed>> $items */
    private function assertLabelFree(array $items, string $label, ?int $except = null): void
    {
        foreach ($items as $i => $item) {
            if ($i !== $except && $this->sameLabel((string) $item['label'], $label)) {
                throw new RuntimeException('duplicate_label_in_group');
            }
        }
    }

    /** Prima la corrispondenza esatta, poi quella senza distinguere le maiuscole. @param list<array<string, mixed>> $items */
    private function findByLabel(array $items, string $label): ?int
    {
        foreach ($items as $i => $item) {
            if ((string) $item['label'] === $label) {
                return $i;
            }
        }
        foreach ($items as $i => $item) {
            if ($this->sameLabel((string) $item['label'], $label)) {
                return $i;
            }
        }
        return null;
    }

    private function sameLabel(string $a, string $b): bool
    {
        return mb_strtolower(trim($a), 'UTF-8') === mb_strtolower(trim($b), 'UTF-8');
    }

    /** @return array{label:string, content:string, type:string} */
    private function element(string $label, string $content, string $type): array
    {
        return ['label' => trim($label), 'content' => $content, 'type' => $type === 'latex' ? 'latex' : 'tikz'];
    }
}
