<?php
/**
 * Test-only PHP built-in server router. Started by RealHttpClientTest
 * via `php -S 127.0.0.1:<port> -t tests/fixtures tests/fixtures/server.php`.
 *
 * Routes:
 *   GET /api/v1/user/me        -> 200 + {"data":{"id":"u_real","email":"r@x"}}
 *   GET /api/v1/teapot         -> 418 + {"error":{"code":"TEAPOT","message":"hi"}}
 *   GET /api/v1/redirect       -> 301 -> /api/v1/user/me
 *
 * Echoes the inbound Authorization + User-Agent headers in a custom
 * `X-Echo-Auth` / `X-Echo-Ua` header so tests can verify they were
 * forwarded.
 */

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');
header('X-Echo-Auth: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
header('X-Echo-Ua: '   . ($_SERVER['HTTP_USER_AGENT']   ?? ''));
header('X-Request-Id: req_real_' . substr(md5($path . $method), 0, 8));

if ($path === '/api/v1/user/me' && $method === 'GET') {
    echo json_encode(['data' => ['id' => 'u_real', 'email' => 'r@x']]);
    return true;
}

if ($path === '/api/v1/teapot' && $method === 'GET') {
    http_response_code(418);
    echo json_encode(['error' => ['code' => 'TEAPOT', 'message' => 'hi']]);
    return true;
}

if ($path === '/api/v1/redirect' && $method === 'GET') {
    http_response_code(301);
    header('Location: /api/v1/user/me');
    return true;
}

http_response_code(404);
echo json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => $path]]);
return true;
