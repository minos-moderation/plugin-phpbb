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
		self::assertSame($this->board->title($id) . "\n\nZwykły testowy wpis.", $item['tekst'], 'a new topic: its title, a blank line, its text');
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

		$id = $this->board->posting($text)['post_id'];

		$sent = $this->gateway->items(0)[0]['tekst'];
		self::assertSame(3000, mb_strlen($sent), 'the title counts within the 3000 characters');
		$title = $this->board->title($id) . "\n\n";
		self::assertSame(0, strpos($sent, $title));
		self::assertSame(0, strpos($text, substr($sent, strlen($title))), 'then the beginning of the post');
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function quotes(): array
	{
		return array(
			'a reply with a quote' => array(
				'<r>Zgadzam się.<QUOTE author="Kasia"><s>[quote=Kasia]</s>TRESC<e>[/quote]</e></QUOTE></r>',
				"Zgadzam się.\nKasia napisał(a):\nTRESC",
			),
			'only a quote, no author' => array('<r><QUOTE><s>[quote]</s>OBELGA<e>[/quote]</e></QUOTE></r>', 'OBELGA'),
			'nested quotes' => array(
				'<r><QUOTE author="Ala"><s>[quote=Ala]</s><QUOTE author="Ola"><s>[quote=Ola]</s>środek<e>[/quote]</e></QUOTE>zewnątrz<e>[/quote]</e></QUOTE>ja</r>',
				"Ala napisał(a):\nOla napisał(a):\nśrodek\nzewnątrz\nja",
			),
		);
	}

	/**
	 * @dataProvider quotes
	 */
	public function testAQuoteIsSentWithItsAuthorAndAPostOfOnlyAQuoteIsHeld(string $xml, string $sent): void
	{
		$posted = $this->board->posting('', 'reply', array('xml' => $xml, 'topic_id' => $this->topic()));

		self::assertSame(ITEM_UNAPPROVED, $posted['visibility'], 'wrapping words in a quote does not publish them');
		self::assertSame($sent, $this->gateway->items(0)[0]['tekst'], 'a reply under the default subject: its text alone');
	}

	public function testFormattingStaysHomeAndLinksAreCounted(): void
	{
		$xml = '<r><QUOTE author="Ktoś"><s>[quote="Ktoś"]</s>Cudze słowa w cytacie.<e>[/quote]</e></QUOTE>'
			. 'Moja odpowiedź z <B><s>[b]</s>pogrubieniem<e>[/b]</e></B> i linkiem '
			. '<URL url="https://example.org/a">https://example.org/a</URL> oraz '
			. '<URL url="https://example.org/b"><s>[url=https://example.org/b]</s>drugim<e>[/url]</e></URL>.</r>';

		$this->board->posting('', 'post', array('xml' => $xml));

		$item = $this->gateway->items(0)[0];
		self::assertStringContainsString("Ktoś napisał(a):\nCudze słowa w cytacie.", $item['tekst']);
		self::assertStringNotContainsString('[b]', $item['tekst']);
		self::assertStringNotContainsString('[quote', $item['tekst']);
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

	/**
	 * @return array<string,array{0:int,1:string|null}>
	 */
	public function successesThatAreNotTheGatewaysAcceptance(): array
	{
		// The body: null for one naming every item sent in `przyjete`, as an acceptance would.
		return array(
			'a proxy\'s login page (200)'         => array(200, '<html>Strona logowania</html>'),
			'a 200 whose przyjete names the post' => array(200, null),
			'a 201 whose przyjete names the post' => array(201, null),
			'a 204 without a body'                => array(204, ''),
		);
	}

	/**
	 * @dataProvider successesThatAreNotTheGatewaysAcceptance
	 */
	public function testOnlyA202AcceptsAnyOther2xxIsRetriedWithAPause(int $status, ?string $body): void
	{
		// The plugins' shared rule D9.6 (F5a): never a configuration error, never an acceptance.
		$this->gateway->script[] = function (array $ids) use ($status, $body) {
			return array('status' => $status, 'body' => ($body !== null) ? $body : json_encode(array('przyjete' => $ids)));
		};
		$now = time();

		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$row = $this->board->row($id);
		self::assertSame(array(pending_store::QUEUED, 'http_' . $status, ''), array($row['status'], $row['error_code'], $row['verdict']));
		self::assertEqualsWithDelta($now + 60, (int) $row['retry_at'], 2, 'the backoff\'s first minute');
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertNull($this->board->settings->last_error(), 'not a configuration error');
		self::assertSame(array(), $this->board->log->entries);
		$pause = $this->board->settings->pause($now);
		self::assertSame(array(settings::PAUSE_FOREIGN, 'http_' . $status), array($pause['kind'], $pause['code']), 'a pause, as after a 503');
		self::assertEqualsWithDelta($now + 60, $pause['until'], 2);

		// After the pause the cron task sends it again, and the gateway's 202 takes it.
		$this->board->sweeper()->sweep($now + 61);
		self::assertCount(2, $this->gateway->requests);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		self::assertNull($this->board->settings->pause($now + 61));
	}

	public function testA408IsANetworkFailureRetriedWithAPause(): void
	{
		// The plugins' shared rule D9.6 (F5a): 408 counts as no answer, not as a configuration error.
		$this->gateway->script[] = array('status' => 408, 'body' => '<html>Request Timeout</html>');
		$now = time();

		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$row = $this->board->row($id);
		self::assertSame(array(pending_store::QUEUED, 'http_408', ''), array($row['status'], $row['error_code'], $row['verdict']));
		self::assertEqualsWithDelta($now + 60, (int) $row['retry_at'], 2);
		self::assertNull($this->board->settings->last_error(), 'not a configuration error');
		self::assertSame(array(), $this->board->log->entries);
		self::assertSame(settings::PAUSE_UNAVAILABLE, $this->board->settings->pause($now)['kind']);
	}

	public function testA202AcceptsTheItemsItNamesAndTheRestIsRetried(): void
	{
		// Three posts queued behind a pause go in one batch; the 202 names two of them.
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$now = time();
		$ids = array();
		foreach (array('Pierwszy', 'Drugi', 'Trzeci') as $word)
		{
			$ids[] = $this->board->posting($word . ' testowy wpis.')['post_id'];
		}
		$left_out = 'phpbb:' . $ids[1];
		$this->gateway->script[] = function (array $sent) use ($left_out) {
			return array('status' => 202, 'body' => json_encode(array('przyjete' => array_values(array_diff($sent, array($left_out))))));
		};

		$this->board->sweeper()->sweep($now + 61);

		self::assertCount(3, $this->gateway->items(1), 'one batch');
		self::assertSame(array(pending_store::PENDING, pending_store::QUEUED, pending_store::PENDING), array(
			$this->board->row($ids[0])['status'], $this->board->row($ids[1])['status'], $this->board->row($ids[2])['status']));
		self::assertSame(submitter::ERROR_ANSWER, $this->board->row($ids[1])['error_code']);
		self::assertEqualsWithDelta($now + 61 + 60, (int) $this->board->row($ids[1])['retry_at'], 2, 'its first attempt: the backoff\'s first minute');
		self::assertNull($this->board->settings->pause($now + 61), 'the 202 ends the pause');

		// The one left out goes again alone.
		$this->board->sweeper()->sweep($now + 61 + 61);
		self::assertSame(array('phpbb:' . $ids[1]), array_column($this->gateway->items(2), 'id'));
		self::assertSame(pending_store::PENDING, $this->board->row($ids[1])['status']);
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
		// The second post is not sent: the first one's 503 paused sending.
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$first = $this->board->posting('Pierwszy testowy wpis.')['post_id'];
		$second = $this->board->posting('Drugi testowy wpis.')['post_id'];

		$this->gateway->refuse(400, 'bledny_identyfikator', null, 0);
		$later = time() + 61;
		$this->board->submitter->submit($this->board->store->due($later, 10), $later);

		$batch = array_column($this->gateway->items(1), 'id');
		sort($batch);
		self::assertSame(array('phpbb:' . $first, 'phpbb:' . $second), $batch, 'the retry is one batch');
		$spoiling = $this->gateway->items(1)[0]['id'];
		$other = $this->gateway->items(1)[1]['id'];
		self::assertSame(array($other), array_column($this->gateway->items(2), 'id'), 'sent again without the spoiling item');
		self::assertSame(pending_store::PENDING, $this->board->row(submitter::parse_item_id($other)[0])['status']);
		self::assertSame(pending_store::VERDICT_REFUSED, $this->board->row(submitter::parse_item_id($spoiling)[0])['verdict']);
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

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function failureModes(): array
	{
		return array(
			'fail-closed' => array(settings::FAIL_CLOSED, ITEM_UNAPPROVED, pending_store::HELD),
			'fail-open'   => array(settings::FAIL_OPEN, ITEM_APPROVED, pending_store::PUBLISHED),
		);
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testASpentDailyLimitAppliesTheFailureModeAtOnceAndIsRemembered(string $mode, int $visibility, string $status): void
	{
		// Audit probes B R3/P4, the other way round: the post used to wait 20 minutes for a
		// retry the gateway had already refused until the end of the day.
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$this->gateway->refuse(429, 'limit_dobowy_klucza', 36000);
		$now = time();

		$id = $this->board->posting('Wpis po wyczerpaniu limitu dobowego.')['post_id'];

		$row = $this->board->row($id);
		self::assertSame(array($status, pending_store::VERDICT_TIMEOUT, 'limit_dobowy_klucza'), array($row['status'], $row['verdict'], $row['error_code']));
		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		$pause = $this->board->settings->pause($now);
		self::assertSame(settings::PAUSE_LIMIT, $pause['kind']);
		self::assertEqualsWithDelta($now + 36000, $pause['until'], 2, 'until the end of the refusal, as the gateway gave it');

		$this->board->sweeper()->sweep($now + 19 * 60);
		$this->board->sweeper()->sweep($now + 20 * 60 + 1);
		self::assertCount(1, $this->gateway->requests, 'not retried after 19 minutes');
		self::assertSame($status, $this->board->row($id)['status']);
	}

	/**
	 * @return array<string,array{0:int,1:int,2:bool}>
	 */
	public function refusalsAgainstTheTimeout(): array
	{
		return array(
			'ending before the time-out'       => array(20, 19 * 60, false),
			'ending after the time-out'        => array(20, 20 * 60 + 1, true),
			'longer than any retry pause, after a one-day time-out' => array(1440, submitter::MAX_RETRY_AFTER_S + 1, true),
		);
	}

	/**
	 * @dataProvider refusalsAgainstTheTimeout
	 */
	public function testARefusalEndingAfterThePostsTimeoutAppliesTheFailureModeAtOnce(int $timeout_min, int $retry_after, bool $at_once): void
	{
		$this->board->configure(array(settings::TIMEOUT_MIN => $timeout_min));
		$this->gateway->refuse(503, 'kolejka_niedostepna', $retry_after);

		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		self::assertSame($at_once ? pending_store::HELD : pending_store::QUEUED, $this->board->row($id)['status']);
		self::assertSame($at_once ? pending_store::VERDICT_TIMEOUT : '', $this->board->row($id)['verdict']);
	}

	public function testAnOverloadIsRememberedUntilTheGatewayAcceptsAgain(): void
	{
		$this->gateway->refuse(429, 'kolejka_pelna', 120);
		$now = time();
		$id = $this->board->posting('Wpis przy pełnej kolejce.')['post_id'];
		self::assertSame(pending_store::QUEUED, $this->board->row($id)['status'], 'retried: the queue frees up before the time-out');
		$pause = $this->board->settings->pause($now);
		self::assertSame(settings::PAUSE_OVERLOAD, $pause['kind']);
		self::assertEqualsWithDelta($now + 120, $pause['until'], 2);

		$this->board->submitter->submit($this->board->store->due($now + 121, 10), $now + 121);

		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		self::assertNull($this->board->settings->pause($now), 'the first 202 clears it');
	}

	public function testThreePostsInAMinuteAfterTheKeysMinuteLimitMakeOneRefusedRequest(): void
	{
		// Audit W-2 / C-22, the other way round: every post of the minute used to be sent and
		// refused, each refusal spending the key's allowance.
		$this->gateway->refuse(429, 'limit_minutowy_klucza', 60);
		$now = time();

		$ids = array();
		foreach (array('Pierwszy', 'Drugi', 'Trzeci') as $word)
		{
			$ids[] = $this->board->posting($word . ' wpis w tej samej minucie.')['post_id'];
		}

		self::assertCount(1, $this->gateway->requests, 'one refused request, not three');
		foreach ($ids as $id)
		{
			self::assertSame(pending_store::QUEUED, $this->board->row($id)['status'], 'held for the cron task');
			self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		}
		self::assertSame(array('0', '0'), array($this->board->row($ids[1])['attempts'], $this->board->row($ids[2])['attempts']),
			'the others were never sent');
		self::assertSame(settings::PAUSE_KEY, $this->board->settings->pause($now)['kind']);

		$this->board->sweeper()->sweep($now + 61);

		self::assertCount(2, $this->gateway->requests);
		self::assertCount(3, $this->gateway->items(1), 'after the pause, the three go in one batch');
		foreach ($ids as $id)
		{
			self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		}
		self::assertNull($this->board->settings->pause($now), 'the 202 ends the pause');
	}

	/**
	 * @return array<string,array{0:int|null,1:string,2:string|null}>
	 */
	public function answersThatPause(): array
	{
		return array(
			'the key\'s minute limit'    => array(429, 'limit_minutowy_klucza', settings::PAUSE_KEY),
			'the key\'s in-flight limit' => array(429, 'limit_w_locie_klucza', settings::PAUSE_KEY),
			'a 429 of another code'      => array(429, 'zbyt_wiele', settings::PAUSE_KEY),
			'the key\'s daily limit'     => array(429, 'limit_dobowy_klucza', settings::PAUSE_LIMIT),
			'the shared B2B ceiling'     => array(429, 'limit_globalny_b2b', settings::PAUSE_OVERLOAD),
			'a full queue'               => array(429, 'kolejka_pelna', settings::PAUSE_OVERLOAD),
			'a 503'                      => array(503, 'kolejka_niedostepna', settings::PAUSE_UNAVAILABLE),
			'no answer at all'           => array(null, 'network', settings::PAUSE_UNAVAILABLE),
			'a 408'                      => array(408, 'http_408', settings::PAUSE_UNAVAILABLE),
			'a 2xx that is not a 202'    => array(200, 'http_200', settings::PAUSE_FOREIGN),
			'a 500 does not pause'       => array(500, 'blad_wewnetrzny', null),
		);
	}

	/**
	 * @dataProvider answersThatPause
	 */
	public function testAnAnswerThatAsksToWaitPausesAllSending(?int $status, string $code, ?string $kind): void
	{
		if ($status === null)
		{
			$this->gateway->script[] = null;
		}
		else if ($status === 200 || $status === 408)
		{
			$this->gateway->script[] = array('status' => $status, 'body' => '{}');
		}
		else
		{
			$this->gateway->refuse($status, $code);
		}
		$now = time();
		$this->board->posting('Wpis, na który brama odpowiada.');
		$this->board->posting('Kolejny wpis tuż potem.');

		$pause = $this->board->settings->pause($now);
		if ($kind === null)
		{
			self::assertNull($pause);
			self::assertCount(2, $this->gateway->requests, 'the next post is sent as usual');
			return;
		}
		self::assertSame(array('kind' => $kind, 'code' => $code), array('kind' => $pause['kind'], 'code' => $pause['code']));
		self::assertEqualsWithDelta($now + 60, $pause['until'], 2, 'without ponow_za_s, a minute');
		self::assertCount(1, $this->gateway->requests, 'nothing else is sent while it lasts');
	}

	public function testAPostWhoseWaitEndsBeforeThePauseGetsTheFailureModeAtOnceUnsent(): void
	{
		$this->gateway->refuse(429, 'limit_dobowy_klucza', 36000);
		$this->board->posting('Wpis, który wyczerpał limit dobowy.');

		$id = $this->board->posting('Wpis po wyczerpaniu limitu.')['post_id'];

		self::assertCount(1, $this->gateway->requests, 'the second post is not sent to be refused again');
		$row = $this->board->row($id);
		self::assertSame(array(pending_store::HELD, pending_store::VERDICT_TIMEOUT, 'limit_dobowy_klucza', '0'),
			array($row['status'], $row['verdict'], $row['error_code'], $row['attempts']));
	}

	public function testAPostWhoseWaitOutlastsThePauseWaitsForTheCronTask(): void
	{
		$this->board->configure(array(settings::TIMEOUT_MIN => 1440));
		$this->gateway->refuse(429, 'limit_dobowy_klucza', 36000);
		$now = time();
		$this->board->posting('Wpis, który wyczerpał limit dobowy.');
		$id = $this->board->posting('Wpis z dobą na werdykt.')['post_id'];

		self::assertSame(pending_store::QUEUED, $this->board->row($id)['status']);
		$this->board->sweeper()->sweep($now + 35000);
		self::assertCount(1, $this->gateway->requests, 'the cron task sends nothing during the pause');

		$this->board->sweeper()->sweep($now + 36001);
		self::assertCount(2, $this->gateway->requests);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
	}

	public function testANewTopicIsSentWithItsTitleFirst(): void
	{
		// Audit probe B P1, the other way round: the title used to stay home, so an offensive
		// title over a neutral text was published as `bezpieczne`.
		$this->board->posting('Neutralna treść pierwszego postu.', 'post', array('title' => 'Obraźliwy tytuł tematu'));

		self::assertSame("Obraźliwy tytuł tematu\n\nNeutralna treść pierwszego postu.", $this->gateway->items(0)[0]['tekst']);
	}

	public function testTheTitleIsSentAsAReaderSeesIt(): void
	{
		// phpBB stores a subject HTML-escaped, a character beyond the BMP as a numeric entity.
		$this->board->posting('Treść.', 'post', array('title' => 'Kot &amp; pies &quot;razem&quot; &lt;3 &#128512; &amp;amp;'));

		self::assertSame("Kot & pies \"razem\" <3 \u{1F600} &amp;\n\nTreść.", $this->gateway->items(0)[0]['tekst']);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function replySubjects(): array
	{
		return array(
			'phpBB\'s default subject' => array('Wątek o pogodzie', 'Re: Wątek o pogodzie', 'Treść odpowiedzi.'),
			'the default under a title starting with "Re: "' => array('Re: pogoda', 'Re: pogoda', 'Treść odpowiedzi.'),
			'the topic\'s title without "Re: "' => array('Wątek o pogodzie', 'Wątek o pogodzie', 'Treść odpowiedzi.'),
			'a subject the author wrote' => array('Wątek o pogodzie', 'Własny temat odpowiedzi', "Własny temat odpowiedzi\n\nTreść odpowiedzi."),
		);
	}

	/**
	 * @dataProvider replySubjects
	 */
	public function testAReplyCarriesOnlyASubjectItsAuthorWrote(string $title, string $subject, string $sent): void
	{
		$topic = $this->topic($title);

		$this->board->posting('Treść odpowiedzi.', 'reply', array('topic_id' => $topic, 'subject' => $subject));

		self::assertSame($sent, $this->gateway->items(0)[0]['tekst']);
	}

	public function testATopicsPollIsSentWithItsQuestionAndOptions(): void
	{
		// Shared rule D9.1: a poll's question and options are part of the text of the post
		// that carries the poll; a word in an option must not pass unassessed.
		$this->board->posting('Treść pierwszego postu.', 'post', array('title' => 'Temat z ankietą',
			'poll' => array('question' => 'Co wybierasz?', 'options' => array('Opcja pierwsza', 'Obraźliwa opcja druga'))));

		self::assertSame("Temat z ankietą\n\nCo wybierasz?\nOpcja pierwsza\nObraźliwa opcja druga\n\nTreść pierwszego postu.",
			$this->gateway->items(0)[0]['tekst']);
	}

	public function testAPollChangedWhileThePostWaitsIsSentAsANewRevision(): void
	{
		$id = $this->board->posting('Treść pierwszego postu.', 'post', array('title' => 'Temat z ankietą',
			'poll' => array('question' => 'Co wybierasz?', 'options' => array('Tak', 'Nie'))))['post_id'];

		// The author edits the first post: same text, a changed poll.
		$this->board->edit($id, 'Treść pierwszego postu.', null, array('question' => 'Co wybierasz?', 'options' => array('Tak', 'Nie', 'Zmieniona opcja')));

		self::assertCount(2, $this->gateway->requests);
		$item = $this->gateway->items(1)[0];
		self::assertSame('phpbb:' . $id . '.1', $item['id']);
		self::assertStringContainsString("Zmieniona opcja", $item['tekst']);
		self::assertSame(md5($item['tekst']), $this->board->row($id)['sent_md5'], 'the digest covers the poll');
	}

	public function testAPollIsTextToAssessEvenOverABlankPost(): void
	{
		$this->board->posting('', 'post', array('title' => 'Temat z ankietą', 'xml' => '<r><B><s>[b]</s> <e>[/b]</e></B></r>',
			'poll' => array('question' => 'Co wybierasz?', 'options' => array('Tak', 'Nie'))));

		self::assertSame("Temat z ankietą\n\nCo wybierasz?\nTak\nNie", $this->gateway->items(0)[0]['tekst'], 'the poll is sent, the title with it');
	}

	public function testAReplyInATopicWithAPollCarriesNoPoll(): void
	{
		$first = $this->board->posting('Treść pierwszego postu.', 'post', array('title' => 'Temat z ankietą',
			'poll' => array('question' => 'Co wybierasz?', 'options' => array('Tak', 'Nie'))))['post_id'];

		$this->board->posting('Odpowiedź w temacie.', 'reply', array('topic_id' => (int) $this->board->post($first)['topic_id']));

		self::assertSame('Odpowiedź w temacie.', $this->gateway->items(1)[0]['tekst']);
	}

	public function testAPollChangedWhileThePostWaitsIsNoLongerTheTextAVerdictIsFor(): void
	{
		$id = $this->board->posting('Treść pierwszego postu.', 'post', array('title' => 'Temat z ankietą',
			'poll' => array('question' => 'Co wybierasz?', 'options' => array('Tak', 'Nie'))))['post_id'];
		// Changed outside the edit flow (not through the posting form).
		$this->board->store_poll((int) $this->board->post($id)['topic_id'], 'Co wybierasz?', array('Tak', 'Nie', 'Dopisana opcja'));

		$this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne'));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'never published for a poll the gateway did not see');
		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
	}

	public function testARetryReadsTheTitleFromTheTopic(): void
	{
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$now = time();
		$id = $this->board->posting('Treść czekająca na ponowienie.', 'post', array('title' => 'Pierwszy tytuł'))['post_id'];
		$this->board->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Tytuł zmieniony w czasie oczekiwania'
			WHERE topic_first_post_id = " . $id);

		$this->board->submitter->submit($this->board->store->due($now + 61, 10), $now + 61);

		self::assertSame("Tytuł zmieniony w czasie oczekiwania\n\nTreść czekająca na ponowienie.", $this->gateway->items(1)[0]['tekst']);
		self::assertSame(md5($this->gateway->items(1)[0]['tekst']), $this->board->row($id)['sent_md5'], 'the digest covers the title');
	}

	/**
	 * An approved topic by someone the extension does not hold, for replies.
	 *
	 * @param string $title The topic's title, as stored.
	 * @return int The topic.
	 */
	private function topic(string $title = 'Wątek testowy'): int
	{
		$first = $this->board->write_post($this->board->xml('Pierwszy wpis tematu.'), ITEM_APPROVED, array('title' => $title));
		return (int) $this->board->post($first)['topic_id'];
	}
}
