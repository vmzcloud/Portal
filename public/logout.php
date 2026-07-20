<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
        Auth::logout();
    }
} else {
    Auth::logout();
}

header('Location: /login.php');
exit;
