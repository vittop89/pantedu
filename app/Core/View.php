<?php

namespace App\Core;

final class View
{
    public function __construct(private string $viewsPath)
    {
    }

    /**
     * Renders a PHP template with the given data. Template receives
     * $this (the View) and the data keys as local variables. The
     * built-in `e()` helper is available for escaping.
     */
    /**
     * Le variabili locali hanno il prefisso `__` per non fare ombra ai dati.
     *
     * extract() gira con EXTR_SKIP, che NON sovrascrive le variabili gia'
     * esistenti: qualunque nome usato qui dentro diventa un nome vietato nelle
     * view, e il dato passato viene scartato in silenzio. Con `$file` e'
     * successo davvero — una view che riceveva 'file' => 'ALTPIEMONTE.csv' si
     * ritrovava il percorso del template e lo mostrava all'utente, senza un
     * errore da nessuna parte.
     *
     * Con i nomi prefissati la collisione resta possibile solo per chi passi
     * un dato che si chiama `__viewFile` o `__viewData`, cioe' mai.
     */
    public function render(string $template, array $data = []): string
    {
        $__viewFile = $this->viewsPath . '/' . ltrim($template, '/') . '.php';
        if (!is_file($__viewFile)) {
            throw new \RuntimeException("view_not_found:$template");
        }
        $__viewData = $data;
        // Via anche i parametri: sono variabili come le altre, e 'template' o
        // 'data' sono nomi che una view potrebbe legittimamente ricevere.
        unset($template, $data);
        extract($__viewData, EXTR_SKIP);
        ob_start();
        include $__viewFile;
        return (string)ob_get_clean();
    }

    public static function default(): self
    {
        $path = Config::get('app.paths.views')
              ?? dirname(__DIR__, 2) . '/views';
        return new self($path);
    }
}
