# Audit tecnico di accessibilità — Pantedu

> Documento di audit a supporto della [Dichiarazione di accessibilità](accessibility.md).
> Traccia la metodologia, gli strumenti, l'ambito e l'esito della valutazione
> di conformità a **WCAG 2.2 livello AA** ed **EN 301 549**, nell'ottica di
> allineamento volontario ai requisiti dello **European Accessibility Act**
> (Dir. UE 2019/882) anche dove non giuridicamente obbligatorio.

## 1. Quadro normativo e applicabilità

| Norma | Si applica a Pantedu? | Note |
|---|---|---|
| **L. 4/2004 + Dir. 2016/2102** (siti web) | Sì, se adottato da una scuola/PA | Quadro di riferimento principale; standard tecnico EN 301 549 = WCAG 2.1 AA. |
| **European Accessibility Act** (Dir. UE 2019/882, D.Lgs. 82/2022) | **No (per obbligo)** | Categoria non coperta (no e-commerce/banche/e-book/trasporti/ecomms/AV); esenzione **microimpresa** per i servizi (art. 4(5)); servizio gratuito non immesso sul mercato. **Allineamento volontario** ai requisiti tecnici. |
| **WCAG 2.2 AA / EN 301 549** | Adottato come **target volontario** | Vedi §4. |

## 2. Metodologia e ambito

- **Tipo**: autovalutazione del soggetto erogatore + test automatici.
- **Strumenti**: axe-core 4.x, Lighthouse, WAVE, ispezione manuale del markup, prove da tastiera, screen reader (NVDA su Firefox).
- **Ambito** (pagine campione, tema chiaro e scuro):
  - Pubbliche: home, login, ToS, AUP, informativa privacy, dichiarazione di accessibilità, segnalazione contenuti, vista pubblica `/public/studio/{id}`.
  - Studente: pagina studio (contenuti, mappe, esercizi).
  - Docente: area docente, profilo, editor verifiche/esercizi.
  - Admin: dashboard, WAF, template, sidebar-config.
  - **Pagine autenticate verificate con axe** (login reale docente + super-admin)
    tramite `tests/e2e/a11y_authenticated.spec.js`: profilo, dashboard docente,
    cambio password, admin dashboard, WAF dashboard/config, template, sidebar-config,
    istituti, deployment → **0 violazioni serious/critical** (2026-06-21), dopo aver
    corretto: pattern ARIA tabs del curriculum (4.1.2), interattivo annidato nei
    pulsanti sezione sidebar (4.1.2), link non distinguibili in waf/config (1.4.1),
    nomi accessibili di input colore e checkbox toggle in sidebar-config (4.1.2).
- **Automazione/CI**: workflow GitHub Actions `a11y.yml` (axe-core, **tag WCAG 2.0/2.1/2.2 A+AA** incl. `wcag22aa`) e `lighthouse.yml`, su pull request + nightly; spec e2e `tests/e2e/a11y_wcag_aa.spec.js`, `lighthouse_a11y.spec.js`, `visual_regression_a11y.spec.js`. Gli asset (`css/`, `js/`, `build/`) sono symlinkati in `public/` nel job così il test gira sulla **pagina realmente stilizzata** (in prod serviti da nginx), non su HTML privo di CSS.

## 3. Esito sintetico

L'audit automatico (axe-core, regole WCAG 2.1/2.2 A+AA) sulle pagine in ambito,
in tema chiaro e scuro, **non rileva violazioni di gravità serious/critical**.
**Verifica 2026-06-21**: la suite CI axe-core con i tag **WCAG 2.2 AA** sulla
pagina stilizzata (`a11y.yml`, GitHub Actions), estesa a **tutte le pagine HTML
pubbliche** (home, login, registrazione, ToS, AUP, informativa privacy,
dichiarazione accessibilità, sicurezza, contatto DPO, segnalazione contenuti,
accettazione ToS) + skip-link + dark-toggle, è risultata **14/14 verde** dopo la
correzione delle violazioni trovate (vedi §4): liste markdown malformate nelle
pagine legali, stato del toggle dark non esposto, dimensione target (2.5.8) di
alcuni link di servizio, contrasto del bottone submit in tema scuro (1.4.3), e
robustezza del form pubblico di segnalazione (rendeva 500 senza DB). Le barriere
note sono risolte; l'unica esenzione
residua è l'**editor di disegno mappe drawio** (componente di terze parti — vedi
Dichiarazione, lettera c), la cui *consultazione* dispone di alternativa testuale.

## 4. Criteri WCAG 2.2 — stato per criterio rilevante

Sono riportati i criteri **nuovi in WCAG 2.2** e quelli che in passato
presentavano criticità. Gli altri criteri 2.1 A/AA sono coperti e verificati
dall'audit automatico senza violazioni serious/critical.

| Criterio | Livello | Stato | Evidenza / note |
|---|---|---|---|
| 1.1.1 Contenuti non testuali | A | ✅ | Immagini con `alt`; **mappe concettuali** con alternativa testuale (concetti + relazioni) generata server-side; **formule matematiche** con MathML assistivo (MathJax `a11y/assistive-mml`), leggibile dagli screen reader — verificato in prod (tutte le formule di una pagina esercizi reale espongono il MathML). |
| 1.3.1 Info e correlazioni | A | ✅ | Tabelle dati con `<th scope="col">`; landmark semantici; form con `<label>`/`aria-label`. Convertitore markdown delle pagine legali corretto: `<ul>` contiene solo `<li>` (no più `<p>` figli diretti). |
| 1.4.3 Contrasto (minimo) | AA | ✅ | axe senza violazioni di contrasto (light+dark); token colore WCAG AA. |
| 1.4.4 Ridimensionamento testo | AA | ✅ | Tipografia in `rem`; layout responsivo fino a 200% zoom. |
| 2.1.1 Tastiera | A | ✅ | Editor TeX (palette `<button>` + campo testo) e **controlli editor verifiche** (`role="button"`+`tabindex`+attivazione Invio/Spazio) operabili da tastiera. Editor drawio: terze parti (esente). |
| 2.4.7 Focus visibile | AA | ✅ | `:focus-visible` esplicito sui controlli-icona; outline UA altrove (non soppresso globalmente). |
| 2.4.11 Focus non oscurato (minimo) | AA (nuovo 2.2) | ✅ | `:focus-visible { scroll-margin-block: 6rem }` in `a11y.css`: lo scroll lascia spazio sopra l'elemento a fuoco, evitando che resti coperto dalla topbar sticky. Spot-check manuale consigliato a ogni revisione. |
| 2.5.7 Movimenti di trascinamento | AA (nuovo 2.2) | ✅ | Il riordino drag-and-drop dei quesiti ha alternativa a puntatore singolo: frecce ▲▼ + campo numerico di posizione. |
| 2.5.8 Dimensione target (minimo) | AA (nuovo 2.2) | ✅ | `--fm-target-aa: 1.5rem` (24px) su controlli interattivi (`a11y.css`); controlli-icona editor a ≥24×24px; link di servizio footer/bottombar/sidebar (`.sel-action-link`, `.fm-bb-menu__*`, `#fm-license-section`, `#open-author-modal`) portati a ≥24px. Verificato da axe `target-size` (wcag22aa) sulla pagina stilizzata: 0 violazioni. |
| 3.2.6 Aiuto coerente | AA (nuovo 2.2) | ✅ | Meccanismi di aiuto (infotip ⓘ, link footer, contatti) in posizione coerente tra le pagine. |
| 3.3.7 Inserimento ridondante | AA (nuovo 2.2) | ✅ | I flussi (login, registrazione) non richiedono di reinserire informazioni già fornite nello stesso processo. |
| 3.3.8 Autenticazione accessibile (minimo) | AA (nuovo 2.2) | ✅ | Login con username/password: incolla consentito, gestori password supportati, nessun test cognitivo (la protezione anti-bot è Proof-of-Work automatica, non un CAPTCHA da risolvere). |
| 4.1.1 Parsing | — | ➖ | **Rimosso in WCAG 2.2**: gli stili inline non costituiscono non-conformità. |
| 4.1.2 Nome, ruolo, valore | A | ✅ | Pattern ARIA per dialog/tabs/disclosure (menu TeX), nomi accessibili sui controlli icona. |

## 5. Meccanismo di feedback

Gli utenti possono segnalare problemi di accessibilità tramite:
- modulo accessibile `/segnalazione-contenuti`;
- email `{{OPERATORE_EMAIL}}` (e `{{OPERATORE_EMAIL}}` per i diritti GDPR collegati).

Risposta entro 30 giorni (vedi Dichiarazione). Procedura di rinvio ad AgID in caso di risposta insoddisfacente.

## 6. Monitoraggio continuo

- CI a11y (`a11y.yml`) su ogni pull request e nightly: la suite axe-core è un
  **gate bloccante** — una violazione serious/critical fa fallire la pipeline,
  intercettando le regressioni prima del rilascio (verde 10/10 al 2026-06-21).
- `lighthouse.yml` (nightly + PR) come segnale aggiuntivo di performance/best-practice.
- Spec e2e dedicate (`a11y_wcag_aa`, `lighthouse_a11y`, `visual_regression_a11y`).
- Revisione della dichiarazione a cadenza semestrale o a ogni release major.

## 7. Azioni residue

| # | Azione | Criterio | Priorità |
|---|---|---|---|
| 1 | Estendere i test screen reader manuali (VoiceOver/JAWS) oltre NVDA. | — | Bassa |
| 2 | Valutare overlay accessibile / alternativa per l'*editing* mappe (oggi terze parti esente). | 2.1.1 | Bassa |

> Risolti rispetto alle revisioni precedenti: config LHCI (ora esegue contro
> server locale, non più 403 su prod); audit esteso alle pagine autenticate
> (docente + super-admin) con login reale → 0 violazioni serious/critical;
> verifica live del MathML assistivo sulle formule; gerarchia heading senza
> salti, 0 ID duplicati, h1 su ogni pagina.

---

_Redatto: 2026-06-21 — Autovalutazione del soggetto erogatore. Standard: WCAG 2.2 AA, EN 301 549._
