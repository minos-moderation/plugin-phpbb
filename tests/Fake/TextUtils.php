<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's text utilities for the stored XML the tests write: quotes are removed with their
 * content, and formatting markup (`<s>`, `<e>`, tags) disappears.
 */
class TextUtils implements \phpbb\textformatter\utils_interface
{
	public function clean_formatting($text)
	{
		$text = preg_replace('#<[se]>.*?</[se]>#s', '', (string) $text);
		$text = preg_replace('#<br\s*/>#', '', (string) $text);
		return html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
	}

	public function remove_bbcode($text, $bbcode_name, $depth = 0)
	{
		$tag = strtoupper($bbcode_name);
		return (string) preg_replace('#<' . $tag . '[\s>].*?</' . $tag . '>#s', '', (string) $text);
	}
}
