<?php

/*
 * Banco di prova della regola `php-mb-convert-encoding-indovinata`
 * (.semgrep.yml), nei due versi. Non è codice dell'applicazione: la scansione
 * della CI non guarda `tests/`, e questo file lo legge solo `semgrep --test`,
 * nel passo «Le regole del progetto, provate nei due versi» di ci.yml.
 *
 * `ruleid:` sulla riga prima di una chiamata vuol dire «qui deve scattare»,
 * `ok:` «qui no». Se la regola smette di scattare dove deve, o scatta dove
 * non deve, `semgrep --test` fallisce.
 */

function valoreRicevuto(array $post): array
{
    $puliti = [];
    foreach ($post as $chiave => $valore) {
        // La forma del vecchio sito (upload-webhook.php, 2025), che ha rovinato 107 mappe.
        // ruleid: php-mb-convert-encoding-indovinata
        $puliti[$chiave] = mb_convert_encoding($valore, 'UTF-8', 'auto');
    }
    return $puliti;
}

function altreFormeCheIndovinano(string $testo): array
{
    return [
        // ruleid: php-mb-convert-encoding-indovinata
        mb_convert_encoding($testo, 'UTF-8', 'ASCII,UTF-8'),
        // ruleid: php-mb-convert-encoding-indovinata
        mb_convert_encoding($testo, 'UTF-8', "UTF-8, ISO-8859-1"),
        // ruleid: php-mb-convert-encoding-indovinata
        mb_convert_encoding($testo, 'UTF-8', "AUTO"),
        // ruleid: php-mb-convert-encoding-indovinata
        mb_convert_encoding($testo, 'UTF-8', ['ASCII', 'UTF-8']),
        // ruleid: php-mb-convert-encoding-indovinata
        mb_detect_encoding($testo),
        // ruleid: php-mb-convert-encoding-indovinata
        mb_detect_encoding($testo, ['ASCII', 'UTF-8']),
        // ruleid: php-mb-convert-encoding-indovinata
        mb_detect_encoding($testo, 'ASCII,UTF-8', false),
    ];
}

function formeCorrette(string $testo): array
{
    return [
        // ok: php-mb-convert-encoding-indovinata
        mb_convert_encoding($testo, 'UTF-8', 'ISO-8859-1'),
        // ok: php-mb-convert-encoding-indovinata
        mb_convert_encoding($testo, 'UTF-8', 'UTF-8'),
        // ok: php-mb-convert-encoding-indovinata
        mb_convert_encoding($testo, 'HTML-ENTITIES', 'UTF-8'),
        // ok: php-mb-convert-encoding-indovinata
        mb_convert_encoding($testo, 'UTF-8', ['Windows-1252']),
        // ok: php-mb-convert-encoding-indovinata
        mb_detect_encoding($testo, ['UTF-8'], true),
        // ok: php-mb-convert-encoding-indovinata
        mb_check_encoding($testo, 'UTF-8'),
    ];
}
