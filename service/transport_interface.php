<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\service;

/**
 * One HTTP POST to the gateway. An interface so the tests can see every request the
 * extension would send, without a network.
 */
interface transport_interface
{
	/**
	 * Sends a request and returns the answer.
	 *
	 * @param string               $url       The full URL.
	 * @param array<string,string> $headers   Header name => value.
	 * @param string               $body      The request body.
	 * @param int                  $timeout_s The whole request's time limit, connection included.
	 * @return array{status:int,body:string}|null Null when no HTTP answer arrived (network error,
	 *     timeout); a failure never carries the request or the key.
	 */
	public function post($url, array $headers, $body, $timeout_s);
}
