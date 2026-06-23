<?php
// Phase 2a bridge — forwards to /login front controller route.
// Preserves URL compatibility for bookmarks / old JS dopo migrazione
// auth a App\Controllers\AuthController.
$qs       = $_SERVER['QUERY_STRING'] ?? '';
$redirect = '/login' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $redirect, true, 302);
exit;
