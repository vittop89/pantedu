# Git Branch - Guida Rapida

## Comandi base (da VS Code)

### Cambiare branch
- Clicca sul nome del branch in basso a sinistra → seleziona il branch

### Creare un branch
- Clicca sul nome del branch → "Create new branch" → dai un nome

### Merge
- Git Graph (tasto destro sul branch) → "Merge into current branch..."
- Oppure: `git checkout master` poi `git merge nome-branch`

### Eliminare un branch
- Git Graph: tasto destro sul nome del branch → "Delete Branch..."

---

## Cosa succede quando cambio branch?

| Cosa | Effetto |
|---|---|
| File nella cartella | Git li cambia automaticamente |
| XAMPP/Apache | **Non serve riavviare** - serve sempre la stessa cartella |
| Sito locale (browser) | Ricarica la pagina per vedere le modifiche |
| Server hosting legacy | ⚠️ Il post-commit hook carica file su hosting condiviso **da qualsiasi branch** |

---

## Flusso per esperimenti sicuri

1. **Creare** il branch: clicca su `master` → "Create new branch" → es. `esperimento`
2. **Disabilita upload automatico** (per non mandare file sperimentali online):
   ```powershell
   $env:GIT_SKIP_DEPLOY=1; git commit -m "messaggio"
   ```
3. **Testa** in locale su `http://pantedu.local`
4. **Se funziona**: torna su `master` → merge → push (va online)
5. **Se non funziona**: torna su `master` → elimina branch → tutto come prima

## ⚠️ Attenzione

- **Prima di cambiare branch**: fai commit o stash delle modifiche non salvate
- **FTP non conosce i branch**: se fai commit senza `GIT_SKIP_DEPLOY=1`, i file vanno online da qualsiasi branch
- **Eliminare un branch** cancella i commit locali ma **non** i file già caricati su hosting condiviso via FTP
- **Resettare `GIT_SKIP_DEPLOY`**: usa il bottone **🔄 Deploy: ON** nella barra in basso di VS Code:
  - Clicca una volta → **Deploy DISATTIVATO** (i commit non caricano su hosting condiviso)
  - Clicca di nuovo → **Deploy ATTIVATO** (i commit caricano su hosting condiviso)
  - In alternativa, da terminale:
  ```powershell
  # Disattiva
  New-Item .git/SKIP_DEPLOY -Force
  # Riattiva
  Remove-Item .git/SKIP_DEPLOY
  ```
