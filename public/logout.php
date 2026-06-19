<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

\Vipsvault\Auth::logout();
redirect('login.php');
