<?php

namespace phpbb\template;

/**
 * Stub of phpBB's template interface: the members the extension calls.
 */
interface template
{
	public function assign_vars(array $vararray);

	public function assign_block_vars($blockname, array $vararray);
}
