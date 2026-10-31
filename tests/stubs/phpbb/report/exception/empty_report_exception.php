<?php

namespace phpbb\report\exception;

/**
 * Stub of phpBB 3.3's report exception of the same name.
 */
class empty_report_exception extends invalid_report_exception
{
	public function __construct()
	{
		parent::__construct('EMPTY_REPORT');
	}
}
