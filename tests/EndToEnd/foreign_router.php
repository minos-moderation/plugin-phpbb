<?php

/*
 * What may answer at a gateway's address without being the gateway (a proxy in front of it, a
 * wrong address), served by PHP's built-in server for the end-to-end test:
 *
 *   php -S 127.0.0.1:<port> tests/EndToEnd/foreign_router.php
 *
 * The n-th request gets the n-th status of MINOS_E2E_STATUSES (comma-separated; the last one
 * repeats); the requests are counted in the file MINOS_E2E_COUNT. A 2xx carries a body that
 * names every item sent in `przyjete`, as the gateway's acceptance does - but not its status.
 */

$count_file = (string) getenv('MINOS_E2E_COUNT');
$count = is_file($count_file) ? (int) file_get_contents($count_file) : 0;
file_put_contents($count_file, (string) ($count + 1), LOCK_EX);
$statuses = array_map('intval', explode(',', (string) getenv('MINOS_E2E_STATUSES')));
$status = $statuses[min($count, count($statuses) - 1)];

$ids = array();
$request = json_decode((string) file_get_contents('php://input'), true);
foreach ((is_array($request) && isset($request['elementy']) && is_array($request['elementy'])) ? $request['elementy'] : array() as $item)
{
	if (is_array($item) && isset($item['id']))
	{
		$ids[] = $item['id'];
	}
}

http_response_code($status);
if ($status >= 200 && $status < 300)
{
	header('Content-Type: application/json');
	echo json_encode(array('przyjete' => $ids));
}
else
{
	header('Content-Type: text/html');
	echo '<html><body>Request Timeout</body></html>';
}
return true;
