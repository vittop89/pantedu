<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Repositories\PdfImportSessionRepository;
use App\Repositories\TeacherContentRepository;
use App\Services\Contract\ContractRepository;
use App\Services\PdfImport\Session\SessionStorage;
use App\Support\TeacherContextResolver;

/**
 * Phase PDF-Import — insert delle row revisionate in teacher_content (bozze).
 *
 * Crea UN documento esercizio (content_subtype='esercizio', visibility='draft')
 * per la sessione e vi appende un problem-group per ogni row selezionata,
 * mappando il payload sullo schema item di pantedu (vedi ContractMapper +
 * TeacherContentController::hardcodedDefaultItems).
 *
 * Riusa il percorso canonico:
 *   TeacherContentRepository::create() → ContractRepository::createEmptyShell…
 *   → ContractAggregate::appendGroup() → ContractRepository::save().
 */
final class ExerciseInserter
{
    public function __construct(
        private readonly TeacherContentRepository $content = new TeacherContentRepository(),
    ) {
    }

    /**
     * @param array{indirizzo?:?string, classe?:?string, subject?:?string} $ctx
     * @param list<string> $rowIds  vuoto = tutte le row
     * @return list<int> id dei contenuti creati
     */
    public function run(
        int $sessionId,
        int $teacherId,
        array $ctx,
        array $rowIds,
        PdfImportSessionRepository $repo,
        SessionStorage $storage
    ): array {
        $session = $repo->find($sessionId);
        if ($session === null || (int)$session['teacher_id'] !== $teacherId) {
            throw new \RuntimeException('session_not_found');
        }
        $prefix = (string)$session['storage_prefix'];
        $rows = $storage->getJson($prefix, 'contracts.json', $teacherId);
        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException('contracts_not_ready');
        }

        // Filtra le row selezionate (o tutte).
        $idset = $rowIds === [] ? null : array_fill_keys($rowIds, true);
        $selected = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            if ($idset === null || isset($idset[(string)($r['id'] ?? '')])) {
                $selected[] = $r;
            }
        }
        if ($selected === []) {
            throw new \RuntimeException('no_rows_selected');
        }

        // ── Modalità "target per-riga" (verifiche correlate) ────────────────
        // Ogni riga va nella verifica/gruppo scelto. Raggruppa per documento,
        // carica un aggregate per documento, append degli item nei gruppi.
        $rowTargets = (array)($ctx['row_targets'] ?? []);
        if ($rowTargets !== []) {
            $byDoc = []; // content_id => list<[row, group]>
            foreach ($selected as $row) {
                $t = $rowTargets[(string)($row['id'] ?? '')] ?? null;
                if (!is_array($t)) {
                    continue; // riga senza verifica scelta → saltata
                }
                $cid = (int)($t['content_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $byDoc[$cid][] = [$row, (string)($t['group'] ?? ($row['container'] ?? ''))];
            }
            if ($byDoc === []) {
                throw new \RuntimeException('no_rows_selected');
            }
            $repo->setStatus($sessionId, PdfImportSessionRepository::STATUS_INSERTING);
            $crepo = ContractRepository::default();
            $docs = [];
            foreach ($byDoc as $cid => $pairs) {
                $agg = $crepo->loadForTeacher((int)$cid, $teacherId);
                if ($agg === null) {
                    throw new \RuntimeException('target_not_found');
                }
                // UN GRUPPO (blocco) PER ESERCIZIO nel documento scelto. In
                // pantedu il GRUPPO è l'esercizio: un V/F = un gruppo con le sue
                // affermazioni-item (tabella "Affermazioni|V|F"), un RM = un gruppo
                // con domanda+opzioni. NON fondere (fonderebbe le affermazioni di
                // V/F diversi in un'unica tabella).
                foreach ($pairs as [$row, $grp]) {
                    $agg->appendGroup($this->buildGroup($row, (string)$grp));
                }
                $crepo->save($agg);
                $docs[] = (int)$cid;
            }
            $repo->setStatus($sessionId, PdfImportSessionRepository::STATUS_INSERTED);
            try {
                $storage->deleteSession($prefix);
            } catch (\Throwable) {
            /* best-effort */
            }
            return $docs;
        }

        // ── Modalità "inserisci nel documento esistente" ────────────────────
        // Lanciato da una pagina esercizio: append degli item nei gruppi del
        // documento (match per titolo del container scelto; se assente, crea il
        // gruppo). Niente nuova bozza.
        $targetId = (int)($ctx['target_content_id'] ?? 0);
        if ($targetId > 0) {
            $repo->setStatus($sessionId, PdfImportSessionRepository::STATUS_INSERTING);
            $crepo = ContractRepository::default();
            $agg = $crepo->loadForTeacher($targetId, $teacherId);
            if ($agg === null) {
                throw new \RuntimeException('target_not_found');
            }
            // Un gruppo (blocco) per esercizio nel documento scelto (vedi sopra).
            foreach ($selected as $row) {
                $agg->appendGroup($this->buildGroup($row, trim((string)($row['container'] ?? ''))));
            }
            $crepo->save($agg);
            $repo->setStatus($sessionId, PdfImportSessionRepository::STATUS_INSERTED);
            try {
                $storage->deleteSession($prefix);
            } catch (\Throwable) {
            /* best-effort */
            }
            return [$targetId];
        }

        $repo->setStatus($sessionId, PdfImportSessionRepository::STATUS_INSERTING);

        $iid = TeacherContextResolver::firstInstituteId($teacherId);
        if ($iid <= 0) {
            throw new \RuntimeException('forbidden');
        }

        $title = $this->uniqueTitle($session, $sessionId);

        $contentId = $this->content->create([
            'teacher_id'    => $teacherId,
            'content_type'  => 'esercizio',
            'section_id'    => null,
            'subject_code'  => (string)($ctx['subject'] ?? ''),
            'indirizzo'     => $this->blankToNull($ctx['indirizzo'] ?? null),
            'classe'        => $this->blankToNull($ctx['classe'] ?? null),
            'topic'         => '',
            'title'         => $title,
            'body_html'     => '',
            'metadata'      => ['imported_from' => 'pdf-import', 'session_id' => $sessionId],
            'visibility'    => 'draft',
            'publish_scope' => 'class',
            'target_classes' => null,
        ]);

        $crepo = ContractRepository::default();
        $crepo->createEmptyShellForNewContent($contentId, $iid);

        $agg = $crepo->loadForTeacher($contentId, $teacherId);
        if ($agg === null) {
            throw new \RuntimeException('contract_load_failed');
        }
        foreach ($selected as $row) {
            $agg->appendGroup($this->buildGroup($row));
        }
        $crepo->save($agg);

        $repo->setTargetContext($sessionId, [
            'subject_id' => null, 'indirizzo_id' => null, 'classe_id' => null, 'section_id' => null,
        ]);
        $repo->setStatus($sessionId, PdfImportSessionRepository::STATUS_INSERTED);

        // Copyright/retention: gli esercizi sono ora in teacher_content → cancella
        // TUTTI gli artefatti della sessione (pagine derivate + JSON). Best-effort.
        try {
            $storage->deleteSession($prefix);
        } catch (\Throwable) {
            // non bloccare l'esito dell'insert per un cleanup fallito
        }

        return [$contentId];
    }

    /** Wrapper pubblico: group pantedu da una row (usato per l'anteprima render). */
    public function buildGroupPublic(array $row): array
    {
        return $this->buildGroup($row);
    }

    /** Titolo blocco: Argomento → contenitore → "Esercizio", + " n.<num>". */
    private function blockTitle(array $row, string $titleFallback = ''): string
    {
        $num  = trim((string)($row['number'] ?? ''));
        $base = trim((string)($row['topic'] ?? '')) !== ''
            ? trim((string)$row['topic'])
            : (trim($titleFallback) !== '' ? trim($titleFallback) : 'Esercizio');
        return $num !== '' ? ($base . ' n.' . $num) : $base;
    }

    /** Costruisce un problem-group pantedu da una row (usato per l'anteprima). */
    private function buildGroup(array $row, string $titleFallback = ''): array
    {
        $type    = (string)($row['type'] ?? 'Collect');
        // RM_VF (domanda condivisa + affermazioni V/F) → gruppo VF di pantedu
        // (tabella "Affermazioni|V|F"). Il tipo RM_VF resta nella riga per la UI.
        if ($type === 'RM_VF') {
            $type = 'VF';
        }
        if (!in_array($type, ['Collect', 'VF', 'RM'], true)) {
            $type = 'Collect';
        }
        $payload = (array)($row['payload'] ?? []);

        return [
            'kind'  => 'problem-group',
            'type'  => $type,
            'title' => $this->blockTitle($row, $titleFallback),
            'intro' => $this->normalizeMath((string)($payload['shared_instruction'] ?? '')),
            'items' => $this->buildItems($type, $payload, $row),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function buildItems(string $type, array $payload, array $row): array
    {
        $items = match ($type) {
            'VF' => array_map(
                fn($s) => $this->baseItem($row, (string)($s['text'] ?? ''))
                    + ['answer' => in_array(($s['answer'] ?? 'V'), ['V', 'F'], true) ? $s['answer'] : 'V', 'options' => []],
                array_values(array_filter((array)($payload['statements'] ?? []), 'is_array')) ?: [[]]
            ),
            'RM' => [
                $this->baseItem($row, (string)($payload['question'] ?? '')) + [
                    'options' => array_map(fn($o) => [
                        'letter'  => (string)($o['letter'] ?? ''),
                        'correct' => (bool)($o['correct'] ?? false),
                        'content' => $this->blocks((string)($o['text'] ?? '')),
                    ], array_values(array_filter((array)($payload['options'] ?? []), 'is_array'))),
                ],
            ],
            default => [ // Collect — fold eventuali sub-problemi nel testo.
                $this->baseItem($row, $this->collectQuestion($payload))
                    + ['solution' => $this->blocks((string)($payload['solution'] ?? ''))],
            ],
        };

        // Badge (numero + colore + difficoltà + pagina) sul PRIMO item del gruppo:
        // l'esercizio è uno solo, anche se ha più sotto-item (VF). La fonte resta
        // vuota (source_key='') → il docente la sceglie dal selettore "origine".
        $badge = $this->makeBadge($row);
        if ($badge !== null && isset($items[0]) && is_array($items[0])) {
            $items[0]['badge'] = $badge;
        }
        return $items;
    }

    /** Badge strutturato dell'esercizio (cfr. ContractRenderer::renderBadge). */
    private function makeBadge(array $row): ?array
    {
        $num   = trim((string)($row['number'] ?? ''));
        $color = (string)($row['badge_color'] ?? '');
        $diff  = (int)($row['difficulty'] ?? 0);
        $page  = trim((string)($row['page'] ?? ''));
        if ($num === '' && $color === '' && $diff <= 0) {
            return null;
        }
        return [
            'source_key'     => (string)($row['origin'] ?? ''), // fonte scelta in revisione
            'page'           => $page,
            'ex_num'         => $num,
            'bg_color'       => $color !== '' ? $color : 'gray',
            'difficulty'     => max(0, min(4, $diff)),
            'difficulty_max' => 4,
        ];
    }

    /** Item base nello schema pantedu (cfr. hardcodedDefaultItems). */
    private function baseItem(array $row, string $text): array
    {
        return [
            'difficulty'     => (int)($row['difficulty'] ?? 0),
            'tags'           => [],
            'category_label' => (string)($row['topic'] ?? ''),
            'category_color' => null,
            // 'source' = chiave fonte (alimenta il selettore "origine"). Presa
            // dalla colonna Origine in revisione; NON il numero (che va nel badge).
            'source'         => (string)($row['origin'] ?? ''),
            'origin'         => 'personal',
            'color'          => 'white',
            'question'       => $this->blocks($text),
            'justification'  => [],
            'body_html'      => '',
        ];
    }

    private function collectQuestion(array $payload): string
    {
        $q = (string)($payload['question'] ?? '');
        $points = (array)($payload['points'] ?? []);
        foreach ($points as $p) {
            if (!is_array($p)) {
                continue;
            }
            $q .= "\n(" . ($p['letter'] ?? '') . ') ' . ($p['text'] ?? '');
        }
        return $q;
    }

    /** @return list<array{type:string,content:string}> */
    private function blocks(string $text): array
    {
        return [['type' => 'text', 'content' => $this->normalizeMath($text)]];
    }

    /**
     * L'estrazione LLM usa spesso $...$ / $$...$$; pantedu (ContractRenderer +
     * MathJax) usa \(...\) / \[...\]. Senza conversione l'esercizio inserito
     * mostrerebbe LaTeX grezzo. ($$ prima di $.)
     */
    private function normalizeMath(string $text): string
    {
        $text = preg_replace('/\$\$(.+?)\$\$/s', '\\\\[$1\\\\]', $text) ?? $text;
        $text = preg_replace('/\$([^\$\n]+?)\$/', '\\\\($1\\\\)', $text) ?? $text;
        return $text;
    }

    private function uniqueTitle(array $session, int $sessionId): string
    {
        $name = (string)($session['original_filename'] ?? 'document.pdf');
        $name = preg_replace('/\.pdf$/i', '', $name) ?: 'PDF';
        $name = mb_substr($name, 0, 60);
        return "Import PDF — {$name} — #{$sessionId}";
    }

    private function blankToNull(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : '';
        return $s === '' ? null : $s;
    }
}
