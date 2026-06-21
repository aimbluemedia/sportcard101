<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

\SportCard101\Auth::logout();
redirect('/');
