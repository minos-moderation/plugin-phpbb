<?php

namespace phpbb\textformatter;

/**
 * Stub of phpBB's text formatter parser interface: the members the extension calls.
 */
interface parser_interface
{
	public function parse($text);

	public function disable_bbcodes();

	public function disable_magic_url();

	public function disable_smilies();

	public function enable_bbcodes();

	public function enable_magic_url();

	public function enable_smilies();
}
