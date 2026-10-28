<?php

namespace minos\moderation\tests\Acp;

use minos\moderation\controller\acp;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\FormRequest;
use minos\moderation\tests\Fake\MemoryTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The ACP page: saving the settings, and never showing the key or the secret back.
 */
final class AcpControllerTest extends TestCase
{
	/** A key the administrator types in. */
	const NEW_KEY = 'wgb2b_nowy_klucz_forum_0123456789';

	/** A secret with characters phpBB's request escaping would change. */
	const NEW_SECRET = 'S3kret&<webhooka>"z-cudzyslowem\'';

	/** @var Board */
	private $board;

	/** @var MemoryTemplate */
	private $template;

	protected function setUp(): void
	{
		$this->board = Board::open();
		$this->template = new MemoryTemplate();
		$GLOBALS['minos_test_form_key_valid'] = true;
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['minos_test_form_key_valid']);
	}

	public function testSavingStoresEverySetting(): void
	{
		$this->submit(array(
			'minos_enabled'        => '1',
			'minos_gateway_url'    => 'https://gateway.example/',
			'minos_api_key'        => self::NEW_KEY,
			'minos_webhook_secret' => self::NEW_SECRET,
			'minos_profile'        => 'forum_teen',
			'minos_fail_mode'      => settings::FAIL_CLOSED,
			'minos_timeout_min'    => '30',
			'minos_censored'       => settings::HOLD,
			'minos_blocked'        => settings::BLOCKED_DELETE,
			'minos_forums'         => array('3'),
		));

		self::assertTrue($this->template->vars['S_MINOS_SAVED'], (string) $this->template->vars['ERROR_MSG']);
		$settings = new settings($this->board->config, $this->board->config_text);
		self::assertSame('https://gateway.example', $settings->gateway_url());
		self::assertSame(self::NEW_KEY, $settings->api_key());
		self::assertSame(self::NEW_SECRET, $settings->webhook_secret(), 'the secret is stored exactly as typed');
		self::assertSame('forum_teen', $settings->profile());
		self::assertSame(settings::FAIL_CLOSED, $settings->fail_mode());
		self::assertSame(1800, $settings->timeout_seconds());
		self::assertSame(settings::HOLD, $settings->censored_mode());
		self::assertSame(settings::BLOCKED_DELETE, $settings->blocked_mode());
		self::assertSame(array(3), $settings->forum_ids());
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SETTINGS_UPDATED'));
	}

	public function testTheChosenGroupsAreStoredAndShown(): void
	{
		$this->submit($this->form(array('minos_groups' => array((string) Board::GROUP_TRUSTED, (string) Board::GROUP_REGISTERED))));

		self::assertTrue($this->template->vars['S_MINOS_SAVED'], (string) $this->template->vars['ERROR_MSG']);
		$settings = new settings($this->board->config, $this->board->config_text);
		self::assertSame(array(Board::GROUP_TRUSTED, Board::GROUP_REGISTERED), $settings->group_ids());
		self::assertFalse($this->template->vars['S_MINOS_ALL_GROUPS']);
		$selected = array();
		foreach ($this->template->blocks['minos_groups'] as $option)
		{
			if ($option['S_SELECTED'])
			{
				$selected[] = $option['VALUE'];
			}
		}
		sort($selected);
		self::assertSame(array(Board::GROUP_REGISTERED, Board::GROUP_TRUSTED), $selected);
		self::assertCount(9, $this->template->blocks['minos_groups'], 'every group of the board');
		$labels = array_column($this->template->blocks['minos_groups'], 'LABEL', 'VALUE');
		self::assertSame(array(Board::GROUP_TEAM, Board::GROUP_TRUSTED), array_slice(array_keys($labels), -2), 'the administrator\'s groups after phpBB\'s own');
		self::assertSame($this->board->language->lang('MINOS_GROUP_OPEN', 'Stali bywalcy'), $labels[Board::GROUP_TRUSTED], 'an open group is marked');
		self::assertSame('Zespół redakcji', $labels[Board::GROUP_TEAM], 'a closed one is not');

		$this->submit($this->form(array('minos_groups' => array())));
		self::assertSame(array(), (new settings($this->board->config, $this->board->config_text))->group_ids(), 'none chosen again: every group');
	}

	public function testTheKeyAndTheSecretAreNeverShownBeyondAPrefix(): void
	{
		$this->submit($this->form(array('minos_api_key' => self::NEW_KEY, 'minos_webhook_secret' => self::NEW_SECRET)));

		$page = $this->template->everything();
		self::assertStringNotContainsString(self::NEW_KEY, $page);
		self::assertStringNotContainsString(htmlspecialchars(self::NEW_KEY), $page);
		self::assertStringNotContainsString(substr(self::NEW_SECRET, 0, 8), $page);
		self::assertStringStartsWith('wgb2b_nowy', $this->template->vars['MINOS_KEY_HINT']);
		self::assertLessThanOrEqual(settings::KEY_HINT_LENGTH + 3, strlen($this->template->vars['MINOS_KEY_HINT']));
		self::assertStringNotContainsString(self::NEW_KEY, json_encode($this->board->log->entries));
	}

	public function testEmptyKeyAndSecretFieldsKeepTheStoredOnes(): void
	{
		$this->submit($this->form(array('minos_api_key' => '', 'minos_webhook_secret' => '')));

		$settings = new settings($this->board->config, $this->board->config_text);
		self::assertSame(Board::KEY, $settings->api_key());
		self::assertSame(Board::SECRET, $settings->webhook_secret());
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function invalidForms(): array
	{
		return array(
			'plain http off the loopback' => array(array('minos_gateway_url' => 'http://gateway.example'), 'MINOS_ERROR_GATEWAY_URL'),
			'credentials in the URL'      => array(array('minos_gateway_url' => 'https://ktos:haslo@gateway.example'), 'MINOS_ERROR_GATEWAY_URL'),
			'a key of another shape'      => array(array('minos_api_key' => 'sk_live_0123456789'), 'MINOS_ERROR_API_KEY'),
			'a short secret'              => array(array('minos_webhook_secret' => 'krotki'), 'MINOS_ERROR_WEBHOOK_SECRET'),
			'an unknown profile'          => array(array('minos_profile' => 'demo_dziecko'), 'MINOS_ERROR_CHOICE'),
			'an unknown failure mode'     => array(array('minos_fail_mode' => 'fail-maybe'), 'MINOS_ERROR_CHOICE'),
			'a timeout below the TTL'     => array(array('minos_timeout_min' => '10'), 'MINOS_ERROR_TIMEOUT'),
			'a timeout under 20 minutes'  => array(array('minos_timeout_min' => '19'), 'MINOS_ERROR_TIMEOUT'),
			'a group the board has not'   => array(array('minos_groups' => array('2', '999')), 'MINOS_ERROR_GROUPS'),
		);
	}

	/**
	 * @dataProvider invalidForms
	 * @param array<string,mixed> $fields
	 */
	public function testAnInvalidFormChangesNothing(array $fields, string $error): void
	{
		$before = array($this->board->config[settings::GATEWAY_URL], $this->board->config_text->values);

		$this->submit($this->form($fields));

		self::assertFalse($this->template->vars['S_MINOS_SAVED']);
		self::assertStringContainsString($this->board->language->lang($error), $this->template->vars['ERROR_MSG']);
		self::assertSame($before, array($this->board->config[settings::GATEWAY_URL], $this->board->config_text->values));
	}

	public function testTheMockGatewayOnTheLoopbackIsAccepted(): void
	{
		$this->submit($this->form(array('minos_gateway_url' => 'http://127.0.0.1:8100')));

		self::assertTrue($this->template->vars['S_MINOS_SAVED']);
	}

	public function testAnInvalidFormKeyChangesNothing(): void
	{
		$GLOBALS['minos_test_form_key_valid'] = false;

		$this->submit($this->form(array('minos_api_key' => self::NEW_KEY)));

		self::assertFalse($this->template->vars['S_MINOS_SAVED']);
		self::assertSame(Board::KEY, $this->board->config_text->values[settings::API_KEY]);
	}

	public function testTheWebhookUrlCarriesNoSessionId(): void
	{
		$this->show(new FormRequest());

		self::assertSame('https://forum.example/app.php/minos/webhook', $this->template->vars['MINOS_WEBHOOK_URL']);
	}

	public function testASessionIdAddedByAHookIsRemovedFromTheWebhookUrl(): void
	{
		// phpBB's append_sid() hooks and $_EXTRA_URL can still add one.
		$helper = new class extends \phpbb\controller\helper {
			public function route($route, array $params = array(), $is_amp = true, $session_id = false, $reference_type = 1)
			{
				return 'https://forum.example/app.php/minos/webhook?sid=0123456789abcdef0123456789abcdef';
			}
		};

		$this->show(new FormRequest(), $helper);

		self::assertSame('https://forum.example/app.php/minos/webhook', $this->template->vars['MINOS_WEBHOOK_URL']);
	}

	public function testTheLastRefusalIsShownWithAnExplanation(): void
	{
		$this->board->settings->record_error(401, 'brak_klucza', time());

		$this->show(new FormRequest());

		self::assertTrue($this->template->vars['S_MINOS_LAST_ERROR']);
		self::assertSame('401 brak_klucza', $this->template->vars['MINOS_LAST_ERROR']);
		self::assertSame($this->board->language->lang('MINOS_REFUSAL_BRAK_KLUCZA'), $this->template->vars['MINOS_LAST_ERROR_HELP']);
	}

	public function testTheListShowsHandledPostsAndTheSupportFlag(): void
	{
		$id = $this->board->posting('Testowy wpis o bardzo złym dniu.')['post_id'];
		$this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'zablokowane',
			'kategorie' => array('samookaleczenie'), 'wsparcie' => true));

		$this->show(new FormRequest());

		$row = $this->template->blocks['minos_rows'][0];
		self::assertSame($id, $row['POST_ID']);
		self::assertTrue($row['S_SUPPORT']);
		self::assertTrue($row['S_HELD']);
		self::assertSame('zablokowane', $row['VERDICT']);
		self::assertStringContainsString('mode=approve_details', $row['U_MCP']);
	}

	public function testAMaskedTextLeftToAModeratorIsShownInTheList(): void
	{
		$id = $this->board->posting('To jest brzydkie słowo.')['post_id'];
		$this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'ocenzurowane',
			'ocenzurowany' => 'To jest <███> słowo.'));

		$this->show(new FormRequest());

		$row = $this->template->blocks['minos_rows'][0];
		self::assertTrue($row['S_MASK_MANUAL']);
		self::assertSame('To jest &lt;███&gt; słowo.', $row['MASKED'], 'escaped for the page');
	}

	public function testAFailOpenApprovalIsShownAsSuch(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$this->board->posting('Zwykły testowy wpis.');
		$this->board->sweeper()->sweep(time() + 20 * 60 + 1);

		$this->show(new FormRequest());

		self::assertSame('Opublikowany bez oceny (fail-open)', $this->template->blocks['minos_rows'][0]['STATUS']);
	}

	public function testASpentDailyLimitIsShownWithItsHourUntilTheNextAcceptance(): void
	{
		// A day's wait for a verdict: the post outlasts the limit and is sent again after it.
		$this->board->configure(array(settings::TIMEOUT_MIN => 1440));
		$this->board->transport->refuse(429, 'limit_dobowy_klucza', 36000);
		$until = time() + 36000;
		$this->board->posting('Wpis po wyczerpaniu limitu dobowego.');

		$this->show(new FormRequest());

		self::assertTrue($this->template->vars['S_MINOS_PAUSE']);
		$message = $this->template->vars['MINOS_PAUSE'];
		self::assertStringContainsString('limit', $message);
		self::assertTrue(strpos($message, gmdate('H:i', $until)) !== false || strpos($message, gmdate('H:i', $until + 1)) !== false,
			'the hour the gateway accepts posts again');
		foreach (array(Board::KEY, 'Odmowa (test).', 'Wpis po wyczerpaniu', '36000') as $never)
		{
			self::assertStringNotContainsString($never, $message, 'never the key, the request or the gateway\'s body');
		}

		// The limit renews: the cron task sends the post again, and the gateway takes it.
		$this->board->sweeper()->sweep($until + 1);
		self::assertCount(2, $this->board->transport->requests);
		$this->template = new MemoryTemplate();
		$this->show(new FormRequest());

		self::assertFalse($this->template->vars['S_MINOS_PAUSE'], 'the first 202 clears it');
		self::assertSame('', $this->template->vars['MINOS_PAUSE']);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function failureModes(): array
	{
		return array('fail-closed' => array(settings::FAIL_CLOSED), 'fail-open' => array(settings::FAIL_OPEN));
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testSavingTheSettingsEndsThePauseSoANewKeyIsTriedAtOnce(string $mode): void
	{
		// The free key's daily limit is spent: the gateway says come back in 10 hours. The
		// administrator enters a paid key; without this every post would get the failure mode
		// (fail-open: published unassessed) until then.
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$this->board->transport->refuse(429, 'limit_dobowy_klucza', 36000);
		$this->board->posting('Wpis, który wyczerpał limit dobowy.');
		self::assertNotNull($this->board->settings->pause(time()));

		$this->submit($this->form(array('minos_api_key' => self::NEW_KEY, 'minos_fail_mode' => $mode)));

		self::assertTrue($this->template->vars['S_MINOS_SAVED']);
		self::assertFalse($this->template->vars['S_MINOS_PAUSE'], 'the page no longer shows it');
		$id = $this->board->posting('Wpis po zmianie klucza.')['post_id'];
		self::assertCount(2, $this->board->transport->requests, 'the next post is sent');
		self::assertSame(self::NEW_KEY, $this->board->transport->requests[1]['headers']['X-Gateway-Key']);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'held for its verdict, never published unassessed');
	}

	public function testAnOverloadedGatewayIsShownAsSuch(): void
	{
		$this->board->transport->refuse(429, 'limit_globalny_b2b', 600);
		$this->board->posting('Wpis w godzinie przeciążenia.');

		$this->show(new FormRequest());

		self::assertTrue($this->template->vars['S_MINOS_PAUSE']);
		self::assertSame($this->board->language->lang('MINOS_PAUSE_OVERLOAD', gmdate('H:i', $this->board->settings->pause(time())['until'])),
			$this->template->vars['MINOS_PAUSE']);
	}

	/**
	 * @return array<string,array{0:int,1:string,2:string}>
	 */
	public function shortPauses(): array
	{
		return array(
			'the key\'s minute limit'  => array(429, 'limit_minutowy_klucza', 'MINOS_PAUSE_KEY'),
			'an unavailable gateway'   => array(503, 'kolejka_niedostepna', 'MINOS_PAUSE_UNAVAILABLE'),
		);
	}

	/**
	 * @dataProvider shortPauses
	 */
	public function testAShortPauseIsShownWithItsHour(int $status, string $code, string $key): void
	{
		$this->board->transport->refuse($status, $code, 90);
		$this->board->posting('Wpis w czasie przerwy.');

		$this->show(new FormRequest());

		self::assertTrue($this->template->vars['S_MINOS_PAUSE']);
		self::assertSame($this->board->language->lang($key, gmdate('H:i', $this->board->settings->pause(time())['until'])),
			$this->template->vars['MINOS_PAUSE']);
	}

	public function testSwitchedOnWithoutAKeyThePageSaysWhatIsMissing(): void
	{
		$this->board->configure(array(), array(settings::API_KEY => ''));

		$this->show(new FormRequest());

		self::assertFalse($this->template->vars['S_MINOS_READY']);
		self::assertTrue($this->template->vars['S_MINOS_ENABLED']);
		self::assertStringContainsString($this->board->language->lang('MINOS_PROBLEM_API_KEY'), $this->template->vars['MINOS_NOT_READY']);
		self::assertStringNotContainsString($this->board->language->lang('MINOS_PROBLEM_WEBHOOK_SECRET'), $this->template->vars['MINOS_NOT_READY']);
		self::assertStringNotContainsString(Board::SECRET, $this->template->everything());
	}

	public function testSwitchingOnAgainSendsWhatWaitedWithAFreshTimeout(): void
	{
		// Shared rule D9.13: a post that waited while the extension was off used to get the
		// failure mode in the first run after it was switched on again.
		$id = $this->board->posting('Wpis czekający na werdykt.')['post_id'];
		$this->submit($this->form(array('minos_enabled' => '0')));
		self::assertFalse($this->board->settings->enabled());
		// An hour passes; the gateway gives up on the delivery the webhook refused.
		$this->board->db->sql_query('UPDATE phpbb_minos_pending SET submitted_at = submitted_at - 3600 WHERE post_id = ' . $id);

		$this->submit($this->form(array('minos_enabled' => '1')));

		$row = $this->board->row($id);
		self::assertSame(array('1', pending_store::QUEUED), array($row['revision'], $row['status']));
		self::assertEqualsWithDelta(time(), (int) $row['submitted_at'], 2, 'a fresh time-out');
		$this->board->sweeper()->sweep(time());
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status'], 'sent again, not timed out');
		self::assertSame('phpbb:' . $id . '.1', $this->board->transport->items(1)[0]['id']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testSavingWhileSwitchedOnRestartsNothing(): void
	{
		$id = $this->board->posting('Wpis czekający na werdykt.')['post_id'];

		$this->submit($this->form(array('minos_enabled' => '1')));

		self::assertSame(array('0', pending_store::PENDING), array($this->board->row($id)['revision'], $this->board->row($id)['status']));
	}

	/**
	 * A complete, valid form with some fields replaced.
	 *
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private function form(array $fields): array
	{
		return $fields + array(
			'minos_enabled'        => '1',
			'minos_gateway_url'    => Board::GATEWAY,
			'minos_api_key'        => '',
			'minos_webhook_secret' => '',
			'minos_profile'        => 'forum_adult',
			'minos_fail_mode'      => settings::FAIL_OPEN,
			'minos_timeout_min'    => '20',
			'minos_censored'       => settings::CENSORED_PUBLISH,
			'minos_blocked'        => settings::HOLD,
		);
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	private function submit(array $fields): void
	{
		$this->show(new FormRequest($fields + array('submit' => 'Wyślij')));
	}

	private function show(FormRequest $request, ?\phpbb\controller\helper $helper = null): void
	{
		$controller = new acp($this->board->settings, $this->board->store, $this->board->forum, $request, $this->template, $this->board->language,
			$this->board->log, new \phpbb\user(), $helper ?: new \phpbb\controller\helper(), './', 'php');
		$controller->display('index.php?i=minos');
	}
}
