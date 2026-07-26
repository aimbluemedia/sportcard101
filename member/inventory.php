<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireMember();
Auth::refresh($pdo);

// Members manage their own collection only.
$INV_OWNER = Auth::userId();
$INV_AREA  = 'member';
$INV_SELF  = '/member/inventory.php';

require __DIR__ . '/../src/inventory_page.php';
