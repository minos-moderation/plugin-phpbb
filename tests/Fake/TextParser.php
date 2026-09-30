<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's parser for plain text: `<t>` with the text escaped and line breaks as `<br/>`. It
 * remembers which features were off during each parse.
 */
class TextParser implements \phpbb\textformatter\parser_interface
{
	/** @var array<string,bool> */
	public $enabled = array('bbcodes' => true, 'magic_url' => true, 'smilies' => true);

	/** @var array<int,array{text:string,enabled:array<string,bool>}> */
	public $parsed = array();

	public function parse($text)
	{
		$this->parsed[] = array('text' => (string) $text, 'enabled' => $this->enabled);
		return '<t>' . str_replace("\n", "<br/>\n", htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8')) . '</t>';
	}

	public function disable_bbcodes()
	{
		$this->enabled['bbcodes'] = false;
	}

	public function disable_magic_url()
	{
		$this->enabled['magic_url'] = false;
	}

	public function disable_smilies()
	{
		$this->enabled['smilies'] = false;
	}

	public function enable_bbcodes()
	{
		$this->enabled['bbcodes'] = true;
	}

	public function enable_magic_url()
	{
		$this->enabled['magic_url'] = true;
	}

	public function enable_smilies()
	{
		$this->enabled['smilies'] = true;
	}
}
