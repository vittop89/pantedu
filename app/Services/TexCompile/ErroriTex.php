<?php

declare(strict_types=1);

namespace App\Services\TexCompile;

/**
 * Gli errori di pdflatex letti dal suo log, e l'estratto da mostrare al
 * docente (24/9/2026).
 *
 * pdflatex in nonstopmode rimedia agli errori e produce quasi sempre un PDF:
 * il servizio TeX lo dava per riuscito e la figura arrivava a metà, senza
 * messaggio. Gemello di `tools/tex-compile-vps/app/errori_tex.py`, che fa lo
 * stesso lato servizio per l'anteprima SVG; qui serve al modal, a cui il
 * servizio manda il log intero (`/compile?with_artifacts=1`). Le due copie
 * devono dire la stessa cosa: le provano `tests/tex/test_errori_tex.py` e
 * `tests/Unit/Services/TexCompile/ErroriTexTest.php` sugli stessi log.
 */
final class ErroriTex
{
    /**
     * Ogni riga che comincia con `! ` è un errore di TeX. `line` è il numero
     * dopo `l.` (riga del documento compilato), `context` le righe fino a
     * `l.NNN` più quella dopo, dove TeX mostra il resto della riga.
     *
     * @return list<array{line:?int, message:string, context:string}>
     */
    public static function daLog(string $log, int $limite = 20): array
    {
        $errori = [];
        if ($log === '') {
            return $errori;
        }
        $righe = preg_split('/\r\n|\n|\r/', $log) ?: [];
        $n = \count($righe);
        foreach ($righe as $i => $riga) {
            if (!str_starts_with($riga, '! ')) {
                continue;
            }
            $messaggio = trim(substr($riga, 2));
            if ($errori !== [] && (str_starts_with($messaggio, '==> Fatal error occurred') || str_starts_with($messaggio, 'Emergency stop'))) {
                continue;
            }
            $numero = null;
            $contesto = [];
            for ($j = $i + 1; $j < min($i + 14, $n); $j++) {
                $seguente = $righe[$j];
                if (str_starts_with($seguente, '! ')) {
                    break;
                }
                $trovata = preg_match('/^l\.(\d+)/', $seguente, $m) === 1;
                if (!$trovata && preg_match('/^\s*(\.\.\.)?\s*$|^Type\s+H <return>|^See the \S+ package documentation/', $seguente) === 1) {
                    continue;
                }
                $contesto[] = $seguente;
                if ($trovata) {
                    $numero = (int) $m[1];
                    if ($j + 1 < $n && trim($righe[$j + 1]) !== '') {
                        $contesto[] = $righe[$j + 1];
                    }
                    break;
                }
            }
            $errori[] = [
                'line'    => $numero,
                'message' => mb_substr($messaggio, 0, 300),
                'context' => mb_substr(trim(implode("\n", $contesto), "\n"), 0, 800),
            ];
            if (\count($errori) >= $limite) {
                break;
            }
        }
        return $errori;
    }

    /**
     * Gli errori come li manda il servizio TeX (`errori_tex.trova_errori`),
     * ridotti alla forma di `daLog`: si tiene solo quel che ha la forma
     * giusta, e i testi con i loro tetti.
     *
     * @param array<mixed> $dalServizio
     * @return list<array{line:?int, message:string, context:string}>
     */
    public static function normalizza(array $dalServizio): array
    {
        $errori = [];
        foreach ($dalServizio as $e) {
            if (!\is_array($e) || !\is_string($e['message'] ?? null) || $e['message'] === '') {
                continue;
            }
            $errori[] = [
                'line'    => \is_int($e['line'] ?? null) ? $e['line'] : null,
                'message' => mb_substr($e['message'], 0, 300),
                'context' => mb_substr(\is_string($e['context'] ?? null) ? $e['context'] : '', 0, 800),
            ];
            if (\count($errori) >= 20) {
                break;
            }
        }
        return $errori;
    }

    /**
     * Prima gli errori con il loro contesto, poi la coda del log (dove
     * pdflatex dice com'è finita). Senza errori: la coda del log.
     *
     * @param list<array{line:?int, message:string, context:string}> $errori
     */
    public static function estratto(string $log, array $errori, int $max = 8000): string
    {
        if ($errori === []) {
            return \strlen($log) <= $max ? $log : "[...]\n" . substr($log, -$max);
        }
        $n = \count($errori);
        $parti = ['pdflatex ha trovato ' . $n . ($n === 1 ? ' errore:' : ' errori:')];
        foreach ($errori as $e) {
            $parti[] = '! ' . $e['message'] . ($e['context'] !== '' ? "\n" . $e['context'] : '');
        }
        $testa = implode("\n\n", $parti);
        $resto = $max - \strlen($testa) - 40;
        if ($resto > 200) {
            $testa .= "\n\n--- fine del log di pdflatex ---\n" . (\strlen($log) > $resto ? substr($log, -$resto) : $log);
        }
        return substr($testa, 0, $max + 200);
    }
}
