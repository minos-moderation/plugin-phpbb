<?php

namespace minos\moderation\tests\Text;

use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\RecordingTransport;
use minos\moderation\tests\Fake\TextUtils;
use PHPUnit\Framework\TestCase;

/**
 * What phpBB 3.3's real text formatter made of invented posts (`tests/fixtures/phpbb-3.3/`,
 * written by `bin/phpbb-text-fixtures.php`), held against the test double of phpBB's text
 * utilities and against the text the extension sends.
 */
final class RealFormatterFixtureTest extends TestCase
{
	private const DIR = __DIR__ . '/../fixtures/phpbb-3.3';

	/** The corpus the fixtures must cover. */
	private const CORPUS = array('bold-inside-a-word', 'quote-then-a-word', 'list', 'link-with-text', 'code', 'color', 'size', 'smilies',
		'image', 'image-only', 'image-with-size', 'attachment-inline', 'flash', 'link-blank-label', 'link-empty-label', 'url-tag', 'bare-link-only',
		'image-zwsp', 'image-shy', 'image-word-joiner', 'image-lrm', 'link-label-shy', 'link-label-zwsp', 'bold-zwsp', 'typed-entity',
		'image-cgj', 'image-vs16', 'image-c1-control', 'image-hangul-filler', 'image-braille-blank', 'link-label-cgj', 'letter-combining-acute');

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function fixtures(): array
	{
		$fixtures = array();
		foreach (glob(self::DIR . '/*.json') ?: array() as $file)
		{
			$fixtures[basename($file, '.json')] = array(json_decode((string) file_get_contents($file), true));
		}
		return $fixtures;
	}

	public function testTheFixturesComeFromOnePhpbb33CommitAndCoverTheCorpus(): void
	{
		$fixtures = $this->fixtures();
		self::assertSame(array(), array_diff(self::CORPUS, array_keys($fixtures)), 'every text of the corpus is recorded');
		$commits = array();
		foreach ($fixtures as $name => $fixture)
		{
			$fixture = $fixture[0];
			self::assertIsArray($fixture, $name);
			self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $fixture['phpbb_commit'], $name);
			self::assertStringStartsWith('3.3.', $fixture['phpbb_version'], $name);
			self::assertFileExists(__DIR__ . '/../../' . $fixture['generator'], $name);
			foreach (array('bbcode', 'xml', 'clean_formatting', 'unparse') as $key)
			{
				self::assertIsString($fixture[$key], $name . ': ' . $key);
			}
			$commits[$fixture['phpbb_commit']] = true;
		}
		self::assertCount(1, $commits, 'one phpBB commit for all of them');
	}

	/**
	 * @dataProvider fixtures
	 * @param array<string,string> $fixture
	 */
	public function testTheTestDoubleIsPhpbbsRealFormatter(array $fixture): void
	{
		$utils = new TextUtils();

		self::assertSame($fixture['clean_formatting'], $utils->clean_formatting($fixture['xml']));
		self::assertSame($fixture['unparse'], $utils->unparse($fixture['xml']));
	}

	/**
	 * What a reader sees of each recorded post, written by hand.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function readerTexts(): array
	{
		return array(
			'bold inside a word'   => array('bold-inside-a-word', 'zakazane'),
			'formatting in words'  => array('formatting-in-words', 'to jest zakazane słowo i drugie'),
			'a quote, then a word' => array('quote-then-a-word', "X napisał(a):\nZgoda\nzakazane"),
			'nested quotes'        => array('nested-quotes', "Ala napisał(a):\nOla napisał(a):\nśrodek\nzewnątrz\nja"),
			'a list'               => array('list', "Lista:\n\njeden\ndwa\n\nkoniec"),
			'an ordered list'      => array('ordered-list', "pierwszy\ndrugi"),
			'a link with its text' => array('link-with-text', 'Zobacz tekst linku tutaj.'),
			'a bare link'          => array('bare-link', 'Adres https://example.org/a w tekście.'),
			'code'                 => array('code', "Przed\nkod  z  odstępami\ndruga linia\npo"),
			'colour'               => array('color', 'kolorowy'),
			'size'                 => array('size', 'rozmiarowy'),
			'underline and italics' => array('underline-italic', 'podkreślone'),
			'smilies'              => array('smilies', 'Wesoło :) i bardzo :D koniec'),
			// The plugins' shared rule F7: an image, an attachment or a video is no text, a
			// link's address is text only where the reader sees it.
			'an image beside text' => array('image', 'Obrazek  koniec'),
			'an image alone'       => array('image-only', ''),
			'an image with a size' => array('image-with-size', ''),
			'an inline attachment' => array('attachment-inline', ''),
			'a video'              => array('flash', ''),
			'a link, blank label'  => array('link-blank-label', ''),
			'a link, no label'     => array('link-empty-label', ''),
			'a [url] address'      => array('url-tag', 'https://example.org/a'),
			'a bare address alone' => array('bare-link-only', 'https://example.org/a'),
			// Characters a reader does not see are no text either (F7, \p{Cf}).
			'an image and U+200B'  => array('image-zwsp', ''),
			'an image and U+00AD'  => array('image-shy', ''),
			'an image and U+2060'  => array('image-word-joiner', ''),
			'an image and U+200E'  => array('image-lrm', ''),
			'a link labelled U+00AD' => array('link-label-shy', ''),
			'a link labelled U+200B' => array('link-label-zwsp', ''),
			'bold around U+200B'   => array('bold-zwsp', ''),
			// A mark without its base letter (\p{M}), a control (\p{Cc}), a blank filler: no
			// text either; a letter keeps its combining mark and is text.
			'an image and U+034F'  => array('image-cgj', ''),
			'an image and U+FE0F'  => array('image-vs16', ''),
			'an image and U+0081'  => array('image-c1-control', ''),
			'an image and U+3164'  => array('image-hangul-filler', ''),
			'an image and U+2800'  => array('image-braille-blank', ''),
			'a link labelled U+034F' => array('link-label-cgj', ''),
			'e and U+0301'         => array('letter-combining-acute', "e\u{0301}"),
			'the entity typed'     => array('typed-entity', '&#8203;'),
			'plain lines'          => array('plain-lines', "Pierwsza linia\nDruga linia\n\nCzwarta & <znaki> \"cudzysłów\""),
			'bold around a blank'  => array('blank-bold', ''),
			'empty italics'        => array('empty-italic', ''),
		);
	}

	/**
	 * @dataProvider readerTexts
	 */
	public function testTheTextSentIsTheTextAReaderSees(string $name, string $seen): void
	{
		$fixture = $this->fixtures()[$name][0];

		self::assertSame($seen, Board::open()->forum->plain_text($fixture['xml']));
	}

	public function testFormattingInsideAWordReachesTheGatewayAsOneWord(): void
	{
		// Audit probe B P6, the other way round, on phpBB's real output: its clean_formatting()
		// splits the word, so the extension must not use it for the text sent.
		$fixture = $this->fixtures()['formatting-in-words'][0];
		self::assertStringContainsString('zak a zane', $fixture['clean_formatting'], 'phpBB splits the word (the reason for the extension\'s own reading)');
		$gateway = new RecordingTransport();
		$board = Board::open(':memory:', $gateway);

		$board->posting('', 'reply', array('xml' => $fixture['xml'], 'topic_id' => $this->topic($board)));

		$sent = $gateway->items(0)[0]['tekst'];
		self::assertStringContainsString('zakazane', $sent);
		self::assertStringContainsString('drugie', $sent);
	}

	public function testAStoredTextThatIsNotPhpbbsXmlIsReadWithItsMarkup(): void
	{
		// phpBB 3.0's storage, before the reparser converted it: nothing in it may be hidden.
		$text = Board::open()->forum->plain_text('Stary <!-- s:) --><img src="{SMILIES_PATH}/icon_e_smile.gif" alt=":)" /><!-- s:) --> zak[b:1a2b3c4d]a[/b:1a2b3c4d]zane');

		self::assertStringContainsString('zak[b:1a2b3c4d]a[/b:1a2b3c4d]zane', $text);
		self::assertStringStartsWith('Stary', $text);
	}

	public function testATextThePatternCannotReadIsNeverTakenForBlank(): void
	{
		// Not UTF-8 (phpBB 3.0's storage could hold anything): the blank check cannot read it,
		// and the text is kept - more text, never less.
		$text = Board::open()->forum->plain_text("\xff\xfe");

		self::assertSame("\xff\xfe", $text);
	}

	public function testAStoredTextWithADocumentTypeIsNotReadAsPhpbbsXml(): void
	{
		// phpBB never stores a DTD; one could declare entities whose references the reading
		// of phpBB's XML would drop. Read as it is, nothing in it disappears.
		$text = Board::open()->forum->plain_text('<!DOCTYPE r [<!ENTITY x "ukryte">]><r>a&x;b</r>');

		self::assertStringContainsString('&x;', $text);
	}

	/**
	 * An approved topic for a reply, so that the text sent is the post's alone.
	 */
	private function topic(Board $board): int
	{
		$first = $board->write_post($board->xml('Pierwszy wpis tematu.'), ITEM_APPROVED);
		return (int) $board->post($first)['topic_id'];
	}
}
