# js/modules/

ES6 module tree introdotto in Phase 8.

## Layout

```
js/modules/
├── bootstrap.js              entry — popola window.FM bridge per legacy
├── core/                     primitive condivise (8b)
│   ├── config.js             SIDEBAR_CONFIG + costanti
│   ├── app-state.js          state sessione (selectedIIS, ...)
│   ├── utils.js              helper puri (UUID, color, slug)
│   ├── api.js                wrapper fetch + AJAX
│   ├── dom-manager.js        sidebar DOM + sizing
│   └── cookie-consent.js     consenso cookie iframe
├── editor/                   editor + content + LaTeX (8c)
│   ├── editor-system.js
│   ├── content-processor.js
│   ├── latex-render.js
│   ├── list-manager.js
│   └── utilities.js
├── ui/                       componenti UI (8f)
│   ├── ui-comp.js
│   ├── modals.js
│   ├── toasts.js
│   ├── panels.js
│   └── table-manager.js      view-only (gen LaTeX è server-side in TexBuilder)
├── print/                    stampa + export TeX (8e + 8g)
│   ├── print-client.js       MVP teacher (8e)
│   ├── print-orchestrator.js sostituto PrintExport (8g)
│   ├── overleaf-forwarder.js
│   └── gdrive-forwarder.js
├── state/                    state + clone (8f)
│   ├── state-manager.js
│   └── clone-manager.js
├── events/                   delegated handlers (8f)
│   └── event-handler.js
├── integrations/             servizi esterni (8h)
│   └── google-drive-latex-saver.js
└── selection/                save_load_scelte client (8h)
    └── selection-controller.js
```

## Convenzione bridge legacy

Ogni modulo durante la transizione (fasi 8b → 8h) esporta sia in modo ES6 sia su `window.FM.X` per i caller legacy:

```js
// js/modules/core/config.js
export const Config = { /* ... */ };
window.FM.modules.config = Config;
```

In 8i il bridge `window.FM.*` resta solo per le poche feature non
ancora migrate; in seguito viene rimosso.

## Caricamento

Le pagine PHP includono nello `<head>`:

```html
<script src="/script.js"></script>           <!-- legacy bridge fino a 8i -->
<script type="module" src="/js/modules/bootstrap.js" defer></script>
```

`type="module"` è defer-by-default, quindi l'ordine di esecuzione è
deterministico anche senza `defer` esplicito.
