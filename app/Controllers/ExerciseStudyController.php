<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Policies\ExerciseAccessPolicy;
use App\Repositories\ExerciseRepository;

/**
 * Rendering moderno DB-backed degli esercizi (M11).
 *
 * Route canoniche:
 *   GET /studio/{indirizzo}/{classe}/{materia}                → lista topics
 *   GET /studio/{indirizzo}/{classe}/{materia}/{topic}        → pagina esercizi
 *   GET /api/studio/topics.json?indirizzo&classe&materia      → JSON topics
 *   GET /api/studio/exercises.json?...                        → JSON collex-items
 *
 * Lo rendering HTML riutilizza il layout moderno app.php + body
 * class exercise-context + layout_es.css; ogni `.fm-collection__item` è
 * generato dal body_html in DB.
 *
 * ExerciseAccessPolicy filtra a livello di repo: studente è sempre
 * confinato al proprio sectionCode.
 */
final class ExerciseStudyController
{
    public function topicsPage(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::html('<h1>DB non disponibile</h1>', 503);
        }
        $repo   = new ExerciseRepository();
        $policy = new ExerciseAccessPolicy(Auth::user() ? $this->currentDomainUser() : null);

        $filters = $policy->apply([
            'indirizzo' => $params['indirizzo'] ?? null,
            // G19.8 — accetta sia "2" (nuovo short) sia "2s" (legacy URL).
            'classe'    => isset($params['classe']) ? \App\Support\ClsNormalizer::expand((string)$params['classe']) : null,
            'materia'   => $params['materia']   ?? null,
            'limit'     => 500,
        ]);
        $rows = $repo->search($filters);

        $topics = [];
        foreach ($rows as $r) {
            $key = $r['topic'];
            $topics[$key] ??= ['topic' => $key, 'count' => 0, 'difficulties' => []];
            $topics[$key]['count']++;
            if ($r['difficulty']) {
                $topics[$key]['difficulties'][$r['difficulty']] = true;
            }
        }
        ksort($topics);
        $topics = array_map(static function (array $t) {
            $t['difficulties'] = array_keys($t['difficulties']);
            sort($t['difficulties']);
            return $t;
        }, array_values($topics));

        $body = $this->renderTopicsHtml($filters, $topics);
        return $this->wrapInShell($req, $body, "Studio — {$filters['materia']}");
    }

    public function topicPage(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::html('<h1>DB non disponibile</h1>', 503);
        }
        $repo   = new ExerciseRepository();
        $policy = new ExerciseAccessPolicy(Auth::user() ? $this->currentDomainUser() : null);

        $topic = $this->normalizeTopic((string)($params['topic'] ?? ''));
        $filters = $policy->apply([
            'indirizzo' => $params['indirizzo'] ?? null,
            // G19.8 — accetta sia "2" (nuovo short) sia "2s" (legacy URL).
            'classe'    => isset($params['classe']) ? \App\Support\ClsNormalizer::expand((string)$params['classe']) : null,
            'materia'   => $params['materia']   ?? null,
            'topic'     => $topic,
            'limit'     => 500,
        ]);
        $rows = $repo->search($filters);

        $body = $this->renderTopicHtml($filters, $topic, $rows, $policy->canEdit());
        return $this->wrapInShell($req, $body, $topic);
    }

    public function topicsJson(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $repo   = new ExerciseRepository();
        $policy = new ExerciseAccessPolicy(Auth::user() ? $this->currentDomainUser() : null);
        $q = $req->query;
        $filters = $policy->apply([
            'indirizzo' => $q['indirizzo'] ?? null,
            'classe'    => isset($q['classe']) ? \App\Support\ClsNormalizer::expand((string)$q['classe']) : null,
            'materia'   => $q['materia']   ?? null,
            'limit'     => 500,
        ]);
        $rows = $repo->search($filters);
        $topics = [];
        foreach ($rows as $r) {
            $topics[$r['topic']] = ($topics[$r['topic']] ?? 0) + 1;
        }
        ksort($topics);
        $out = [];
        foreach ($topics as $t => $n) {
            $out[] = ['topic' => $t, 'count' => $n];
        }
        return Response::json(['ok' => true, 'topics' => $out]);
    }

    public function exercisesJson(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $repo   = new ExerciseRepository();
        $policy = new ExerciseAccessPolicy(Auth::user() ? $this->currentDomainUser() : null);
        $q = $req->query;
        $filters = $policy->apply([
            'indirizzo'  => $q['indirizzo']  ?? null,
            'classe'     => isset($q['classe']) ? \App\Support\ClsNormalizer::expand((string)$q['classe']) : null,
            'materia'    => $q['materia']    ?? null,
            'topic'      => $q['topic']      ?? null,
            'difficulty' => isset($q['difficulty']) ? (int)$q['difficulty'] : null,
            'limit'      => min(500, max(1, (int)($q['limit'] ?? 100))),
            'offset'     => max(0, (int)($q['offset'] ?? 0)),
        ]);
        $rows = $repo->search($filters);
        // Body/solution non inclusi in search() → fetch singolo on demand
        // mantiene il payload leggero (lista solo titoli + meta).
        return Response::json(['ok' => true, 'count' => count($rows), 'rows' => $rows]);
    }

    public function exerciseJson(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $policy = new ExerciseAccessPolicy(Auth::user() ? $this->currentDomainUser() : null);
        $repo = new ExerciseRepository();
        $row = $repo->find((int)($params['id'] ?? 0));
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        // Student scope check: rifiuta se l'esercizio è fuori dalla sua sezione.
        $constraints = $policy->scopeConstraints();
        foreach ($constraints as $k => $v) {
            if (($row[$k] ?? null) !== $v) {
                return Response::json(['error' => 'forbidden'], 403);
            }
        }
        return Response::json(['ok' => true, 'exercise' => $row]);
    }

    // ─────── Rendering ───────

    private function renderTopicsHtml(array $filters, array $topics): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        $h  = '<main class="fm-study">';
        $h .= '<h1>' . $esc($filters['materia'] ?? 'Esercizi') . ' — '
            . $esc($filters['indirizzo'] ?? '') . ' · ' . $esc($filters['classe'] ?? '') . '</h1>';
        if (!$topics) {
            $h .= '<p class="fm-muted">Nessun topic disponibile per questa sezione.</p>';
        } else {
            $h .= '<ul class="fm-study-topics">';
            foreach ($topics as $t) {
                $slug = rawurlencode($t['topic']);
                $diff = $t['difficulties']
                    ? ' <span class="fm-study-diff">(diff: ' . implode(',', array_map('intval', $t['difficulties'])) . ')</span>'
                    : '';
                $h .= '<li><a href="/studio/' . $esc($filters['indirizzo']) . '/'
                    . $esc($filters['classe']) . '/' . $esc($filters['materia']) . '/' . $slug . '">'
                    . $esc($t['topic']) . '</a> <span class="fm-study-count">×' . (int)$t['count'] . '</span>' . $diff . '</li>';
            }
            $h .= '</ul>';
        }
        $h .= '</main>';
        return $h;
    }

    private function renderTopicHtml(array $filters, string $topic, array $rows, bool $canEdit): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        $h  = '<div id="fm-upbar"></div>';
        $h .= '<div class="fm-pagestyle fm-db-study">';
        $h .= '<div class="fm-titolo"><h1>' . $esc($topic) . '</h1></div>';
        $h .= '<div class="fm-draggable-container" data-db-backed="1">';

        if (!$rows) {
            $h .= '<p class="fm-muted">Nessun esercizio in questo topic per la tua sezione.</p>';
        } else {
            // Phase 15 — chrome master-style: ogni topic è un .fm-groupcollex con
            // button.fm-collapsible + div.content che wrappa ol.collexercise.
            // Il modulo collapsible.js apre di default su fm:navigated
            // (UX moderna: contenuto visibile al caricamento, chiudibile).
            $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $topic) ?? $topic;
            $h .= '<div class="fm-groupcollex" id="problem_' . $esc($slug) . '">';
            $h .= '<div class="fm-pos-check-es"></div>';
            $h .= '<button class="fm-collapsible" type="button">' . $esc($topic) . '</button>';
            $h .= '<div class="content">';
            $h .= '<div class="fm-scrollbarhide">';
            $h .= '<ol class="fm-collexercise fm-db-collexercise" style="padding-left:0;margin-left:30px">';

            $ids  = array_column($rows, 'id');
            $full = $this->loadExercisesBulk($ids);
            foreach ($rows as $r) {
                $id   = (int)$r['id'];
                $full[$id] ??= $r;
                $body = (string)($full[$id]['body_html'] ?? '');
                $sol  = (string)($full[$id]['solution_html'] ?? '');
                $diff = (int)$r['difficulty'];
                $tags = is_array($r['tags'] ?? null) ? $r['tags'] : [];
                $tagCls = $tags ? ' ' . $esc(implode(' ', $tags)) : '';
                $h .= '<div class="fm-collection__item diff' . $diff . $tagCls . '" data-id="' . $id . '">';
                $h .= '<div class="fm-titolo-quesito">#' . $id . ' · diff ' . $diff . '</div>';
                $h .= '<li class="fm-li-inline">';
                $h .= '<div class="fm-collection">' . $body . '</div>';
                if ($sol !== '') {
                    $h .= '<div class="fm-sol">' . $sol . '</div>';
                }
                $h .= '</li>';
                $h .= '</div>';
            }
            $h .= '</ol>';
            $h .= '</div>'; // .scrollbarhide
            $h .= '</div>'; // .content
            $h .= '</div>'; // .fm-groupcollex
        }
        $h .= '</div></div>';
        return $h;
    }

    /** @param list<int> $ids @return array<int,array> */
    private function loadExercisesBulk(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT id, body_html, solution_html FROM exercises WHERE id IN ($place)",
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    private function wrapInShell(Request $req, string $body, string $title): Response
    {
        // Popola #fm-upbar placeholder server-side via _upbar_loader.
        $base = dirname(__DIR__, 2);
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
        // G-fix-css-rename — emettere SIA legacy (exercise-context, admin-access)
        // SIA prefisso BEM (fm-exercise-context, fm-admin-access) per match con
        // regole CSS rinominate Sprint K-N senza rompere selettori legacy ancora
        // presenti in vendor/legacy code.
        $bodyClass    = 'exercise-context fm-exercise-context fm-studio-light';
        if (Auth::check() && Auth::hasAccess('teacher')) {
            $bodyClass .= ' fm-teacher-access';
        }
        if (Auth::check() && Auth::hasAccess('admin')) {
            $bodyClass .= ' admin-access fm-admin-access';
        }
        $currentRoute = $req->path;
        // Studio DB-backed: tier1 (no TikzJax/jQuery UI/FontAwesome heavy);
        // contenuto è HTML statico dal DB, basta MathJax + Quill.
        $fmExerciseAssetsTier1 = true;
        $pageHead     = '';
        ob_start();
        include $base . '/views/layout/app.php';
        return new Response((string)ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function normalizeTopic(string $raw): string
    {
        $raw = rawurldecode($raw);
        return trim(str_replace('_', ' ', $raw));
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function currentDomainUser(): ?\App\Domain\User
    {
        $a = Auth::user();
        if (!$a) {
            return null;
        }
        return new \App\Domain\User(
            username:     (string)($a['username'] ?? ''),
            passwordHash: '',
            role:         (string)($a['role']     ?? 'guest'),
            active:       true,
            course:       $a['course'] ?? null,
        );
    }
}
