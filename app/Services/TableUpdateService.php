<?php

namespace App\Services;

use App\Support\SafePath;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Table DOM mutations on exercise/verification HTML files. Actions:
 * updateTabelleAlign, updateMultipleCheckboxes, updateTableAttribute,
 * updateCheckboxPosition, deleteRow, addRowAbove/Below,
 * addColumnLeft/Right, deleteColumn.
 *
 * Replaces legacy app/Legacy/update/update_table.php (iter-3 U10).
 * Service methods return plain-text body so existing JS clients stay
 * compatible (legacy mixes debug echo + final json line).
 */
final class TableUpdateService
{
    /** @var list<string> */
    private array $roots;
    private string $basePath;

    public function __construct(FileService $files, ?string $basePath = null)
    {
        $all = $files->roots();
        $this->roots = array_values(array_intersect_key(
            $all,
            array_flip(['eser', 'verifiche', 'risdoc', 'drafts', 'lab', 'didattica', 'mappe', 'strcomp']),
        ));
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 2)), '/');
    }

    public function dispatch(array $post): string
    {
        $action = $post['action'] ?? null;
        if ($action === 'updateTabelleAlign') {
            return $this->updateTabelleAlign($post);
        }
        $tableIndex = $post['tableIndex'] ?? ($post['updates'][0]['tableIndex'] ?? null);
        if ($tableIndex === null) {
            return json_encode(['status' => 'error', 'message' => 'Parametro mancante: tableIndex']);
        }
        if (!isset($post['url'])) {
            return json_encode(['status' => 'error', 'message' => 'Parametro mancante:url - Exit']);
        }

        $filePath     = (string) $post['url'];
        $abs          = $this->resolveFile($filePath);
        $out          = "\nDEBUG: Percorso assoluto del file: $abs\n\n";

        $html = (string) @file_get_contents($abs);
        if ($html === '') {
            return json_encode(['status' => 'error', 'message' => 'Il contenuto del file è vuoto']);
        }
        $dom = $this->load($html);

        $tables = $dom->getElementsByTagName('table');
        $tableIndex = (int) $tableIndex;
        $user = isset($post['user']) ? (string)$post['user'] : ' nessuno';

        $out .= "DEBUG: Contenuto di \$_POST:\n" . print_r($post, true);
        $out .= "DEBUG: Inizio script\n";
        $out .= "\nDEBUG: user:" . $user . "\n";
        $out .= "\nDEBUG: tableIndex: $tableIndex\n\n";
        $out .= "DEBUG: Numero totale di tabelle trovate: " . $tables->length . "\n";

        if ($tables->length <= $tableIndex) {
            return $out . 'Tabella non trovata';
        }
        $table = $tables->item($tableIndex);

        return match ($action) {
            'updateMultipleCheckboxes' => $out . $this->updateCheckboxes($post, $dom, $tables, $tableIndex, $user, $abs),
            'updateTableAttribute'     => $out . $this->updateTableAttribute($post, $dom, $table, $abs, (string)$action),
            'updateCheckboxPosition'   => $out . $this->updateCheckboxPosition($post, $dom, $table, $abs),
            'deleteRow'                => $out . $this->deleteRow($post, $dom, $table, $abs),
            'addRowAbove', 'addRowBelow' => $out . $this->addRow($post, $dom, $table, $abs, (string)$action),
            'addColumnLeft', 'addColumnRight', 'deleteColumn' => $out . $this->changeColumn($post, $dom, $table, $abs, (string)$action),
            default => $out . "DEBUG: Azione non corrispondente a updateMultipleCheckboxes\n",
        };
    }

    private function updateTabelleAlign(array $post): string
    {
        if (!isset($post['url'])) {
            return json_encode(['status' => 'error', 'message' => 'Parametro mancante: url']);
        }
        $abs = $this->resolveFile((string)$post['url']);
        $html = (string) @file_get_contents($abs);
        $dom = $this->load($html);

        $idx = (int)($post['tabelleIndex'] ?? 0);
        $align = (string)($post['align'] ?? '');
        $xp = new DOMXPath($dom);
        $divs = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " tabelle ")]');
        $out = "DEBUG: div.tabelle trovati: " . $divs->length . ", tabelleIndex: $idx\n";
        if ($divs->length <= $idx) {
            return $out . json_encode(['status' => 'error', 'message' => 'div.tabelle non trovato']);
        }
        $divs->item($idx)->setAttribute('data-align', $align);
        if (file_put_contents($abs, $dom->saveHTML(), LOCK_EX)) {
            return $out . json_encode(['status' => 'success', 'message' => "data-align aggiornato a $align"]);
        }
        return $out . json_encode(['status' => 'error', 'message' => 'Errore durante il salvataggio del file']);
    }

    private function updateCheckboxes(array $post, DOMDocument $dom, \DOMNodeList $tables, int $tableIndex, string $user, string $abs): string
    {
        $updates = $post['updates'] ?? null;
        $out = "DEBUG: Azione updateMultipleCheckboxes rilevata\n";
        if ($updates === null) {
            return $out . "DEBUG: Nessun aggiornamento trovato in updates\n";
        }

        $first = true;
        foreach ($updates as $u) {
            $rowIndex = (int)($u['rowIndex'] ?? 0) - 1;
            $colIndex = (int)($u['colIndex'] ?? 0);
            $isChecked = filter_var($u['isChecked'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($first) {
                $out .= "Col index (client): $colIndex\n\n";
                $first = false;
            }
            $out .= "tableIndex1: $tableIndex\n";
            $out .= "Col index (client): $colIndex\n";
            $out .= "Row index (client): " . ($u['rowIndex'] ?? '') . "\n";
            $out .= "Row index (server): $rowIndex\n";

            $table = $tables->item($tableIndex);
            $out .= "tableIndex2: $tableIndex\n";
            $out .= "Row index (client): " . ($u['rowIndex'] ?? '') . "\n";
            $out .= "Row index (server): $rowIndex\n";

            $rows = $table->getElementsByTagName('tr');
            $out .= "Total rows: " . $rows->length . "\n";
            if ($rows->length <= $rowIndex) {
                $out .= json_encode(['status' => 'error', 'message' => 'Riga non trovata']) . "\n";
                continue;
            }
            $row = $rows->item($rowIndex);
            $cells = $row->getElementsByTagName('td');
            $out .= "Total cells: " . $cells->length . "\n";
            if ($cells->length <= $colIndex) {
                $out .= json_encode(['status' => 'error', 'message' => 'Colonna non trovata']) . "\n";
                continue;
            }
            $cell = $cells->item($colIndex);
            $out .= "Cell index: $colIndex, Cell object: " . ($cell ? 'valid' : 'null') . "\n";
            if (!($cell instanceof DOMElement)) {
                $out .= json_encode(['status' => 'error', 'message' => "La cella all'indice $colIndex non è un elemento DOM valido"]) . "\n";
                continue;
            }

            $checkboxRM = null;
            foreach ($cell->getElementsByTagName('input') as $input) {
                $class = trim($input->getAttribute('class'));
                if (str_contains($class, 'checkboxRM')) {
                    $checkboxRM = $input;
                    break;
                }
            }

            $isInsert = str_contains($user, 'insertcheckbox');
            if ($isChecked) {
                if ($isInsert && !$checkboxRM) {
                    $existingWrap = null;
                    foreach ($cell->getElementsByTagName('div') as $div) {
                        if ($div->getAttribute('class') === 'wrapCheckCell') {
                            $existingWrap = $div;
                            break;
                        }
                    }
                    if (!$existingWrap) {
                        $out .= "CheckboxRM non trovato, creazione di un nuovo checkbox.\n";
                        $wrap = $dom->createElement('div');
                        $wrap->setAttribute('class', 'wrapCheckCell');
                        $wrap->setAttribute('style', 'display: flex;');
                        $cb = $dom->createElement('input');
                        $cb->setAttribute('type', 'checkbox');
                        $cb->setAttribute('class', 'checkbox checkboxRM');
                        $cb->setAttribute('onclick', 'event.stopPropagation();');
                        $wrap->appendChild($cb);
                        $lab = $dom->createElement('label');
                        $lab->setAttribute('class', 'fm-collection');
                        $lab->setAttribute('onclick', 'event.stopPropagation();');
                        while ($cell->hasChildNodes()) {
                            $lab->appendChild($cell->firstChild);
                        }
                        $wrap->appendChild($lab);
                        while ($cell->hasChildNodes()) {
                            $cell->removeChild($cell->firstChild);
                        }
                        $cell->appendChild($wrap);
                        $cell->removeAttribute('class');
                    } else {
                        $out .= "Il contenitore .wrapCheckCell esiste già, nessuna azione necessaria.\n";
                    }
                } elseif (!$isInsert && $checkboxRM) {
                    $out .= "CheckboxRM trovato, aggiungo checked.\n";
                    $checkboxRM->setAttribute('checked', 'checked');
                    $c = $checkboxRM->getAttribute('class');
                    if (!str_contains($c, 'solchecked')) {
                        $checkboxRM->setAttribute('class', $c . ' solchecked');
                    }
                }
            } else {
                if ($isInsert) {
                    if ($checkboxRM) {
                        $parent = $checkboxRM->parentNode;
                        $parent->removeChild($checkboxRM);
                        if ($parent->hasChildNodes()) {
                            $collex = null;
                            foreach ($parent->childNodes as $ch) {
                                if ($ch->nodeType === XML_ELEMENT_NODE && $ch->getAttribute('class') === 'fm-collection') {
                                    $collex = $ch;
                                    break;
                                }
                            }
                            if ($collex) {
                                while ($collex->hasChildNodes()) {
                                    $cell->appendChild($collex->firstChild);
                                }
                            }
                            $cell->removeChild($parent);
                            $cell->setAttribute('class', 'fm-collection');
                            $out .= "Contenitore .wrapCheckCell rimosso.\n";
                        }
                    } else {
                        $out .= "CheckboxRM non trovato per rimozione.\n";
                    }
                } elseif ($checkboxRM) {
                    $out .= "CheckboxRM trovato, rimuovo checked.\n";
                    $checkboxRM->removeAttribute('checked');
                    $c = $checkboxRM->getAttribute('class');
                    $c = str_replace([' solchecked', 'solchecked ', 'solchecked'], '', $c);
                    $checkboxRM->setAttribute('class', trim($c));
                }
            }
        }
        if (file_put_contents($abs, $dom->saveHTML(), LOCK_EX)) {
            $out .= json_encode(['status' => 'success', 'message' => 'Stato del checkboxRM aggiornato con successo']);
        } else {
            $out .= json_encode(['status' => 'error', 'message' => 'Errore durante il salvataggio del file']);
        }
        return $out;
    }

    private function updateTableAttribute(array $post, DOMDocument $dom, DOMElement $table, string $abs, string $action): string
    {
        $key   = (string)($post['key'] ?? '');
        $value = (string)($post['value'] ?? '');
        $tableIndex = (int)($post['tableIndex'] ?? 0);
        $out  = "Azione: $action\nChiave: $key\nValore: $value\nIndice della tabella: $tableIndex\n";
        $out .= "Numero totale di tabelle trovate: " . $dom->getElementsByTagName('table')->length . "\n";
        $out .= "Tabella trovata all'indice $tableIndex\n";

        if ($key === 'typecell') {
            $table->setAttribute('data-typecell', $value);
            $out .= "Attributo data-typecell aggiornato con valore: $value\n";
        } else {
            $table->setAttribute("data-$key", $value);
            $out .= "Attributo data-$key aggiornato con valore: $value\n";
        }
        $out .= file_put_contents($abs, $dom->saveHTML(), LOCK_EX)
            ? "File salvato con successo: $abs\n"
            : "Errore durante il salvataggio del file: $abs\n";
        $out .= json_encode(['status' => 'success', 'message' => "$key aggiornato con successo", 'updatedKey' => $key, 'updatedValue' => $value]);
        return $out;
    }

    private function updateCheckboxPosition(array $post, DOMDocument $dom, DOMElement $table, string $abs): string
    {
        $rowIndex = (int)($post['rowIndex'] ?? 0) - 1;
        $colIndex = (int)($post['colIndex'] ?? 0);
        $position = (string)($post['position'] ?? '');

        $out  = "DEBUG: updateCheckboxPosition\n";
        $out .= "Row index (client): " . ($post['rowIndex'] ?? '') . "\n";
        $out .= "Row index (server): $rowIndex\n";
        $out .= "Col index: $colIndex\n";
        $out .= "Position: $position\n";

        $rows = $table->getElementsByTagName('tr');
        if ($rows->length <= $rowIndex) {
            return $out . json_encode(['status' => 'error', 'message' => 'Riga non trovata']);
        }
        $cells = $rows->item($rowIndex)->getElementsByTagName('td');
        if ($cells->length <= $colIndex) {
            return $out . json_encode(['status' => 'error', 'message' => 'Colonna non trovata']);
        }
        $cell = $cells->item($colIndex);

        $wrap = null;
        foreach ($cell->getElementsByTagName('div') as $div) {
            if (str_contains($div->getAttribute('class'), 'wrapCheckCell')) {
                $wrap = $div;
                break;
            }
        }
        if (!$wrap) {
            return $out . json_encode(['status' => 'error', 'message' => 'wrapCheckCell non trovato']);
        }

        $c = $wrap->getAttribute('class');
        $c = preg_replace('/\bpos-(top|bottom|left|right)\b/', '', $c) ?? $c;
        $newClass = trim($c . ' pos-' . $position);
        $wrap->setAttribute('class', $newClass);

        $style = $wrap->getAttribute('style');
        $style = preg_replace('/flex-direction:\s*[^;]+;?/', '', $style) ?? $style;
        $style = preg_replace('/align-items:\s*[^;]+;?/', '', $style) ?? $style;
        $flexDir = match ($position) {
            'top'    => 'column',
            'bottom' => 'column-reverse',
            'left'   => 'row',
            'right'  => 'row-reverse',
            default  => '',
        };
        $newStyle = trim(trim($style) . ' flex-direction: ' . $flexDir . '; align-items: center;');
        $newStyle = trim(preg_replace('/\s+/', ' ', $newStyle) ?? $newStyle);
        $wrap->setAttribute('style', $newStyle);

        $out .= "Classe aggiornata: $newClass\n";
        $out .= "Stile aggiornato: $newStyle\n";
        $out .= file_put_contents($abs, $dom->saveHTML(), LOCK_EX)
            ? json_encode(['status' => 'success', 'message' => 'Posizione checkbox aggiornata con successo'])
            : json_encode(['status' => 'error', 'message' => 'Errore durante il salvataggio del file']);
        return $out;
    }

    private function deleteRow(array $post, DOMDocument $dom, DOMElement $table, string $abs): string
    {
        $rowIndex = (int)($post['rowIndex'] ?? 0) - 1;
        $rows = $table->getElementsByTagName('tr');
        $out  = "Row index (client): " . ($post['rowIndex'] ?? '') . "\nRow index (server): $rowIndex\nTotal rows: " . $rows->length . "\n";
        if ($rowIndex < 0 || $rowIndex >= $rows->length) {
            return $out . 'ERRORE: Indice della riga non valido.';
        }
        $del = $rows->item($rowIndex);
        $parent = $del->parentNode;
        if (!$del || !$parent) {
            return $out . 'ERRORE: Riga non trovata o già rimossa.';
        }
        $out .= "Row to delete: " . $del->nodeValue . "\nParent node: " . $parent->nodeName . "\nTable node: " . $table->nodeName . "\n";
        $parent->removeChild($del);
        file_put_contents($abs, $dom->saveHTML(), LOCK_EX);
        $out .= json_encode(['status' => 'success', 'message' => 'Riga eliminata con successo']);
        return $out;
    }

    private function addRow(array $post, DOMDocument $dom, DOMElement $table, string $abs, string $action): string
    {
        $rowIndex = (int)($post['rowIndex'] ?? 0) - 1;
        $rows = $table->getElementsByTagName('tr');
        $out = "Row index (client): " . ($post['rowIndex'] ?? '') . "\nRow index (server): $rowIndex\nTotal rows: " . $rows->length . "\n";
        if ($rowIndex < 0 || $rowIndex >= $rows->length) {
            return $out . json_encode(['status' => 'error', 'message' => 'Indice della riga non valido']);
        }
        $ref = $rows->item($rowIndex);
        $parent = $ref ? $ref->parentNode : null;
        if (!$ref || !$parent) {
            return $out . json_encode(['status' => 'error', 'message' => 'Riga di riferimento non trovata o nodo padre non valido.']);
        }
        $new = $ref->cloneNode(true);
        if ($action === 'addRowAbove') {
            $parent->insertBefore($new, $ref);
            $out .= "Nuova riga aggiunta sopra l'indice $rowIndex\n";
        } else {
            $parent->insertBefore($new, $ref->nextSibling);
            $out .= "Nuova riga aggiunta sotto l'indice $rowIndex\n";
        }
        file_put_contents($abs, $dom->saveHTML(), LOCK_EX);
        return $out . json_encode(['status' => 'success', 'message' => 'Riga aggiunta con successo']);
    }

    private function changeColumn(array $post, DOMDocument $dom, DOMElement $table, string $abs, string $action): string
    {
        $colIndex = (int)($post['colIndex'] ?? 0);
        $typeCell = $table->getAttribute('data-typecell');
        $typeArr  = explode('|', trim($typeCell, '|'));

        if ($action === 'deleteColumn') {
            if ($colIndex < 0 || $colIndex >= count($typeArr)) {
                return json_encode(['success' => false, 'message' => 'Indice della colonna non valido']);
            }
            foreach ($table->getElementsByTagName('tr') as $r) {
                $cells = $r->getElementsByTagName('td');
                if ($cells->length > $colIndex) {
                    $r->removeChild($cells->item($colIndex));
                }
            }
            array_splice($typeArr, $colIndex, 1);
        } else {
            $columnType = $typeArr[$colIndex] ?? 'X';
            foreach ($table->getElementsByTagName('tr') as $r) {
                $cells = $r->getElementsByTagName('td');
                if ($cells->length > $colIndex) {
                    $ref = $cells->item($colIndex);
                    $new = $ref->cloneNode(true);
                    if ($action === 'addColumnLeft') {
                        $r->insertBefore($new, $ref);
                    } else {
                        $r->insertBefore($new, $ref->nextSibling);
                    }
                }
            }
            if ($action === 'addColumnLeft') {
                array_splice($typeArr, $colIndex, 0, $columnType);
            } else {
                array_splice($typeArr, $colIndex + 1, 0, $columnType);
            }
        }
        $new = '|' . implode('|', $typeArr) . '|';
        $table->setAttribute('data-typecell', $new);

        $clean = preg_replace('/ {2,}/', ' ', (string) $dom->saveHTML()) ?? '';
        if (file_put_contents($abs, $clean, LOCK_EX) === false) {
            return json_encode(['success' => false, 'message' => 'Impossibile aprire il file per la scrittura.']);
        }
        return json_encode(['success' => true, 'dataTypeCell' => $new]);
    }

    private function load(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        return $dom;
    }

    private function resolveFile(string $filePath): string
    {
        $rel = ltrim(str_replace('\\', '/', $filePath), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            throw new RuntimeException('invalid_file_path');
        }
        $abs = $this->basePath . '/' . $rel;
        return SafePath::resolve($abs, $this->roots, mustExist: true);
    }
}
