<?php
/**
 * Sidebar partial — sel-wrapper (indirizzo/classe/materia) + navigation
 * scrollbar. Included by views/layout/app.php. Requires:
 *   $curriculum   (array from CurriculumService::all())
 *   $isAdmin      (bool)
 *   $isTeacher    (bool)
 *   $authedUser   (array|null)
 *
 * Does NOT render modals or the frame — those live in their own
 * partials so pages can omit them when served with ?embed=1.
 *
 * Naming (Unit 2c — full de-legacyzzazione):
 *   - Classi con prefisso `fm-sb-*` (sidebar scope): sec, panel, close,
 *     slider, lab, sel, tip, scroll, dark.
 *   - Pannelli sidepage con ID `fm-sp-<key>` e attributo
 *     `data-sidepage="<key>"` (key: mappe|lab|eser|verif|bes|risdoc).
 *   - Ogni button sezione (`.fm-sb-sec`) porta lo stesso
 *     `data-sidepage="<key>"`: è la single source of truth per il
 *     lookup JS (`Config.SIDEBAR_CONFIG[sidebarId].sidepage === key`).
 *   - Nessuna classe legacy (`.btn`, `.sidepage`, `.materia`, `.slider`,
 *     `.tooltip`, `.scrollbar`, `.closeTextMenu`, `darkmode-btn`) e
 *     nessun ID legacy (`btn0..btn5`, `Mappe/DidLab/Eser/Verif/
 *     StrumBesAltro/RisDoc`, `style-1`, `darkmode-btn` ×3).
 */
use App\Core\Auth;
use App\Core\Csrf;

$group_by = static function (array $items, string $key): array {
    $out = [];
    foreach ($items as $row) {
        if (!($row['active'] ?? false)) continue;
        $bucket = trim((string)($row[$key] ?? ''));
        $out[$bucket] ??= [];
        $out[$bucket][] = $row;
    }
    return $out;
};
$iisGroups = $group_by($curriculum['indirizzi'] ?? [], 'group');
$clsGroups = $group_by($curriculum['classi']    ?? [], 'group');
$materie   = array_values(array_filter(
    $curriculum['materie'] ?? [],
    fn($r) => (bool)($r['active'] ?? false)
));

// G20.0 — istituti del docente (solo per teacher/admin)
$teacherInstitutes = [];
if ($isTeacher || $isAdmin) {
    try {
        $authedUserId = (int)($authedUser['id'] ?? 0);
        if ($authedUserId > 0) {
            $instRepo = new \App\Repositories\InstituteRepository();
            $teacherInstitutes = $instRepo->listForTeacher($authedUserId);
        }
    } catch (\Throwable $e) { /* tabelle non ancora migrate / no link */ }
}
// Phase 25.Q — istituto attivo server-side (current_institute_id)
$currentInstituteId = null;
if ($isTeacher || $isAdmin) {
    try { $currentInstituteId = Auth::currentInstitute(); } catch (\Throwable $_) {}
}
?>
<label class="fm-switch">
    <input type="checkbox" id="IObar" checked>
    <span class="fm-sb-close">CHIUDI</span>
    <span class="fm-sb-slider">✖</span>
</label>

<?php
// Phase 25.R.2.1 — guest sidebar: nascondi sel-wrapper (selettori istituto/
// indirizzo/classe/materia), fm-resource-auth e fm-sb-scroll. Resta
// visibile solo sel-wrapper-actions (Login / Darkmode / Registrati).
$_fmGuest = !Auth::check();
// WS4 — sezioni pubblicate (publish_public) visibili SENZA login: il guest vede
// quei pulsanti + i selettori indirizzo/classe/materia (versione pubblica globale,
// senza istituto). Al login la sidebar normale (con istituto) le rimpiazza.
$_publicSections = [];
if ($_fmGuest) {
    try { $_publicSections = (new \App\Repositories\SidebarSectionRepository())->publicSections(); } catch (\Throwable $_) {}
}
$_hasPublic = $_fmGuest && $_publicSections !== [];
?>
<nav class="sidebar fm-sidebar"
     aria-label="Navigazione principale e selezione classe/materia"
     <?= $_fmGuest ? 'data-fm-guest="1"' : '' ?>>
    <div class="sel-wrapper" data-curriculum-endpoint="/curriculum">
        <?php if (!$_fmGuest || $_hasPublic): ?>
        <?php if (($isTeacher || $isAdmin) && \count($teacherInstitutes) > 0): ?>
            <label class="fm-sb-lab" for="sel-istituto">Istituto:</label>
            <select class="fm-sb-sel" id="sel-istituto"
                    data-current-iid="<?= htmlspecialchars((string)($currentInstituteId ?? ''), ENT_QUOTES) ?>"
                    data-csrf="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES) ?>">
                <?php foreach ($teacherInstitutes as $inst): ?>
                    <?php // Mostra il nome ufficiale MIUR (l'header_label resta per le
                          // intestazioni dei documenti, non per la selezione istituto).
                          $label = trim((string)($inst['name'] ?? '')) !== ''
                                  ? (string)$inst['name']
                                  : (string)$inst['header_label']; ?>
                    <?php
                          $iid = (int)$inst['id'];
                          $isCurrent = ($currentInstituteId !== null && $iid === $currentInstituteId); ?>
                    <option value="<?= htmlspecialchars($inst['code'], ENT_QUOTES) ?>"
                            data-iid="<?= $iid ?>"
                            <?= $isCurrent ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?><?= $isCurrent ? ' ●' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select><br>
        <?php elseif ($isTeacher || $isAdmin): ?>
            <div class="fm-sb-istituto-empty fm-muted fm-text-11 fm-py-1" >
                <a href="/area-docente/profilo">📌 Collega un istituto</a>
            </div>
        <?php endif; ?>
        <label class="fm-sb-lab" for="sel-iis">Indirizzo: </label>
        <select class="fm-sb-sel" id="sel-iis">
            <option disabled>Scegli l'indirizzo:</option>
            <?php foreach ($iisGroups as $groupName => $rows): ?>
                <optgroup label="<?= htmlspecialchars($groupName ?: 'Altri', ENT_QUOTES) ?>">
                    <?php foreach ($rows as $row): ?>
                        <option value="<?= htmlspecialchars($row['code'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($row['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select><br>

        <label class="fm-sb-lab" for="sel-cls">Classe:</label>
        <select class="fm-sb-sel" id="sel-cls">
            <option disabled>Scegli la classe:</option>
            <?php foreach ($clsGroups as $groupName => $rows): ?>
                <optgroup label="<?= htmlspecialchars($groupName ?: 'Altri', ENT_QUOTES) ?>">
                    <?php foreach ($rows as $row): ?>
                        <option value="<?= htmlspecialchars($row['code'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($row['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select><br>

        <label class="fm-sb-lab" for="sel-mater">Materia:</label>
        <select class="fm-sb-sel" id="sel-mater">
            <option disabled>Materia:</option>
            <?php foreach ($materie as $row): ?>
                <option value="<?= htmlspecialchars($row['code'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars($row['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; /* !$_fmGuest — selettori curriculum */ ?>

        <div class="sel-wrapper-actions">
            <?php if ($isAdmin || $isTeacher): ?>
                <?php /* G22.S15.bis Fase 5+ — Link "📚 Curriculum" rimosso:
                    la gestione catalog + pivot e' integrata in
                    /area-docente/profilo (sezione "Curriculum dell'istituto").
                    Per arrivarci si clicca "Area docente" nella header user. */ ?>
                <?php /* Phase 25 — popup scorciatoie LaTeX (a sinistra del darkmode).
                         NB: NON usare la classe .fm-sb-dark qui — è il selettore JS
                         del toggle dark/light (bootstrap-compat findDarkModeBtn);
                         riusarla rubava il binding e rompeva il pulsante scuro. */ ?>
                <button id="fm-shortcuts-btn" class="fm-shortcuts-mini" title="Scorciatoie LaTeX da tastiera" type="button" aria-label="Mostra scorciatoie LaTeX da tastiera">
                    <span class="fm-shortcuts-icon" aria-hidden="true">⌨️</span>
                </button>
                <button class="fm-sb-dark fm-darkmode-mini" title="Attiva/Disattiva modalità scura" type="button" aria-label="Attiva o disattiva la modalità scura" aria-pressed="false">
                    <span class="fm-darkmode-icon" aria-hidden="true">🌙</span>
                </button>
                <a class="sel-action-link sel-action-link--danger" href="/logout" title="Disconnetti" data-full-reload>Logout</a>
            <?php elseif (!Auth::check()): ?>
                <a class="sel-action-link" href="/login">Login</a>
                <button class="fm-sb-dark fm-darkmode-mini" title="Attiva/Disattiva modalità scura" type="button" aria-label="Attiva o disattiva la modalità scura" aria-pressed="false">
                    <span class="fm-darkmode-icon" aria-hidden="true">🌙</span>
                </button>
                <a class="sel-action-link" href="/register">Registrati</a>
            <?php else: ?>
                <?php /* Phase 25.Q.10 — student loggato: stesso Logout grosso
                         di teacher/admin (uniformità UX). */ ?>
                <button class="fm-sb-dark fm-darkmode-mini" title="Attiva/Disattiva modalità scura" type="button" aria-label="Attiva o disattiva la modalità scura" aria-pressed="false">
                    <span class="fm-darkmode-icon" aria-hidden="true">🌙</span>
                </button>
                <a class="sel-action-link sel-action-link--danger" href="/logout" title="Disconnetti" data-full-reload>Logout</a>
            <?php endif; ?>
        </div>
    </div>

    <?php /* Phase 13: container .selwrapbtn-es legacy "ADMIN RESTRICTED
              ZONE" rimosso. Il pulsante ATTIVA è ora iniettato dentro
              ogni .sidepage (vedi sotto) — visibile solo per admin/teacher
              e azione scoped al sidepage parent (modifica solo gli
              elementi della sezione corrente). Per studenti, lo stesso
              spazio ospiterà il prompt username/password per accedere
              alle risorse del docente (gestito client-side). */ ?>
    <?php if (!$_fmGuest): ?>
    <div id="fm-resource-auth" class="fm-resource-auth"></div>
    <?php endif; ?>
    <div id="fm-sb-attiva-tip" class="fm-sb-tip" hidden>
        <p class="fm-text-xs fm-text-inverse fm-m-0">in caso di modifica dell'header, per aggiornarlo su tutti i file salvati premere su ATTIVA e poi su SALVA.</p>
    </div>

    <?php /* Phase 20 — layout 2-row: user+badges top centrato, links row bottom
              centrata. Logout/Login/Registrati spostati in sel-wrapper-actions
              accanto al darkmode-btn (simmetria: Logout a destra, Login/Registrati
              ai lati). */ ?>
    <?php if ($isTeacher): ?>
        <div class="sel-session-banner fm-session-banner fm-session-banner--teacher">
            <div class="fm-session-user">👤 <strong><?= htmlspecialchars($authedUser['username'] ?? '', ENT_QUOTES) ?></strong><?php
                if (!empty($isSuperAdmin)): ?> <span class="fm-super-badge" title="Super-Admin tecnico">🛡️</span><?php endif; ?></div>
            <div class="fm-session-links">
                <a href="/area-docente/dashboard">Area docente</a>
                <?php if (!empty($isSuperAdmin)): ?>
                    <a href="/admin/dashboard">Admin</a>
                    <?php /* G19.45 — link `/admin/analytics` rimosso da fm-session-links:
                         ridondante con `.fm-admin-tool` di /admin/dashboard che punta
                         allo stesso URL. Accesso unico via dashboard. */ ?>
                <?php endif; ?>
                <?php /* Phase 25.E6 — link self-service GDPR */ ?>
                <a href="/privacy/your-data" title="Esercita i tuoi diritti GDPR (Art. 15-22)">🔒 I tuoi dati</a>
            </div>
        </div>
    <?php elseif ($isAdmin): ?>
        <div class="sel-session-banner fm-session-banner fm-session-banner--admin">
            <div class="fm-session-user">👤 <strong><?= htmlspecialchars($authedUser['username'] ?? '', ENT_QUOTES) ?></strong></div>
            <div class="fm-session-links">
                <a href="/admin/tools">🔧 Tools</a>
                <?php /* G19.45 — `/admin/analytics` accessibile solo via dashboard tile */ ?>
                <a href="/privacy/your-data" title="Esercita i tuoi diritti GDPR">🔒 I tuoi dati</a>
            </div>
        </div>
    <?php elseif (Auth::check() && Auth::role() === 'student'): ?>
        <?php /* Phase 25.Q.9-10 — banner sessione studente: marker per
                 nascondere widget "Accesso risorse docente" (vedi
                 student-resource-auth.js) + mostra username + link
                 "I tuoi dati" GDPR. Logout è in sel-wrapper-actions
                 (uniformità con teacher/admin). */ ?>
        <div class="sel-session-banner fm-session-banner fm-session-banner--student">
            <div class="fm-session-user">👤 <strong><?= htmlspecialchars($authedUser['username'] ?? '', ENT_QUOTES) ?></strong></div>
            <div class="fm-session-links">
                <a href="/privacy/your-data" title="Esercita i tuoi diritti GDPR">🔒 I tuoi dati</a>
            </div>
        </div>
    <?php endif; ?>

    <?php /* Phase 25.E6 — i 3 link trust (Privacy/Sicurezza/DPO) sono nel
              bottom-bar globale (views/partials/modals.php) per Art. 12 §2
              GDPR (facilitazione esercizio diritti). */ ?>

    <?php
        // Phase 13: il pulsante ATTIVA viene iniettato dentro OGNI .sidepage
        // SOLO per admin/teacher. Lo scope dell'edit mode è il sidepage
        // parent (modifica solo gli elementi di quella sezione).
        $editBtnHtml = ($isAdmin || $isTeacher)
            ? '<button class="fm-btn fm-btn--xs js-edit-section" type="button" title="Modifica sezione" aria-label="Modifica sezione" data-action="toggle-edit-section">'
                . '<strong>✎</strong></button>'
            : '';
    ?>
    <?php if (!$_fmGuest || $_hasPublic): ?>
    <div id="fm-sb-scroll" class="fm-sb-scroll">
        <?php
        // ADR-027 — render data-driven dei button sezione da sidebar_sections
        // (template istituto + override docente). Visibilità per-ruolo via
        // visible_roles (risdoc escluso agli studenti). Fallback al markup
        // hardcoded se DB/migration non disponibili (parità garantita: i 6 seed
        // replicano 1:1 lo stato precedente; color NULL ⇒ colore dai token CSS).
        $_sbRole = $isAdmin ? 'admin' : ($isTeacher ? 'teacher' : 'student');
        $_sbInst = (int)($_currInstituteId ?? 0);
        $_sbTid  = ($isTeacher || $isAdmin) ? (int)($_currUserId ?? 0) : null;
        $_sbSections = [];
        if ($_fmGuest) {
            // Guest: SOLO le sezioni pubblicate (publish_public), già caricate sopra.
            $_sbSections = $_publicSections;
        } else {
            try {
                $_sbSections = (new \App\Repositories\SidebarSectionRepository())
                    ->forRender($_sbInst, $_sbTid ?: null, $_sbRole);
            } catch (\Throwable $_) { $_sbSections = []; }
        }
        ?>
        <?php if ($_sbSections): ?>
            <?php $_sbIdx = 0; foreach ($_sbSections as $_s):
                $_k = htmlspecialchars((string)$_s['section_key'], ENT_QUOTES);
                // Phase 24.73 — --sb-i = indice di render (offset sticky + margine
                // gestiti in CSS, ordine-agnostici): niente più top hardcoded.
                $_styleInner = '--sb-i:' . $_sbIdx;
                if (!empty($_s['color'])) {
                    // Colore data-driven: background inline (colora QUALSIASI
                    // sezione, incluse le custom senza regola CSS dedicata) +
                    // override del token (preserva border/hover dei 6 default).
                    $_c = htmlspecialchars((string)$_s['color'], ENT_QUOTES);
                    $_styleInner .= ';background:' . $_c . ';--fm-c-sec-' . $_k . ':' . $_c;
                }
                $_style = ' style="' . $_styleInner . '"';
                $_sbIdx++;
            ?>
        <?php $_gm = htmlspecialchars((string)($_s['group_mode'] ?? ''), ENT_QUOTES); ?>
        <button class="fm-sb-sec" data-sidepage="<?= $_k ?>"<?= $_style ?> title="<?= $_gm === 'category' ? 'Sezione sempre visibile, non dipende dalla classe selezionata.' : 'Sezione legata a indirizzo, classe e materia selezionati in alto.' ?>"><strong><?= htmlspecialchars((string)$_s['label'], ENT_QUOTES) ?></strong><?php if ($isTeacher || $isAdmin): ?><span class="fm-sb-info" aria-hidden="true" data-group-mode="<?= $_gm ?>">i</span><?php endif; ?></button>
        <div id="fm-sp-<?= $_k ?>" class="fm-sb-panel" data-sidepage="<?= $_k ?>" data-group-mode="<?= $_gm ?>"><?= $editBtnHtml ?></div>
            <?php endforeach; ?>
        <?php else: ?>
        <?php /* Fallback hardcoded: DB non disponibile o migration 070 non applicata. */ ?>
        <?php $_info = ($isTeacher || $isAdmin); ?>
        <button class="fm-sb-sec" data-sidepage="mappe" style="--sb-i:0" title="Sezione legata a indirizzo, classe e materia selezionati in alto."><strong>Mappe concettuali</strong><?php if ($_info): ?><span class="fm-sb-info" aria-hidden="true" data-group-mode="subject">i</span><?php endif; ?></button>
        <div id="fm-sp-mappe" class="fm-sb-panel" data-sidepage="mappe" data-group-mode="subject"><?= $editBtnHtml ?></div>

        <button class="fm-sb-sec" data-sidepage="lab" style="--sb-i:1" title="Sezione legata a indirizzo, classe e materia selezionati in alto."><strong>Laboratorio</strong><?php if ($_info): ?><span class="fm-sb-info" aria-hidden="true" data-group-mode="subject">i</span><?php endif; ?></button>
        <div id="fm-sp-lab" class="fm-sb-panel" data-sidepage="lab" data-group-mode="subject"><?= $editBtnHtml ?></div>

        <button class="fm-sb-sec" data-sidepage="eser" style="--sb-i:2" title="Sezione legata a indirizzo, classe e materia selezionati in alto."><strong>Esercizi</strong><?php if ($_info): ?><span class="fm-sb-info" aria-hidden="true" data-group-mode="subject">i</span><?php endif; ?></button>
        <div id="fm-sp-eser" class="fm-sb-panel" data-sidepage="eser" data-group-mode="subject"><?= $editBtnHtml ?></div>

        <button class="fm-sb-sec" data-sidepage="verif" style="--sb-i:3" title="Sezione legata a indirizzo, classe e materia selezionati in alto."><strong>Verifiche</strong><?php if ($_info): ?><span class="fm-sb-info" aria-hidden="true" data-group-mode="subject">i</span><?php endif; ?></button>
        <div id="fm-sp-verif" class="fm-sb-panel" data-sidepage="verif" data-group-mode="subject"><?= $editBtnHtml ?></div>

        <button class="fm-sb-sec" data-sidepage="bes" style="--sb-i:4" title="Sezione sempre visibile, non dipende dalla classe selezionata."><strong>BES/DSA - RECUPERI</strong><?php if ($_info): ?><span class="fm-sb-info" aria-hidden="true" data-group-mode="category">i</span><?php endif; ?></button>
        <div id="fm-sp-bes" class="fm-sb-panel" data-sidepage="bes" data-group-mode="category"><?= $editBtnHtml ?></div>

        <?php /* Phase 25.Q — RisDoc riservato a teacher/admin; nascosto a student/guest. */ ?>
        <?php if ($isTeacher || $isAdmin): ?>
        <button class="fm-sb-sec" data-sidepage="risdoc" style="--sb-i:5" title="Sezione sempre visibile, non dipende dalla classe selezionata."><strong>Risorse docente (riservato)</strong><?php if ($_info): ?><span class="fm-sb-info" aria-hidden="true" data-group-mode="category">i</span><?php endif; ?></button>
        <div id="fm-sp-risdoc" class="fm-sb-panel" data-sidepage="risdoc" data-group-mode="category"><?= $editBtnHtml ?></div>
        <?php endif; ?>
        <?php endif; ?>

        <?php /* Phase 13 (revised): le materie del docente vengono
                 aggiunte dinamicamente al #sel-mater (vedi
                 js/modules/features/dynamic-subjects.js). I 6 button
                 sezione caricano content DB-backed via db-sidepage.js
                 (mapping data-sidepage → content_type). */ ?>
    </div>
    <?php endif; /* !$_fmGuest — fm-sb-scroll */ ?>

    <?php /* WS4 — i selettori della sidebar pubblica (guest) sono popolati dal
             modulo js/modules/features/public-sidebar-selectors.js (CSP-strict
             friendly, niente <script> inline). */ ?>
</nav>
