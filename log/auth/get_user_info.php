<?php
// Legacy bridge — forwards to /auth/user-info.
header('Location: /auth/user-info', true, 302);
exit;
