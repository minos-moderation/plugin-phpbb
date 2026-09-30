<?php

namespace phpbb;

/**
 * Stub of phpBB's user.
 */
class user
{
	/** @var array<string,mixed> */
	public $data = array('user_id' => 2);

	/** @var string */
	public $ip = '192.0.2.10';

	public function format_date($gmepoch, $format = false, $forcedate = false)
	{
		return gmdate('Y-m-d H:i', (int) $gmepoch);
	}
}
