<?php
/**
 * Signing in to /admin.
 *
 * There is no user list: the site owner sets one password the first time they open /admin, and it
 * is stored only as a hash. Sign-in attempts are rate limited per visitor, the session cookie is
 * locked to this site, and every form that changes something carries a one-time token.
 */
require_once dirname(__DIR__, 2) . '/api/rate_limit.php';

class Auth
{
    private const IDLE_SECONDS = 28800;   // signed out after 8 hours of inactivity
    private const MIN_LENGTH   = 12;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        session_name('th_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        if (!empty($_SESSION['admin']) && time() - (int) ($_SESSION['seen'] ?? 0) > self::IDLE_SECONDS) {
            $_SESSION = [];
        }
        $_SESSION['seen'] = time();
    }

    public static function isSetUp(): bool
    {
        return is_file(self::file());
    }

    /** First run: the owner picks the password. Returns an error message, or null when it worked. */
    public static function setPassword(string $password, string $repeat): ?string
    {
        if (self::isSetUp())                 return 'A password has already been set for this site.';
        if ($password !== $repeat)           return 'The two passwords do not match.';
        if (strlen($password) < self::MIN_LENGTH) return 'Use at least ' . self::MIN_LENGTH . ' characters, and something you do not use anywhere else.';
        if (!is_dir(Store::stateDir()) && !@mkdir(Store::stateDir(), 0775, true)) {
            return 'The storage folder could not be created on the server.';
        }

        $saved = @file_put_contents(self::file(), json_encode([
            'hash'      => password_hash($password, PASSWORD_DEFAULT),
            'createdAt' => gmdate('c'),
        ]), LOCK_EX);
        if ($saved === false) return 'The password could not be saved. Check that the storage folder is writable.';

        self::signIn();
        return null;
    }

    /** Returns an error message, or null when the password was right. */
    public static function attempt(string $password): ?string
    {
        if (!checkRateLimit('cms_login', 6, 900)) {
            return 'Too many sign-in attempts. Please wait 15 minutes and try again.';
        }

        $data = json_decode((string) @file_get_contents(self::file()), true);
        $hash = is_array($data) ? (string) ($data['hash'] ?? '') : '';
        if ($hash === '' || !password_verify($password, $hash)) {
            usleep(random_int(150000, 400000));   // slow down guessing
            return 'That password is not right.';
        }

        self::signIn();
        return null;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

        return (string) $_SESSION['csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $token);
    }

    private static function signIn(): void
    {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['seen']  = time();
        $_SESSION['csrf']  = bin2hex(random_bytes(16));
    }

    private static function file(): string
    {
        return Store::stateDir() . '/auth.json';
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
