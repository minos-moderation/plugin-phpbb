<?php

namespace phpbb\controller;

/**
 * Stub of phpBB's controller helper. Like phpBB's `append_sid()`, it adds the session id
 * unless the caller passes its own (an empty one means none).
 */
class helper
{
	public function route($route, array $params = array(), $is_amp = true, $session_id = false, $reference_type = 1)
	{
		$url = 'https://forum.example/app.php/minos/webhook';
		return ($session_id === false) ? $url . '?sid=0123456789abcdef0123456789abcdef' : $url;
	}
}
