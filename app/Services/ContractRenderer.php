<?php

namespace App\Services;

use App\Services\Rendering\RmColumnTypes;
use App\Support\Storage\StorageFactory;

/**
 * Phase 15 — Renderer JSON contract → HTML moderno.
 *
 * Input: array contract `pantedu.content.v1` (teacher_content.body
 * deserializzato via StorageProvider::get(contract_key)).
 * Output: HTML da iniettare in #fm-content, chrome identica ai template
 * legacy (.fm-pagestyle > .fm-groupcollex > .fm-collapsible + .content >
 * ol.collexercise > .fm-collection__item).
 *
 * Block type handlers:
 *   - text  → <p>escaped</p>
 *   - latex → wrapped string (MathJax scan + typeset)
 *   - tikz  → <script type="text/tikz" data-*>script</script>
 *   - list  → <ol|<ul> type=X><li>recursive</li>...</ol|</ul>
 *
 * Per tipologia group (VF/RM/Collect):
 *   - VF: header "Risposta: V|F" + giustificazione
 *   - RM: <ol class="rm-options"> opzioni + correct marker + just
 *   - Collect: solution blocks standard
 *
 * Badge: HTML snippet minimal con source_key, page, ex_num, bg_color,
 * difficulty bullets. Resolve source_key via sources.registry.json.
 */
final class ContractRenderer
{
    /** @var array<string,array> source_key → {book, volume, authors} */
    private array $sourceMap;

    /**
     * Phase 25.Q.8 — flag scope: true = teacher/admin (UI completa di edit),
     * false = student/guest (solo contenuto leggibile, niente edit controls).
     */
    private bool $canEdit;

    /** G27 — contatore globale di render: ogni renderContract() ottiene un
     *  instanceId univoco, usato per prefissare gli id (checkbox) così due
     *  render dello STESSO contenuto sulla stessa pagina (esercizio + verifica
     *  correlata) non producano id duplicati. */
    private static int $renderSeq = 0;
    private int $instanceId = 0;

    public function __construct(?array $sourceMap = null, bool $canEdit = true)
    {
        $this->sourceMap = $sourceMap ?? [];
        $this->canEdit   = $canEdit;
    }

    public static function loadSourcesFor(int $instituteId, int $teacherId, bool $canEdit = true): self
    {
        try {
            $key = "institutes/$instituteId/private/$teacherId/sources.registry.json";
            $bytes = StorageFactory::default()->get($key);
            $reg = json_decode($bytes, true) ?: [];
            $map = [];
            foreach (($reg['sources'] ?? []) as $s) {
                if (!empty($s['key'])) {
                    $map[$s['key']] = $s;
                }
            }
            return new self($map, $canEdit);
        } catch (\Throwable) {
            return new self([], $canEdit);
        }
    }

    public function renderContract(array $contract): string
    {
        $this->instanceId = ++self::$renderSeq; // id univoci per questo render
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $h  = '<div class="fm-contract-render">';
        $h .= '<div class="fm-titolo"><h1>' . $esc($contract['title'] ?? '') . '</h1></div>';

        // Phase 16 — `#header_page` è emesso UNA VOLTA a livello di pagina
        // (ContentStudyController::renderTopicHtml), non per-contract — evita
        // ID duplicati quando la pagina ha più contratti (es. esercizio +
        // verifiche correlate in `#type_verAll`). La citazione è aggregata
        // client-side (aggregateHeaderPageCitations) dalle `.origin` attive. */
        if (!empty($contract['meta']['source_citation'])) {
            // Marker invisibile: data-attribute sul wrapper del contract.
            // Consumer JS può usarlo come fallback se le fonti non sono
            // risolvibili via `/api/teacher/sources.json`.
            $h .= '<meta name="fm-source-citation" content="'
                . $esc($contract['meta']['source_citation']) . '">';
        }

        foreach (($contract['groups'] ?? []) as $g) {
            $h .= $this->renderGroup($g);
        }
        $h .= '</div>';
        return $h;
    }

    /** Phase 20 — entry point public per rendere SOLO un group (usato
     *  da TeacherContentController::groupAdd per restituire l'HTML
     *  rich del gruppo appena inserito, così il client può sostituire
     *  il clone legacy del template con il render server uniforme). */
    public function renderGroupPublic(array $g): string
    {
        return $this->renderGroup($g);
    }

    /** Phase 20 — normalizza il campo `type` del group a una delle tre
     *  famiglie logiche: VF, RM, Collect. Gestisce sia valori "canonici"
     *  (`type_VF`, `type_RMulti-6`, `type_Collect-1`) sia alias già ridotti. */
    private function normalizeType(string $type): string
    {
        if (preg_match('/^(type_)?VF/i', $type)) {
            return 'VF';
        }
        if (preg_match('/^(type_)?RM/i', $type)) {
            return 'RM';
        }
        return 'Collect';
    }

    private function renderGroup(array $g): string
    {
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $title = $esc($g['title'] ?? '');
        $type  = (string)($g['type'] ?? 'Collect');
        $norm  = $this->normalizeType($type);
        $gid   = $esc((string)($g['id'] ?? ''));
        // G23.fix4 — Intro centralizzato: supporta TRE formati:
        //   1. Array di blocks (nuovo schema, uniforme con question/answer/...) →
        //      renderBlocks($intro, 'question') con DSA buttons F/GF + nested OL
        //   2. Stringa con HTML inline/block (legacy server save) → emette raw
        //   3. Stringa plain text → escape HTML safe
        $introRaw = $g['intro'] ?? '';
        if (is_array($introRaw)) {
            // Schema nuovo: array di blocks. Section='question' per emettere
            // F/GF buttons + .fm-dsa-li-num markers (CSS list-style preset).
            // Sanitize è applicato in renderBlocks (G24.phase1).
            $intro = $this->renderBlocks($introRaw, 'question');
        } else {
            $introStr = (string)$introRaw;
            $introHasHtml = (bool)preg_match('#<(b|strong|i|em|u|s|sub|sup|a|span|ol|ul|li|p|br|div)\b#i', $introStr);
            // G24.phase1 — Sanitize string intro legacy (path raw HTML).
            // L'intro storato come stringa con tag inline va attraverso
            // HTMLPurifier per chiudere XSS injection point.
            $intro = $introHasHtml
                ? \App\Services\Security\HtmlSanitizer::forBlockContent($introStr)
                : $esc($introStr);
        }

        $h  = '<div class="fm-groupcollex" id="' . $gid . '" data-type="' . $esc($type) . '">';
        // Phase 16 — markup admin server-side (no jQuery injection).
        // CSS body.fm-admin-access + body.fm-verifica-mode gestiscono visibility.
        //
        // ORDINE CRITICO: collapsible.js usa `btn.nextElementSibling === .content`
        // per aprire/chiudere. Qualsiasi elemento tra .fm-collapsible e .content
        // rompe il toggle. .moveBtn va APPENDED in fondo a .fm-groupcollex (legacy
        // pattern: `$(".fm-groupcollex").append(moveBtn)`).
        $h .= '<div class="fm-pos-check-es">' . $this->renderSelection($gid) . '</div>';
        // WCAG 2.2 AA (ADR-023): il toggle è un <button> interno (titolo) così
        // il checkmod (controlli interattivi) NON è annidato in un button
        // (nested-interactive). .fm-collapsible resta il contenitore clickabile
        // (collapsible.js usa nextElementSibling === .content, invariato).
        $h .= '<div class="fm-collapsible">'
           . '<button type="button" class="fm-collapse-toggle" aria-expanded="false">' . $title . '</button>'
           . $this->renderCheckmod() . '</div>';
        // Perf (ADR-023) — `fm-mj-lazy`: MathJax salta il typeset di queste
        // formule al load (ignoreHtmlClass); collapsible.js le impagina
        // all'espansione (rimuove la classe + typesetPromise + ricalcola altezza).
        $h .= '<div class="content fm-mj-lazy">';
        $h .= '<div class="fm-scrollbarhide">';
        // G23.fix16 — Giustifica è ora FIELD SEPARATO `$g['giustifica']`
        // (string). L'intro NON contiene più span.giustifica inline. Strip
        // di residui legacy per pulizia. Render giustifica come span dedicato
        // appended a `.fm-testo > div` con testo custom da $g['giustifica'] o
        // default hardcoded.
        $introClean = (string)preg_replace(
            '/<span\s+class=["\']giustifica["\'][^>]*>.*?<\/span>/is',
            '',
            $intro
        );
        $needsGiust = ($norm === 'VF' || $norm === 'RM');
        $giustText = $needsGiust
            ? ($esc((string)($g['giustifica'] ?? 'Giustifica adeguatamente le risposte')))
            : '';

        // Phase 20 — VF: wrapper .VF con header table "Affermazioni | V | F"
        // prima della lista di affermazioni (riproduce struttura del template
        // legacy modelli_eser.php per coerenza CSS + UX editor).
        if ($norm === 'VF') {
            $h .= '<div class="VF">';
            $h .= '<div class="fm-testo"><div>' . $introClean;
            // G23.fix16 — span giustifica con testo da $g['giustifica'] field
            // separato (custom) o default hardcoded ("Giustifica adeguatamente...")
            if ($needsGiust) {
                $h .= '<span class="fm-giustifica"> ' . $giustText . '</span>';
            }
            $h .= '</div></div>';
            $h .= '<table class="vf-header-table"><tbody><tr>'
                . '<th>Affermazioni</th><td>V</td><td>F</td>'
                . '</tr></tbody></table>';
            $h .= '</div><br>';
            $h .= '<div class="Aff">';
            foreach (($g['items'] ?? []) as $idx => $it) {
                $h .= $this->renderItem($it, $norm, (int)$idx, (string)($g['id'] ?? 'g'));
            }
            $h .= '</div>';
            $h .= '</div></div>';
            $h .= '</div>';
            return $h;
        }

        if ($introClean !== '' || $needsGiust) {
            $h .= '<div class="fm-testo"><div>' . $introClean;
            // G23.fix16 — span giustifica con testo da $g['giustifica'] o default
            if ($needsGiust) {
                $h .= '<span class="fm-giustifica"> ' . $giustText . '</span>';
            }
            $h .= '</div></div>';
        }
        $h .= '<ol class="fm-collexercise" style="padding-left:0;margin-left:30px">';
        foreach (($g['items'] ?? []) as $idx => $it) {
            $h .= $this->renderItem($it, $norm, (int)$idx, (string)($g['id'] ?? 'g'));
        }
        $h .= '</ol></div></div>';
        // .moveBtn + .move-position-problem sono ora inline dentro .checkmod
        // (nella collapsible) — non più elementi separati alla fine di .fm-groupcollex.
        $h .= '</div>';
        return $h;
    }

    private function renderItem(array $it, string $groupType, int $idx = 0, string $groupId = ''): string
    {
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        // Fallback id sintetico: molti contract legacy hanno id=null. Il data-id
        // deve essere sempre stabile per handlers (A/R persistence, delete, move).
        $rawId = (string)($it['id'] ?? '');
        if ($rawId === '') {
            $rawId = $groupId . '_q' . $idx;
        }
        $id    = $esc($rawId);
        $diff  = (int)($it['difficulty'] ?? 0);
        $src   = $esc((string)($it['source'] ?? ''));
        $cat   = $esc($it['category_label'] ?? '');
        $catCol = $it['category_color'] ?? null;

        $h  = '<div class="fm-collection__item diff' . $diff . ($src ? ' ' . $src : '') . '" data-id="' . $id . '">';
        // Phase 16 — .checkIN ora contiene anche .fm-titolo-quesito (centered).
        $h .= $this->renderCheckIN($src, $cat, $catCol);
        $h .= '<li class="fm-li-inline">';

        // DSA wrapper (F/GF checkboxes) emesso SEMPRE come PRIMO child di
        // <li class="fm-li-inline">, prima del badge e di qualsiasi altro markup.
        // Posizione block-top per visibilità immediata. Visibilità reale
        // gestita da CSS (`body.fm-teacher-access`).
        $dsaUid = $id . '-li0';
        // G27.dsa.persist — item.mark = "F"|"GF"|"" persistito server-side
        // (allowlist patchItem). Se valorizzato, renderDsaWrapper pre-attiva
        // il button corretto cosi' al reload pagina lo stato e' restored
        // senza dipendere da sessionStorage.
        $itemMark = (string)($it['mark'] ?? '');
        $h .= $this->renderDsaWrapper($dsaUid, $itemMark);

        // Badge inline. Wrapper `.fm-badge-row` legacy per allineare badge
        // (e source/book) alla stessa baseline; ora SENZA DSA wrapper (spostato sopra).
        if (!empty($it['badge'])) {
            $h .= '<span class="fm-badge-row">';
            $h .= $this->renderBadge($it['badge']);
            $h .= '</span>';
        }

        // G27.dsa.persist — item.dsa_marks = mappa path-keyed dei marker F/GF
        // sui sub-li (es. {"0": "F", "0.1": "GF"}). Threaded down a renderBlocks
        // per pre-applicare data-fm-dsa-state + .fm-dsa-active su ogni <li>.
        $dsaMarks = (array)($it['dsa_marks'] ?? []);

        // Question content — section="question": liste ricevono pulsanti DSA F/GF.
        $h .= '<div class="fm-collection">';
        $h .= $this->renderBlocks($it['question'] ?? [], 'question', 0, $dsaMarks);
        $h .= '</div>';

        // Type-specific (VF/RM/Collect). Il `$groupType` qui è GIÀ
        // normalizzato dal renderGroup a uno di: VF, RM, Collect.
        if ($groupType === 'VF') {
            // Affermazione singola: .wrapsolVF con toggle V/F + giustsol
            $ans = (string)($it['answer'] ?? 'V');
            $cls = ($ans === 'F') ? 'F' : 'V';
            $h .= '<div class="fm-wrapsol-vf">';
            $h .= '<div class="fm-sol ' . $cls . '" title="Risposta: ' . $cls . '"></div>';
            $h .= '<div class="fm-giustsol"><div>'
                . '<strong class="fm-sol-label">GIUSTIFICAZIONE</strong> '
                . $this->renderBlocks($it['justification'] ?? [], 'justification', 0, $dsaMarks)
                . '</div></div>';
            $h .= '</div>';
        } elseif ($groupType === 'RM') {
            // G23 — Rendering RM table centralizzato. Logica chunk/typecell/markup
            // estratta in `renderRmTable()` per condivisione con applier client
            // (markup mirrored con `js/modules/render/rm-table-view.js`).
            $opts     = (array)($it['options'] ?? []);
            $rmLayout = (array)($it['rmLayout'] ?? []);
            if (!$opts) {
                $opts = [
                    ['letter' => 'a', 'correct' => false, 'content' => [['type' => 'text','content' => 'Es1']]],
                    ['letter' => 'b', 'correct' => false, 'content' => [['type' => 'text','content' => 'Es2']]],
                    ['letter' => 'c', 'correct' => false, 'content' => [['type' => 'text','content' => 'Es3']]],
                    ['letter' => 'd', 'correct' => false, 'content' => [['type' => 'text','content' => 'Es4']]],
                ];
            }
            $h .= '<div class="collexTab fm-collection"></div>';
            // G23.fix8 — Wrapper supporta orientation horizontal|vertical.
            // Default horizontal (tabelle affiancate). vertical = stacked.
            $orientation = (string)($rmLayout['orientation'] ?? 'horizontal');
            $wrapStyle = $orientation === 'vertical'
                ? 'display:flex;flex-direction:column;gap:12px;overflow-x:auto'
                : 'display:flex;gap:12px;flex-wrap:wrap;overflow-x:auto';
            $h .= '<div class="fm-flex20 fm-tabelle fm-rm-tables-wrap" data-orientation="'
                . htmlspecialchars($orientation, ENT_QUOTES, 'UTF-8')
                . '" style="' . $wrapStyle . '">';
            // G23.fix8 — Multi-table support: rmLayout.tables[] array, ognuno
            // con propri rows/cols/typecell. Options sono globali (lettere
            // a/b/c/d/... attraverso tutte le tabelle), distribuiti in chunk.
            $tables = (array)($rmLayout['tables'] ?? []);
            if (count($tables) > 1) {
                $optIdx = 0;
                foreach ($tables as $tIdx => $tCfg) {
                    $tRows = (int)($tCfg['rows'] ?? 0);
                    $tCols = (int)($tCfg['cols'] ?? 0);
                    $cellsNeeded = max(1, $tRows * $tCols);
                    $chunk = array_slice($opts, $optIdx, $cellsNeeded);
                    $optIdx += $cellsNeeded;
                    $h .= $this->renderRmTable($chunk, (array)$tCfg, $dsaMarks, (int)$tIdx);
                }
            } else {
                $h .= $this->renderRmTable($opts, $rmLayout, $dsaMarks, 0);
            }
            $h .= '</div>';
            $h .= '<div class="fm-giustsol"><div>'
                . '<strong class="fm-sol-label">GIUSTIFICAZIONE</strong> '
                . $this->renderBlocks($it['justification'] ?? [], 'justification', 0, $dsaMarks)
                . '</div></div>';
        } else {
            // Collect — soluzione inline (section="solution" → liste con
            // class fm-dsa-li-list ma senza pulsanti F/GF interattivi).
            if (!empty($it['solution'])) {
                $h .= '<div class="fm-sol">'
                    . '<strong class="fm-sol-label">SOLUZIONE</strong> '
                    . $this->renderBlocks($it['solution'], 'solution', 0, $dsaMarks) . '</div>';
            }
        }

        $h .= '</li></div>';
        return $h;
    }

    /** Phase 16 — Markup legacy di `.checkIN` emesso inline per ogni
     *  `.fm-collection__item`. Ora include `.fm-titolo-quesito` centrato tra origin
     *  e editQuesito (era div separato, ora fluido nel layout).
     *  `.origin` select inizia vuoto: populated client-side da
     *  upbar-controls.populateOriginSelects() all'auto-attivazione di
     *  verifica-mode (Phase 21: ensureVerificaMode su body.fm-admin-access). */
    private function renderCheckIN(string $currentSource = '', string $category = '', ?string $categoryColor = null): string
    {
        $esc  = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $titoloStyle = $categoryColor ? ' style="background-color:' . $esc($categoryColor) . '"' : '';

        // Phase 25.Q.8 — studente: solo titolo quesito, niente controls admin.
        if (!$this->canEdit) {
            return '<div class="fm-check-in">'
                 . '<div class="fm-titolo-quesito"' . $titoloStyle . '>' . $esc($category) . '</div>'
                 . '</div>';
        }

        $h  = '<div class="fm-check-in">';
        $h .= '<div class="fm-a-bin">';
        $h .= '<div><input type="checkbox" class="fm-checkbox-ain" aria-label="Approfondimento"><label class="fm-labcheck-in">A</label></div>';
        $h .= '<div><input type="checkbox" class="fm-checkbox-bin" aria-label="Recupero"><label class="fm-labcheck-in">R</label></div>';
        $h .= '</div>';
        $h .= '<div class="fm-input-wrapper-pt"><p class="pt">pt:</p>';
        $h .= '<input type="number" class="fm-input-pt" step="0.5" min="0" value="1" aria-label="Punti del quesito"></div>';
        $h .= '<div class="fm-origin-selector"><select class="origin" aria-label="Origine / fonte" data-current="' . $esc($currentSource) . '"></select></div>';
        $h .= '<div class="fm-color-selector"><select class="fm-color-select" aria-label="Colore evidenziazione">';
        foreach (['white','green','blue','red','purple','orange'] as $c) {
            $style = $c === 'white' ? 'background-color:white;color:black' : "background-color:$c";
            $h .= '<option value="' . $c . '" style="' . $style . '">' . ucfirst($c) . '</option>';
        }
        $h .= '</select></div>';
        // Titolo quesito centrato (prima era div separato sotto .checkIN).
        $h .= '<div class="fm-titolo-quesito"' . $titoloStyle . '>' . $esc($category) . '</div>';
        // A11y (WCAG 2.1.1/4.1.2): controlli-icona a <div> resi operabili da
        // tastiera (role=button + tabindex + nome accessibile); l'attivazione
        // Invio/Spazio è gestita dall'handler keydown in checkin-handlers.js.
        $h .= '<div class="fm-edit-quesito">';
        $h .= '<div class="fm-edit-q fm-add-btn" role="button" tabindex="0" aria-label="Aggiungi quesito" title="Aggiungi"><p><strong>+</strong></p></div>';
        $h .= '<div class="fm-edit-q fm-clone" role="button" tabindex="0" aria-label="Clona quesito" title="Clona"><img src="/img/copy.svg" alt=""></div>';
        $h .= '<div class="fm-edit-q fm-single-modifica-btn" role="button" tabindex="0" aria-label="Modifica quesito" title="Modifica"><img src="/img/edit.svg" alt=""></div>';
        $h .= '<div class="fm-edit-q fm-single-quick-save-btn" role="button" tabindex="0" aria-label="Salva quesito" style="display:none;" title="Salva"><img src="/img/quicksave.svg" alt=""></div>';
        $h .= '<div class="fm-edit-q fm-remove-btn" role="button" tabindex="0" aria-label="Elimina quesito" title="Elimina"><img src="/img/delete.svg" alt=""></div>';
        $h .= '</div>';
        $h .= '<div class="fm-move-quesito">';
        $h .= '<button class="fm-move-up-btn" title="Sposta su" aria-label="Sposta quesito su">↑</button>';
        $h .= '<button class="fm-move-down-btn" title="Sposta giù" aria-label="Sposta quesito giù">↓</button>';
        $h .= '<input type="number" class="fm-move-position" min="1" step="1" title="Posizione" aria-label="Posizione del quesito">';
        $h .= '</div>';
        // G22.S15 — sync-quesito-btn rimosso: era un placeholder che mostrava
        // solo il timestamp updated_at in toast, niente refresh DOM/conflict.
        $h .= '</div>';
        return $h;
    }

    /** Phase 16 — `.selection` per `.fm-groupcollex` (posizione + totali A/R).
     *  Prima popolato da UIComp.InsertCheckPos clonando da
     *  Elementi_Riservati.html. Ora emesso inline. */
    private function renderSelection(string $gid = ''): string
    {
        // Phase 25.Q.8 — studente: nessuna selection/check (UI compositiva
        // verifica solo docente). HTML mai inviato.
        if (!$this->canEdit) {
            return '';
        }
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        // G27 — gli id checkbox derivavano SOLO dal gid del gruppo. Quando la
        // STESSA pagina rende più volte lo stesso contenuto (es. esercizio +
        // verifica correlata generata da esso, in #type_verAll), gli id
        // collidevano → un <label for> attivava SEMPRE la prima checkbox (quella
        // in alto): cliccando "A" sulla verifica si selezionava l'esercizio.
        // Prefisso per-render (instanceId, bump in renderContract) → id univoci.
        // Il JS usa la CLASSE .checkboxA/.checkboxB, non l'id → invariato.
        $idA = 'fm-chkA-' . $this->instanceId . '-' . $esc($gid);
        $idB = 'fm-chkB-' . $this->instanceId . '-' . $esc($gid);
        $h  = '<div class="selection">';
        // Phase 24.77 — Posizione: input numerico + stepper custom ▲▼ (step 1).
        // Gli spinner nativi sono troppo piccoli/poco visibili nella toolbar
        // scura → frecce custom gestite in checkin-handlers (onCheckinClick).
        $h .= '<div class="fm-position">'
            . '<input type="number" class="fm-def-position-imp" min="1" step="1" aria-label="Posizione">'
            . $this->renderStepperSpan()
            . '</div>';
        $h .= '<div class="fm-check">';
        // Phase 24.77 — la checkbox è visivamente nascosta (pill .labcheck): serve
        // il legame label↔input via for/id (UNIVOCO per gruppo) per togglare al
        // click sulla pill ⇒ evidenziazione via :has(.checkbox:checked).
        $h .= '<div><input type="checkbox" class="checkboxA" id="' . $idA . '"><label class="labcheck" for="' . $idA . '">A</label></div>';
        $h .= '<div><input type="checkbox" class="checkboxB" id="' . $idB . '"><label class="labcheck" for="' . $idB . '">R</label></div>';
        $h .= '<div class="fm-input-wrapper-pt-tot"><p class="fm-pt-tot-a" title="Punti totali sezione A">ptA</p><span class="total-pointsA">0</span></div>';
        $h .= '<div class="fm-input-wrapper-pt-tot" style="margin-right:5px"><p class="fm-pt-tot-b" title="Punti totali sezione R">ptR</p><span class="total-pointsB">0</span></div>';
        $h .= '</div></div>';
        return $h;
    }

    /** Phase 16 — `.checkmod` dentro `.fm-collapsible` (Giustifica + Soluzioni
     *  + bottoni editEser + moveBtn + move-position-problem). Tutti inline
     *  flex-row a destra del title del collapsible. Prima generato da
     *  CheckmodManager. */
    private function renderCheckmod(): string
    {
        // Phase 25.Q.8 — studente non vede checkmod (GIUST/SOL toggle + edit/move).
        // Sicurezza: HTML proprio NON inviato al client, no defense via CSS-only.
        if (!$this->canEdit) {
            return '';
        }
        $h  = '<div class="fm-checkmod">';
        $h .= '<div class="fm-wrapcheckgiust">';
        $h .= '<input type="checkbox" class="checkbox checkgiust" aria-label="Mostra giustificazione" checked>';
        $h .= '<label class="labgiust" title="Giustifica">GIUST</label>';
        $h .= '</div>';
        $h .= '<div class="fm-wrapchecksol">';
        $h .= '<input type="checkbox" class="checkbox checksol" aria-label="Mostra soluzioni" checked>';
        $h .= '<label class="labsol" title="Soluzioni">SOL</label>';
        $h .= '</div>';
        $h .= '<div class="fm-edit-eser">';
        $h .= '<div class="edit fm-modifica-btn" role="button" tabindex="0" aria-label="Modifica tipologia" title="Modifica tipologia"><img src="/img/edit.svg" alt=""></div>';
        $h .= '<div class="edit fm-quick-save-btn" role="button" tabindex="0" aria-label="Salva tipologia" style="display:none;" title="Salva"><img src="/img/quicksave.svg" alt=""></div>';
        $h .= '<div class="edit fm-elimina-btn" role="button" tabindex="0" aria-label="Elimina tipologia" title="Elimina tipologia"><img src="/img/delete.svg" alt=""></div>';
        $h .= '</div>';
        $h .= $this->renderMoveBtn();
        $h .= '</div>';
        return $h;
    }

    /** Phase 16 — controls di posizione per `.fm-groupcollex`, inline dentro
     *  `.checkmod` (a destra dei bottoni editEser):
     *   .moveBtn              → drag handle (visual solo, bind JS su sortable)
     *   .move-position-problem → input numerico per riordinare per posizione */
    private function renderMoveBtn(): string
    {
        // Phase 25.Q.8 — studente non vede controls di riordino problemi.
        if (!$this->canEdit) {
            return '';
        }
        $h  = '<div class="fm-move-btn" title="Trascina per riordinare">';
        $h .= '<img src="/img/moveUpDown.svg" alt="Move">';
        $h .= '</div>';
        // Phase 24.77 — input riordino gruppo + stepper BEM ▲▼ (step 1).
        $h .= '<span class="fm-num-field">'
            . '<input type="number" class="fm-move-position-problem" min="1" step="1" title="Posizione gruppo" aria-label="Posizione del gruppo">'
            . $this->renderStepperSpan()
            . '</span>';
        return $h;
    }

    /** Phase 24.77 — Stepper custom ▲▼ (BEM) accanto a un input number.
     *  Va inserito SUBITO dopo l'input: l'handler (checkin-handlers
     *  onCheckinClick, `.fm-stepper__btn`) lo localizza via
     *  previousElementSibling e legge step/min dall'input. */
    private function renderStepperSpan(): string
    {
        return '<span class="fm-stepper" aria-hidden="true">'
            . '<button type="button" class="fm-stepper__btn fm-stepper__btn--up" tabindex="-1">&#9650;</button>'
            . '<button type="button" class="fm-stepper__btn fm-stepper__btn--down" tabindex="-1">&#9660;</button>'
            . '</span>';
    }

    /** Phase 16 — badge come raw LaTeX typeset da MathJax (replica master).
     *
     *  Output struttura:
     *    \(
     *      \begin{array}{|c|}
     *        \hline
     *        \small{\text{BOOK}}   \\[-5pt]
     *        \tiny{\text{VOLUME}}  \\[-5pt]
     *        \tiny{\text{AUTHORS}} \\[-5pt]
     *        \hline
     *      \end{array}
     *      \quad
     *      \overset{\color{red}\huge\bullet\circ\circ\circ}{
     *        \underset{\text{P-}PAGE}{
     *          \bbox[border: 1px solid white; background: COLOR,3pt]{
     *            {\mathmakebox[cm][c]{\textcolor{white}{\large EXNUM}}}
     *          }
     *        }
     *      }
     *      \quad
     *    \)
     *
     *  Wrappato in `<span class="fm-badge">` con data-raw per edit mode.
     *  `.fm-latex` è conservato così `data-raw` pattern funziona anche sul
     *  badge (editor può leggere la sorgente LaTeX originale).
     */
    private function renderBadge(array $b): string
    {
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $srcKey = (string)($b['source_key'] ?? '');
        $src    = $this->sourceMap[$srcKey] ?? [];
        $book   = (string)($src['book']    ?? '');
        $volume = (string)($src['volume']  ?? '');
        $authors = (string)($src['authors'] ?? '');
        $page   = (string)($b['page']   ?? '');
        $exNum  = (string)($b['ex_num'] ?? '');
        $bg     = (string)($b['bg_color'] ?? 'gray');
        $diff   = (int)($b['difficulty'] ?? 0);
        $diffMax = (int)($b['difficulty_max'] ?? 4);

        // Normalizza bg_color a nome colore LaTeX valido o hex.
        // Colori base supportati da xcolor: red, green, blue, cyan, magenta,
        // yellow, orange, white, black, gray, purple, ecc.
        $bgSafe = $this->sanitizeLatexColor($bg);

        // Escape chars TeX-sensitive nei testi (& # _ ^ $ ~ %).
        $bookTex    = $this->escTex($book);
        $volumeTex  = $this->escTex($volume);
        $authorsTex = $this->escTex($authors);
        $pageTex    = $this->escTex($page);
        $exNumTex   = $this->escTex($exNum);

        // Bullets difficoltà: filled (●) + empty (○)
        $dots = '';
        for ($i = 1; $i <= $diffMax; $i++) {
            $dots .= $i <= $diff ? '\bullet' : '\circ';
        }

        // Costruisci la sorgente LaTeX.
        // ORDINE (sinistra→destra): prima il BADGE (numero+difficoltà+pagina),
        // poi la FONTE (book/volume/authors). Richiesta utente 2026-06-04:
        // "prima il badge poi la fonte". (Prima era fonte→badge.)
        $tex  = '\(';
        $tex .= '\overset{\color{red}\huge ' . $dots . '}{';
        $tex .= '\underset{\text{P-}' . $pageTex . '}{';
        $tex .= '\bbox[border: 1px solid white; background: ' . $bgSafe . ',3pt]{';
        $tex .= '{\mathmakebox[cm][c]{\textcolor{white}{\large ' . $exNumTex . '}}}';
        $tex .= '}}}';
        if ($book !== '' || $volume !== '' || $authors !== '') {
            $tex .= '\quad\begin{array}{|c|}\hline';
            if ($book !== '') {
                $tex .= '\small{\text{' . $bookTex . '}}\\\\[-5pt]';
            }
            if ($volume !== '') {
                $tex .= '\tiny{\text{' . $volumeTex . '}}\\\\[-5pt]';
            }
            if ($authors !== '') {
                $tex .= '\tiny{\text{' . $authorsTex . '}}\\\\[-5pt]';
            }
            $tex .= '\hline\end{array}';
        }
        $tex .= '\)';

        // Wrappa in span riconoscibile per edit mode (data-raw preserva sorgente).
        return '<span class="fm-badge fm-latex" data-source="' . $esc($srcKey)
             . '" data-page="' . $esc($page)
             . '" data-ex-num="' . $esc($exNum)
             . '" data-difficulty="' . $diff
             . '" data-bg-color="' . $esc($bg)
             . '" data-raw="' . $esc($tex) . '">' . $tex . '</span>';
    }

    /** Escape caratteri TeX-sensitive in testo normale.
     *  G22.S15.bis Fase 5+ — delegate alla utility canonica. */
    private function escTex(string $s): string
    {
        return \App\Services\Tex\TexEscape::escape($s);
    }

    /** Sanitizza bg_color: accetta nomi xcolor o hex → nome xcolor.
     *  Altrimenti fallback "gray". */
    private function sanitizeLatexColor(string $c): string
    {
        $c = strtolower(trim($c));
        $validNames = ['red','green','blue','cyan','magenta','yellow','orange','white','black','gray','grey','purple','brown','pink','violet','lime','teal','olive'];
        if (in_array($c, $validNames, true)) {
            return $c === 'grey' ? 'gray' : $c;
        }
        if (preg_match('/^#([0-9a-f]{6})$/', $c, $m)) {
            // xcolor non supporta hex direttamente — usa \color[HTML]{...}
            // ma dentro \bbox "background: X" serve nome. Fallback: mappa hex→nome.
            $map = [
                '#ff0000' => 'red', '#00ff00' => 'green', '#0000ff' => 'blue',
                '#00ffff' => 'cyan', '#ff00ff' => 'magenta', '#ffff00' => 'yellow',
                '#ffa500' => 'orange', '#808080' => 'gray', '#ffffff' => 'white',
                '#000000' => 'black',
            ];
            return $map[$c] ?? 'gray';
        }
        return 'gray';
    }

    /** Render `.dsa-wrapper-container` server-side — emesso inline come
     *  sibling dello span `.fm-badge` dentro `.fm-badge-row`. Sostituisce
     *  l'injection client-side di `js/modules/features/dsa-marks.js` per
     *  garantire SSR + visibilità immediata (no flash di contenuto). Il
     *  modulo dsa-marks rileva il wrapper già presente e skippa l'inject,
     *  ma continua a gestire change/persistenza in sessionStorage.
     */
    private function renderDsaWrapper(string $uid, string $initialMark = ''): string
    {
        // Phase 25.Q.8 — i bottoni F/GF (mark facoltativo/giustifica facoltativa)
        // sono strumenti docente per personalizzazione PDF per BES/DSA. Studente
        // vede solo l'esito (eventuale marker già applicato) senza poter toggleare.
        if (!$this->canEdit) {
            return '';
        }
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $u = $esc($uid);
        // G22.S15.bis (rev2) — Pulsanti F/GF stessa logica dei li-buttons:
        // mutex toggle inset/outset, NESSUNA mutazione testo, persistenza.
        // G27.dsa.persist — initialMark da contract item.mark: pre-applica
        // data-fm-dsa-state e .fm-dsa-active sul button corretto cosi' lo
        // stato e' visibile al boot senza fetch async (no flash UI).
        $mark = ($initialMark === 'F' || $initialMark === 'GF') ? $initialMark : '';
        $stateAttr = $esc($mark);
        $activeF  = $mark === 'F'  ? ' fm-dsa-active' : '';
        $activeGF = $mark === 'GF' ? ' fm-dsa-active' : '';
        $h  = '<div class="dsa-wrapper-container" data-fm-dsa-uid="' . $u . '" data-fm-dsa-state="' . $stateAttr . '">';
        $h .= '<button type="button" class="fm-dsa-li-btn fm-dsa-item-btn fm-dsa-item-F'  . $activeF  . '" data-mark="F"  title="*F* — facoltativo">F</button>';
        $h .= '<button type="button" class="fm-dsa-li-btn fm-dsa-item-btn fm-dsa-item-GF' . $activeGF . '" data-mark="GF" title="*GF* — giustifica facoltativa">GF</button>';
        $h .= '</div>';
        return $h;
    }

    /** Render list<block> → HTML.
     *
     *  Phase 16: text blocks renderizzati come `<span>` (inline) invece di
     *  `<p>` (block). Il parser legacy split testo + latex in blocchi separati
     *  ma il layout originale è inline (MathJax `\(...\)`). Block-level `<p>`
     *  causava a-capo forzati nelle giustificazioni (es. "aF: è ✓ bF: è" su
     *  righe separate invece che inline). `<span>` preserva il flow.
     *  Se il testo contiene `\n` o altri segnali di paragrafo → `<p>` block.
     *
     *  $isQuestion: quando true (rendering della traccia, non solution/options/
     *  justification) i `<li>` dei blocchi `list` ricevono in testa uno span
     *  `.dsa-checkbox-container` con checkbox (utile per docenti che
     *  contrassegnano consegne DSA — visibilità via CSS body.fm-teacher-access).
     */
    /**
     * Render blocchi semantici contract → HTML.
     *
     * @param array  $blocks  array di {type, content, ...}
     * @param string $section "question" | "solution" | "justification" | "options" | "sub"
     *                        (sub = chiamata ricorsiva da list item, eredita parent)
     */
    /**
     * Calcola il testo del marker (mostrato in `.fm-dsa-li-num` per la outer
     * question list) in base a preset+indice. I sub-livelli usano CSS nativo
     * (browser list-style-type) — qui generiamo solo il marker outer.
     *
     * Mapping speculare a Sanitizer::PRESET_LEVELS[*][0] (label LaTeX) e
     * a CSS [data-fm-list-style="<preset>"] > li::marker rules.
     */
    /** G27.dsa.fix — Marker default per un sub-list senza preset esplicito,
     *  basato sulla profondita' di nesting. Replica il default browser
     *  ol{decimal} > ol{lower-alpha} > ol{lower-roman} ciclico, e
     *  ul{disc} > ul{circle} > ul{square} ciclico. */
    // Marker PER-LIVELLO di ogni preset (mirror client _UL_LEVELS/_OL_LEVELS +
    // Sanitizer::PRESET_LEVELS + CSS ::marker). UL = char letterali; OL = codici
    // (UA=Alfa-maiusc, la=alfa-minusc, UR=Roman-maiusc, lr=roman-minusc,
    // 0D=decimal-zero, D=decimal) + suffisso . o ). Il livello D usa LEVELS[min(D,2)].
    private const UL_LEVELS = [
        'arrow-bullet' => ['➤', '♦', '●'],
        'star-circle'  => ['★', '○', '■'],
        ''             => ['●', '○', '■'], // ul senza preset: cerchio pieno / vuoto / quadrato pieno (glifi geometrici grandi, dimensioni uniformi — • e ▪ erano troppo piccoli)
    ];
    private const OL_LEVELS = [
        'alpha-decimal'      => ['UA.', 'D.',  'la.'],
        'lower-alpha-roman'  => ['la.', 'lr.', 'D.'],
        'roman-alpha'        => ['UR.', 'UA.', 'D.'],
        'decimal-zero'       => ['0D.', 'la.', 'lr.'],
        'paren'              => ['D)',  'la)', 'lr)'],
        'alpha-paren'        => ['UA)', 'D)',  'la)'],
        'lower-alpha-paren'  => ['la)', 'lr)', 'D)'],
        'roman-paren'        => ['UR)', 'UA)', 'D)'],
        'decimal-zero-paren' => ['0D)', 'la)', 'lr)'],
        ''                   => ['D.',  'la.', 'lr.'], // ol senza preset
    ];

    private static function markerFromCode(string $code, int $idx): string
    {
        if (!\preg_match('/[.)]$/', $code)) {
            return $code; // bullet letterale (➤ ♦ ● ★ ○ ■)
        }
        $suffix = \substr($code, -1);
        $core   = \substr($code, 0, -1);
        $s = match ($core) {
            'UA' => self::numToAlpha($idx, true),
            'la' => self::numToAlpha($idx, false),
            'UR' => self::numToRoman($idx, true),
            'lr' => self::numToRoman($idx, false),
            '0D' => \sprintf('%02d', $idx),
            default => (string)$idx, // 'D'
        };
        return $s . $suffix;
    }

    /** Marker testuale per il livello $depth di una lista col preset ROOT $preset.
     *  Level-aware: i nested ereditano la gerarchia del preset root (mirror client). */
    private static function computeListMarker(string $preset, int $idx, bool $isOrdered, int $depth = 0): string
    {
        if (!$isOrdered) {
            $lv = self::UL_LEVELS[$preset] ?? null;
            return $lv ? $lv[\min($depth, \count($lv) - 1)] : '●';
        }
        $lv = self::OL_LEVELS[$preset] ?? self::OL_LEVELS[''];
        return self::markerFromCode($lv[\min($depth, \count($lv) - 1)], $idx);
    }

    /** Converte 1..26 → A..Z (uppercase) o a..z (lowercase). >26 wrap-around. */
    private static function numToAlpha(int $n, bool $upper): string
    {
        $base = $upper ? ord('A') : ord('a');
        return chr($base + (($n - 1) % 26));
    }

    /** Converte 1..3999 in numero romano (uppercase o lowercase). */
    private static function numToRoman(int $n, bool $upper): string
    {
        $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
                50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
        $out = '';
        foreach ($map as $val => $sym) {
            while ($n >= $val) {
                $out .= $sym;
                $n -= $val;
            }
        }
        return $upper ? $out : strtolower($out);
    }

    /**
     * G23 — Render `.fm-rm-table` HTML da options[] + rmLayout.
     *
     * Markup IDENTICO al client `js/modules/render/rm-table-view.js`
     * `renderRmTable()` (single source of truth). I tipi colonna (X/V/B/T/N)
     * sono dispatch-ati via `RmColumnTypes::toHtml()`.
     *
     * Strategia chunking:
     *   1. Se `rmLayout.rows + rmLayout.cols` presenti → chunk fisso N×M.
     *   2. Altrimenti se ≤4 options "corte" (<30 char) → 1×count.
     *   3. Altrimenti default 2 colonne.
     *
     * @param array $opts      Array di option [letter, correct, content].
     * @param array $rmLayout  Layout {rows, cols, typecell, mixtr, mixcol, mpagew, specificWidth}.
     */
    private function renderRmTable(array $opts, array $rmLayout, array $dsaMarks = [], int $tableIdx = 0): string
    {
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $rows = (int)($rmLayout['rows'] ?? 0);
        $cols = (int)($rmLayout['cols'] ?? 0);

        // Auto-chunk se rows/cols non specificati esplicitamente
        if ($rows < 1 || $cols < 1) {
            $count = count($opts);
            if ($count <= 4) {
                $allShort = true;
                foreach ($opts as $op) {
                    $cont = $op['content'] ?? [];
                    $rawLen = 0;
                    if (is_array($cont)) {
                        foreach ($cont as $cb) {
                            $rawLen += is_array($cb) ? strlen((string)($cb['content'] ?? '')) : strlen((string)$cb);
                        }
                    } else {
                        $rawLen = strlen((string)$cont);
                    }
                    if ($rawLen > 30) {
                        $allShort = false;
                        break;
                    }
                }
                $cols = $allShort ? max(1, $count) : 2;
            } else {
                $cols = 2;
            }
            $rows = max(1, (int)ceil($count / $cols));
        }

        $typecell = (string)($rmLayout['typecell'] ?? '');
        if ($typecell === '') {
            $typecell = '|' . str_repeat('X|', $cols);
        }
        $colTypes = RmColumnTypes::parseTypecell($typecell, $cols);
        // Normalizza typecell coerente con colTypes derivati
        $typecell = '|' . implode('|', $colTypes) . '|';

        $mpagew = !empty($rmLayout['mpagew']) ? '1' : '0';
        // Default mpagew=1 se non specificato (compat con render esistente)
        if (!isset($rmLayout['mpagew']) && empty($rmLayout['specificWidth'])) {
            $mpagew = '1';
        }
        $mixtr  = !empty($rmLayout['mixtr']) ? '1' : '0';
        $mixcol = !empty($rmLayout['mixcol']) ? '1' : '0';
        $specificWidth = (string)($rmLayout['specificWidth'] ?? $rmLayout['width'] ?? '');

        $widthStyle = $mpagew === '1' ? '100%' : ($specificWidth !== '' ? $specificWidth . 'px' : 'auto');

        $h  = '<table class="fm-rm-table"';
        $h .= ' data-typecell="' . $esc($typecell) . '"';
        $h .= ' data-rows="' . $rows . '"';
        $h .= ' data-cols="' . $cols . '"';
        $h .= ' data-mpagew="' . $mpagew . '"';
        $h .= ' data-mixtr="' . $mixtr . '"';
        $h .= ' data-mixcol="' . $mixcol . '"';
        if ($specificWidth !== '') {
            $h .= ' data-width="' . $esc($specificWidth) . '"';
        }
        $h .= ' style="border-collapse:collapse;width:' . $widthStyle . '">';
        $h .= '<tbody>';

        for ($r = 0; $r < $rows; $r++) {
            $h .= '<tr>';
            for ($c = 0; $c < $cols; $c++) {
                $idx     = $r * $cols + $c;
                $op      = $opts[$idx] ?? null;
                $correct = !empty($op['correct'] ?? false);
                $colType = $colTypes[$c] ?? 'X';

                $h .= '<td class="rm-option' . ($correct ? ' rm-correct' : '') . '"'
                    . ' data-row="' . $r . '" data-col="' . $c . '"'
                    . ' style="border:1px solid #888;padding:6px;vertical-align:top">';
                $h .= '<div class="fm-wrap-check-cell" style="display:flex;gap:6px;align-items:flex-start">';
                // Valore-soluzione per colonne T/N (input) e label per B (button).
                $cellVal = (string)($op['value'] ?? '');
                $h .= RmColumnTypes::toHtml($colType, $correct, ['value' => $cellVal, 'label' => ($cellVal !== '' ? $cellVal : 'btn')]);
                $h .= '<label class="fm-collection" style="flex:1;cursor:pointer"><div class="fm-cell-content">';
                if ($op) {
                    $content = $op['content'] ?? [];
                    if (is_string($content)) {
                        $h .= $esc($content);
                    } else {
                        // G27.dsa.persist — pathPrefix per disambiguare li
                        // tra celle: stesso scheme `cell_{r}_{c}` usato lato
                        // client da computeItemScopedLiPath. Senza prefisso,
                        // li[0] in cell(0,0) e li[0] in cell(0,1) avrebbero
                        // entrambi path "0" → mark salvati su una cella si
                        // applicano all'altra.
                        $cellPrefix = "t{$tableIdx}_cell_{$r}_{$c}";
                        $h .= $this->renderBlocks($content, 'options', 0, $dsaMarks, $cellPrefix);
                    }
                }
                $h .= '</div></label>';
                $h .= '</div>';
                $h .= '</td>';
            }
            $h .= '</tr>';
        }

        $h .= '</tbody></table>';
        return $h;
    }

    /**
     * @param array<string,string> $dsaMarks Mappa path-keyed dei marker F/GF
     *   per i `<li>` discendenti dell'item corrente. Chiavi = path posizionale
     *   `"0"`, `"0.1"`, `"0.1.2"` (vedi liButtonKey() in dsa-marks.js per
     *   semantica di indicizzazione). Valori = "F"|"GF"|"". Threaded down
     *   ricorsivamente. Vuoto per renderBlocks chiamato fuori da un item
     *   (es. group intro).
     * @param string $pathPrefix Path accumulato del parent <li> per questa
     *   ricorsione. Vuoto al primo livello (= prefisso vuoto).
     */
    private function renderBlocks(
        array $blocks,
        string $section = 'question',
        int $depth = 0,
        array $dsaMarks = [],
        string $pathPrefix = '',
        string $rootPreset = '',
    ): string {
        $esc = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $out = '';
        foreach ($blocks as $b) {
            $t = $b['type'] ?? '';
            if ($t === 'text') {
                $content = (string)($b['content'] ?? '');
                $normalized = preg_replace('/\n+\s*\n+/u', "\n\n", $content) ?? $content;
                // G24.phase1 — XSS sanitization: se il content contiene tag
                // inline (<b>/<i>/<u>/<s>/<sub>/<sup>/<a>/<span>), passa per
                // HTMLPurifier con allowlist conservativa (no script, no
                // javascript: href, no on* handlers, no data: URI).
                // Senza, l'attaccante poteva injettare `<a href="javascript:">`
                // o `<span onclick="">` direttamente nei text blocks.
                // data-raw resta escaped per roundtrip editor (no leak).
                $hasInlineHtml = (bool)preg_match('#<(b|strong|i|em|u|s|sub|sup|a|span)\b#i', $content);
                if ($hasInlineHtml) {
                    $sanitized = \App\Services\Security\HtmlSanitizer::forBlockContent($normalized);
                    $visible   = nl2br($sanitized);
                } else {
                    $visible = nl2br($esc($normalized));
                }
                // G27.text.spacing — trailing space dopo span text per
                // evitare concatenation con block successivo (es. "Cell
                // outer:" + "Lista nested:" → "Cell outer:Lista nested:"
                // dopo strip_tags). Browser collassa whitespace adjacent
                // → no impact visivo HTML; LaTeX preserva separator.
                $out .= '<span class="fm-text" data-raw="' . $esc($content) . '">'
                     . $visible . '</span> ';
            } elseif ($t === 'latex') {
                // MathJax scan: mantengo delimitatori così typesetPromise
                // riconosce automaticamente. Evito escape HTML sul contenuto
                // latex (MathJax ha bisogno di \( \) letterali).
                // data-raw preserva la sorgente latex NON compilata per editor.
                $content = (string)($b['content'] ?? '');
                $out .= '<span class="fm-latex" data-raw="' . $esc($content) . '">'
                     . $content . '</span>';
            } elseif ($t === 'tikz') {
                $pkg = $esc($b['tex_packages'] ?? '');
                $lib = $esc($b['tikz_libs'] ?? '');
                // G22.S15 — preserva attributi data-template-* (template-filler).
                $tid  = $esc($b['data_template_id']   ?? '');
                $tdat = $esc($b['data_template_data'] ?? '');
                // G24.phase3 — XSS sanitization: escape `</` literal nel body
                // TikZ. Senza, un attaccante poteva injettare:
                //   ...\\end{tikzpicture}</script><script>alert(1)</script>
                // chiudendo prematuramente il <script type="text/tikz"> wrapper.
                $tikzBody = \App\Services\Security\TikzScriptValidator::sanitize(
                    (string)($b['script'] ?? '')
                );
                $out .= '<script type="text/tikz" data-show-console="true"'
                     . ($pkg  ? ' data-tex-packages=\'' . $pkg . '\'' : '')
                     . ($lib  ? ' data-tikz-libraries="' . $lib . '"' : '')
                     . ($tid  ? ' data-template-id="' . $tid . '"'   : '')
                     . ($tdat ? ' data-template-data="' . $tdat . '"' : '')
                     . '>' . $tikzBody . '</script>';
            } elseif ($t === 'geogebra') {
                // G22.S15.bis Fase 4 — Blocco GeoGebra. Output: SVG inline
                // diretto (browser renderizza nativamente) con classe e
                // attributi `data-ggb-*` per round-trip in edit-mode.
                //   <span class="fm-geogebra-wrap"
                //         data-ggb-base64="UEsD..."
                //         data-ggb-label="...">
                //     <svg ...>...</svg>
                //   </span>
                // Per pdflatex master un pre-process estrae lo SVG e lo
                // converte in PDF via /svg-to-pdf, sostituendo con
                // \includegraphics nel TeX.
                $ggb   = $esc($b['ggb_b64'] ?? '');
                $label = $esc($b['label'] ?? '');
                $width = $esc($b['width'] ?? '');  // es. "60%" | "8cm" | "\\linewidth" | ""
                $svgRaw = (string)($b['svg'] ?? '');
                // G24.phase2 — XSS sanitization SVG inline. enshrined/svg-sanitize
                // strip <script>, on* handlers, <foreignObject> con HTML, javascript:
                // URI. Senza, teacher compromesso poteva injettare:
                //   <svg><script>fetch('/api/teacher/...').then(...)</script></svg>
                $svg = $svgRaw !== '' ? \App\Services\Security\SvgSanitizer::sanitize($svgRaw) : '';
                if ($svg === '') {
                    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="60"><rect width="200" height="60" fill="#fee" stroke="#c00"/><text x="100" y="35" font-size="12" text-anchor="middle" fill="#c00">[GeoGebra: SVG mancante]</text></svg>';
                }
                // Style inline: percentuali → max-width CSS; unità LaTeX
                // (\\linewidth, cm, pt) → ignorate in HTML (default = SVG natural).
                $style = '';
                if ($width !== '' && preg_match('/^(\d+(?:\.\d+)?)%$/', $width, $wm)) {
                    // max-width sul wrapper. La regola globale CSS
                    // `.fm-geogebra-wrap svg { width: 100% }` scala lo SVG figlio.
                    $style = 'max-width:' . $wm[1] . '%;width:' . $wm[1] . '%;';
                }
                $out .= '<span class="fm-geogebra-wrap"'
                     . ($ggb   ? ' data-ggb-base64="' . $ggb . '"' : '')
                     . ($label ? ' data-ggb-label="' . $label . '"' : '')
                     . ($width ? ' data-ggb-width="' . $width . '"' : '')
                     . ($style ? ' style="' . $style . '"' : '')
                     . '>' . $svg . '</span>';
            } elseif ($t === 'list') {
                $tag   = !empty($b['ordered']) ? 'ol' : 'ul';
                $style = $b['list_style'] ?? null;
                $preset = (string)($b['list_preset'] ?? '');
                // Preset ROOT della gerarchia: ereditato se siamo già dentro una
                // lista ($rootPreset settato), altrimenti = preset di questa lista.
                // I marker dei livelli annidati derivano da QUI (per-livello), non
                // più da defaultPresetForDepth (che ignorava il preset root).
                $listRootPreset = $rootPreset !== '' ? $rootPreset : $preset;
                $start = $b['start'] ?? null;
                $isOrdered = !empty($b['ordered']);
                $attrs = $style ? ' type="' . $esc($style) . '"' : '';
                if ($preset !== '') {
                    $attrs .= ' data-fm-list-style="' . $esc($preset) . '"';
                }
                if ($start !== null) {
                    $attrs .= ' start="' . (int)$start . '"';
                }
                // Markup uniforme: .fm-dsa-li-num emesso per TUTTE le outer list
                // (question/solution/justification), così il marker outer è coerente
                // anche in giustsol/sol — altrimenti CSS `.fm-dsa-li-list{list-style:none}`
                // + `> li{display:flex}` nascondevano i marker nativi.
                // F/GF buttons SOLO per question (UI DSA della traccia).
                // Sub-list interne usano marker nativi browser (display:list-item).
                $attrs .= ' data-dsa-section="' . $esc($section) . '"';
                $out .= "<$tag class=\"fm-dsa-li-list\"$attrs>";
                $liIdx = (int)($b['start'] ?? 1) - 1;
                // G23.fix6 — section='options' (cell RM content) usa LI plain
                // (no .fm-dsa-li-num/.fm-dsa-li-content wrappers). CSS rule
                // `.rm-option .fm-dsa-li-list > li { display: list-item; }`
                // attiva native marker. Emettere `.fm-dsa-li-num` causerebbe
                // DOUBLE marker (span + native CSS).
                // G27.dsa — F/GF buttons emessi per TUTTE le sezioni che
                // fanno parte della "domanda" (question, sub-list nested,
                // options=cella RM). NON emessi in solution/justification.
                $isQuestion = \in_array($section, ['question', 'sub', 'options'], true);
                // G27.dsa.fix — Struttura UNIFORME per tutti i livelli (outer,
                // sub, options): [F/GF] [marker esplicito] [content] in flex
                // layout. Niente piu' `display: list-item` per options/sub
                // (che mostrava il marker browser dopo i F/GF). Marker per
                // sub-list senza preset esplicito = ciclo decimal/alpha/roman
                // basato su $depth (analogo CSS browser default).
                // G27.dsa.persist — chiave path-based per dsa_marks: indice
                // posizionale (0-based, NON $liIdx perche' DOM children sono
                // sempre 0..N-1 indipendentemente da `start`). Il path matcha
                // liButtonKey() lato client (`Array.from(list.children).indexOf`).
                $childIdx = -1;
                foreach (($b['items'] ?? []) as $item) {
                    $liIdx++;
                    $childIdx++;
                    $marker = self::computeListMarker($listRootPreset, $liIdx, $isOrdered, $depth);
                    $nextSection = ($section === 'question' || $section === 'sub') ? 'sub' : $section;
                    // Path corrente per questo <li>; threaded down ai sub-list.
                    $childPath = $pathPrefix === '' ? (string)$childIdx : $pathPrefix . '.' . $childIdx;
                    $liMark = '';
                    if ($isQuestion) {
                        $maybeMark = $dsaMarks[$childPath] ?? '';
                        if ($maybeMark === 'F' || $maybeMark === 'GF') {
                            $liMark = $maybeMark;
                        }
                    }
                    $liAttr = $isQuestion ? ' data-fm-dsa-state="' . $esc($liMark) . '"' : '';
                    $activeF  = $liMark === 'F'  ? ' fm-dsa-active' : '';
                    $activeGF = $liMark === 'GF' ? ' fm-dsa-active' : '';
                    $dsaBtnsHtml = $isQuestion
                        ? '<span class="fm-dsa-li-buttons" aria-label="Marca DSA">'
                            . '<button type="button" class="fm-dsa-li-btn fm-dsa-li-F'  . $activeF  . '" data-mark="F"  title="*F* — facoltativo">F</button>'
                            . '<button type="button" class="fm-dsa-li-btn fm-dsa-li-GF' . $activeGF . '" data-mark="GF" title="*GF* — giustifica facoltativa">GF</button>'
                          . '</span>'
                        : '';
                    $out .= '<li' . $liAttr . '>'
                        . $dsaBtnsHtml
                        . '<span class="fm-dsa-li-num">' . $esc($marker) . '</span>'
                        . '<span class="fm-dsa-li-content">'
                            . $this->renderBlocks($item, $nextSection, $depth + 1, $dsaMarks, $childPath, $listRootPreset)
                        . '</span>'
                        . '</li>';
                }
                $out .= "</$tag>";
            }
        }
        return $out;
    }
}
