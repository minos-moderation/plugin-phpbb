<?php

namespace minos\moderation\tests\Fake;

use minos\moderation\service\transport_interface;

/**
 * The gateway as the tests script it: every request is recorded, and each gets the next
 * scripted answer, or `202` accepting every item when the script is empty.
 */
class RecordingTransport implements transport_interface
{
	/** @var array<int,array{url:string,headers:array<string,string>,body:string,timeout:int}> */
	public $requests = array();

	/** @var array<int,array{status:int,body:string}|null> */
	public $script = array();

	public function post($url, array $headers, $body, $timeout_s)
	{
		$this->requests[] = array('url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout_s);
		if ($this->script)
		{
			return array_shift($this->script);
		}
		$ids = array();
		foreach (json_decode($body, true)['elementy'] as $item)
		{
			$ids[] = $item['id'];
		}
		return array('status' => 202, 'body' => json_encode(array('przyjete' => $ids)));
	}

	/**
	 * Scripts a refusal in the gateway's shape.
	 *
	 * @param int      $status
	 * @param string   $code
	 * @param int|null $retry_after
	 * @param int|null $element
	 * @return void
	 */
	public function refuse($status, $code, $retry_after = null, $element = null)
	{
		$error = array('kod' => $code, 'komunikat' => 'Odmowa (test).');
		if ($retry_after !== null)
		{
			$error['ponow_za_s'] = $retry_after;
		}
		if ($element !== null)
		{
			$error['element'] = $element;
		}
		$this->script[] = array('status' => $status, 'body' => json_encode(array('blad' => $error)));
	}

	/**
	 * The items of a recorded request.
	 *
	 * @param int $index
	 * @return array<int,array<string,mixed>>
	 */
	public function items($index)
	{
		return json_decode($this->requests[$index]['body'], true)['elementy'];
	}
}
