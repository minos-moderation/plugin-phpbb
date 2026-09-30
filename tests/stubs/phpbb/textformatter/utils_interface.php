<?php

namespace phpbb\textformatter;

/**
 * Stub of phpBB's text formatter utilities interface: the members the extension calls.
 */
interface utils_interface
{
	public function clean_formatting($text);

	public function remove_bbcode($text, $bbcode_name, $depth = 0);
}
