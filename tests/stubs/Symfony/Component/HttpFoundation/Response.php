<?php

namespace Symfony\Component\HttpFoundation;

/**
 * Stub of Symfony's response.
 */
class Response
{
	/** @var string */
	protected $content;

	/** @var int */
	protected $status;

	/** @var HeaderBag */
	public $headers;

	public function __construct($content = '', $status = 200, array $headers = array())
	{
		$this->content = (string) $content;
		$this->status = (int) $status;
		$this->headers = new HeaderBag($headers);
	}

	public function getStatusCode()
	{
		return $this->status;
	}

	public function getContent()
	{
		return $this->content;
	}
}
