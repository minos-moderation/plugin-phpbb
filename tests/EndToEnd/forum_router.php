<?php

/*
 * A forum for the end-to-end test, served by PHP's built-in server:
 *
 *   php -S 127.0.0.1:<port> tests/EndToEnd/forum_router.php
 *
 * `POST /minos/webhook` goes through the extension's real controller, receiver and applier
 * over the test board's SQLite file (MINOS_E2E_DB), with the webhook secret MINOS_E2E_SECRET;
 * after the answer it runs what phpBB's `app.php` runs after sending one (`kernel.terminate`).
 * Each delivery's status is appended to MINOS_E2E_LOG as a JSON line.
 */

require __DIR__ . '/../bootstrap.php';

use minos\moderation\controller\webhook;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use Symfony\Component\HttpFoundation\Request;

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if ($path !== '/minos/webhook' || (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST')
{
	http_response_code(404);
	return true;
}

$board = Board::open((string) getenv('MINOS_E2E_DB'), null, false);
$board->configure(array(), array(settings::WEBHOOK_SECRET => (string) getenv('MINOS_E2E_SECRET')));

$body = (string) file_get_contents('php://input');
$header = isset($_SERVER['HTTP_X_WERGILIUSZ_PODPIS']) ? (string) $_SERVER['HTTP_X_WERGILIUSZ_PODPIS'] : '';
$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => $header), $body), $board->receiver, $board->deferred, $board->dispatcher);
$response = $controller->handle();

http_response_code($response->getStatusCode());
header('Content-Type: text/plain; charset=utf-8');
$payload = json_decode($body, true);
file_put_contents((string) getenv('MINOS_E2E_LOG'), json_encode(array(
	'id'     => is_array($payload) && isset($payload['id']) ? $payload['id'] : null,
	'status' => $response->getStatusCode(),
)) . "\n", FILE_APPEND | LOCK_EX);

$board->dispatcher->terminate();
return true;
