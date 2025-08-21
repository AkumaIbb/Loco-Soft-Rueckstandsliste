<?php
/**
 * Simple CSRF token helper functions.
 */

declare(strict_types=1);

/**
 * Returns the current CSRF token, creating a new one if necessary.
 *
 * @param bool $renew Generate a new token even if one already exists.
 */
function csrf_token(bool $renew = false): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ($renew || empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a submitted CSRF token against the session value.
 */
function csrf_validate(string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return is_string($sessionToken) && $token !== '' && hash_equals($sessionToken, $token);
}
