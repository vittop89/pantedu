<?php
// Legacy bridge — forwards to /logout front controller route.
$qs       = $_SERVER['QUERY_STRING'] ?? '';
$redirect = '/logout' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $redirect, true, 302);
exit;
