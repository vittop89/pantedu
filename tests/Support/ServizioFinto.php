<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Un servizio HTTP vero, in un processo a parte (`php -S`), che risponde quello
 * che la prova gli dice.
 *
 * Serve alle prove che parlano con il servizio TeX senza doverlo installare:
 * la connessione, il codice HTTP e il corpo sono veri, e cURL li tratta come
 * tratterebbe quelli del servizio. Una porta chiusa, invece, è una porta su cui
 * nessuno ascolta: è il guasto di produzione dell'8 settembre 2026 (il
 * container cercava il TeX sul proprio 127.0.0.1), riprodotto senza rete.
 *
 * Il servizio annota ogni richiesta ricevuta (percorso, Host, X-Forwarded-For)
 * in un file che la prova può leggere con `richieste()`.
 */
final class ServizioFinto
{
    /** @var resource|null */
    private $processo;

    // Proprietà dichiarate fuori dal costruttore e senza `readonly`: il lettore
    // di PHP di semgrep non regge `readonly`, né promosso né dichiarato qui, e
    // il file sarebbe letto solo in parte (misurato il 23/9/2026). Le scrive
    // solo il costruttore.
    private string $cartella;

    public int $porta;

    private function __construct(string $cartella, int $porta, mixed $processo)
    {
        $this->cartella = $cartella;
        $this->porta = $porta;
        $this->processo = $processo;
    }

    /**
     * @param array<string, array{0: int, 1: string, 2?: int, 3?: string}> $risposte
     *        percorso => [codice HTTP, corpo] oppure [codice HTTP, corpo, secondi
     *        di attesa prima di rispondere], con un quarto elemento facoltativo:
     *        il Content-Type (predefinito `application/json`). Serve a fingere
     *        chi risponde con una pagina, come il nginx dell'applicazione
     *        (23/9/2026, CrowdSecLapiTest).
     *
     * I secondi servono a riprodurre il servizio che **c'è e sta lavorando**:
     * la connessione si stabilisce, e poi scade il tetto del client. cURL dà
     * errno 28 come quando i pacchetti si perdono, ma è tutt'altra cosa
     * (TexCompilazioneLentaTest). L'attesa vale solo per il percorso chiesto:
     * il saluto `/__chi-sei` risponde sempre subito, altrimenti l'avvio del
     * servizio finto aspetterebbe anche lui.
     */
    public static function avvia(array $risposte): self
    {
        $cartella = sys_get_temp_dir() . '/pantedu-servizio-finto-' . bin2hex(random_bytes(6));
        mkdir($cartella, 0700, true);
        // Sui runner di casa girano più lavori sulla stessa macchina: una porta
        // che risponde può essere il server di un altro. Si chiede chi è.
        $gettone = bin2hex(random_bytes(8));
        $router = $cartella . '/router.php';
        file_put_contents($router, '<?php' . "\n"
            . '$risposte = ' . var_export($risposte, true) . ";\n"
            . '$percorso = (string)parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);' . "\n"
            . 'if ($percorso === "/__chi-sei") { echo ' . var_export($gettone, true) . '; return true; }' . "\n"
            . 'file_put_contents(__DIR__ . "/richieste.jsonl", json_encode(['
            . '"percorso" => $percorso, "host" => $_SERVER["HTTP_HOST"] ?? "", '
            . '"inoltrato" => $_SERVER["HTTP_X_FORWARDED_FOR"] ?? ""]) . "\n", FILE_APPEND);' . "\n"
            . '$r = $risposte[$percorso] ?? [404, "{\"detail\":\"Not Found\"}"];' . "\n"
            . '[$codice, $corpo] = $r;' . "\n"
            . 'if (($r[2] ?? 0) > 0) { sleep((int)$r[2]); }' . "\n"
            . 'http_response_code($codice);' . "\n"
            . 'header("Content-Type: " . ($r[3] ?? "application/json"));' . "\n"
            . 'echo $corpo;' . "\n"
            . 'return true;' . "\n");

        for ($tentativo = 0; $tentativo < 5; $tentativo++) {
            $porta = random_int(20000, 45000);
            $processo = proc_open(
                [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', "127.0.0.1:{$porta}", $router],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $tubi,
                $cartella,
            );
            if (!\is_resource($processo)) {
                continue;
            }
            for ($attesa = 0; $attesa < 50; $attesa++) {
                if (!(proc_get_status($processo)['running'] ?? false)) {
                    break; // porta già presa da un altro: se ne prova un'altra
                }
                $prova = @fsockopen('127.0.0.1', $porta, $codice, $messaggio, 0.1);
                if ($prova !== false) {
                    fclose($prova);
                    $chi = @file_get_contents("http://127.0.0.1:{$porta}/__chi-sei", false, stream_context_create([
                        'http' => ['timeout' => 5, 'ignore_errors' => true],
                    ]));
                    if ($chi === $gettone) {
                        return new self($cartella, $porta, $processo);
                    }
                    break; // risponde qualcun altro su quella porta
                }
                usleep(100_000);
            }
            proc_terminate($processo);
            proc_close($processo);
        }
        self::svuota($cartella);
        throw new \RuntimeException('il servizio finto non è partito');
    }

    /**
     * Una porta su cui nessuno ascolta: aperta dal sistema e chiusa subito.
     * Chi ci si collega riceve «connessione rifiutata» (errno 7 di cURL).
     */
    public static function portaChiusa(): int
    {
        $presa = stream_socket_server('tcp://127.0.0.1:0', $codice, $messaggio);
        if ($presa === false) {
            throw new \RuntimeException("nessuna porta libera: $messaggio");
        }
        $nome = (string)stream_socket_get_name($presa, false);
        fclose($presa);
        return (int)substr($nome, (int)strrpos($nome, ':') + 1);
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->porta}";
    }

    /** @return list<array{percorso: string, host: string, inoltrato: string}> */
    public function richieste(): array
    {
        $righe = @file($this->cartella . '/richieste.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_filter(array_map(
            static fn(string $r): mixed => json_decode($r, true),
            $righe,
        ), 'is_array'));
    }

    public function ferma(): void
    {
        if (\is_resource($this->processo)) {
            proc_terminate($this->processo);
            proc_close($this->processo);
        }
        $this->processo = null;
        self::svuota($this->cartella);
    }

    private static function svuota(string $cartella): void
    {
        foreach (glob($cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($cartella);
    }
}
