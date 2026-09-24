---
tags:
  - documentazione/adr
date: 2026-09-24
tipo: adr
status: accettato
aliases: ["ADR-050", "modelli TikZ versionati", "biblioteca TikZ", "sincronizza_modelli"]
cssclasses: []
---

# ADR-050 — I modelli TikZ curati nel codice arrivano con il rilascio

## Stato

**ACCETTATO** il 24 settembre 2026, su indicazione dell'utente: il modello
«cinematica 1D» riscritto doveva andare in produzione «aprendo una PR, e poi
ci procede il rilascio», non con una scrittura a mano sul server.

## Il problema

La biblioteca dei modelli TikZ è il JSON d'istanza
`storage/data/modelli_tikz_elements.json`, fuori da git, che scrive il
pannello `/admin/templates#tikz` (`TikzElementsService`). Era l'unico posto:
un modello riscritto e provato nel repository non aveva una strada per
arrivare in produzione. Le migrazioni sono solo SQL, e il rilascio non tocca i
dati d'istanza. Restavano il copia-incolla nel pannello o una scrittura sul
server, tutti e due senza revisione, senza prova e senza traccia.

## La decisione

- I modelli che si curano nel codice stanno in `storage/templates/tikz/`: un
  `.tex` per modello e `modelli.json`, il manifesto (gruppo, etichetta, tipo,
  file, impronte delle versioni superate). Le istruzioni per chi li cambia
  stanno accanto ai file, in `storage/templates/tikz/LEGGIMI.md`.
- Il rilascio, al passo 8-quater di `tools/webhook/deploy-container.sh`, lancia
  `tools/tikz/sincronizza_modelli.php --apply` **dentro il container nuovo come
  `www-data`**: la stessa identità del pannello, che scrive lo stesso file, e
  il codice appena messo in servizio. Scrive con `TikzElementsService`, cioè
  con il lock e la sostituzione atomica del pannello.
- **Il pannello resta libero.** Per ogni voce: si crea se manca, si sostituisce
  solo una versione dichiarata superata (la sua impronta è in `sostituisce`),
  e un testo diverso — cambiato dal pannello — non si tocca e si segnala. I
  modelli fuori dal manifesto non si toccano mai.
- Un guasto dell'allineamento avvisa e lascia una riga fra le anomalie
  (`modelli_tikz`), senza fermare il rilascio: il sito serve con la biblioteca
  di prima.

## Alternative scartate

- **Base versionata letta a cascata**, come i modelli delle verifiche (base in
  git, scostamenti nei dati). Avrebbe cambiato il modo in cui leggono la
  biblioteca cinque consumatori (pannello, dialoghi dell'editor, workspace e
  scostamenti dei docenti, esportazione), e l'istanza oggi contiene *tutto*:
  per far vincere la base si sarebbe dovuto svuotarla. L'allineamento lascia
  la forma dei dati com'è.
- **Sovrascrivere sempre** con la versione del repository: cancellerebbe in
  silenzio una correzione fatta dal pannello.
- **Una migrazione**: le migrazioni sono SQL, e il JSON non è nel database.

## Conseguenze

- Cambiare un modello versionato vuol dire anche dichiarare l'impronta della
  versione di prima; se la si dimentica, il rilascio non sostituisce e lo dice
  («cambiato dal pannello»): il guasto è visibile, non silenzioso.
- Un modello cancellato dal pannello ma ancora nel manifesto torna al
  rilascio dopo: per toglierlo si toglie dal manifesto.
- Il passo 8-quater gira dal rilascio **successivo** a quello che lo porta:
  `deploy-container.sh` installa sé stesso, ma il giro in corso usa lo script
  di prima (`docs/dev/ci-cd.md`). A mano, sul server, lo stesso comando del
  passo: `docker exec -u www-data <container> php /var/www/pantedu/tools/tikz/sincronizza_modelli.php`
  (a secco) e poi con `--apply`.
- Le prove: le regole in `tests/Unit/Services/Tikz/ModelliTikzVersionatiTest.php`,
  il passo del rilascio in `tests/ops/modelli-tikz-rilascio.test.sh`, la
  compilazione dei modelli senza errori TeX in `tests/tex/test_modelli_tikz.py`
  (in CI il TeX non c'è e la compilazione si salta dicendolo).
