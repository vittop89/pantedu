<?php

declare(strict_types=1);

namespace App\Support;

/**
 * `app.url` vuota o non valida, e un flusso che doveva costruire un
 * collegamento assoluto (23/9/2026). La lancia `IndirizzoPubblico::radice()`
 * dopo aver registrato l'anomalia: chi la prende decide come dirlo a chi
 * aspettava il messaggio, non se dirlo.
 */
final class IndirizzoPubblicoMancante extends \RuntimeException
{
}
