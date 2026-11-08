<?php

/** @var array $config */

require_once __DIR__ . '/../lib/boot.php';

use Photobooth\Service\ConfigurationService;
use Photobooth\Utility\PathUtility;

$config = ConfigurationService::getInstance()->getConfiguration();

// Make sure CSRF helper is available even if only admin_boot is required
if (!function_exists('checkCsrfOrFail')) {
    /**
     * @param  array<string,mixed>  $source
     */
    function checkCsrfOrFail(array $source, string $key = 'csrf'): void
    {
        $sessionToken  = $_SESSION[$key] ?? '';
        $incomingToken = $source[$key] ?? '';
        if (!hash_equals((string)$sessionToken, (string)$incomingToken)) {
            $logger = Photobooth\Service\LoggerService::getInstance()->getLogger('main');
            $logger->debug('CSRF validation failed', [
                'expected' => $sessionToken,
                'provided' => $incomingToken,
                'path'     => $_SERVER['REQUEST_URI'] ?? '',
                'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
            ]);
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit();
        }
    }
}

// Enforce admin session only when login is enabled
if ($config['login']['enabled']) {
    if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
        // API calls (fetch / ajax) cannot follow a redirect to the login page:
        // answer with a JSON error so the admin interface can react.
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if (str_contains($script, '/api/')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'error' => 'Not authenticated',
                'login' => PathUtility::getPublicPath('login'),
            ]);
            exit();
        }
        header('Location: ' . PathUtility::getPublicPath('login'));
        exit();
    }
}
