<?php
declare(strict_types=1);

final class AdminAuth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('gaugeiq_admin');
            session_set_cookie_params([
                'httponly' => true,
                'secure' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function verifyCsrf(string $token): bool
    {
        self::startSession();
        return $token !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $token);
    }

    public static function login(PDO $db, string $username, string $password): bool
    {
        $stmt = $db->prepare('SELECT id, username, password_hash FROM gaugeiq_admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$user['id'];
        $_SESSION['admin_username'] = (string)$user['username'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }

    public static function check(): bool
    {
        self::startSession();
        return isset($_SESSION['admin_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function username(): string
    {
        self::startSession();
        return (string)($_SESSION['admin_username'] ?? '');
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
