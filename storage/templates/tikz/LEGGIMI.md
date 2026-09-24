# Modelli TikZ versionati

I modelli della biblioteca TikZ (menu «TeX ▾» dell'editor, `/admin/templates#tikz`)
che si curano nel codice. Il rilascio li porta nella biblioteca dell'istanza a
ogni giro (passo 8-quater di `tools/webhook/deploy-container.sh`). Perché così e
non altrimenti: [ADR-050](../../../wiki/decisions/ADR-050-modelli-tikz-versionati.md).

- `modelli.json` — il manifesto: per ogni modello il gruppo, l'etichetta, il
  tipo (`tikz` o `latex`), il file e le impronte delle versioni che sostituisce;
- un `.tex` per modello, in una cartella per gruppo.

## Che cosa fa il rilascio

Per ogni voce del manifesto, nel suo gruppo:

| nell'istanza c'è… | il rilascio |
|---|---|
| niente con quell'etichetta | lo crea |
| lo stesso testo | non fa niente |
| una versione la cui impronta è in `sostituisce` | la sostituisce |
| un altro testo | **non lo tocca** e lo scrive nel registro («cambiato dal pannello») |

Un modello dell'istanza che non è nel manifesto non viene mai toccato. Le
regole stanno in `app/Services/Tikz/ModelliTikzVersionati.php`.

## Cambiare un modello

1. Si scrive il `.tex` nuovo.
2. Si aggiunge a `sostituisce` l'impronta della versione di prima, altrimenti
   l'istanza tiene quella vecchia e il rilascio la segnala come cambiata dal
   pannello:

   ```bash
   git show HEAD:storage/templates/tikz/fisica/cinematica-1d.tex | php tools/tikz/sincronizza_modelli.php --impronta
   ```

   Se il modello in produzione era stato ritoccato dal pannello, la sua
   impronta è diversa: la si calcola dal testo che c'è in produzione, e la si
   aggiunge solo se lo si vuole davvero sostituire.
3. Si prova e **si guardano le immagini**: `python3 -B -m unittest discover -s
   tests/tex -v` compila ogni modello come la produzione (errori TeX e misure
   della figura), con `PANTEDU_ANTEPRIME=<cartella>` salva un PNG per modello.
   Se la figura cambia di proposito, dopo averla guardata si rigenerano le
   misure attese (`tests/fixtures/modelli-tikz-misure.json`) con
   `PANTEDU_MISURE_AGGIORNA=1`. La stessa cosa attraverso l'applicazione:
   `npx playwright test tests/e2e/editor/modelli-tikz-compilano.spec.js`
   (`@tex`, servono il server e il servizio TeX locali). Il manifesto:
   `tests/Unit/Services/Tikz/ModelliTikzVersionatiTest.php`.
4. A secco, contro una copia dei dati: `php tools/tikz/sincronizza_modelli.php`.

## Aggiungere un modello

Il `.tex` e una voce nel manifesto, senza `sostituisce`. Per un modello che
esiste già nell'istanza e che si vuole portare sotto versione, `sostituisce`
porta l'impronta del testo che c'è oggi.

## Togliere un modello

Si toglie dal manifesto (resta nell'istanza, e da lì lo cancella
l'amministratore). Lasciato nel manifesto, un modello cancellato dal pannello
torna al rilascio dopo.

## Come si scrive un modello

Un modello TikZ è un documento con preambolo e `\begin{document}`, senza
`\documentclass` (lo aggiunge il servizio TeX). Sul modello di «poligono» e
«cinematica 1D»: i parametri in chiavi (`\pgfkeys`, `\tikzset`) valide per
tutta la figura e ridefinibili in una parte sola; gli elementi ripetibili in
liste a barre (`\SetObjects{A/…/…, B/…/…}`); commenti che dicono l'ordine dei
campi. Un campo con virgole va tra graffe.

Che cosa si aspetta chi lo usa, e che le prove del 24/9/2026 hanno trovato
mancare nei primi modelli riscritti (un modello nuovo lo rispetta dall'inizio):

- **nessun risultato sbagliato in silenzio**: un elemento chiesto si vede
  (un vettore nullo con etichetta mostra l'etichetta; nel primo quadrante le
  scritte sugli assi non spariscono), oppure la compilazione si ferma;
- **errori in italiano** che nominano lista, voce e campo: nome citato che non
  esiste, numero di campi sbagliato, decimale scritto con la virgola (i numeri
  si scrivono col punto: `1.5`), campo obbligatorio vuoto;
- **liste tolleranti**: spazi attorno alle barre, virgola dopo l'ultimo
  elemento, righe vuote o commentate, campo `mostra` omesso (vale 1);
- **niente sovrapposizioni con i dati del docente**: curve ritagliate al loro
  pannello, etichette che non finiscono su linee, frecce o altri testi, blocchi
  (casi, schemi, righe) che si spostano se l'ingombro cresce;
- **con i dati d'esempio lo stesso disegno** di prima, salvo i difetti corretti,
  misurato a pixel.
