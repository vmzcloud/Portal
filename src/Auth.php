<?php

declare(strict_types=1);

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function user(): ?array
    {
        self::startSession();
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        static $cached = false;
        static $user = null;
        if ($cached) {
            return $user;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, username, role, is_active, must_change_password, created_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row = $stmt->fetch() ?: null;
        if (!$row || (int) ($row['is_active'] ?? 1) !== 1) {
            unset($_SESSION['user_id']);
            $user = null;
            $cached = true;
            return null;
        }

        $row['is_active'] = (int) ($row['is_active'] ?? 1);
        $row['must_change_password'] = (int) ($row['must_change_password'] ?? 0);
        $user = $row;
        $cached = true;
        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user && $user['role'] === 'admin';
    }

    public static function mustChangePassword(): bool
    {
        $user = self::user();
        return $user && (int) ($user['must_change_password'] ?? 0) === 1;
    }

    /**
     * @return true|string true on success, or error message string
     */
    public static function attempt(string $username, string $password): true|string
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, password_hash, is_active, must_change_password
             FROM users WHERE username = ? COLLATE NOCASE'
        );
        $stmt->execute([trim($username)]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return 'Invalid username or password.';
        }
        if ((int) ($row['is_active'] ?? 1) !== 1) {
            return 'Account is deactivated. Contact an administrator.';
        }

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            json_error('Please log in first', 401);
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') {
            json_error('Admin privileges required', 403);
        }
        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            json_error('Password change required', 403);
        }
        return $user;
    }

    /** Block API use until password is changed (except password endpoint). */
    public static function requireUsableSession(): array
    {
        $user = self::requireLogin();
        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            json_error('Password change required before continuing', 403);
        }
        return $user;
    }

    public static function userGroupIds(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT group_id FROM group_members WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function publicUserRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'role' => $row['role'],
            'is_active' => (int) ($row['is_active'] ?? 1),
            'must_change_password' => (int) ($row['must_change_password'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
