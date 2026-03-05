<?php
/**
 * ERP System — REST API Router
 * 
 * Единая точка входа: erp/api/index.php
 * URL: /erp/api/index.php?action=journal.list&...
 * Или через .htaccess: /erp/api/journal/list
 */

header('Content-Type: application/json; charset=utf-8');

// ── CORS ────────────────────────────────────────────
$cfg = require __DIR__ . '/config.php';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $cfg['cors_origins'])) {
    header("Access-Control-Allow-Origin: {$origin}");
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Auth ────────────────────────────────────────────
function checkAuth(): bool {
    global $cfg;
    $token = $cfg['api_token'] ?? '';
    if (empty($token) || $token === 'CHANGE_ME_TO_RANDOM_STRING') {
        return true; // нет токена — пропускаем (dev mode)
    }
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return hash_equals($token, $m[1]);
    }
    return false;
}

if (!checkAuth()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Helpers ─────────────────────────────────────────
function jsonInput(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function jsonResponse($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function errorResponse(string $msg, int $code = 400): never {
    jsonResponse(['error' => $msg], $code);
}

function param(string $key, $default = null) {
    return $_GET[$key] ?? $_POST[$key] ?? $default;
}

// ── Routing ─────────────────────────────────────────
// Формат: action=module.method  (journal.list, products.create, etc.)
$action = param('action', '');
if (!$action) {
    // Попробуем path-based routing: /erp/api/journal/list
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = preg_replace('#^.*/api/#', '', $path);
    $parts = array_filter(explode('/', trim($path, '/')));
    if (count($parts) >= 2) {
        $action = $parts[0] . '.' . $parts[1];
    } elseif (count($parts) === 1) {
        $action = $parts[0] . '.list';
    }
}

if (!$action) {
    jsonResponse([
        'name'    => 'Lenta ERP API',
        'version' => '0.1.0',
        'modules' => ['journal', 'products', 'inventory', 'finance', 'tasks', 'ai', 'system'],
    ]);
}

[$module, $method] = array_pad(explode('.', $action, 2), 2, 'list');

// ── Module Loader ───────────────────────────────────
$moduleFile = __DIR__ . "/modules/{$module}.php";
if (!file_exists($moduleFile)) {
    errorResponse("Unknown module: {$module}", 404);
}

require_once __DIR__ . '/db.php';
require_once $moduleFile;

$className = 'ERP_' . ucfirst($module);
if (!class_exists($className)) {
    errorResponse("Module class not found: {$className}", 500);
}

$handler = new $className();
if (!method_exists($handler, $method)) {
    errorResponse("Unknown method: {$module}.{$method}", 404);
}

try {
    $result = $handler->$method();
    jsonResponse($result);
} catch (PDOException $e) {
    errorResponse('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    errorResponse($e->getMessage(), $e->getCode() ?: 400);
}
