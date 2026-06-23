<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\SidebarSectionRepository;

/**
 * WS4 — render PUBBLICO (senza login) di una sezione sidebar marcata
 * `publish_public` da /admin/sidebar-config.
 *
 * Espone il guscio `sel-wrapper` **privo di istituto** + il pulsante/pannello
 * della sezione, così la sezione è visualizzabile in rete senza autenticazione.
 * Dopo il login, la pagina autenticata ri-renderizza l'intera sidebar normale
 * (con selettori istituto) — vedi views/partials/sidebar.php.
 *
 * Rotta pubblica (nessun middleware auth): GET /public/sidebar/{key}.
 */
final class PublicSidebarController
{
    public function section(Request $req, array $params): Response
    {
        $key = preg_replace('/[^a-z0-9_-]/i', '', (string)($params['key'] ?? ''));
        if ($key === '') {
            return Response::html('<!-- key mancante -->', 400);
        }
        $sec = (new SidebarSectionRepository())->publicSectionByKey($key);
        if (!$sec) {
            return Response::html('<!-- sezione non pubblica -->', 404);
        }
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $k = $h($sec['section_key']);
        $style = !empty($sec['color']) ? ' style="--fm-sb-color:' . $h((string)$sec['color']) . '"' : '';

        // Guscio sel-wrapper SENZA selettori istituto + pulsante/pannello sezione.
        $html = '<div class="sel-wrapper sel-wrapper--public" data-public="1">'
              . '<button type="button" class="fm-sb-sec" data-sidepage="' . $k . '"' . $style . '>'
              . '<strong>' . $h((string)$sec['label']) . '</strong></button>'
              . '<div id="fm-sp-' . $k . '" class="fm-sb-panel" data-sidepage="' . $k . '"'
              . ' data-group-mode="' . $h((string)$sec['group_mode']) . '"></div>'
              . '</div>';

        return Response::html($html);
    }
}
