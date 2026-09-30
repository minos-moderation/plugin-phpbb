<?php

namespace phpbb\log;

/**
 * Stub of phpBB's log interface: the member the extension calls.
 */
interface log_interface
{
	public function add($mode, $user_id, $log_ip, $log_operation, $log_time = false, $additional_data = array());
}
