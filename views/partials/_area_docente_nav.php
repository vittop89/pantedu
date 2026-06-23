<?php
/**
 * G20.1 — Navigazione condivisa per le pagine /area-docente/*.
 * Variabile attesa: $currentRoute (string).
 */
$tabs = [
    ['route' => '/area-docente/dashboard', 'icon' => '🏠', 'label' => 'Dashboard'],
    ['route' => '/area-docente/profilo',   'icon' => '👤', 'label' => 'Profilo & istituti'],
    ['route' => '/area-docente/templates', 'icon' => '📝', 'label' => 'I miei modelli'],
    ['route' => '/area-docente/categorie', 'icon' => '🗂️', 'label' => 'Categorie'],
    ['route' => '/area-docente/fonti',     'icon' => '📚', 'label' => 'Fonti / citazioni'],
];
$current = $currentRoute ?? '';
?>
<nav class="fm-area-docente-nav" aria-label="Area docente">
    <div class="fm-area-docente-nav__inner">
        <span class="fm-area-docente-nav__title">📚 Area docente</span>
        <div class="fm-area-docente-nav__tabs" role="tablist">
            <?php foreach ($tabs as $t): ?>
                <a href="<?= htmlspecialchars($t['route'], ENT_QUOTES) ?>"
                   class="fm-area-docente-nav__tab<?= $current === $t['route'] ? ' fm-area-docente-nav__tab--active' : '' ?>"
                   role="tab"
                   aria-selected="<?= $current === $t['route'] ? 'true' : 'false' ?>">
                    <span class="fm-area-docente-nav__icon"><?= $t['icon'] ?></span>
                    <span><?= htmlspecialchars($t['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</nav>
