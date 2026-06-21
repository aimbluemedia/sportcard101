<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

\Sportscard101\Auth::logout();
redirect('login.php');
