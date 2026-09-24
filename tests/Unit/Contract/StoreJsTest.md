# JS Store tests

The vanilla store at `js/modules/core/store.js` is covered indirectly by the
e2e tests (buttons_smoke + verifiche_studio_smoke): interazioni sul
checkbox `#multiarg`, modal Fonti, e refresh origini esercitano già tutte
le API (get/set/subscribe/configure + persist session).

Unit test dedicate sono out-of-scope per Phase 16 Step 5 (il progetto non
ha ancora un runner JS unitario: tutti i test sono PHPUnit + Playwright).

Quando introdotto un runner JS (Vitest/Jest) la suite target sarà:

  - get/set notifica subscriber una sola volta
  - prev === new NO fire
  - persist 'session' scrive in sessionStorage
  - persist 'local' scrive in localStorage
  - load iniziale da persistence via configure(initial)
  - unsubscribe rimuove il listener
  - patch merge shallow
