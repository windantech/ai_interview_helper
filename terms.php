<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\View;

View::guest('pages/terms', ['title' => 'Terms of use', 'wide' => true]);
