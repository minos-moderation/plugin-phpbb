<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\service;

/**
 * The gateway request over cURL.
 *
 * Redirects are never followed (the key would travel to wherever a redirect points), and
 * the certificate is always verified.
 */
class curl_transport implements transport_interface
{
	/** Seconds allowed for the connection itself. */
	const CONNECT_TIMEOUT_S = 5;

	/**
	 * {@inheritdoc}
	 */
	public function post($url, array $headers, $body, $timeout_s)
	{
		if (!function_exists('curl_init'))
		{
			return null;
		}
		$handle = curl_init($url);
		if ($handle === false)
		{
			return null;
		}
		$lines = array();
		foreach ($headers as $name => $value)
		{
			$lines[] = $name . ': ' . $value;
		}
		curl_setopt_array($handle, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT_S, (int) $timeout_s),
			CURLOPT_TIMEOUT        => (int) $timeout_s,
		));
		$answer = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		if (PHP_VERSION_ID < 80000)
		{
			// A no-op since PHP 8.0, and deprecated since 8.5.
			curl_close($handle);
		}
		if (!is_string($answer) || $status === 0)
		{
			return null;
		}
		return array('status' => $status, 'body' => $answer);
	}
}
