<div class="fm-card fm-text-center">
    <div class="fm-text-icon-3xl fm-mb-2"><?= e($icon ?? '⚠️') ?></div>
    <h1 class="fm-title fm-justify-center fm-error-title" style="--fm-error-color:<?= e($color ?? 'var(--fm-c-danger)') ?>">
        <?= e($code) ?> — <?= e($title) ?>
    </h1>
    <p class="fm-muted"><?= e($message ?? '') ?></p>
    <?php if (!empty($extraHtml)): echo $extraHtml; endif; ?>
    <div class="fm-mt-6">
        <a class="fm-btn fm-btn--primary" href="/?home=1" data-full-reload>← Torna alla home</a>
        <?php if (!empty($showLogout)): ?>
            <a class="fm-btn fm-btn--danger" href="/logout">🚪 Logout</a>
        <?php endif; ?>
    </div>
</div>
