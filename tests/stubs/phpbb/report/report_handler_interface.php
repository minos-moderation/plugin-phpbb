<?php

namespace phpbb\report;

/**
 * Stub of phpBB 3.3's report handler interface.
 */
interface report_handler_interface
{
	/**
	 * @param int    $id
	 * @param int    $reason_id
	 * @param string $report_text
	 * @param int    $user_notify
	 * @return null
	 */
	public function add_report($id, $reason_id, $report_text, $user_notify);

	/**
	 * @param int $id
	 * @return null
	 */
	public function validate_report_request($id);
}
