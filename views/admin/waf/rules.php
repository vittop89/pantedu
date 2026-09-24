<?php
/** @var array<string,string> $config */
/** @var list<array<string,mixed>> $rules */
/** @var string $csrf */
$current_tab = 'rules';
$page_title  = 'Custom rules';
include __DIR__ . '/_layout_head.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<details class="fm-mb-6">
    <summary class="fm-cursor-pointer fm-fw-600">➕ Nuova regola</summary>
    <form data-fm-action="createRule" class="fm-mt-4">
    <div class="fm-waf-kv">
        <label>Nome</label>
        <input type="text" name="name" required placeholder="Block aggressive crawlers">

        <label>Descrizione</label>
        <input type="text" name="description" placeholder="Optional">

        <label>Action</label>
        <select name="action">
            <option value="block">block (403)</option>
            <option value="challenge">challenge (interstitial)</option>
            <option value="allow">allow (bypass)</option>
            <option value="log_only">log_only (no action)</option>
        </select>

        <label>Priority</label>
        <input type="number" name="priority" value="100" min="1" max="999">

        <label>Enabled</label>
        <select name="enabled"><option value="1" selected>ON</option><option value="0">OFF</option></select>

        <label>Conditions JSON</label>
        <textarea name="conditions" rows="6" class="fm-font-mono fm-text-14" placeholder='{
  "logic": "AND",
  "conditions": [
    {"field": "user_agent", "operator": "contains", "value": "AhrefsBot"}
  ]
}'></textarea>
    </div>
    <div class="fm-mt-4">
        <button class="fm-btn fm-btn--primary" type="submit">Crea regola</button>
        <span id="fm-rule-status" class="fm-ml-4 fm-text-15"></span>
    </div>
    </form>
    <div class="fm-mt-4 fm-text-14 fm-text-muted">
    <strong>Campi disponibili:</strong> <code>ip</code> <code>country</code> <code>asn</code>
    <code>user_agent</code> <code>url</code> <code>referer</code> <code>cookie</code> <code>method</code><br>
    <strong>Operatori:</strong> <code>equals</code> <code>contains</code> <code>starts_with</code>
    <code>ends_with</code> <code>matches_regex</code> <code>is_in_list</code> <code>ip_in_cidr</code>
    </div>
</details>

<h2 class="fm-mt-4 fm-mb-2 fm-text-17">📋 Regole esistenti (<?= count($rules) ?>)</h2>
<table class="fm-waf-table">
<thead><tr>
    <th scope="col">Prio</th><th scope="col">Nome</th><th scope="col">Action</th><th scope="col">Conditions</th><th scope="col">Hits</th><th scope="col">Status</th><th scope="col">Updated</th><th scope="col"></th>
</tr></thead>
<tbody>
<?php foreach ($rules as $r): ?>
    <tr>
        <td><?= (int)$r['priority'] ?></td>
        <td><strong><?= $h($r['name']) ?></strong><br><small class="fm-text-muted"><?= $h($r['description']) ?></small></td>
        <td><span class="fm-waf-badge <?= $h($r['action']) ?>"><?= $h($r['action']) ?></span></td>
        <td><code class="fm-text-xs fm-max-w-300 fm-d-inline-block fm-truncate"
                  title="<?= $h($r['conditions']) ?>"><?= $h($r['conditions']) ?></code></td>
        <td><?= (int)$r['match_count'] ?></td>
        <td><?= ((int)$r['enabled'] === 1) ? '✅ ON' : '⛔ OFF' ?></td>
        <td><small><?= $h($r['updated_at']) ?></small></td>
        <td>
            <button data-fm-action="toggleRule" data-fm-id="<?= (int)$r['id'] ?>" class="fm-btn" type="button">Toggle</button>
            <button data-fm-action="deleteRule" data-fm-id="<?= (int)$r['id'] ?>" class="fm-btn fm-btn--danger" type="button">🗑️</button>
        </td>
    </tr>
<?php endforeach; ?>
<?php if (empty($rules)): ?>
    <tr><td colspan="8" class="fm-text-center fm-text-muted fm-p-8">Nessuna regola configurata.</td></tr>
<?php endif; ?>
</tbody>
</table>

</div><!-- /.fm-card -->

<div id="fm-page-config" hidden data-csrf="<?= $h($csrf) ?>"></div>
<?= \App\Support\ViteManifest::script('js/entries/admin-waf-rules.js') ?>

</div><!-- /.fm-card -->
