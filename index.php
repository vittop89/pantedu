<?php
/**
 * Homepage entry — delega interamente al layout app (Phase 6a).
 * Il layout emette l'HTML completo (head + sidebar + modals + iframe
 * legacy). Il content principale della home è vuoto: la sidebar è
 * già lo strumento principale di navigazione.
 */
require_once __DIR__ . '/app/bootstrap.php';

$pageTitle   = 'PANTEDU';
$pageContent = '';
include __DIR__ . '/views/layout/app.php';
