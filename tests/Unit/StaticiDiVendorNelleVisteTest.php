<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le viste chiedono a /vendor/ solo file che ci sono, con l'impronta giusta
 * (23/9/2026).
 *
 * Revisione architetturale 2026-09, D-2: il JavaScript di Quill (215 KB) si
 * caricava su ogni pagina da `views/partials/head.php` e da
 * `_exercise_assets.php`, e nessun file lo istanziava. Tolto il file, una
 * vista che lo chiedesse ancora farebbe un 404 per pagina (e il JavaScript di
 * Quill, per chi guarda i registri, sembrerebbe ancora in uso). E un foglio
 * aggiornato senza aggiornare `integrity` il browser lo scarta in silenzio:
 * la pagina si disegna senza quello stile e nessuno se ne accorge.
 *
 * Qui si leggono le viste così come sono scritte: ogni `src` o `href`
 * letterale che punta a `/vendor/` deve esistere in `public/vendor/`, e se il
 * tag porta un `integrity` sha384 deve essere quello del file. MathJax non
 * compare: il suo indirizzo lo compone `_mathjax_loader.php` a runtime e il
 * file lo copia la build (tools/build/vendor-assets.mjs).
 */
final class StaticiDiVendorNelleVisteTest extends TestCase
{
    private const RADICE = __DIR__ . '/../..';

    /**
     * @return list<array{vista:string, url:string, integrita:?string}>
     */
    private static function riferimenti(): array
    {
        $trovati = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::RADICE . '/views', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (!$file->isFile() || !preg_match('/\.(php|html)$/', $file->getFilename())) {
                continue;
            }
            $testo = (string)file_get_contents($file->getPathname());
            // Via gli spezzoni PHP dentro i tag: lo script con il nonce
            // (`<script` seguito dall'eco di Csp::attributo()) ha un `>` suo,
            // e senza toglierlo il tag finirebbe lì, prima di `src` (la prima
            // versione di questa prova non vedeva gli script).
            $testo = (string)preg_replace('/<\?(?:=|php\b).*?\?>/s', '', $testo);
            // Un tag intero (<link …> o <script …>), anche su più righe.
            if (!preg_match_all('/<(?:link|script)\b[^>]*>/is', $testo, $tag)) {
                continue;
            }
            foreach ($tag[0] as $t) {
                if (!preg_match('#\b(?:src|href)\s*=\s*"(/vendor/[^"?\#]+)#', $t, $u)) {
                    continue;
                }
                $integrita = preg_match('/\bintegrity\s*=\s*"sha384-([^"]+)"/', $t, $i) ? $i[1] : null;
                $trovati[] = [
                    'vista'     => substr($file->getPathname(), strlen(self::RADICE) + 1),
                    'url'       => $u[1],
                    'integrita' => $integrita,
                ];
            }
        }
        return $trovati;
    }

    #[Test]
    public function ogni_file_di_vendor_chiesto_da_una_vista_esiste(): void
    {
        $riferimenti = self::riferimenti();
        // Una ricerca che non trova niente darebbe verde senza aver guardato:
        // oggi il foglio di Quill è chiesto da due viste.
        self::assertNotEmpty($riferimenti, 'nessun riferimento a /vendor/ trovato nelle viste');

        $mancanti = [];
        foreach ($riferimenti as $r) {
            if (!is_file(self::RADICE . '/public' . $r['url'])) {
                $mancanti[] = $r['vista'] . ' → ' . $r['url'];
            }
        }
        self::assertSame([], $mancanti, 'viste che chiedono a /vendor/ un file che non c\'è');
    }

    #[Test]
    public function l_impronta_dichiarata_e_quella_del_file(): void
    {
        $sbagliate = [];
        $controllate = 0;
        foreach (self::riferimenti() as $r) {
            $percorso = self::RADICE . '/public' . $r['url'];
            if ($r['integrita'] === null || !is_file($percorso)) {
                continue;
            }
            $controllate++;
            $vera = base64_encode(hash_file('sha384', $percorso, true));
            if ($vera !== $r['integrita']) {
                $sbagliate[] = $r['vista'] . ' → ' . $r['url'] . ' (il file ha sha384-' . $vera . ')';
            }
        }
        self::assertGreaterThan(0, $controllate, 'nessun integrity da controllare: la ricerca non guarda più niente');
        self::assertSame([], $sbagliate, 'integrity che il browser rifiuterebbe');
    }
}
