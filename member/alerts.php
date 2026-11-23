<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireMember();
Auth::refresh($pdo);

$AH_AREA = 'member';
$AH_PRO  = Auth::isPro();

require __DIR__ . '/../src/alert_history_page.php';
