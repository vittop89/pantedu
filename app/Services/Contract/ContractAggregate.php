<?php

namespace App\Services\Contract;

/**
 * Phase 16 — Aggregate root per un contract JSON (il body di una
 * verifica/esercizio/mappa/lab serializzato in storage). Incapsula i dati
 * + fornisce accessor semantici per navigare groups/items senza richiedere
 * a ogni consumer di sapere la struttura interna.
 *
 * Immutabile nelle chiavi identificative (contentId, storageKey). Il payload
 * JSON (`$data`) può essere mutato via metodi (patchItem, bumpVersion, ...)
 * per poi essere ri-salvato via `ContractRepository::save()`.
 *
 * Item locator format (stringa opaca usata dal frontend):
 *   - numeric id → cerca nei `items[].id` interi
 *   - synthetic "<group_id>_q<idx>" → cerca per group id + item index
 *   - "g<idx>_q<idx>" → fallback con group index
 */
final class ContractAggregate
{
    public function __construct(
        public readonly int $contentId,
        public readonly string $storageKey,
        private array $data,
        public readonly ?array $contentRow = null,
    ) {
        // Phase 19 — validazione JSON-Schema sempre attiva.
        // Errori loggati in error_log (diagnostica), non bloccano il load:
        // la corruption di un singolo contract non deve far cadere l'intera
        // pagina. Se servono blocchi hard, gestiti dal caller via
        // ContractSchemaValidator::validate() diretto.
        if ($data !== []) {
            $errors = (new ContractSchemaValidator())->validate($data);
            if ($errors) {
                \error_log(\sprintf(
                    '[contract-schema] id=%d key=%s errors=%s',
                    $contentId,
                    $storageKey,
                    \implode(' | ', \array_slice($errors, 0, 5))
                ));
            }
        }
    }

    public function data(): array
    {
        return $this->data;
    }

    public function version(): int
    {
        return (int)($this->data['version'] ?? 0);
    }

    public function title(): string
    {
        return (string)($this->data['title'] ?? '');
    }

    /** @return list<array> */
    public function groups(): array
    {
        return array_values((array)($this->data['groups'] ?? []));
    }

    public function meta(): array
    {
        return (array)($this->data['meta'] ?? []);
    }

    /**
     * Localizza un item per `itemRef` e ritorna `[$groupIdx, $itemIdx]` oppure null.
     * Match order:
     *   1) confronto diretto su `$item['id']`
     *   2) pattern "<groupId>_q<idx>"
     *   3) pattern "g<groupIdx>_q<itemIdx>"
     */
    public function findItemIndex(string $itemRef): ?array
    {
        $itemRef = trim($itemRef);
        if ($itemRef === '') {
            return null;
        }
        $groups = $this->groups();
        foreach ($groups as $gi => $g) {
            $gid = (string)($g['id'] ?? '');
            $items = (array)($g['items'] ?? []);
            foreach ($items as $ii => $it) {
                $id = (string)($it['id'] ?? '');
                if ($id !== '' && $id === $itemRef) {
                    return [$gi, $ii];
                }
                if ($gid !== '' && ($gid . '_q' . $ii) === $itemRef) {
                    return [$gi, $ii];
                }
                if (('g' . $gi . '_q' . $ii) === $itemRef) {
                    return [$gi, $ii];
                }
            }
        }
        return null;
    }

    public function getItem(string $itemRef): ?array
    {
        $idx = $this->findItemIndex($itemRef);
        if (!$idx) {
            return null;
        }
        return $this->data['groups'][$idx[0]]['items'][$idx[1]] ?? null;
    }

    /**
     * Merge-patch su un item (array_merge shallow). Campi non presenti nel
     * patch restano invariati. Per reset di un campo usare valore null/vuoto
     * esplicito nel patch.
     */
    public function patchItem(string $itemRef, array $patch): self
    {
        $idx = $this->findItemIndex($itemRef);
        if (!$idx) {
            throw new ContractItemNotFoundException(
                "Item '$itemRef' non trovato nel contract #{$this->contentId}"
            );
        }
        [$gi, $ii] = $idx;
        $cur = $this->data['groups'][$gi]['items'][$ii] ?? [];
        // Merge sub-fields per `badge` (oggetto con page/ex_num/bg_color/
        // difficulty/source_key). Senza, un patch parziale cancellerebbe le
        // sub-properties non incluse.
        if (
            isset($patch['badge']) && is_array($patch['badge'])
            && isset($cur['badge']) && is_array($cur['badge'])
        ) {
            $patch['badge'] = array_replace($cur['badge'], $patch['badge']);
        }
        $this->data['groups'][$gi]['items'][$ii] = array_replace($cur, $patch);
        return $this;
    }

    /** Phase 20 — rimuove un gruppo dal contract. Ri-indicizza (no gaps). */
    public function deleteGroup(string $groupRef): self
    {
        $groupIdx = $this->resolveGroupIdx($groupRef);
        if ($groupIdx === null) {
            throw new ContractItemNotFoundException(
                "Group '$groupRef' non trovato nel contract #{$this->contentId}"
            );
        }
        array_splice($this->data['groups'], $groupIdx, 1);
        return $this;
    }

    /**
     * Phase 20 — merge-patch su un gruppo (title/intro e campi di meta del
     * gruppo). `$patch` viene filtrato dall'allowlist nel controller; qui
     * applichiamo `array_replace` shallow sui campi top-level del gruppo.
     */
    public function patchGroup(string $groupRef, array $patch): self
    {
        $groupIdx = $this->resolveGroupIdx($groupRef);
        if ($groupIdx === null) {
            throw new ContractItemNotFoundException(
                "Group '$groupRef' non trovato nel contract #{$this->contentId}"
            );
        }
        $cur = $this->data['groups'][$groupIdx] ?? [];
        $this->data['groups'][$groupIdx] = array_replace($cur, $patch);
        return $this;
    }

    /**
     * Phase 17 — muove un gruppo (by id o index "g<N>") in una nuova
     * posizione. Clamp automatico ai bound [0, count-1].
     */
    public function moveGroup(string $groupRef, int $newIdx): self
    {
        $groupIdx = $this->resolveGroupIdx($groupRef);
        if ($groupIdx === null) {
            throw new ContractItemNotFoundException(
                "Group '$groupRef' non trovato nel contract #{$this->contentId}"
            );
        }
        $groups = &$this->data['groups'];
        $count = count($groups);
        $newIdx = max(0, min($count - 1, $newIdx));
        if ($newIdx === $groupIdx) {
            return $this;
        }
        $removed = array_splice($groups, $groupIdx, 1);
        array_splice($groups, $newIdx, 0, $removed);
        return $this;
    }

    /** Risolve un group ref a index: accetta `group.id`, `g<N>`, `gN_<anything>`. */
    private function resolveGroupIdx(string $ref): ?int
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        foreach ($this->groups() as $gi => $g) {
            if ((string)($g['id'] ?? '') === $ref) {
                return $gi;
            }
            if ('g' . $gi === $ref) {
                return $gi;
            }
        }
        // Fallback: parse primo segmento "g<N>" (es. "g2_q0")
        if (preg_match('/^g(\d+)(?:_|$)/', $ref, $m)) {
            $i = (int)$m[1];
            if (isset($this->groups()[$i])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Phase 17 — trova un gruppo per titolo (case-insensitive + trim).
     * Ritorna l'index del gruppo o null se non trovato.
     */
    public function findGroupByTitle(string $title): ?int
    {
        $needle = mb_strtolower(trim($title));
        if ($needle === '') {
            return null;
        }
        foreach ($this->groups() as $gi => $g) {
            $gTitle = mb_strtolower(trim((string)($g['title'] ?? '')));
            if ($gTitle === $needle) {
                return $gi;
            }
        }
        return null;
    }

    /**
     * Append di un item a un gruppo esistente (by index). Assegna nuovo UUID
     * al copy se `$item` non ne ha uno. Ritorna l'id dell'item inserito.
     */
    public function appendItemToGroup(int $groupIdx, array $item): string
    {
        $groups = $this->groups();
        if (!isset($groups[$groupIdx])) {
            throw new \RuntimeException("Group index $groupIdx not found");
        }
        $copy = json_decode((string)json_encode($item, JSON_UNESCAPED_UNICODE), true);
        if (!is_array($copy)) {
            $copy = [];
        }
        if (empty($copy['id'])) {
            $copy['id'] = $this->newUuid();
        }
        $this->data['groups'][$groupIdx]['items'][] = $copy;
        return (string)$copy['id'];
    }

    /**
     * Append di un nuovo gruppo in coda. Deep-copy dell'array. Assegna UUID
     * al gruppo se privo di id. Ritorna l'id del gruppo inserito.
     */
    public function appendGroup(array $group): string
    {
        $copy = json_decode((string)json_encode($group, JSON_UNESCAPED_UNICODE), true);
        if (!is_array($copy)) {
            $copy = [];
        }
        if (empty($copy['id'])) {
            $copy['id'] = $this->newUuid();
        }
        // UUID anche per gli items, se mancanti.
        foreach (($copy['items'] ?? []) as $ii => $it) {
            if (empty($it['id'])) {
                $copy['items'][$ii]['id'] = $this->newUuid();
            }
        }
        $this->data['groups'][] = $copy;
        return (string)$copy['id'];
    }

    /**
     * Phase 17 — duplica un item inserendone una copia IMMEDIATAMENTE DOPO.
     * Il nuovo item riceve un id UUID distinto (gli altri campi sono deep
     * copy via json encode/decode). Ritorna il nuovo id.
     */
    public function duplicateItem(string $itemRef): string
    {
        $idx = $this->findItemIndex($itemRef);
        if (!$idx) {
            throw new ContractItemNotFoundException(
                "Item '$itemRef' non trovato nel contract #{$this->contentId}"
            );
        }
        [$gi, $ii] = $idx;
        $src = $this->data['groups'][$gi]['items'][$ii] ?? [];
        $copy = json_decode((string)json_encode($src, JSON_UNESCAPED_UNICODE), true);
        if (!is_array($copy)) {
            $copy = [];
        }
        $newId = $this->newUuid();
        $copy['id'] = $newId;
        array_splice($this->data['groups'][$gi]['items'], $ii + 1, 0, [$copy]);
        return $newId;
    }

    /** Rimuove un item dal contract. Ri-indicizza (no gaps). */
    public function deleteItem(string $itemRef): self
    {
        $idx = $this->findItemIndex($itemRef);
        if (!$idx) {
            throw new ContractItemNotFoundException(
                "Item '$itemRef' non trovato nel contract #{$this->contentId}"
            );
        }
        [$gi, $ii] = $idx;
        array_splice($this->data['groups'][$gi]['items'], $ii, 1);
        return $this;
    }

    /**
     * Muove un item alla posizione `$newIdx` all'interno del proprio gruppo.
     * Clamp automatico ai bound [0, count-1].
     */
    public function moveItem(string $itemRef, int $newIdx): self
    {
        $idx = $this->findItemIndex($itemRef);
        if (!$idx) {
            throw new ContractItemNotFoundException(
                "Item '$itemRef' non trovato nel contract #{$this->contentId}"
            );
        }
        [$gi, $ii] = $idx;
        $items = &$this->data['groups'][$gi]['items'];
        $count = count($items);
        $newIdx = max(0, min($count - 1, $newIdx));
        if ($newIdx === $ii) {
            return $this;
        }
        $removed = array_splice($items, $ii, 1);
        array_splice($items, $newIdx, 0, $removed);
        return $this;
    }

    /** Aggiorna i metadata top-level (es. title, meta.source_citation). */
    public function patchMeta(array $patch): self
    {
        $this->data['meta'] = array_replace($this->meta(), $patch);
        return $this;
    }

    public function bumpVersion(): self
    {
        $this->data['version'] = $this->version() + 1;
        return $this;
    }

    /** Ri-costruisce l'array con una copia del data (per evitare aliasing). */
    public function withData(array $data): self
    {
        return new self($this->contentId, $this->storageKey, $data, $this->contentRow);
    }

    /**
     * Phase 16 — soft-migration: assegna un UUID v4 a ogni `items[].id` che
     * non ha ancora un id stabile. Niente rewrite forzato dei JSON: il
     * repository chiama questa durante `save()`, quindi la migrazione
     * avviene naturalmente la prima volta che ogni contract viene salvato.
     *
     * Criteri "id assente":
     *   - campo `id` mancante / null / stringa vuota
     *   - id puramente numerico-come-stringa → preservato (potrebbero essere
     *     DB ids storici da un altro sistema).
     *   - Ritorna true se ha aggiunto almeno un UUID (caller può decidere
     *     se bumpare la version per questa modifica "interna").
     */
    public function ensureItemIds(): bool
    {
        $changed = false;
        if (!isset($this->data['groups']) || !is_array($this->data['groups'])) {
            return false;
        }
        foreach ($this->data['groups'] as $gi => $g) {
            $items = $g['items'] ?? null;
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $ii => $it) {
                $cur = $it['id'] ?? null;
                if ($cur === null || $cur === '' || $cur === 0) {
                    $this->data['groups'][$gi]['items'][$ii]['id'] = $this->newUuid();
                    $changed = true;
                }
            }
        }
        return $changed;
    }

    private function newUuid(): string
    {
        // UUID v4 puro PHP, no dipendenze.
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Phase 16 Step 4 — statistiche denormalizzate per ricerca/filtering
     * veloce a livello DB (evita di parsare l'intero JSON per ogni query).
     * ContractRepository::save() le sincronizza in `teacher_content.metadata_json.stats`.
     *
     * Campi:
     *   - item_count       (quesiti totali)
     *   - group_count      (n. groups/problemi)
     *   - group_types      ({VF:n, RM:n, Collect:n})
     *   - difficulty_max, difficulty_avg
     *   - source_codes     (union di tutti gli origin/source attivi)
     *   - has_vf, has_rm, has_collect, has_tikz
     *   - computed_at      (unix timestamp)
     */
    public function computeStats(): array
    {
        $itemCount = 0;
        $groupTypes = [];
        $difficulties = [];
        $sources = [];
        $hasTikz = false;
        foreach ($this->groups() as $g) {
            $type = (string)($g['type'] ?? 'Collect');
            $groupTypes[$type] = ($groupTypes[$type] ?? 0) + 1;
            foreach ((array)($g['items'] ?? []) as $it) {
                $itemCount++;
                if (isset($it['difficulty']) && is_numeric($it['difficulty'])) {
                    $difficulties[] = (int)$it['difficulty'];
                }
                foreach (['origin', 'source'] as $k) {
                    $v = (string)($it[$k] ?? '');
                    if ($v !== '') {
                        $sources[$v] = true;
                    }
                }
                if (!$hasTikz && $this->itemContainsTikz($it)) {
                    $hasTikz = true;
                }
            }
        }
        return [
            'item_count'     => $itemCount,
            'group_count'    => count($this->groups()),
            'group_types'    => $groupTypes,
            'difficulty_max' => $difficulties ? max($difficulties) : 0,
            'difficulty_avg' => $difficulties
                ? round(array_sum($difficulties) / count($difficulties), 2) : 0.0,
            'source_codes'   => array_values(array_keys($sources)),
            'has_vf'         => isset($groupTypes['VF']) && $groupTypes['VF'] > 0,
            'has_rm'         => isset($groupTypes['RM']) && $groupTypes['RM'] > 0,
            'has_collect'    => isset($groupTypes['Collect']) && $groupTypes['Collect'] > 0,
            'has_tikz'       => $hasTikz,
            'version'        => $this->version(),
            'computed_at'    => time(),
        ];
    }

    private function itemContainsTikz(array $item): bool
    {
        foreach (['question', 'justification'] as $blockKey) {
            foreach ((array)($item[$blockKey] ?? []) as $blk) {
                if (is_array($blk) && ($blk['type'] ?? '') === 'tikz') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Phase 25.P.3 — Classifica condivisibilità del contract ai sensi del
     * diritto d'autore (art. 70-bis L. 633/1941).
     *
     * Logica:
     *   - Ogni item ha optional `badge.source_key` / `origin` / `source` che
     *     punta a una entry in sources.registry.json (libro di testo).
     *   - Item CON source_key non vuoto → "dal libro" (uso privato docente OK,
     *     condivisione VIETATA).
     *   - Item SENZA source_key → "personale" (creato ex novo, condivisione OK).
     *
     * Output `source_type`:
     *   - 'personal'      → 100% item personali, contract condivisibile
     *   - 'book_textbook' → 100% item dal libro, NON condivisibile (ex art. 70-bis)
     *   - 'mixed'         → almeno 1 item dal libro + almeno 1 personale,
     *                       NON condivisibile per cautela
     *   - null            → contract vuoto (0 item), source_type non determinato
     *
     * @return array{source_type: ?string, items_personal: int, items_from_book: int, items_total: int}
     */
    public function classifyShareability(): array
    {
        $totalItems = 0;
        $itemsFromBook = 0;
        $itemsPersonal = 0;

        foreach ($this->groups() as $g) {
            foreach ((array)($g['items'] ?? []) as $it) {
                $totalItems++;
                // Determina se item ha sorgente registry (libro testo).
                // Pattern accettati (in ordine di priorità): item.origin,
                // item.source, item.badge.source_key.
                $srcKey = (string)(
                    $it['origin']
                    ?? $it['source']
                    ?? ($it['badge']['source_key'] ?? '')
                );
                if (trim($srcKey) !== '') {
                    $itemsFromBook++;
                } else {
                    $itemsPersonal++;
                }
            }
        }

        $type = null;
        if ($totalItems > 0) {
            if ($itemsFromBook === 0) {
                $type = 'personal';
            } elseif ($itemsPersonal === 0) {
                $type = 'book_textbook';
            } else {
                $type = 'mixed';
            }
        }

        return [
            'source_type'      => $type,
            'items_personal'   => $itemsPersonal,
            'items_from_book'  => $itemsFromBook,
            'items_total'      => $totalItems,
        ];
    }
}
