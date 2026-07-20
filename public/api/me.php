<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$user = Auth::user();
json_ok([
    'user' => $user,
    'csrf_token' => Auth::csrfToken(),
    'authenticated' => $user !== null,
    'is_admin' => Auth::isAdmin(),
    'must_change_password' => Auth::mustChangePassword(),
]);
