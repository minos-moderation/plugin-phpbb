<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's text utilities for the stored XML the tests write: `clean_formatting()` drops the
 * formatting markup (`<s>`, `<e>`, tags) and keeps the text; `unparse()` gives back what the
 * author typed, BBCode included.
 */
class TextUtils implements \phpbb\textformatter\utils_interface
{
	public function clean_formatting($text)
	{
		$text = preg_replace('#<[se]>.*?</[se]>#s', '', (string) $text);
		$text = preg_replace('#<br\s*/>#', '', (string) $text);
		return html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
	}

	public function unparse($text)
	{
		$text = preg_replace('#<br\s*/>#', '', (string) $text);
		return html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
	}
}
