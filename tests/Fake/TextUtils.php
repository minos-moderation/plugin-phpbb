<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's text utilities (`phpbb\textformatter\s9e\utils` of phpBB 3.3), as they are:
 * `clean_formatting()` puts a space before every markup element (`<s>`, `<e>`), removes them
 * and keeps the text - so formatting inside a word splits it; `unparse()` gives back what the
 * author typed, BBCode included. `tests/Text/RealFormatterFixtureTest.php` holds this double
 * to the output phpBB's real formatter recorded in `tests/fixtures/phpbb-3.3/`.
 */
class TextUtils implements \phpbb\textformatter\utils_interface
{
	public function clean_formatting($text)
	{
		$dom = new \DOMDocument();
		$dom->loadXML((string) preg_replace('#<[es]>#', ' $0', (string) $text));
		$xpath = new \DOMXPath($dom);
		foreach (iterator_to_array($xpath->query('//e | //s')) as $node)
		{
			$node->parentNode->removeChild($node);
		}
		return $dom->documentElement->textContent;
	}

	public function unparse($text)
	{
		return html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
	}
}
