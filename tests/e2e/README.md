# E2E browser testing (Playwright)

Setup Phase 9-e2e.

## Prerequisiti

- Node.js 20+
- XAMPP Apache attivo con vhost `pantedu.local` puntato al progetto
- Admin account in `log/data/admin_users.json` con password nota

## Install

```bash
npm install                       # installa @playwright/test
npx playwright install chromium   # scarica il browser (~160 MB)
```

## Variabili ambiente

```bash
# Git Bash
export FM_E2E_ADMIN_USERNAME=admin
export FM_E2E_ADMIN_PASSWORD=la_tua_password_admin
export FM_E2E_BASE_URL=http://pantedu.local    # default

# PowerShell
$env:FM_E2E_ADMIN_PASSWORD = "la_tua_password_admin"
```

## Run

```bash
npm run e2e            # headless
npm run e2e:ui         # GUI debug
npm run e2e:headed     # browser visibile
```

## Suite

- `smoke.spec.js` — home carica, window.FM esposto, login admin
- `registration.spec.js` — submit teacher → approve → login → print

## Pattern per nuovi test durante Phase 9 JS split

Per ogni estrazione namespace legacy → modulo ES6:

1. **Prima** scrivi un test che verifica lo stato attuale
2. `npm run e2e` → verde
3. Estrai + mantieni bridge su `window.X`
4. Riesegui → verde = commit, rosso = rollback

## CI (futuro)

```yaml
- run: npm ci
- run: npx playwright install --with-deps chromium
- run: npm run e2e
```
