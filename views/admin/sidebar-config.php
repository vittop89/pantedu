<?php
/**
 * ADR-027 Step 7 — Configurazione sidebar (super_admin).
 *
 * @var string $csrf
 * @var list<array<string,mixed>> $sections
 * @var list<string> $all_types
 * @var string $flash
 * @var string $flash_kind
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '📌 Configurazione sidebar';
$page_subtitle = 'Nome, colore, ordine e visibilità dei pulsanti sezione. Aggiungi sezioni custom. Modifica il template globale.';
$breadcrumb    = [['label' => 'Operations'], ['label' => 'Sidebar']];
include __DIR__ . '/_partials/page_head.php';

$isStudentVisible = static fn(array $s): bool => in_array('student', $s['visible_roles'] ?? [], true);
$h2 = $h;
// Switch toggle riusabile (al posto del checkbox).
$toggle = static function (string $name, bool $on, string $value = '1', string $title = '') use ($h2): string {
    // a11y (WCAG 4.1.2): la label .fm-toggle non ha testo (solo lo slider) →
    // la checkbox resterebbe senza nome accessibile. Garantiamo sempre un
    // aria-label (dal title se fornito, altrimenti dal value).
    $aria = $title !== '' ? $title : $value;
    return '<label class="fm-toggle"' . ($title !== '' ? ' title="' . $h2($title) . '"' : '') . '>'
        . '<input type="checkbox" name="' . $h2($name) . '" value="' . $h2($value) . '"'
        . ' aria-label="' . $h2($aria) . '"' . ($on ? ' checked' : '') . '>'
        . '<span class="fm-toggle__sl"></span></label>';
};
/** @var list<array{key:string,label:string}> $template_groups (origin/category disponibili) */
$template_groups = $template_groups ?? [];
?>

<?php if ($flash !== ''): ?>
    <div class="fm-alert fm-alert--<?= $flash_kind === 'error' ? 'danger' : 'success' ?>"><?= $h($flash) ?></div>
<?php endif; ?>

<section class="fm-card fm-card--wide fm-mb-6">
    <h2 class="fm-card__title">Sezioni esistenti <button type="button" class="fm-infotip" aria-label="Info sezioni"><span class="fm-infotip__body" hidden><p>Ogni pannello può creare i <strong>tipi di documento</strong> spuntati (anche più d'uno → nel pannello compare un selettore tipo). Colore vuoto = colore del tema (usa la tavolozza o un hex). "Visibile agli studenti" disattivato = sezione nascosta agli studenti, contenuti inclusi (docenti/admin la vedono sempre). Le sezioni di sistema mantengono il loro identificatore interno; ciò che conta è il <strong>Nome</strong>.</p><p>ℹ️ <strong>Partizioni</strong> (colonna "Fork template") = quali gruppi di <em>template istituzionali</em> sono forkabili nella sezione (vengono da <a href="/admin/templates">/admin/templates</a>, live). Sono cosa diversa dalle <strong>categorie</strong> con cui ogni <em>docente</em> organizza i propri documenti (Area docente → Categorie). Il toggle 🔒 decide solo se il docente può rinominare le categorie <em>predefinite</em>.</p></span></button></h2>

    <div class="fm-table-wrap">
    <table class="fm-table">
        <thead><tr>
            <th scope="col">#</th><th scope="col">Nome</th><th scope="col">Colore</th>
            <th scope="col">Studenti</th><th scope="col">Attiva</th><th scope="col">Visualizzazione</th><th scope="col">Fork template</th>
            <th scope="col" title="Assegna la sezione a docenti specifici (vuoto = tutti)">Docenti</th>
            <th scope="col" title="Pubblica la sezione in rete, visibile senza login">Pubblica superadmin</th><th scope="col"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sections as $s): $def = !empty($s['is_default']);
            $_types = $s['allowed_content_types'] ?? []; ?>
            <tr>
                <form method="post" action="/admin/sidebar-config/save">
                    <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="section_key" value="<?= $h($s['section_key']) ?>">
                    <td><input type="number" name="position" value="<?= (int)$s['position'] ?>" min="0" max="999" style="width:4em"></td>
                    <td><input type="text" name="label" value="<?= $h($s['label']) ?>" maxlength="128" required style="width:14em">
                        <?= $def ? '' : ' <span title="custom" class="fm-muted">✨</span>' ?></td>
                    <td style="white-space:nowrap">
                        <input type="color" class="fm-color-pick" value="<?= $h($s['color'] ?: '#cccccc') ?>"
                               title="Scegli un colore" aria-label="Tavolozza">
                        <input type="text" name="color" value="<?= $h($s['color'] ?? '') ?>"
                               pattern="#[0-9a-fA-F]{3,8}" placeholder="(tema)" style="width:6.5em"
                               aria-label="Colore esadecimale della sezione (vuoto = tema)"
                               title="Hex es. #86efb5, vuoto = tema">
                    </td>
                    <td style="text-align:center"><?= $toggle('visible_student', $isStudentVisible($s), '1', 'Visibile agli studenti') ?></td>
                    <td style="text-align:center"><?= $toggle('active', !empty($s['active']), '1', 'Sezione attiva') ?></td>
                    <td>
                        <?php $_isCat = ($s['group_mode'] ?? '') === 'category'; $_cats = $s['default_categories'] ?? []; ?>
                        <select name="group_mode" class="fm-sc-groupmode" style="font-size:.85em">
                            <option value="subject"  <?= !$_isCat ? 'selected' : '' ?>>per materia</option>
                            <option value="category" <?= $_isCat  ? 'selected' : '' ?>>per categoria</option>
                        </select>
                        <?php /* Phase 25 — editor categorie predefinite (SSOT documenti).
                                  Visibile solo con "per categoria". Aggiungi/rinomina/rimuovi.
                                  Rimuovere una categoria NON elimina i documenti del docente:
                                  restano come categoria "residua" migrabile in Area docente. */ ?>
                        <div class="fm-sc-cats" style="margin-top:5px<?= $_isCat ? '' : ';display:none' ?>">
                            <?php /* Phase 25 — lock categorie predefinite: se ON il docente NON
                                      può rinominare le predefinite in /area-docente/categorie. */ ?>
                            <label class="fm-d-flex fm-gap-1 fm-items-center" style="font-size:.72em;margin-bottom:2px"
                                   title="Se attivo, il docente non può rinominare le categorie predefinite">
                                <?= $toggle('lock_default_categories', !empty($s['lock_default_categories']), '1', 'Blocca rinomina categorie predefinite') ?> 🔒 blocca predefinite
                            </label>
                            <label class="fm-d-flex fm-gap-1 fm-items-center" style="font-size:.72em;margin-bottom:5px"
                                   title="Se attivo, il docente non può creare nuove categorie custom in questa sezione">
                                <?= $toggle('lock_custom_categories', !empty($s['lock_custom_categories']), '1', 'Blocca creazione nuove categorie custom') ?> 🚫 blocca nuove custom
                            </label>
                            <span class="fm-muted" style="font-size:.68em;display:block">Categorie predefinite:</span>
                            <div class="fm-sc-cats__list" style="display:flex;flex-direction:column;gap:2px">
                            <?php foreach ($_cats as $c): ?>
                                <span class="fm-sc-cat-row" style="display:flex;gap:2px;align-items:center">
                                    <input type="text" name="default_categories[]" value="<?= $h((string)$c) ?>"
                                           class="fm-input" style="width:7em;font-size:.78em" maxlength="32" pattern="[a-zA-Z0-9_ -]{1,32}">
                                    <button type="button" class="fm-btn fm-btn--xs fm-sc-cat-del" title="Rimuovi dai default (i documenti del docente restano come categoria residua)">🗑</button>
                                </span>
                            <?php endforeach; ?>
                            </div>
                            <button type="button" class="fm-btn fm-btn--xs fm-sc-cat-add" style="margin-top:2px">+ categoria</button>
                        </div>
                    </td>
                    <td>
                        <?php /* Phase 25 — toggle master "fork" rimosso: il fork è
                                  IMPLICITO dalla selezione delle partizioni. Selezionare
                                  ≥1 partizione abilita il fork (vedi save: allowFork =
                                  !empty($groups)); deselezionare tutte lo disabilita. */ ?>
                        <span class="fm-muted" style="font-size:.7em;display:block;margin-bottom:3px">Partizioni forkabili:</span>
                        <?php $_sgroups = $s['template_groups'] ?? []; ?>
                        <div class="fm-fork-groups" style="display:flex;flex-direction:column;gap:1px;max-height:7em;overflow:auto">
                        <?php foreach ($template_groups as $g): ?>
                            <label class="fm-d-inline-flex fm-gap-1 fm-items-center" style="font-size:.72em">
                                <?= $toggle('template_groups[]', in_array($g['key'], $_sgroups, true), $g['key'], 'Fork template: ' . $g['label']) ?>
                                <span><?= $h($g['label']) ?></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <?php /* WS4 — colonna 'docenti': 3 modalità (tutti / per istituto-indirizzo-classe / docenti specifici). */ ?>
                        <?php
                            $_ts  = (string)($s['teacher_scope'] ?? 'all');
                            $_tv  = (array)($s['teacher_scope_value'] ?? []);
                            $_tids = array_map('intval', (array)($s['teacher_ids'] ?? []));
                        ?>
                        <select name="teacher_scope" class="fm-ts-mode" style="font-size:.78em">
                            <option value="all"      <?= $_ts === 'all' ? 'selected' : '' ?>>Tutti i docenti</option>
                            <option value="scope"    <?= $_ts === 'scope' ? 'selected' : '' ?>>Per istituto/indirizzo/classe</option>
                            <option value="teachers" <?= $_ts === 'teachers' ? 'selected' : '' ?>>Docenti specifici</option>
                        </select>
                        <div class="fm-ts-scope" style="margin-top:3px;<?= $_ts === 'scope' ? '' : 'display:none' ?>">
                            <select name="scope_institute_id" style="font-size:.72em;width:10em">
                                <option value="">(ogni istituto)</option>
                                <?php foreach (($institutes ?? []) as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>" <?= (int)($_tv['institute_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>><?= $h($i['label']) ?></option>
                                <?php endforeach; ?>
                            </select><br>
                            <input type="text" name="scope_indirizzo" value="<?= $h((string)($_tv['indirizzo'] ?? '')) ?>" placeholder="indirizzo (vuoto=tutti)" style="font-size:.72em;width:10em;margin-top:2px"><br>
                            <input type="text" name="scope_classe" value="<?= $h((string)($_tv['classe'] ?? '')) ?>" placeholder="classe (vuoto=tutte)" style="font-size:.72em;width:10em;margin-top:2px">
                        </div>
                        <div class="fm-ts-teachers" style="margin-top:3px;<?= $_ts === 'teachers' ? '' : 'display:none' ?>">
                            <select name="teacher_ids[]" multiple size="3" style="font-size:.72em;min-width:10em"
                                    title="Ctrl/Cmd+click per selezionare più docenti">
                                <?php foreach (($teachers ?? []) as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $_tids, true) ? 'selected' : '' ?>><?= $h($t['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </td>
                    <td style="text-align:center"><?= $toggle('publish_public', !empty($s['publish_public']), '1', 'Pubblica in rete senza login') ?></td>
                    <td><button type="submit" class="fm-btn fm-btn--xs fm-btn--primary">Salva</button></td>
                </form>
                <?php if (!$def): ?>
                <td>
                    <form method="post" action="/admin/sidebar-config/delete">
                        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="fm-btn fm-btn--xs fm-btn--danger">🗑</button>
                    </form>
                    <script>document.currentScript.parentNode.querySelector('form[action="/admin/sidebar-config/delete"]').addEventListener("submit",function(event){if(!confirm('Eliminare la sezione <?= $h($s['section_key']) ?>?'))event.preventDefault()})</script>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php /* WS4 — toggle sotto-controlli della colonna 'docenti' in base alla modalità. */ ?>
    <script>
    document.addEventListener('change', function (e) {
        const sel = e.target.closest('.fm-ts-mode');
        if (!sel) return;
        const td = sel.closest('td');
        if (!td) return;
        const scope = td.querySelector('.fm-ts-scope');
        const teachers = td.querySelector('.fm-ts-teachers');
        if (scope)    scope.style.display    = sel.value === 'scope'    ? '' : 'none';
        if (teachers) teachers.style.display = sel.value === 'teachers' ? '' : 'none';
    });
    </script>
</section>

<section class="fm-card fm-card--wide">
    <h2 class="fm-card__title">✨ Nuova sezione custom</h2>
    <form method="post" action="/admin/sidebar-config/save" class="fm-form-grid">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_new" value="1">
        <label class="fm-label">Key (slug univoco)
            <input type="text" name="section_key" pattern="[a-z0-9_-]{2,32}" placeholder="es. progetti" required>
        </label>
        <label class="fm-label">Nome
            <input type="text" name="label" maxlength="128" placeholder="es. Progetti di classe" required>
        </label>
        <label class="fm-label">Colore (tavolozza o hex, opzionale)
            <span style="display:flex;gap:6px;align-items:center">
                <input type="color" class="fm-color-pick" value="#88aaff" title="Tavolozza" aria-label="Tavolozza">
                <input type="text" name="color" pattern="#[0-9a-fA-F]{3,8}" placeholder="(tema)" style="flex:1 1 auto;min-width:0" aria-label="Colore esadecimale della sezione (vuoto = tema)">
            </span>
        </label>
        <label class="fm-label">Posizione
            <input type="number" name="position" value="99" min="0" max="999">
        </label>
        <label class="fm-label">Visualizzazione item
            <select name="group_mode">
                <option value="category">per categoria (es. Verifiche, BES)</option>
                <option value="subject">per materia (es. Mappe, Esercizi)</option>
            </select>
        </label>
        <label class="fm-label">Partizioni template forkabili
            <span class="fm-muted" style="display:block;font-size:.8em;margin-bottom:3px">
                Seleziona uno o più gruppi: il fork si abilita automaticamente quando
                almeno una partizione è selezionata (nessun toggle "fork" separato).
            </span>
            <div class="fm-fork-groups" style="display:flex;flex-direction:column;gap:2px">
            <?php foreach ($template_groups as $g): ?>
                <label class="fm-d-inline-flex fm-gap-1 fm-items-center" style="font-size:.85em">
                    <?= $toggle('template_groups[]', false, $g['key'], 'Fork template: ' . $g['label']) ?> <span><?= $h($g['label']) ?></span>
                </label>
            <?php endforeach; ?>
            </div>
        </label>
        <label class="fm-label fm-d-flex fm-gap-2 fm-items-center">
            <?= $toggle('visible_student', true, '1', 'Visibile agli studenti') ?> Visibile agli studenti
        </label>
        <p class="fm-muted fm-text-em-md" style="grid-column:1/-1;margin:0">
            Ogni pannello crea automaticamente tutti i formati di documento (Link, File, drawio,
            Stile esercizi, Personalizzabile) — non c'è da scegliere tipi.
        </p>
        <div>
            <button type="submit" class="fm-btn fm-btn--primary">Crea sezione</button>
        </div>
    </form>
</section>

<style>
/* ADR-027 — switch toggle (al posto dei checkbox). */
.fm-toggle { position: relative; display: inline-block; width: 36px; height: 19px; vertical-align: middle; flex: 0 0 auto; }
.fm-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.fm-toggle .fm-toggle__sl { position: absolute; inset: 0; background: #555c6b; border-radius: 19px; transition: .18s; cursor: pointer; }
.fm-toggle .fm-toggle__sl::before { content: ""; position: absolute; width: 15px; height: 15px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: .18s; }
.fm-toggle input:checked + .fm-toggle__sl { background: #2563eb; }
.fm-toggle input:checked + .fm-toggle__sl::before { transform: translateX(17px); }
.fm-toggle input:focus-visible + .fm-toggle__sl { outline: 2px solid #93c5fd; outline-offset: 1px; }
/* ADR-027 — tavolozza colore a dimensione fissa (evita l'allargamento full-width
   ereditato dai form admin). */
.fm-color-pick {
    flex: 0 0 auto;
    width: 42px;
    height: 32px;
    min-width: 42px;
    padding: 2px;
    border: 1px solid var(--fm-c-border, #c7cdd6);
    border-radius: 4px;
    background: transparent;
    cursor: pointer;
    vertical-align: middle;
}
</style>
<script>
// ADR-027 — sync tavolozza nativa ↔ campo hex. Il color-picker scrive l'hex
// nel campo name="color" adiacente; svuotando l'hex la sezione torna al tema.
(function () {
    document.addEventListener("input", function (e) {
        var p = e.target;
        if (!p.classList || !p.classList.contains("fm-color-pick")) return;
        // trova il vicino input[name=color] nello stesso contenitore
        var box = p.closest("td, span, label") || p.parentNode;
        var hex = box && box.querySelector('input[name="color"]');
        if (hex) hex.value = p.value;
    });
})();
</script>
<script>
// Phase 24.73 — Salva riga in AJAX: niente più full-page reload. Il submit
// del form-riga viene intercettato, inviato via fetch, e il pulsante mostra
// l'esito inline. (La "Nuova sezione" e l'eliminazione restano full-submit
// perché ristrutturano la tabella.)
(function () {
    // NB: i <form> dentro <tr> sono HTML invalido → il parser li "foster-parenta"
    // FUORI dalla tabella: il <form> resta VUOTO mentre input/button restano
    // nella riga, associati al form via form-OWNER (per questo il submit nativo
    // funziona). Quindi NON usare form.querySelector/descendant: si usano
    // form.elements, e.submitter e new FormData(form) che lavorano via owner.
    const rowForms = document.querySelectorAll('form[action="/admin/sidebar-config/save"]');
    rowForms.forEach((form) => {
        if (form.elements["_new"]) return; // crea-sezione: full submit
        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            const btn = e.submitter
                || (form.elements ? Array.from(form.elements).find(el => el.type === "submit") : null);
            const old = btn ? btn.textContent : "";
            if (btn) { btn.disabled = true; btn.textContent = "…"; }
            try {
                const res = await fetch(form.action, {
                    method: "POST",
                    body: new FormData(form),
                    credentials: "same-origin",
                    headers: { "X-Requested-With": "fetch" },
                });
                const ok = res.ok || res.redirected;
                if (btn) btn.textContent = ok ? "✓ Salvato" : "✗";
            } catch (_) {
                if (btn) btn.textContent = "✗ Errore";
            }
            if (btn) setTimeout(() => { btn.disabled = false; btn.textContent = old; }, 1300);
        });
    });
})();
</script>

<script>
/* Phase 25 — editor categorie predefinite per sezione (dinamico su group_mode). */
(function () {
    function catsBox(sel) { return sel.closest("td")?.querySelector(".fm-sc-cats"); }
    function rowHtml() {
        return '<span class="fm-sc-cat-row" style="display:flex;gap:2px;align-items:center">'
            + '<input type="text" name="default_categories[]" value="" class="fm-input" style="width:7em;font-size:.78em" maxlength="32" pattern="[a-zA-Z0-9_ -]{1,32}">'
            + '<button type="button" class="fm-btn fm-btn--xs fm-sc-cat-del" title="Rimuovi dai default">🗑</button></span>';
    }
    document.addEventListener("change", function (e) {
        const sel = e.target.closest?.(".fm-sc-groupmode");
        if (!sel) return;
        const box = catsBox(sel);
        if (!box) return;
        if (sel.value === "category") { box.style.display = ""; return; }
        // → per materia: se ci sono categorie con valore, avvisa della perdita.
        const vals = Array.from(box.querySelectorAll('input[name="default_categories[]"]'))
            .map(i => i.value.trim()).filter(Boolean);
        if (vals.length) {
            const ok = confirm("Passando a «per materia» perderai le categorie predefinite di questa sezione:\n\n  "
                + vals.join(", ")
                + "\n\nI documenti dei docenti NON vengono eliminati (restano come categoria 'residua' migrabile in Area docente).\n\nContinuare?");
            if (!ok) { sel.value = "category"; return; }
        }
        box.style.display = "none";
    });
    document.addEventListener("click", function (e) {
        const add = e.target.closest?.(".fm-sc-cat-add");
        if (add) {
            const list = add.closest(".fm-sc-cats")?.querySelector(".fm-sc-cats__list");
            if (list) { list.insertAdjacentHTML("beforeend", rowHtml()); list.lastElementChild.querySelector("input")?.focus(); }
            return;
        }
        const del = e.target.closest?.(".fm-sc-cat-del");
        if (del) { del.closest(".fm-sc-cat-row")?.remove(); }
    });
})();
</script>
