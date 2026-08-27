<?php
/**
 * WP MCP Connector — standalone rescue endpoint.
 *
 * Does NOT load WordPress, so it keeps working even when a broken theme or
 * plugin file has fatally crashed the site. It can do exactly one thing:
 * restore a .wpmcp-bak backup (created automatically on every file write)
 * over the broken file, inside wp-content.
 *
 * POST: token=<rescue token>&path=<path relative to wp-content>
 */

header('Content-Type: application/json');

function wpmcp_out($code, $data) {
	http_response_code($code);
	echo json_encode($data);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	wpmcp_out(405, ['error' => 'POST only']);
}

$secret_file = __DIR__ . '/.rescue-secret';
$token = (string) ($_POST['token'] ?? '');
$path  = (string) ($_POST['path'] ?? '');

if (!is_file($secret_file) || $token === '' || !password_verify($token, (string) file_get_contents($secret_file))) {
	wpmcp_out(403, ['error' => 'Invalid rescue token']);
}

if ($path === '' || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
	wpmcp_out(400, ['error' => 'Invalid path']);
}

$content_dir = dirname(__DIR__, 2); // wp-content/plugins/wp-mcp-connector -> wp-content
$target = $content_dir . '/' . ltrim(str_replace('\\', '/', $path), '/');
$backup = $target . '.wpmcp-bak';

$real = realpath($backup);
if ($real === false || strpos($real, realpath($content_dir) . DIRECTORY_SEPARATOR) !== 0) {
	wpmcp_out(404, ['error' => 'No .wpmcp-bak backup found for that path']);
}

if (!copy($backup, $target)) {
	wpmcp_out(500, ['error' => 'Restore failed — check file permissions']);
}

wpmcp_out(200, ['restored' => $path, 'from' => $path . '.wpmcp-bak']);
