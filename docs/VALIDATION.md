# Validazione input

Dove e come si valida l'input in pantedu. Risponde a "dove valido il campo X?" e "qual è il pattern".

## Validator centrale — `App\Support\Validator`

[`app/Support/Validator.php`](../app/Support/Validator.php) — **extractor typed, fail-fast**. Avvolge un array sorgente (`$_POST`, `$_GET`, JSON body) ed estrae valori tipati validati. Su input invalido lancia `RuntimeException` con messaggio **codificato** `motivo:campo` → il controller lo mappa a 4xx.

```php
use App\Support\Validator;

$v    = new Validator($req->all());        // o $_POST / json body
$name = $v->string('fileName', max: 255);
$dir  = $v->string('dir', regex: '#^[A-Za-z0-9_\-/.]+$#');
$id   = $v->int('id', min: 1);
$type = $v->in('type', ['mappa', 'esercizio', 'verifica', 'document']);
$mail = $v->email('email');
$flag = $v->bool('published', default: false);
```

### Metodi

| Metodo | Estrae / valida | Eccezioni (codice:campo) |
|--------|------------------|--------------------------|
| `string($k, min?, max?, regex?, required=true, default?)` | stringa trimmata | `missing_field`, `not_string`, `too_short`, `too_long`, `invalid_format` |
| `int($k, min?, max?, required=true, default=0)` | intero | `missing_field`, `not_int`, `too_low`, `too_high` |
| `in($k, array $allowed, required=true, default?)` | enum (whitelist) | `missing_field`, `not_in_allowed` |
| `email($k, maxLen=254, required=true, default?)` | email RFC | `not_email` (+ quelle di `string`) |
| `bool($k, required=false, default=false)` | booleano da form/JSON | `missing_field`, `not_bool` |
| `filename($k, array $exts, maxLen=255)` | nome file (no path, no null-byte, ext whitelist) | `null_byte`, `path_in_filename`, `extension_not_allowed` |
| `webPath($k, ?array $exts, maxLen=500)` | path relativo (anti-traversal, no `..`, no `C:`, charset whitelist) | `null_byte`, `traversal`, `absolute_path`, `invalid_chars`, `extension_not_allowed` |

### Pattern: mappare l'eccezione a HTTP

```php
try {
    $id = $v->int('id', min: 1);
} catch (\RuntimeException $e) {
    return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
}
```

Il codice (`too_long:fileName`) è machine-readable: il frontend può evidenziare il campo. **Non** restituire `$e->getMessage()` con dati utente dentro — qui contiene solo `motivo:nomecampo`, safe.

### Sicurezza

`filename()` e `webPath()` sono **anti path-traversal** e vanno usati per ogni input che diventa un percorso prima di `SafePath::resolve()`. Non costruire path da `string()` grezzo.

## Validator di dominio (specializzati)

Per strutture complesse esistono validator dedicati — usali al posto di check inline:

| Validator | Per |
|-----------|-----|
| `App\Services\Risdoc\Pt\PtValidator` | schema documento risdoc (PT) |
| `App\Services\Contract\ContractSchemaValidator` | schema "contract" dei contenuti |
| `App\Services\Security\TikzScriptValidator` | sicurezza degli script TikZ/LaTeX (anti-RCE) |
| `App\Services\Security\HtmlSanitizer` / `SvgSanitizer` | sanitizzazione output HTML/SVG (non validazione input, ma correlato) |
| `App\Services\PdfImport\EnrichmentAgents\Validator` | output degli agenti LLM dell'import PDF |

## Stato adozione (gap noto)

Il `Validator` centrale oggi è usato solo da pochi controller (`AdminController`, `CurriculumController`, `FileController`, `TikzController`). Altrove la validazione è **inline** (`if` sparsi) o assente → audit più lento e rischio di check dimenticati.

**Adozione incrementale consigliata** (non big-bang): a ogni modifica di un controller, sostituire i check inline con `Validator`. Priorità agli endpoint che ricevono id (IDOR/BOLA), path (traversal), enum (`content_type`/scope) e upload. Nessuna riscrittura di massa: il `Validator` è retrocompatibile e si adotta un endpoint alla volta.

## Vedi anche

- `docs/ROUTES.md` — quali endpoint ricevono input (candidati a validazione).
- `app/Support/SafePath.php` — risoluzione path sicura (a valle di `webPath()`).
- `ARCHITECTURE.md` — runbook "trova X".
