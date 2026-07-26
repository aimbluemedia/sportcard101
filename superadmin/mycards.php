<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

// The superadmin's OWN collection, in the admin layout. Member collections
// are visible as read-only stats on the Collections page — not managed here.
$INV_OWNER = Auth::userId();
$INV_AREA  = 'admin';
$INV_SELF  = '/superadmin/mycards.php';

require __DIR__ . '/../src/inventory_page.php';
