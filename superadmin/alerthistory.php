<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

$AH_AREA = 'admin';
$AH_PRO  = true; // admins always see the full history

require __DIR__ . '/../src/alert_history_page.php';
