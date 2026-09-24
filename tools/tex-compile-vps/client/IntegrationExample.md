# Integrazione lato hosting legacy — esempi

Pattern consigliati per integrare `TexCompileClient` nei controller
esistenti di pantedu senza rompere il flow legacy.

## 1. Wiring base (manuale, no DI container)

In linea con la convenzione del progetto (PSR-4, niente container automatico):

```php
// app/Controllers/VerificaController.php

use App\Services\TexCompile\TexCompileClient;
use App\Core\Config;

class VerificaController
{
    private TexCompileClient $texCompile;

    public function __construct()
    {
        $this->texCompile = new TexCompileClient(
            endpoint: (string) Config::get('tex_compile.endpoint'),
            secret:   (string) Config::get('tex_compile.secret'),
        );
    }

    public function generatePdf(int $verificaId): Response
    {
        // ... logica esistente: carica template, dati, render TexBuilder ...
        $texSource = $this->texBuilder->build($context);

        $result = $this->texCompile->compile(
            texSource: $texSource,
            docId: "verifica_{$verificaId}",
            engine: 'pdflatex',
            passes: 2,
        );

        if (!$result['ok']) {
            // Loggare, NON esporre 'log' completo all'utente (può contenere paths)
            Audit::log('verifica.compile_failed', [
                'verifica_id' => $verificaId,
                'http_status' => $result['http_status'],
                'log_excerpt' => substr($result['log'], 0, 500),
            ]);
            return Response::json([
                'error' => 'Compilazione PDF fallita.',
                'detail' => $this->extractUserFriendlyError($result['log']),
            ], 500);
        }

        $pdfPath = $this->resolvePdfStoragePath($verificaId);
        file_put_contents($pdfPath, $result['pdf']);

        Audit::log('verifica.compile_ok', [
            'verifica_id' => $verificaId,
            'duration_ms' => $result['duration_ms'],
            'pdf_bytes'   => strlen($result['pdf']),
        ]);

        return Response::json(['ok' => true, 'pdf_url' => $this->pdfPublicUrl($verificaId)]);
    }

    private function extractUserFriendlyError(string $latexLog): string
    {
        // Cerca prima riga "! ..." nel log LaTeX (errore tipico)
        if (preg_match('/^!\s*(.+)$/m', $latexLog, $m)) {
            return $m[1];
        }
        return 'Errore generico nel sorgente LaTeX.';
    }
}
```

## 2. Config (`config/services.php` o equivalente)

```php
return [
    'tex_compile' => [
        'endpoint' => $_ENV['TEX_COMPILE_ENDPOINT'] ?? 'https://tex.tuosito.it',
        'secret'   => $_ENV['TEX_COMPILE_SECRET']   ?? '',
        'timeout'  => (int) ($_ENV['TEX_COMPILE_TIMEOUT'] ?? 35),
    ],
    // ...
];
```

`.env` di produzione su hosting condiviso:

```ini
TEX_COMPILE_ENDPOINT=https://tex.tuosito.it
TEX_COMPILE_SECRET=<stesso valore impostato sul VPS in /opt/tex-compile/.env>
```

## 3. Fallback se VPS irraggiungibile

Per compatibilità durante il rollout, mantieni il flow legacy come
fallback (se applicabile al tuo setup):

```php
public function generatePdf(int $verificaId): Response
{
    $texSource = $this->texBuilder->build($context);

    // Tentativo principale: VPS
    if ($this->texCompile->health()) {
        $result = $this->texCompile->compile($texSource, "verifica_{$verificaId}");
        if ($result['ok']) {
            return $this->savePdfAndRespond($verificaId, $result['pdf']);
        }
        Audit::log('verifica.vps_failed_falling_back', [
            'verifica_id' => $verificaId,
            'log' => substr($result['log'], 0, 500),
        ]);
    }

    // Fallback: download .tex per compile manuale lato utente
    return Response::tex($texSource, "verifica_{$verificaId}.tex");
}
```

> ATTENZIONE: `health()` aggiunge un round-trip extra. In produzione
> conviene rimuoverlo e gestire solo gli errori del compile reale.

## 4. Async / queue (futuro)

Se vuoi disaccoppiare la generazione PDF dalla request HTTP utente:

```php
// Enqueue (immediato, non blocca utente)
$job = $this->jobQueue->push('verifica.compile', [
    'verifica_id' => $verificaId,
    'tex_source'  => $texSource,
]);
return Response::json(['job_id' => $job->id, 'status' => 'queued']);

// Worker (cron ogni 30s, o systemd timer)
foreach ($this->jobQueue->pending('verifica.compile') as $job) {
    $result = $this->texCompile->compile($job->payload['tex_source'], "verifica_{$job->payload['verifica_id']}");
    if ($result['ok']) {
        // salva PDF, notifica utente (websocket / polling)
    }
    $this->jobQueue->complete($job, $result);
}
```

su hosting condiviso shared il cron è limitato → opzione concreta solo se passi al VPS
anche il sito stesso (architettura "all-in", vedi `README.md` opzione A).
Per il PoC iniziale **resta sincrono**: 2-5s di latenza compile sono
accettabili in UX e tengono il codice semplice.

## 5. Test E2E suggerito

Aggiungi a `tests/e2e/`:

```javascript
// tests/e2e/g21_vps_compile.spec.js
test('verifica genera PDF via VPS compile', async ({ page }) => {
    await page.goto('/verifica/42/genera');
    await page.click('button[data-fm-action="genera-pdf"]');

    // Attesa: compile typical 1-3s
    await page.waitForResponse(
        r => r.url().includes('/api/verifica/42/pdf') && r.status() === 200,
        { timeout: 15000 },
    );

    const pdf = await page.locator('iframe.fm-pdf-viewer').getAttribute('src');
    expect(pdf).toMatch(/\.pdf(\?.*)?$/);
});
```

## 6. Monitoring lato app

Esponi una metrica nella dashboard admin (se esiste):

```php
// app/Controllers/AdminController.php
public function texCompileStatus(): Response
{
    return Response::json([
        'vps_healthy' => $this->texCompile->health(),
        'last_24h'    => $this->audit->countSince('verifica.compile_ok', '-24h'),
        'failures_24h'=> $this->audit->countSince('verifica.compile_failed', '-24h'),
    ]);
}
```

## 7. Sicurezza — checklist

- [ ] Segreto HMAC mai committato su git (solo in `.env`, in `.gitignore`)
- [ ] Endpoint VPS solo HTTPS, mai HTTP
- [ ] Rate limit nginx attivo (default 20 req/min/IP)
- [ ] `client_max_body_size` allineato (max 8 MB)
- [ ] Log `.tex` content NON salvato lato VPS (compliance: contiene dati alunni)
- [ ] Fail2ban attivo per SSH (incluso in `provision.sh`)
- [ ] Snapshot VPS settimanali abilitati lato provider (~1-2 €/mese)
- [ ] Rotazione cert TLS automatica verificata: `certbot renew --dry-run`
