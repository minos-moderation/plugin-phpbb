<?php

namespace phpbb\request;

/**
 * Stub of phpBB's request interface: the members the extension calls.
 */
interface request_interface
{
	const POST = 0;
	const GET = 1;
	const REQUEST = 2;
	const COOKIE = 3;
	const SERVER = 4;
	const FILES = 5;

	public function variable($var_name, $default, $multibyte = false, $super_global = self::REQUEST);

	public function raw_variable($var_name, $default, $super_global = self::REQUEST);

	public function is_set_post($name);
}
