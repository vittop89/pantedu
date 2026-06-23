# `css/modules/` — Component-scoped CSS modules

Modular CSS components introduced in **Phase C.4 / D**. Replaces the
monolithic `layout.css` over time via strangler-fig pattern.

## Architettura

ITCSS-inspired layering:

```
1. Settings    -> css/tokens.css         (variables, design system)
2. Generic     -> css/a11y.css           (resets, accessibility base)
3. Elements    -> css/modules/_*.css     (component primitives)
4. Objects     -> css/layout.css         (page layout, legacy monolith)
5. Components  -> css/admin.css, layout_editor.css, ...  (route-specific)
```

## Naming

- **File**: `_componentName.css` (underscore prefix = partial,
  importato via @import nel main bundle).
- **Classes**: BEM-like — `.fm-component`, `.fm-component__elem`,
  `.fm-component--modifier`.
- **CSS Custom Properties**: solo da `tokens.css`. Niente magic numbers,
  niente hex hardcoded.

## Inclusione

I moduli sono importati da `css/components.css` (entry point per le
nuove componenti) che è caricato da `head.php` + `shell.php` dopo
`tokens.css` + `a11y.css`.

```html
<link rel="stylesheet" href="/css/tokens.css">
<link rel="stylesheet" href="/css/a11y.css">
<link rel="stylesheet" href="/css/components.css"><!-- imports modules -->
<link rel="stylesheet" href="/css/layout.css"><!-- legacy monolith -->
```

## Status migrazione

| Modulo | Status | Replaces in monolith |
|---|---|---|
| `_buttons.css` | ✅ Active | `.fm-btn` + variants (layout.css legacy duplicate) |
| `_forms.css` | ✅ Active | `.fm-field`, `.fm-input-*`, `.fm-checkbox`, `.fm-radio`, `.fm-fieldset` |
| `_modals.css` | ✅ Active | `.fm-modal`, `.fm-modal-body`, `.fm-modal-close` |
| `_alerts.css` | ✅ Active | `.fm-alert--*` (info/success/warning/danger) |
| `_cards.css` | ✅ Active | `.fm-card--shell` + `.fm-card--section` + `.fm-card--wide` (variants espliciti, legacy `.fm-card` resta in shell.css + layout.css per back-compat) |
| `_breadcrumb.css` | ✅ Active | `.fm-breadcrumb`, `.fm-bc-sep` (shell.css legacy) |
| `_badges.css` | ✅ Active | `.fm-status` + data-role variants + semantic variants |
| `_topbar.css` | ✅ Active | `.fm-topbar`, `.fm-tb-actions` + mobile responsive |
| `_admin-toolnav.css` | ✅ Active | `.fm-admin-toolnav` + `__group` + `__btn` (page_head.php admin pattern) |
| `_sidebar.css` | ✅ Active (struttural core) | `.fm-sidebar` + `__selector` + `__scroll` + `__section` + `__panel` — NUOVO design system parallel; legacy `.sidebar` + `.fm-sb-*` resta in layout.css per migration progressiva |
| `_editor-toolbar.css` | ✅ Active (chrome only) | `.fm-editor-toolbar` toolbar pattern token-based; legacy `.fm-editor-toolbar` con !important resta in layout.css finche' i builder JS smettono di set inline styles |
| `_login-federated.css` | ✅ Active | `.fm-btn--federated`, `.fm-btn--spid`, `.fm-btn--cie`, `.fm-login-divider`, `.fm-login-federated` — bottoni SPID/CIE su /login (Phase D.2) |
| `_editor.css` (futuro full editor) | 🚧 TODO | Quill/Tiptap/CodeMirror integration styles (da consolidare con layout_editor.css) |
| `_skip-link.css` | ✅ Active (in `a11y.css`) | WCAG 2.4.1 skip link |
| `_focus-visible.css` | ✅ Active (in `a11y.css`) | WCAG 2.4.7 global focus indicator |

## Deprecation headers nei file legacy

Per signal ai future developer + ai prossimi LLM sessions, sono stati
aggiunti header `@deprecated PROGRESSIVE` con mapping legacy→modulo:

- `css/layout.css` (top of file): full mapping table 10 voci
- `css/shell.css` (top of file): mapping 8 voci principali
- `css/admin.css` (sezione admin-toolnav): pointer al modulo

Le regole legacy NON sono state rimosse: aspettano refactor view
per essere sostituite ufficialmente. Caricamento order in head.php +
shell.php garantisce: modules base → legacy override.

Quando una regola in `layout.css` viene replicata da un modulo:
1. Verifica visual parity (screenshot diff).
2. Marca la vecchia regola con commento `/* DEPRECATED: see modules/_X.css */`.
3. Pianifica rimozione in PR separata dopo 1 sprint senza regressioni.

## Migration workflow

```bash
# 1. Identifica componenti candidati
grep -E '^\.fm-(btn|modal|alert|field)' css/layout.css | head -20

# 2. Estrai in modulo
# Crea css/modules/_componentName.css con regole CLEAN tokenizzate

# 3. Marca old in layout.css come DEPRECATED
# 4. Test su una pagina che usa il componente
# 5. Se OK, remove old + commit
```

## Best practices nuovi moduli

- Solo `var(--fm-*)` per colori/spacing/font-size, MAI hex hardcoded
- `:focus-visible` invece di `:focus` per a11y (skip mouse focus)
- Solo `outline: none` se sostituito con `box-shadow` per indicator
- `@media (prefers-reduced-motion)` per ogni animazione/transition
- Component-scoped (`.fm-component .fm-component__child`), no
  contextual selectors generici
- BEM modifier per stati (`--disabled`, `--loading`, `--error`)
- Mai `!important` (eccetto sandbox emergency override)
