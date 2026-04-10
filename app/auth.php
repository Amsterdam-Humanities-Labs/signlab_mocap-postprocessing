<?php
/**
 * Authentication middleware.
 *
 * Usage in page endpoints:  require_once __DIR__ . '/auth.php'; $currentUser = requireAuth();
 * Usage in API endpoints:   require_once __DIR__ . '/auth.php'; $currentUser = requireAuthApi();
 * Admin check:              if (isAdmin($currentUser['username'])) { ... }
 */

function getSessionUser(): ?array {
    if (!isset($_COOKIE['sessionObject'])) {
        return null;
    }

    $decoded = json_decode(urldecode($_COOKIE['sessionObject']), true);
    if (!$decoded || empty($decoded['userId']) || empty($decoded['username'])) {
        return null;
    }

    // Check expiry
    if (!empty($decoded['expiresAt'])) {
        $expires = strtotime($decoded['expiresAt']);
        if ($expires !== false && $expires < time()) {
            return null;
        }
    }

    return [
        'userId'   => $decoded['userId'],
        'username' => $decoded['username'],
        'role'     => $decoded['role'] ?? 'user',
    ];
}

function requireAuth(): array {
    $user = getSessionUser();
    if (!$user) {
        header('Location: /login.html');
        exit;
    }
    return $user;
}

function requireAuthApi(): array {
    $user = getSessionUser();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
    return $user;
}

function isAdmin(string $username): bool {
    return in_array($username, ['gomer', 'jari'], true);
}
