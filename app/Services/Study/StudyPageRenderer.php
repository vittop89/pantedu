<?php

declare(strict_types=1);

namespace App\Services\Study;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;

/**
 * Rendering HTML della pagina di studio: lista dei topic, pagina del topic
 * (mappa drawio con alternativa testuale, documento custom, contratto
 * esercizi/verifiche) e shell della pagina (upbar, sidebar, stile).
 *
 * Estratto da ContentStudyController il 2026-09-04 (revisione
 * architetturale, intervento P5; ADR-029): il controller decide COSA
 * mostrare (filtri, ACL, scope), questo servizio decide COME. Riceve dati e
 * restituisce stringhe; l'unico stato e' il repository dei contenuti (per
 * risolvere i riferimenti fra contenuti) e il permesso di modifica del
 * visitatore, che decide se emettere i controlli di edit.
 *
 * I metodi sono quelli del controller, spostati tali e quali: la storia
 * delle scelte (Phase 18 mappe, WCAG 1.1.1, Phase 25.Q.8 edit controls) e'
 * nei loro commenti.
 */
final class StudyPageRenderer
{
    /**
     * Come si scrive un dato dentro uno `<script>` della pagina (23/9/2026,
     * revisione architetturale A-3). L'XML delle mappe andava negli script con
     * JSON_UNESCAPED_SLASHES e senza JSON_HEX_TAG: un `</script>` nel file del
     * docente chiudeva lo script e quello che seguiva era HTML della pagina di
     * studio, per studenti e ospiti. Con JSON_HEX_TAG `<` e `>` diventano
     * \u003C e \u003E, e né `</script` né `<!--` possono comparire; gli altri
     * tre come in views/admin/sections.php. Il JSON decodificato è lo stesso
     * XML, byte per byte (tests/Integration/MappeNegliScriptDellaPaginaTest.php).
     */
    private const JSON_NELLO_SCRIPT = JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private TeacherContentRepository $repo;

    /** Il visitatore puo' modificare (docente/admin): emette i controlli di edit. */
    private bool $canEdit;

    /** Il deposito dei blob delle mappe; le prove ne passano uno con la loro chiave. */
    private ?\App\Services\Maps\MapBlobStore $blobStore;

    public function __construct(
        TeacherContentRepository $repo,
        bool $canEdit,
        ?\App\Services\Maps\MapBlobStore $blobStore = null
    ) {
        $this->repo = $repo;
        $this->canEdit = $canEdit;
        $this->blobStore = $blobStore;
    }

    /** Vedi ContentStudyController::userCanEdit(): la decisione resta al controller. */
    private function userCanEdit(): bool
    {
        return $this->canEdit;
    }

    // ─────── Rendering HTML ───────

    public function renderTopicsHtml(string $type, array $params, array $topics): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        $h  = '<main class="fm-study">';
        $h .= '<h1>' . $esc(ucfirst($type)) . ' — ' . $esc($params['subj'] ?? '');
        $h .= ' <span class="fm-muted">(' . $esc($params['ind'] ?? '') . ' · ' . $esc($params['cls'] ?? '') . ')</span></h1>';

        if (!$topics) {
            $h .= '<p class="fm-muted">Nessun ' . $esc($type) . ' pubblicato per questa sezione.</p>';
        } else {
            $h .= '<ul class="fm-study-topics">';
            foreach ($topics as $t) {
                $slug = rawurlencode($t['topic']);
                $h .= '<li><a href="/studio/' . $esc($type) . '/' . $esc($params['ind'] ?? '') . '/'
                    . $esc($params['cls'] ?? '') . '/' . $esc($params['subj'] ?? '') . '/' . $slug . '">'
                    . $esc($t['topic']) . '</a> <span class="fm-study-count">×' . (int)$t['count'] . '</span></li>';
            }
            $h .= '</ul>';
        }
        $h .= '</main>';
        return $h;
    }

    /**
     * Phase 18 — render dedicato per content_type=mappa: iframe Google
     * Drawio full-width, senza upbar/header_page/fm-draggable-container
     * (read-only, niente CRUD da fare).
     */
    /**
     * WCAG 1.1.1 (Contenuti non testuali) — alternativa testuale accessibile
     * della mappa concettuale. La mappa è un diagramma drawio reso in un iframe
     * di terze parti (viewer.diagrams.net), non navigabile da tastiera/screen
     * reader. Qui estraiamo dal XML i concetti (nodi con testo) e le relazioni
     * (archi sorgente→destinazione) e li presentiamo come elenco testuale in un
     * <details> collassabile, nativamente accessibile: il contenuto della mappa
     * è così fruibile senza vedere il diagramma né usare il mouse.
     *
     * Ritorna '' se dall'XML non si estrae testo (mappa puramente grafica): in
     * tal caso non si genera una lista fittizia.
     */
    private function mapTextAlternative(string $xml): string
    {
        if (\trim($xml) === '') {
            return '';
        }
        $esc = static fn(?string $s): string => \htmlspecialchars((string)$s, ENT_QUOTES);
        // Normalizza un value drawio (può contenere HTML rich-label): <br> →
        // spazio, via i tag, decodifica entità, collassa gli spazi.
        $clean = static function (?string $v): string {
            if ($v === null || $v === '') {
                return '';
            }
            $v = \preg_replace('#<br\s*/?>#i', ' ', (string)$v);
            $v = \strip_tags((string)$v);
            $v = \html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $v = \preg_replace('/\s+/u', ' ', (string)$v);
            return \trim((string)$v);
        };

        $nodes = [];   // id => label
        $edges = [];   // [sourceId, label, targetId]
        $prev = \libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        if (@$doc->loadXML($xml)) {
            foreach ($doc->getElementsByTagName('mxCell') as $cell) {
                /** @var \DOMElement $cell */
                $id    = (string)$cell->getAttribute('id');
                $value = $clean($cell->getAttribute('value'));
                if ($cell->getAttribute('vertex') === '1') {
                    if ($value !== '' && $id !== '') {
                        $nodes[$id] = $value;
                    }
                } elseif ($cell->getAttribute('edge') === '1') {
                    $edges[] = [
                        (string)$cell->getAttribute('source'),
                        $value,
                        (string)$cell->getAttribute('target'),
                    ];
                }
            }
            // Nodi "rich": <object label="..." id="..."><mxCell vertex="1"/></object>
            // — l'id logico (referenziato dagli archi) sta sull'object, il value
            // dell'mxCell interno è vuoto.
            foreach ($doc->getElementsByTagName('object') as $obj) {
                /** @var \DOMElement $obj */
                $id = (string)$obj->getAttribute('id');
                $label = $clean($obj->getAttribute('label'));
                if ($id !== '' && $label !== '') {
                    $nodes[$id] = $label;
                }
            }
        }
        \libxml_clear_errors();
        \libxml_use_internal_errors($prev);

        if ($nodes === [] && $edges === []) {
            return '';
        }

        $h  = '<details class="fm-mappa-alt" style="margin:6px 0 0;border:1px solid #d9dee6;border-radius:6px;background:#fafbfc">';
        $h .= '<summary style="cursor:pointer;padding:8px 12px;font-weight:600;color:#1f2937">'
            . '📄 Versione testuale accessibile della mappa</summary>';
        $h .= '<div class="fm-mappa-alt__body" style="padding:4px 16px 12px">';

        if ($nodes !== []) {
            $h .= '<p style="margin:6px 0 2px;font-weight:600;color:#475569">Concetti (' . \count($nodes) . ')</p><ul style="margin:0 0 8px;padding-left:20px">';
            foreach ($nodes as $label) {
                $h .= '<li>' . $esc($label) . '</li>';
            }
            $h .= '</ul>';
        }

        // Relazioni leggibili: "A → (etichetta) → B" (solo se entrambi i nodi
        // hanno testo, altrimenti l'arco non è descrivibile a parole).
        $rel = [];
        foreach ($edges as [$from, $lbl, $to]) {
            $a = $nodes[$from] ?? '';
            $b = $nodes[$to] ?? '';
            if ($a === '' || $b === '') {
                continue;
            }
            $rel[] = $lbl !== '' ? ($a . ' → ' . $lbl . ' → ' . $b) : ($a . ' → ' . $b);
        }
        if ($rel !== []) {
            $h .= '<p style="margin:6px 0 2px;font-weight:600;color:#475569">Relazioni (' . \count($rel) . ')</p><ul style="margin:0;padding-left:20px">';
            foreach ($rel as $line) {
                $h .= '<li>' . $esc($line) . '</li>';
            }
            $h .= '</ul>';
        }

        $h .= '</div></details>';
        return $h;
    }

    /**
     * Il link di una mappa senza file, se è un indirizzo web (http o https):
     * nient'altro diventa un collegamento nella pagina.
     *
     * @param array<string,mixed> $riga
     */
    private static function linkDellaMappa(array $riga): ?string
    {
        $meta = $riga['metadata'] ?? null;
        if (!\is_array($meta) && isset($riga['metadata_json'])) {
            $meta = json_decode((string)$riga['metadata_json'], true);
        }
        $href = \is_array($meta) && \is_array($meta['mappa'] ?? null) ? trim((string)($meta['mappa']['href'] ?? '')) : '';
        $schema = strtolower((string)parse_url($href, PHP_URL_SCHEME));
        return $href !== '' && \in_array($schema, ['http', 'https'], true) ? $href : null;
    }

    /**
     * La scheda di una mappa che sta altrove: titolo, dove si apre, e per un
     * drawio su Drive come averla nella pagina.
     */
    private static function schedaLinkDellaMappa(int $rid, string $topic, string $title, string $link): string
    {
        $esc = static fn(string $s): string => \htmlspecialchars($s, ENT_QUOTES);
        $host = (string)parse_url($link, PHP_URL_HOST);
        $h  = '<div class="fm-mappa-wrap fm-mappa-link" data-id="' . $rid . '"'
            . ' style="margin:10px;padding:14px;border:1px solid #d9dee6;border-radius:6px">';
        $h .= '<div class="fm-titolo-quesito" style="font-weight:bold;margin-bottom:6px">' . $esc($topic) . ' — ' . $esc($title) . '</div>';
        $h .= '<p style="margin:0 0 8px">🔗 Questa mappa si apre su <strong>' . $esc($host) . '</strong>.</p>';
        $h .= '<a class="fm-btn fm-btn--primary fm-btn--sm" href="' . $esc($link) . '" target="_blank" rel="noopener noreferrer">Apri la mappa ↗</a>';
        if (\App\Services\Maps\MappaDaLinkDrive::idDalLink($link) !== null) {
            $h .= '<p class="fm-muted" style="font-size:12px;margin:8px 0 0">È un file drawio su Google Drive che non è stato importato. '
                . 'Per vederla qui: rendi il file pubblico e crea di nuovo la mappa con «Link esterno», oppure caricalo con «Carica file».</p>';
        }
        $h .= '</div>';
        return $h;
    }

    /** Il pulsante «Scarica .drawio» di una mappa (lo serve il listener in fondo alla pagina). */
    private function pulsanteScaricaDrawio(int $rid): string
    {
        return '<button type="button" class="fm-btn fm-btn--ghost fm-btn--sm fm-mappa-download-btn"'
            . ' data-fm-content-id="' . $rid . '"'
            . ' title="Scarica il file .drawio sul tuo dispositivo. Aprilo su https://app.diagrams.net via File → Open o drag-and-drop.">'
            . '📥 Scarica .drawio</button>';
    }

    /**
     * Phase 18 — render dedicato per content_type=mappa: iframe Google
     * Drawio full-width, senza upbar/header_page/fm-draggable-container
     * (read-only, niente CRUD da fare).
     */
    private function renderMappaTopicHtml(array $params, string $topic, array $rows): string
    {
        $esc = static fn(?string $s): string => \htmlspecialchars((string)$s, ENT_QUOTES);
        $h  = '<div class="fm-pagestyle fm-mappa-study">';
        // Phase 20 — breadcrumb inline (topic · type · subj · ind/cls)
        // rimosso: l'evidenziazione dell'item aperto avviene via highlight
        // nella sidepage (data-content-id match sul .fm-contract-wrap /
        // .fm-mappa-wrap visibile a pagina).
        if (!$rows) {
            $h .= '<p class="fm-muted" style="padding:10px">Nessuna mappa in questo topic.</p>';
            $h .= '</div>';
            return $h;
        }
        // Phase G7 — render mappe con viewer.diagrams.net?lightbox + XML
        // INLINE compresso (gzdeflate + base64) via fragment #R. Vantaggi:
        //   - lightbox toolbar (page nav, zoom, copia, fit, edit pencil)
        //   - no fetch URL (no mixed-content http/https, no CORS)
        //   - server e' single source of truth (blob locale cifrato)
        // Fallback per file > MAX_INLINE_BYTES (URL browser limit ~2MB):
        // embed mode + postMessage (perde lightbox toolbar ma carica
        // file grandi).
        $blobStore = $this->blobStore ?? new \App\Services\Maps\MapBlobStore();
        $inlineBlobs = [];                    // id => xml plaintext (per fallback embed)
        $MAX_FRAGMENT_BYTES = 800 * 1024;     // ~800KB compressed → ~1.1MB URL

        foreach ($rows as $r) {
            $rid  = (int)$r['id'];
            $full = $this->repo->find($rid) ?: $r;
            $blobPath = (string)($full['map_blob_path'] ?? '');
            $ownerTid = (int)($full['teacher_id'] ?? 0);

            // 15/9/2026 — una mappa nata dal «🔗 Link esterno» senza il file sul
            // server: si mostra il collegamento, non l'avviso «orphan» di una
            // migrazione che non la riguarda (segnalato dall'utente).
            $link = $blobPath === '' ? self::linkDellaMappa($full) : null;
            if ($link !== null) {
                $h .= self::schedaLinkDellaMappa($rid, (string)$r['topic'], (string)$r['title'], $link);
                continue;
            }
            if ($blobPath === '' || $ownerTid <= 0) {
                // Orphan: blob mancante (drawio_id 404 o legacy fake id).
                $h .= '<div class="fm-mappa-wrap fm-mappa-orphan" data-id="' . $rid . '"'
                    . ' style="margin:10px;padding:14px;border:1px dashed #c2c2c2;border-radius:6px;background:#fafafa">';
                $h .= '<div class="fm-titolo-quesito" style="font-weight:bold;margin-bottom:4px">'
                    . $esc($r['topic']) . ' — ' . $esc($r['title']) . '</div>';
                $h .= '<div class="fm-muted" style="font-size:13px">⚠ Mappa non disponibile localmente (orphan).</div>';
                $h .= '<div class="fm-muted" style="font-size:11px;margin-top:6px">Il file originale su Drive non e\' stato trovato durante la migrazione. '
                    . 'Per ripristinarla: docente carica il drawio dal proprio archivio via "Crea mappa → Carica file".</div>';
                $h .= '</div>';
                continue;
            }

            try {
                $xml = $blobStore->get($ownerTid, $blobPath);
            } catch (\Throwable $e) {
                error_log("ContentStudyController.mappa blob load fail id=$rid: " . $e->getMessage());
                $h .= '<div class="fm-mappa-wrap fm-error" data-id="' . $rid . '">'
                    . $esc($r['topic']) . ' — ' . $esc($r['title']) . ' '
                    . '<span class="fm-muted">(errore lettura blob)</span></div>';
                continue;
            }

            // Strategia render:
            //   1. viewer + #R fragment (gzdeflate+base64) — file piccoli;
            //   2. viewer + embed + postMessage — file grandi: l'XML glielo
            //      passa la pagina, dal browser.
            //
            // Fino al 15/9/2026 in HTTPS i file grandi andavano al viewer con
            // #U e un URL firmato (/api/maps/dl). Il viewer non lo scarica dal
            // browser ma dal proxy di diagrams.net (IP Cloudflare, user-agent
            // «draw.io»): il WAF gli rispondeva con la challenge e il viewer
            // diceva «File non trovato» (waf_logs, challenge_first dal 1/9). E
            // il contenuto decifrato passava dai server di JGraph, contro
            // ADR-009. Con postMessage l'XML resta nel browser.
            $title = (string)$r['title'];

            // drawio bug: durante load XML via fragment #R, fa
            // decodeURIComponent su alcuni attribute values. Se il testo
            // contiene "%X" dove X non e' hex valido (es. "%;", "% ", "%&")
            // → "URI malformed" exception.
            // Fix: escape preventivo dei "%" literal a "%25" (URI encoded).
            // Drawio decodifica via decodeURIComponent → torna al "%"
            // originale. Le sequenze gia' valide "%XX" sono preservate
            // (lookahead negativo). Save round-trip OK perche' drawio
            // ri-encodifica quando salva.
            $xmlSafe    = (string)\preg_replace('/%(?![0-9A-Fa-f]{2})/', '%25', $xml);
            $compressed = @\gzdeflate($xmlSafe, 9);
            $fragment   = $compressed !== false ? \base64_encode($compressed) : '';

            if ($fragment !== '' && \strlen($fragment) <= $MAX_FRAGMENT_BYTES) {
                // 1. Fragment inline. drawio Editor.parseDiagramNode applica
                // decodeURIComponent sul fragment dopo "#R" prima del
                // base64 decode + inflate. I caratteri base64 +/= a volte
                // causano "URI malformed". Fix: rawurlencode del fragment
                // → decodeURIComponent ripristina base64 standard.
                $src = 'https://viewer.diagrams.net/?lightbox=1&dark=0&nav=1'
                     . '&title=' . \rawurlencode($title)
                     . '#R' . \rawurlencode($fragment);
                $mode = 'viewer-fragment';
            } else {
                // 2. File grandi: embed + postMessage, in sviluppo e in produzione.
                $inlineBlobs[$rid] = $xml;
                $h .= '<div class="fm-mappa-wrap" data-id="' . $rid . '" data-fm-mode="embed-blob" style="margin:10px">';
                $h .= '<div class="fm-titolo-quesito" style="font-weight:bold;margin-bottom:6px">'
                    . $esc($r['topic']) . ' — ' . $esc($title) . '</div>';
                // viewer.diagrams.net + lightbox + embed proto: combo
                // sperimentale per ottenere la lightbox toolbar (page nav,
                // zoom) ANCHE per file grandi caricati via postMessage XML
                // inline (evitando il limite URL fragment ~800KB).
                $h .= '<iframe class="fm-mappa-iframe" id="fm-mappa-iframe-' . $rid . '"'
                    . ' title="Mappa concettuale: ' . $esc($title) . '"'
                    . ' src="https://viewer.diagrams.net/?lightbox=1&embed=1&proto=json&dark=0&nav=1"'
                    . ' loading="lazy" allow="fullscreen"'
                    . ' style="width:100%;height:620px;border:1px solid #ccc;border-radius:4px"></iframe>';
                $h .= $this->mapTextAlternative($xml);
                // Il download del .drawio anche per le grandi: fino al 15/9/2026
                // in produzione le grandi passavano dal ramo con l'URL firmato,
                // che il pulsante l'aveva.
                $h .= '<div class="fm-mappa-actions"'
                    . ' style="margin-top:6px;text-align:right;display:flex;gap:6px;justify-content:flex-end">'
                    . $this->pulsanteScaricaDrawio($rid)
                    . '</div>';
                $h .= '</div>';
                continue;
            }

            // Phase G7 — link "Modifica copia": apre app.diagrams.net in
            // NUOVA FINESTRA con la mappa pre-caricata via fragment #R
            // (gzdeflate+base64 inline). L'utente edita li' nativamente
            // e usa il save/save-as standard di drawio per scaricare o
            // salvare su Drive personale. NESSUN salvataggio sul nostro
            // server — l'originale del docente proprietario resta
            // intatto a prescindere.
            //
            // $fragment riusato: e' gia' calcolato sopra per il viewer
            // mode 1 (deflate+base64). Disponibile sempre se compressed
            // size OK (<800KB). Per file grandi: fallback download
            // diretto del XML come .drawio (l'utente apre poi in app
            // drawio desktop o caricalo su drawio.com manualmente).
            $h .= '<div class="fm-mappa-wrap" data-id="' . $rid . '" data-fm-mode="' . $mode . '" style="margin:10px">';
            $h .= '<div class="fm-titolo-quesito" style="font-weight:bold;margin-bottom:6px">'
                . $esc($r['topic']) . ' — ' . $esc($title) . '</div>';
            $h .= '<iframe class="fm-mappa-iframe"'
                . ' title="Mappa concettuale: ' . $esc($title) . '"'
                . ' src="' . $esc($src)
                . '" loading="lazy" allow="fullscreen"'
                . ' style="width:100%;height:620px;border:1px solid #ccc;border-radius:4px"></iframe>';
            $h .= $this->mapTextAlternative($xml);
            // Phase G7 — bottoni "Modifica copia" + "Scarica .drawio".
            // Per file piccoli (fragment OK): link diretto ad
            // app.diagrams.net?#R<encoded> in nuova finestra. Drawio
            // Editor.parseDiagramNode decodifica via decodeURIComponent
            // → atob → inflate. rawurlencode garantisce che il base64
            // standard (+/=) non confonda decodeURIComponent.
            // Per file grandi (ramo embed qui sopra): solo download Blob locale
            // (l'utente apre il .drawio su drawio.com via File → Open o
            // drag-and-drop).
            //
            // inlineBlobs sempre popolato per supportare il download.
            $inlineBlobs[$rid] = $xml;
            $h .= '<div class="fm-mappa-actions" style="margin-top:6px;text-align:right;display:flex;gap:6px;justify-content:flex-end">';
            // Qui arrivano solo le mappe piccole: il frammento c'è sempre.
            $forkUrl = 'https://app.diagrams.net/?src=about&title='
                     . \rawurlencode($title) . '#R' . \rawurlencode($fragment);
            $h .= '<a class="fm-btn fm-btn--ghost fm-btn--sm" target="_blank" rel="noopener"'
                . ' href="' . $esc($forkUrl) . '"'
                . ' title="Apre app.diagrams.net in nuova finestra con una copia modificabile.'
                . ' Usa File → Save / Save as nel menu drawio per scaricare il file modificato.">'
                . '📝 Modifica copia su drawio.com ↗</a>';
            $h .= $this->pulsanteScaricaDrawio($rid);
            $h .= '</div>';
            $h .= '</div>';
        }

        // Il file di ogni mappa, per «Scarica .drawio» e per il visualizzatore
        // delle mappe grandi (embed + postMessage), in un'isola JSON.
        //
        // 23/9/2026 (revisione architetturale A-19, R-3 passo 3) — erano due
        // funzioni scritte qui in due `<script>` in linea, con l'XML dentro il
        // JavaScript: fuori da ESLint e dalle prove, e con la CSP rigorosa
        // eseguibili solo grazie al nonce. Adesso il comportamento sta nel
        // bundle (js/modules/features/mappe-della-pagina.js) e qui restano i
        // dati: `type="application/json"`, che il browser non esegue e che
        // non porta il nonce. La codifica resta JSON_NELLO_SCRIPT (A-3): anche
        // in un'isola di dati un `</script>` del file la chiuderebbe.
        // JSON_FORCE_OBJECT: gli id fanno sempre da chiavi, anche se un giorno
        // fossero 0, 1, 2… (json_encode ne farebbe una lista).
        if ($inlineBlobs !== []) {
            $json = \json_encode($inlineBlobs, self::JSON_NELLO_SCRIPT | JSON_FORCE_OBJECT);
            if ($json === false) {
                // Un file non in UTF-8 (FileDrawio lo rifiuta dal 23/9, ma i
                // blob di prima possono esserci): nessuna mappa in pagina, e
                // il pulsante lo dice, invece di un'isola che non si legge.
                error_log('StudyPageRenderer: XML delle mappe non codificabile in JSON: ' . \json_last_error_msg());
                $json = '{}';
            }
            $h .= '<script type="application/json" data-fm-mappe-xml>' . $json . '</script>';
        }
        $h .= '</div>';
        return $h;
    }

    /**
     * Phase 24.35 — Estrai PT AST da row.metadata.body_pt. La query base
     * `repo->search` NON include metadata_json (perf), quindi se non c'è
     * fa lookup via find($id) per recuperarlo.
     */
    private function extractBodyPt(array $row, bool $allowPlaceholder = false): ?array
    {
        $meta = $row['metadata'] ?? null;
        if (!\is_array($meta)) {
            $raw = $row['metadata_json'] ?? null;
            if (\is_string($raw) && $raw !== '') {
                $meta = \json_decode($raw, true);
            }
        }
        // Se search query non ha metadata, fai full lookup
        if (!\is_array($meta) && isset($row['id'])) {
            $full = $this->repo->find((int)$row['id']);
            if ($full) {
                $raw = $full['metadata_json'] ?? null;
                if (\is_string($raw) && $raw !== '') {
                    $meta = \json_decode($raw, true);
                }
            }
        }
        if (!\is_array($meta)) {
            return null;
        }
        $pt = $meta['body_pt'] ?? null;
        if (!\is_array($pt) || \count($pt) === 0) {
            return null;
        }
        $first = $pt[0] ?? null;
        if (!\is_array($first) || empty($first['_type'])) {
            return null;
        }
        // G22.S22 — Se body_pt è solo placeholder (sectionHeader + block
        // vuoti, niente problem-group ne testo non-vuoto), ritorna null per
        // far fallback al contract.json. Evita "schermata vuota" quando il
        // metadata contiene solo lo scheletro "Esercizi per studenti / Verifiche".
        // ADR-024 — il path CUSTOM passa $allowPlaceholder=true: il body_pt È il
        // documento (anche se è solo "sectionHeader + corpo vuoto" appena creato)
        // → va sempre reso col componente per l'editing, mai fallback contract.
        // La regola del segnaposto è una sola, e decide anche il 📥 della barra.
        if (!$allowPlaceholder && \App\Support\RigheDellaBarra::eSegnaposto($pt)) {
            return null;
        }
        return $pt;
    }

    /** Phase 24.45 — legge metadata.layout (exercises|custom) con full lookup fallback. */
    private function extractLayout(array $row): string
    {
        $meta = $row['metadata'] ?? null;
        if (!\is_array($meta)) {
            $raw = $row['metadata_json'] ?? null;
            if (\is_string($raw) && $raw !== '') {
                $meta = \json_decode($raw, true);
            }
        }
        if (!\is_array($meta) && isset($row['id'])) {
            $full = $this->repo->find((int)$row['id']);
            if ($full) {
                $raw = $full['metadata_json'] ?? null;
                if (\is_string($raw) && $raw !== '') {
                    $meta = \json_decode($raw, true);
                }
            }
        }
        if (!\is_array($meta)) {
            return '';
        }
        $l = $meta['layout'] ?? '';
        return \is_string($l) ? $l : '';
    }

    private function isCustomLayout(array $row): bool
    {
        return $this->extractLayout($row) === 'custom'
            && \is_array($this->extractBodyPt($row, true));
    }

    /** ADR-024 — legge metadata.render_mode (interactive|html), default interactive.
     *  Stesso full-lookup fallback di extractLayout: il $row del path studio non
     *  include sempre metadata_json, quindi se assente si rilegge via repo->find. */
    private function extractRenderMode(array $row): string
    {
        $m = $this->extractMeta($row)['render_mode'] ?? '';
        return $m === 'html' ? 'html' : 'interactive';
    }

    /** Metadata del content come array. Stesso full-lookup fallback di
     *  extractRenderMode/extractLayout: il $row del path studio non include
     *  sempre metadata_json, quindi se assente si rilegge via repo->find. */
    private function extractMeta(array $row): array
    {
        $meta = $row['metadata'] ?? null;
        if (!\is_array($meta)) {
            $raw = $row['metadata_json'] ?? null;
            if (\is_string($raw) && $raw !== '') {
                $meta = \json_decode($raw, true);
            }
        }
        if (!\is_array($meta) && isset($row['id'])) {
            $full = $this->repo->find((int)$row['id']);
            if ($full) {
                $raw = $full['metadata_json'] ?? null;
                if (\is_string($raw) && $raw !== '') {
                    $meta = \json_decode($raw, true);
                }
            }
        }
        return \is_array($meta) ? $meta : [];
    }

    /** Flag metadata.includeHeaderHtml (default true): controlla se l'intestazione
     *  istituto + selettori (primo sectionHeader-con-selectors del body_pt)
     *  compare nell'HTML statico pubblicato reso agli studenti. */
    private function extractIncludeHeaderHtml(array $row): bool
    {
        $meta = $this->extractMeta($row);
        if (!\array_key_exists('includeHeaderHtml', $meta)) {
            return true;
        }
        return \filter_var($meta['includeHeaderHtml'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Phase 24.45 — render layout=custom: pagina libera senza scaffolding
     * #header_page / .fm-draggable-container. PT body diretto in wrapper minimal
     * .fm-pt-custom-page (matcha la coppia .fm-risdoc-toolbar/.fm-risdoc-sticky-head
     * lato edit ma in render finale serve solo il body PT).
     */
    private function renderCustomTopicHtml(string $type, array $params, array $row): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        $rid = (int)$row['id'];
        $ptBody = $this->extractBodyPt($row, true);
        // ADR-030 — doc "valori per terna": applica i valori 🔗 della terna
        // dell'URL (indirizzo/classe/materia) e RIMUOVE il blocco ternaStore
        // prima del render, così la vista studente/SSR mostra i valori giusti
        // per la combinazione. No-op per i doc non terna_scoped.
        //
        // Il flag `terna_scoped` è in metadata_json (PLAINTEXT, presente grazie a
        // with_metadata) → gating CHEAP: solo per i doc terna_scoped si fa la
        // find() dedicata per il body_pt DECIFRATO (search non lo espone in modo
        // affidabile). Per i doc normali: nessun decrypt extra.
        $metaPlain030 = \is_array($row['metadata'] ?? null) ? $row['metadata']
            : ((\is_string($row['metadata_json'] ?? null) && $row['metadata_json'] !== '')
                ? (\json_decode($row['metadata_json'], true) ?: []) : []);
        if (\App\Services\Risdoc\Pt\TernaBinding::isTernaScoped($metaPlain030)) {
            $full030 = $this->repo->find($rid);
            $bp030 = $full030['metadata']['body_pt'] ?? null;
            if (\is_array($bp030) && $bp030 !== []) {
                $ternaKey = ($params['ind'] ?? '') . '/' . ($params['cls'] ?? '') . '/' . ($params['subj'] ?? '');
                $ptBody = \App\Services\Risdoc\Pt\TernaBinding::applyAndStrip($bp030, $ternaKey);
            }
        }
        $canEdit = $this->userCanEdit();
        $title = (string)($row['title'] ?? 'Documento');
        $renderMode = $this->extractRenderMode($row);

        // 2026-05-30 — checkbox "Includi intestazione e selettori nell'HTML
        // statico pubblicato" (metadata.includeHeaderHtml, default true). Se off,
        // rimuove il primo blocco sectionHeader-con-selettori (l'intestazione
        // istituto + classe/indirizzo/disciplina) dal body reso ai lettori. Il
        // docente continua a vederla in edit: l'editor ricarica body_pt completo
        // via adapter, qui togliamo solo dal markup pubblicato.
        if (\is_array($ptBody) && $ptBody !== [] && !$this->extractIncludeHeaderHtml($row)) {
            $first = $ptBody[0] ?? null;
            if (
                \is_array($first)
                && (($first['_type'] ?? '') === 'sectionHeader')
                && \is_array($first['selectors'] ?? null)
            ) {
                \array_shift($ptBody);
            }
        }

        // G24 (ADR-022) — WebComponent unificato <fm-pt-document>: consolida
        // view/edit/export (JSON/TeX/HTML) in un componente coeso. SSR-first:
        // il body HTML (PtToHtml) è pre-renderizzato DENTRO il tag con
        // data-ptdoc-ssr → no-flash + graceful degradation se JS off.
        // Il componente cattura quell'HTML per la view, carica body_pt lazy
        // solo per edit/export. Sostituisce il vecchio markup .fm-pt-rendered
        // + topbar buttons sparsi + pt-inline-editor.js.
        $bodyHtml = \App\Services\Risdoc\Pt\PtToHtml::render($ptBody ?? [], [
            'fields' => [],
            'state'  => [
                'classe'     => $params['cls']  ?? '',
                'sezione'    => '',
                'indirizzo'  => $params['ind']  ?? '',
                'disciplina' => $params['subj'] ?? '',
            ],
        ]);

        // ADR-024 — render_mode=html per studenti (no edit): vista "articolo"
        // sanitizzato puro, nessun componente. Il markup è identico a quello
        // che il componente mostrerebbe in view (stessi CSS _pt-page-doc.css).
        if ($renderMode === 'html' && !$canEdit) {
            $h  = '<div id="fm-upbar"></div>';
            $h .= '<div class="fm-pagestyle fm-pt-custom-page fm-pt-custom-page--html"'
                . ' data-layout="custom" data-render-mode="html"'
                . ' data-content-type="' . $esc($type) . '">';
            $h .= '<article class="ptdoc__body ptdoc__body--standalone">';
            if ($title !== '') {
                $h .= '<h2 class="ptdoc__title">' . $esc($title) . '</h2>';
            }
            $h .= $bodyHtml;
            $h .= '</article>';
            $h .= '</div>';
            return $h;
        }

        // ADR-024 — render_mode=html per docente: componente in vista HTML con
        // topbar per ri-switchare a interattivo. Altrimenti interattivo pieno.
        // 2026-05-27 — CENTRALIZZAZIONE: il documento custom usa la STESSA shell
        // dei modelli (`.fm-risdoc-view--unified` + script risdoc + body class
        // fm-studio-risdoc via wrapInShell) → stile, impaginazione e funzionalità
        // (navigator, sticky, export hooks) IDENTICI. Unica differenza:
        // source="teacher-content" vs "risdoc-template".
        // 2026-05-27 — niente #fm-upbar legacy sui doc custom: la topbar è la
        // <fm-doc-topbar> dentro <fm-pt-document> (come i modelli, che NON hanno
        // l'upbar). Evita la doppia barra in cima.
        $h  = '<div class="fm-risdoc-view fm-risdoc-view--unified fm-pt-custom-page" data-layout="custom"'
            . ' data-render-mode="' . $esc($renderMode) . '"'
            . ' data-content-type="' . $esc($type) . '">';
        $h .= '<fm-pt-document doc-id="' . $rid . '" source="teacher-content"'
            . ($canEdit ? ' can-edit="1"' : '')
            . ' render-mode="' . $esc($renderMode) . '"'
            . ' title="' . $esc($title) . '">';
        // SSR body (no-flash, graceful degradation). data-ptdoc-ssr =
        // hook per il componente che cattura solo questo HTML.
        $h .= '<div data-ptdoc-ssr>' . $bodyHtml . '</div>';
        $h .= '</fm-pt-document>';
        $h .= '</div>';
        // ADR-026 #3 — fm-risdoc-export.js + fm-risdoc-toolbar-actions.js
        // ELIMINATI (cleanup post engine-delete: toolbar/save/export ora resi
        // da fm-pt-document._topbarButtons internamente). Section-navigator
        // resta come modulo standalone (UI navigazione sezioni).
        $h .= '<script' . \App\Support\Csp::attributo() . ' src="/js/components/risdoc/fm-risdoc-section-navigator.js"></script>';
        return $h;
    }

    /**
     * Pagina dedicata "nessun documento" per i tipi documento (risdoc/bes)
     * quando la combinazione indirizzo/classe/materia/topic non ha un documento.
     * Evita la pagina generica esercizi (disclaimer + "Nessun item"), che su una
     * pagina-documento confonde l'utente.
     */
    private function renderDocEmptyHtml(string $type, array $params, string $topic): string
    {
        $esc = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        $ind  = $esc($params['ind']  ?? '');
        $cls  = $esc($params['cls']  ?? '');
        $subj = $esc($params['subj'] ?? '');
        $tp   = $esc($topic);
        $kind = $type === 'bes' ? 'documento BES/DSA' : 'documento';
        $muted = 'color:var(--fm-text-muted,#9fb0c8);';
        // NB: niente <div id="fm-upbar"> qui → wrapInShell non inietta la toolbar
        // verifica/documento (la pagina "nessun documento" deve restare pulita).
        $h  = '<div class="fm-pagestyle fm-doc-empty" style="display:flex;justify-content:center;padding:48px 16px;">';
        $h .= '<div style="max-width:560px;text-align:center;background:var(--fm-card-bg,#1b2230);border:1px solid var(--fm-border,#33415a);border-radius:12px;padding:28px 26px;">';
        $h .= '<div style="font-size:40px;line-height:1;margin-bottom:10px;">📄</div>';
        $h .= '<h2 style="margin:0 0 10px;font-size:18px;">Nessun ' . $kind . ' per questa combinazione</h2>';
        $h .= '<p style="margin:0 0 14px;' . $muted . '">Non esiste un ' . $kind . ' (topic <strong>' . $tp . '</strong>) per:<br>'
            . '<strong>indirizzo ' . $ind . ' · classe ' . $cls . ' · materia ' . $subj . '</strong>.</p>';
        $h .= '<p style="margin:0;' . $muted . 'font-size:13px;">Imposta indirizzo/classe/materia nella barra laterale sulla combinazione giusta per aprire un '
            . $kind . ' esistente, oppure crealo dalla sezione <strong>Risorse docente</strong> (pulsante <strong>+</strong>).</p>';
        $h .= '</div></div>';
        return $h;
    }

    public function renderTopicHtml(string $type, array $params, string $topic, array $rows): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        // Phase 18 — formato 'map' (mappe): render iframe pulito senza
        // upbar/header_page. ADR-027 — branch su FORMATO, non sul nome-tipo.
        if (TeacherContentRepository::formatOf($type) === 'map') {
            return $this->renderMappaTopicHtml($params, $topic, $rows);
        }
        // Tipi DOCUMENTO (risdoc/bes): se NON c'è un documento per questa
        // combinazione indirizzo/classe/materia/topic, mostra una pagina DEDICATA
        // "nessun documento" invece della pagina generica esercizi (disclaimer
        // esercizi + "Nessun item" → confonde l'utente su una pagina-documento).
        if (\count($rows) === 0 && \in_array($type, ['risdoc', 'bes'], true)) {
            return $this->renderDocEmptyHtml($type, $params, $topic);
        }
        // Phase 24.45 — layout=custom (PT-libero): scaffolding minimo, no
        // <div id="header_page">, no .fm-draggable-container. La struttura del
        // documento è interamente nel body_pt costruito via PT editor.
        // Solo single-row teacher_content (caso /studio/{type}/.../{topic}).
        if (\count($rows) === 1 && $this->isCustomLayout($rows[0])) {
            return $this->renderCustomTopicHtml($type, $params, $rows[0]);
        }
        $h  = '<div id="fm-upbar"></div>';
        $h .= '<div id="header_page">';
        $h .= '<div class="fm-header-body"></div>';
        $h .= '<div class="fm-source-citation"></div>';
        $h .= '</div>';
        $h .= '<div class="fm-pagestyle fm-db-study">';
        // Phase 20 — breadcrumb inline (topic · type · subj · ind/cls)
        // rimosso: il titolo del contract è già nel `.fm-contract-wrap`
        // (ContractRenderer) e l'evidenziazione dell'item aperto avviene
        // via highlight nella sidepage (vedi sidepage-highlight.js).
        $h .= '<div class="fm-draggable-container" data-db-backed="1" data-content-type="' . $esc($type) . '">';
        if (!$rows) {
            $h .= '<p class="fm-muted">Nessun item in questo topic.</p>';
        } else {
            // Phase 16 — render via ContractRepository (centralizza letture
            // + espone version per optimistic locking lato client).
            $instituteId = (int)($params['institute_id'] ?? 0);
            $teacherId   = (int)($params['teacher_id']   ?? 0);
            // Phase 25.Q.8 — guard scope: solo teacher/admin vedono edit controls.
            $canEdit = $this->userCanEdit();
            $renderer = \App\Services\ContractRenderer::loadSourcesFor($instituteId, $teacherId, $canEdit);
            $contractRepo = \App\Repositories\Contract\ContractRepository::default();

            foreach ($rows as $r) {
                $rid = (int)$r['id'];

                // Phase 24.35 — render PT AST (metadata.body_pt) se presente.
                // Priorità: contract → PT body → legacy body_html.
                $ptBody = $this->extractBodyPt($r);
                if (\is_array($ptBody) && \count($ptBody) > 0) {
                    $ptKind = (string)($r['content_type'] ?? $type);
                    $ptKindAttr = in_array($ptKind, ['verifica', 'esercizio'], true)
                        ? ' data-kind="' . $ptKind . '"' : '';
                    $h .= '<div class="fm-contract-wrap fm-pt-rendered" data-id="' . $rid . '"' . $ptKindAttr . ' data-source="pt">';
                    $h .= '<h3 class="fm-pt-item-title">' . $esc((string)$r['title']) . '</h3>';
                    $h .= \App\Services\Risdoc\Pt\PtToHtml::render($ptBody, [
                        'fields' => [],
                        'state'  => [
                            'classe'     => $params['cls']  ?? '',
                            'sezione'    => '',
                            'indirizzo'  => $params['ind']  ?? '',
                            'disciplina' => $params['subj'] ?? '',
                        ],
                    ]);
                    $h .= '</div>';
                    continue;
                }

                $agg = $contractRepo->load($rid);
                if ($agg) {
                    // Wrapper:
                    //   - data-kind abilita lo sticky stacking legacy. Phase 24.77 —
                    //     esteso anche a 'esercizio' (prima solo verifica) così i
                    //     .fm-groupcollex aperti restano sticky sotto .fm-titolo.
                    //   - data-version propaga la version per If-Match sul save.
                    // Phase 17 — rimosso il label .collex-pick (funzionalità
                    // superflua sovrapposta al flusso tipoEsercizio_ver).
                    // Fix clone verifica→esercizio: il data-kind abilita il gate
                    // JS del clone (checkin-handlers `inVerifica`). Derivalo dal
                    // content_type REALE della riga, non dall'URL $type (che può
                    // essere una sezione risdoc/bes → in passato bloccava il clone).
                    $rowKind = (string)($r['content_type'] ?? $type);
                    $kind = in_array($rowKind, ['verifica', 'esercizio'], true)
                        ? ' data-kind="' . $rowKind . '"' : '';
                    $h .= '<div class="fm-contract-wrap" data-id="' . $rid . '"'
                       . $kind . ' data-version="' . $agg->version() . '">';
                    $h .= $renderer->renderContract($agg->data());
                    $h .= '</div>';
                    continue;
                }
                // Phase 17 — DEPRECATED body_html fallback.
                // Zero righe live ne dipendono (verificato da
                // tools/audit_legacy_body_html.php). Manteniamo il path solo
                // come safety net: se un admin importa legacy data, non
                // crashiamo, ma logghiamo un warning strutturato.
                $full = $this->repo->find($rid);
                $body = (string)($full['body_html'] ?? '');
                if ($body !== '') {
                    error_log(sprintf(
                        '[deprecated] body_html fallback used for teacher_content.id=%d (no contract_key). '
                        . 'Run tools/audit_legacy_body_html.php to identify + migrate.',
                        $rid
                    ));
                }
                $h .= '<div class="fm-contract-fallback fm-collection__item" data-id="' . $rid . '" data-legacy="1">';
                $h .= '<div class="fm-titolo-quesito">#' . $rid . ' · ' . $esc($r['title']) . '</div>';
                // Hardening (audit 2026-06-14, FND-007): sink XSS latente sul
                // fallback deprecato body_html — sanitizza comunque (HTMLPurifier)
                // anche se "zero righe live" lo raggiungono. Defense-in-depth.
                $h .= '<div class="fm-collection">' . \App\Services\Security\HtmlSanitizer::forBlockContent($body) . '</div></div>';
            }
        }
        $h .= '</div></div>';
        return $h;
    }

    public function wrapInShell(Request $req, string $body, string $title, ?string $type = null): Response
    {
        // Popola #fm-upbar (server-rendered) via _upbar_loader.
        // Questo file sta in app/Services/Study, tre livelli sotto la radice.
        // La riga veniva dal controller (due livelli): con 2 puntava ad app/,
        // l'include del layout falliva in silenzio e la pagina usciva vuota.
        $base = dirname(__DIR__, 3);
        $upbarPath = $base . '/views/partials/_upbar_loader.php';
        if (is_file($upbarPath) && preg_match('#<div\s+id=["\']fm-upbar["\']\s*>\s*</div\s*>#i', $body)) {
            ob_start();
            require $upbarPath;
            $upbarHtml = (string)ob_get_clean();
            $body = preg_replace(
                '#<div\s+id=["\']fm-upbar["\']\s*>\s*</div\s*>#i',
                '<div id="fm-upbar">' . $upbarHtml . '</div>',
                $body,
                1,
            ) ?? $body;
        }

        $isPartial = ($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1';
        if ($isPartial) {
            return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $pageTitle    = 'PANTEDU — ' . $title;
        $pageContent  = $body;
        // Phase 15 — body.fm-admin-access abilita le regole CSS legacy (#sel-origin,
        // #toggle-checkboxABin-control, #analytics-btn, [id^=type_verAll]) che
        // sono gated da `body.fm-admin-access` oltre a `.fm-upbar.fm-admin-access`.
        // Phase 18 — formato 'map': NO exercise-context (evita padding-top:95px
        // per upbar fixed). ADR-027 — branch su FORMATO.
        // Le pagine elenco (/studio/{type}/{ind}/{cls}/{subj}) arrivano senza
        // tipo: formatOf() vuole una stringa e con null lanciava un TypeError,
        // che il WAF (fail-closed) trasformava nella pagina «Verifica…» in loop.
        if ($type !== null && TeacherContentRepository::formatOf($type) === 'map') {
            $bodyClass = 'fm-mappa-view fm-studio-light';
        } elseif (str_contains($body, 'fm-doc-empty')) {
            // Pagina dedicata "nessun documento": shell PULITA, niente
            // exercise-context (toolbar verifica + padding upbar fixed).
            $bodyClass = 'fm-studio-light';
        } elseif (str_contains($body, '<fm-pt-document ')) {
            // NB lo SPAZIO finale è voluto: l'elemento reale è
            // `<fm-pt-document doc-id=...` (con attributi), mentre il COMMENTO in
            // views/partials/_topbar_modern.php contiene la stringa
            // `<fm-pt-document>` (senza spazio) → senza lo spazio lo str_contains
            // matchava il commento e dava la shell documento alle pagine ESERCIZIO
            // (topbar "DOCUMENTO" + UI esercizio mancante). Vedi fix regressione.
            // 2026-05-27 — CENTRALIZZAZIONE: la pagina di un documento custom
            // (fm-pt-document) usa lo STESSO body class dei modelli
            // (fm-studio-risdoc) → stesse regole CSS scoped + chrome/impaginazione.
            $bodyClass = 'fm-studio-risdoc fm-studio-light';
        } else {
            // G-fix-css-rename — emette SIA legacy SIA prefisso BEM per match
            // con regole CSS Sprint K-N (admin-access → fm-admin-access etc).
            $bodyClass = 'exercise-context fm-exercise-context fm-studio-light';
        }
        if (class_exists(Auth::class) && Auth::check() && Auth::hasAccess('admin')) {
            $bodyClass .= ' admin-access fm-admin-access';
        }
        // Body class per docenti (teacher e oltre): abilita le UI di
        // produzione verifica come `.dsa-wrapper-container` inline e
        // checkbox DSA nei `<li>` della traccia. Studenti NON la ricevono.
        if (class_exists(Auth::class) && Auth::check() && Auth::hasAccess('teacher')) {
            $bodyClass .= ' fm-teacher-access';
        }
        $currentRoute = $req->path;
        // Phase 16 — TikZJax ora serve anche per /studio/: i contract possono
        // contenere blocchi TikZ (renderizzati da ContractRenderer come
        // <script type="text/tikz">) e la preview in edit mode usa
        // window.process_tikz per renderizzare le modifiche live.
        $fmExerciseAssetsTier1 = false;
        $pageHead     = '';
        ob_start();
        include $base . '/views/layout/app.php';
        return new Response((string)ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
