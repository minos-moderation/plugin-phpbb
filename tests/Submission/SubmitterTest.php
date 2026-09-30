<?php

namespace minos\moderation\tests\Submission;

use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\service\submitter;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * What the extension sends to the gateway, and what it does with each kind of answer.
 */
final class SubmitterTest extends TestCase
{
	/** @var Board */
	private $board;

	/** @var RecordingTransport */
	private $gateway;

	protected function setUp(): void
	{
		$this->gateway = new RecordingTransport();
		$this->board = Board::open(':memory:', $this->gateway);
	}

	public function testTheRequestHasTheContractsShape(): void
	{
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		self::assertCount(1, $this->gateway->requests);
		$request = $this->gateway->requests[0];
		self::assertSame(Board::GATEWAY . '/api/v1/b2b/oceny', $request['url']);
		self::assertSame(Board::KEY, $request['headers']['X-Gateway-Key']);
		self::assertSame('application/json', $request['headers']['Content-Type']);
		self::assertLessThanOrEqual(10, $request['timeout']);
		self::assertSame(array('elementy'), array_keys(json_decode($request['body'], true)));
		$item = $this->gateway->items(0)[0];
		self::assertSame(array('id', 'tekst', 'profil', 'meta'), array_keys($item));
		self::assertSame('phpbb:' . $id, $item['id']);
		self::assertSame('Zwykły testowy wpis.', $item['tekst']);
		self::assertSame('forum_adult', $item['profil']);
	}

	public function testTheIdFollowsTheContractsRuleAndCarriesNoContent(): void
	{
		$this->board->posting('Treść, której nie ma w identyfikatorze.');

		$id = $this->gateway->items(0)[0]['id'];
		self::assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,64}$/', $id);
		self::assertMatchesRegularExpression('/^phpbb:[1-9][0-9]*$/', $id);
		self::assertSame(array(12345, 0), submitter::parse_item_id(submitter::item_id(12345, 0)));
		self::assertSame(array(12345, 3), submitter::parse_item_id(submitter::item_id(12345, 3)));
	}

	public function testMetaHoldsOnlyTheAllowedSignals(): void
	{
		$this->board->posting('Wpis autorki z pięcioma postami.');
		$this->board->posting('Pierwszy wpis nowego użytkownika.', 'post', array('poster_id' => 3));
		$this->board->posting('Wpis gościa.', 'post', array('poster_id' => ANONYMOUS));

		$meta = array();
		foreach (array(0, 1, 2) as $index)
		{
			$meta[] = $this->gateway->items($index)[0]['meta'];
		}
		foreach ($meta as $one)
		{
			self::assertSame(array(), array_diff(array_keys($one), array('links', 'link_domains', 'author_first_post')));
		}
		self::assertFalse($meta[0]['author_first_post']);
		self::assertTrue($meta[1]['author_first_post']);
		self::assertArrayNotHasKey('author_first_post', $meta[2], 'a guest is nobody\'s first post');
	}

	public function testTheRequestNeverCarriesAnEmailAnIpOrAUserId(): void
	{
		$this->board->posting('Wpis testowy bez danych osobowych.');

		$body = $this->gateway->requests[0]['body'];
		self::assertStringNotContainsString(Board::USER_EMAIL, $body);
		self::assertStringNotContainsString(Board::USER_IP, $body);
		$keys = array();
		$decoded = json_decode($body, true);
		array_walk_recursive($decoded, static function ($value, $key) use (&$keys) {
			$keys[] = $key;
		});
		self::assertSame(array(), array_intersect($keys, array('email', 'user_email', 'ip', 'poster_ip', 'user_id', 'poster_id', 'author_id', 'username')));
	}

	public function testTheTextIsTheFirst3000CharactersOfThePlainText(): void
	{
		$text = str_repeat('Zażółć gęślą jaźń. ', 250);
		self::assertGreaterThan(3000, mb_strlen($text));

		$this->board->posting($text);

		$sent = $this->gateway->items(0)[0]['tekst'];
		self::assertSame(3000, mb_strlen($sent));
		self::assertSame(0, strpos($text, $sent), 'the sent text is the beginning of the post');
	}

	public function testQuotesAndFormattingStayHomeAndLinksAreCounted(): void
	{
		$xml = '<r><QUOTE author="Ktoś"><s>[quote="Ktoś"]</s>Cudze słowa w cytacie.<e>[/quote]</e></QUOTE>'
			. 'Moja odpowiedź z <B><s>[b]</s>pogrubieniem<e>[/b]</e></B> i linkiem '
			. '<URL url="https://example.org/a">https://example.org/a</URL> oraz '
			. '<URL url="https://example.org/b"><s>[url=https://example.org/b]</s>drugim<e>[/url]</e></URL>.</r>';

		$this->board->posting('', 'post', array('xml' => $xml));

		$item = $this->gateway->items(0)[0];
		self::assertStringNotContainsString('Cudze słowa', $item['tekst']);
		self::assertStringNotContainsString('[b]', $item['tekst']);
		self::assertStringContainsString('pogrubieniem', $item['tekst']);
		self::assertSame(2, $item['meta']['links']);
	}

	public function testTitleAndAltTextIsAssessedWithTheRest(): void
	{
		$xml = '<r>Zobacz obrazek <IMG src="https://example.org/a.png" alt="podpis &quot;w&quot; atrybucie alt">'
			. '<s>[img]</s>https://example.org/a.png<e>[/img]</e></IMG> i <ABBR title="ukryty tytuł"><s>[abbr]</s>skrót<e>[/abbr]</e></ABBR>.</r>';

		$this->board->posting('', 'post', array('xml' => $xml));

		$text = $this->gateway->items(0)[0]['tekst'];
		self::assertStringContainsString('skrót', $text);
		self::assertStringContainsString('podpis "w" atrybucie alt', $text);
		self::assertStringContainsString('ukryty tytuł', $text);
	}

	public function testLinkDomainsAreTheRegistrableDomainsOfTheLinksAtMostTen(): void
	{
		$links = array('https://forum.example.org/a', 'http://www.example.org/b', 'https://sklep.example.co.uk/', 'https://example.com.pl/x',
			'http://192.0.2.7/ip', 'https://[2001:db8::1]/ip6', 'https://localhost/');
		for ($i = 1; $i <= 12; $i++)
		{
			$links[] = 'https://strona' . $i . '.example' . $i . '.net/';
		}
		$xml = '<r>';
		foreach ($links as $link)
		{
			$xml .= '<URL url="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</URL> ';
		}
		$xml .= '</r>';

		$this->board->posting('', 'post', array('xml' => $xml));

		$meta = $this->gateway->items(0)[0]['meta'];
		self::assertSame(count($links), $meta['links']);
		self::assertCount(10, $meta['link_domains']);
		self::assertSame(array('example.org', 'example.co.uk', 'example.com.pl', 'example1.net'), array_slice($meta['link_domains'], 0, 4),
			'one entry per registrable domain; no IP address, no single label');
		foreach ($meta['link_domains'] as $domain)
		{
			self::assertMatchesRegularExpression('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain);
		}
	}

	public function testAPostWithoutLinksSendsNoDomains(): void
	{
		$this->board->posting('Wpis bez linków.');

		self::assertSame(array('links' => 0, 'link_domains' => array()), array_intersect_key(
			$this->gateway->items(0)[0]['meta'], array('links' => 1, 'link_domains' => 1)));
	}

	public function testTheProfileSettingIsSent(): void
	{
		$this->board->configure(array(settings::PROFILE => 'forum_teen'));

		$this->board->posting('Wpis na forum młodzieżowym.');

		self::assertSame('forum_teen', $this->gateway->items(0)[0]['profil']);
	}

	public function testAnAcceptedPostWaitsForItsWebhook(): void
	{
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$row = $this->board->row($id);
		self::assertSame(pending_store::PENDING, $row['status']);
		self::assertSame('1', $row['attempts']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	/**
	 * @return array<string,array{0:int,1:string}>
	 */
	public function retryableRefusals(): array
	{
		return array(
			'the queue is full'       => array(429, 'kolejka_pelna'),
			'the key\'s minute limit' => array(429, 'limit_minutowy_klucza'),
			'the queue is down'       => array(503, 'kolejka_niedostepna'),
		);
	}

	/**
	 * @dataProvider retryableRefusals
	 */
	public function testARetryableRefusalWaitsAsLongAsTheGatewayAsks(int $status, string $code): void
	{
		$this->gateway->refuse($status, $code, 42);
		$now = time();

		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$row = $this->board->row($id);
		self::assertSame(pending_store::QUEUED, $row['status']);
		self::assertSame($code, $row['error_code']);
		self::assertEqualsWithDelta($now + 42, (int) $row['retry_at'], 2);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(array(), $this->board->log->entries, 'a busy gateway is not a configuration error');
	}

	public function testWithoutPonowZaSTheBackoffDoublesFromAMinute(): void
	{
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$now = time();
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];
		self::assertEqualsWithDelta($now + 60, (int) $this->board->row($id)['retry_at'], 2);

		$later = $now + 61;
		$this->board->submitter->submit($this->board->store->due($later, 10), $later);

		self::assertEqualsWithDelta($later + 120, (int) $this->board->row($id)['retry_at'], 2);
		self::assertSame('2', $this->board->row($id)['attempts']);
	}

	public function testNoAnswerAtAllIsRetriedLikeABusyGateway(): void
	{
		$this->gateway->script[] = null;
		$now = time();

		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		self::assertSame(pending_store::QUEUED, $this->board->row($id)['status']);
		self::assertSame(submitter::ERROR_NETWORK, $this->board->row($id)['error_code']);
		self::assertEqualsWithDelta($now + 60, (int) $this->board->row($id)['retry_at'], 2);
	}

	public function testASuccessThatIsNotTheGatewaysAcceptanceIsRetried(): void
	{
		$this->gateway->script[] = array('status' => 200, 'body' => '<html>Strona logowania</html>');

		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		self::assertSame(pending_store::QUEUED, $this->board->row($id)['status']);
		self::assertSame(submitter::ERROR_ANSWER, $this->board->row($id)['error_code']);
	}

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function configurationRefusals(): array
	{
		return array(
			'fail-open, an unknown key'     => array(settings::FAIL_OPEN, 401, 'brak_klucza'),
			'fail-open, no webhook'         => array(settings::FAIL_OPEN, 403, 'brak_webhooka'),
			'fail-closed, a foreign profile' => array(settings::FAIL_CLOSED, 403, 'profil_niedozwolony'),
			'fail-closed, B2B is off'       => array(settings::FAIL_CLOSED, 404, 'nie_znaleziono'),
		);
	}

	/**
	 * @dataProvider configurationRefusals
	 */
	public function testAConfigurationRefusalAppliesTheFailureModeAndRecordsOnlyTheCode(string $mode, int $status, string $code): void
	{
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$this->gateway->refuse($status, $code);

		$id = $this->board->posting('Zwykły testowy wpis, którego treść nie trafi do dziennika.')['post_id'];

		$row = $this->board->row($id);
		self::assertSame(pending_store::VERDICT_REFUSED, $row['verdict']);
		self::assertSame($code, $row['error_code']);
		$expected = ($mode === settings::FAIL_OPEN) ? ITEM_APPROVED : ITEM_UNAPPROVED;
		self::assertSame($expected, (int) $this->board->post($id)['post_visibility']);

		$critical = $this->board->log->of('LOG_MINOS_GATEWAY_REFUSED');
		self::assertCount(1, $critical);
		self::assertSame('critical', $critical[0]['mode']);
		self::assertSame(array($status, $code), $critical[0]['data']);
		self::assertSame(array('status' => $status, 'code' => $code), array_intersect_key(
			(array) $this->board->settings->last_error(), array('status' => 1, 'code' => 1)));

		$logged = json_encode($this->board->log->entries, JSON_UNESCAPED_UNICODE);
		self::assertStringNotContainsString(Board::KEY, $logged);
		self::assertStringNotContainsString('treść nie trafi', $logged);
	}

	public function testARedirectIsAConfigurationError(): void
	{
		$this->gateway->script[] = array('status' => 301, 'body' => '');

		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		self::assertSame(submitter::ERROR_REDIRECT, $this->board->row($id)['error_code']);
		self::assertSame(pending_store::VERDICT_REFUSED, $this->board->row($id)['verdict']);
	}

	public function testAnItemThatSpoilsABatchFailsAloneAndTheRestGoesAgain(): void
	{
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$first = $this->board->posting('Pierwszy testowy wpis.')['post_id'];
		$second = $this->board->posting('Drugi testowy wpis.')['post_id'];

		$this->gateway->refuse(400, 'bledny_identyfikator', null, 1);
		$later = time() + 61;
		$this->board->submitter->submit($this->board->store->due($later, 10), $later);

		self::assertCount(2, $this->gateway->items(2), 'the retry is one batch');
		self::assertSame(array('phpbb:' . $first), array_column($this->gateway->items(3), 'id'), 'sent again without the spoiling item');
		self::assertSame(pending_store::PENDING, $this->board->row($first)['status']);
		self::assertSame(pending_store::VERDICT_REFUSED, $this->board->row($second)['verdict']);
	}

	public function testAPostNoLongerWaitingIsNotSentAgain(): void
	{
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];
		$this->board->db->sql_query('UPDATE phpbb_posts SET post_visibility = ' . ITEM_APPROVED . ' WHERE post_id = ' . $id);

		$later = time() + 61;
		$this->board->submitter->submit($this->board->store->due($later, 10), $later);

		self::assertCount(1, $this->gateway->requests);
		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
	}
}
