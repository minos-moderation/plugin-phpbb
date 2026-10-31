<?php

namespace phpbb\report;

use phpbb\report\exception\factory_invalid_argument_exception;

/**
 * Stub of phpBB 3.3's report handler factory (`phpbb.report.handler_factory`): phpBB's takes
 * the container and builds a new handler for each call; this one is given the post handler.
 */
class handler_factory
{
	/** @var report_handler_interface */
	protected $post;

	public function __construct(report_handler_interface $post)
	{
		$this->post = $post;
	}

	/**
	 * @param string $type `post` or `pm`.
	 * @return report_handler_interface
	 */
	public function get_instance($type)
	{
		if ($type === 'post')
		{
			return $this->post;
		}
		throw new factory_invalid_argument_exception();
	}
}
