<?php

namespace minos\moderation\tests\Receiver;

use minos\moderation\controller\acp;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\FormRequest;
use minos\moderation\tests\Fake\MemoryTemplate;
use minos\moderation\tests\Fake\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Nobody is notified twice: the extension notifies only for what it publishes itself,
 * once, and never for a post phpBB's own approval path (the MCP) published. The moderators'
 * e-mail about a post in the queue waits for the extension's outcome: none for a post it
 * publishes, one for a post it leaves to them.
 */
final class NotificationTest extends TestCase
{
	private const TOPIC_IN_QUEUE = 'notification.type.topic_in_queue';
	private const POST_IN_QUEUE = 'notification.type.post_in_queue';
	private const BOARD = 'notification.method.board';
	private const EMAIL = 'notification.method.email';

	/** @var Board */
	private $board;

	/** @var RecordingTransport */
	private $gateway;

	protected function setUp(): void
	{
		$this->gateway = new RecordingTransport();
		$this->board = Board::open(':memory:', $this->gateway);
	}

	public function testANewTopicsApprovalNotifiesEachKindOnce(): void
	{
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$this->safe($id);
		$this->safe($id);

		self::assertSame(array(
			'add notification.type.quote'          => 1,
			'add notification.type.topic'          => 1,
			'delete notification.type.post_in_queue'  => 1,
			'delete notification.type.topic_in_queue' => 1,
		), $this->tally());
	}

	public function testAReplysApprovalNotifiesEachKindOnce(): void
	{
		$topic = $this->board->write_post($this->board->xml('Pierwszy wpis tematu.'), ITEM_APPROVED);
		$this->board->db->sql_query('UPDATE phpbb_topics SET topic_posts_approved = 1');
		$id = $this->board->posting('Odpowiedź testowa.', 'reply', array('topic_id' => (int) $this->board->post($topic)['topic_id']))['post_id'];

		$this->safe($id);

		self::assertSame(array(
			'add notification.type.bookmark'         => 1,
			'add notification.type.forum'            => 1,
			'add notification.type.post'             => 1,
			'add notification.type.quote'            => 1,
			'delete notification.type.post_in_queue' => 1,
		), $this->tally());
	}

	public function testWhenAModeratorApprovedFirstTheExtensionNotifiesNobody(): void
	{
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];
		// The MCP approves, and phpBB notifies on that path.
		$this->board->visibility->set_post_visibility(ITEM_APPROVED, array($id), 100, Board::FORUM, 5, time(), '', true, true);

		$this->safe($id);

		self::assertSame(array(), $this->board->notifications->calls);
	}

	public function testTheAdapterNeverApprovesAPostThatIsNotInTheQueue(): void
	{
		$id = $this->board->write_post($this->board->xml('Już zatwierdzony wpis.'), ITEM_APPROVED);

		self::assertFalse($this->board->forum->approve($this->board->forum->load_post($id), time()));

		self::assertSame(array(), $this->board->notifications->calls);
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testASoftDeletionAnnouncesNothing(): void
	{
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'zablokowane'));

		foreach ($this->board->notifications->calls as $call)
		{
			self::assertSame('delete', $call[0], 'only the "in the queue" notices are withdrawn');
		}
	}

	public function testAFailOpenApprovalInThePostingRequestNotifiesOnce(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$this->gateway->refuse(401, 'brak_klucza');

		$this->board->posting('Zwykły testowy wpis.');

		self::assertSame(1, $this->tally()['add notification.type.topic']);
	}

	public function testAPostHeldAndThenPublishedSendsTheModeratorsNoEmail(): void
	{
		// Audit C-27, the other way round: phpBB e-mailed the moderators about every held post
		// at once, and the extension could only withdraw the notice from the board afterwards.
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		self::assertSame(1, $this->board->notifications->delivered(self::TOPIC_IN_QUEUE, self::BOARD), 'listed on the board meanwhile');
		self::assertSame(0, $this->board->notifications->delivered(self::TOPIC_IN_QUEUE, self::EMAIL), 'no e-mail while it is assessed');

		$this->safe($id);

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(0, $this->queue_emails(), 'published: no e-mail at all');
		self::assertContains(array('delete', self::TOPIC_IN_QUEUE, (int) $this->board->post($id)['topic_id']), $this->board->notifications->calls);
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public function postsLeftToTheModerators(): array
	{
		return array(
			'blocked, kept in the queue' => array(static function (Board $board, int $id) {
				$board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'zablokowane'));
			}),
			'unassessed, fail-closed' => array(static function (Board $board, int $id) {
				$board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'nieocenione'));
			}),
			'no verdict in time' => array(static function (Board $board, int $id) {
				$board->sweeper()->sweep(time() + 20 * 60 + 1);
			}),
			'the same verdict delivered twice' => array(static function (Board $board, int $id) {
				$board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'zablokowane'));
				$board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'zablokowane'));
			}),
			'its title changed under it: the outcome no longer applies' => array(static function (Board $board, int $id) {
				$board->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Inny tytuł' WHERE topic_first_post_id = " . $id);
				$board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne'));
			}),
		);
	}

	/**
	 * @dataProvider postsLeftToTheModerators
	 */
	public function testAPostLeftInTheQueueSendsTheModeratorsExactlyOneEmail(callable $outcome): void
	{
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];
		self::assertSame(0, $this->queue_emails());

		$outcome($this->board, $id);

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(1, $this->board->notifications->delivered(self::TOPIC_IN_QUEUE, self::EMAIL));
		self::assertSame(1, $this->queue_emails(), 'one e-mail, of one kind');
	}

	public function testAReplyWithoutTextToAssessIsAnnouncedAsAPostInTheQueue(): void
	{
		// Shared rule D9.5: a reply of only a link with a blank text is held and gets the
		// failure mode at once, without a request.
		$topic = $this->board->write_post($this->board->xml('Pierwszy wpis tematu.'), ITEM_APPROVED);
		$this->board->db->sql_query('UPDATE phpbb_topics SET topic_posts_approved = 1');
		$id = $this->board->posting('', 'reply', array('topic_id' => (int) $this->board->post($topic)['topic_id'],
			'xml' => '<r><URL url="https://kasyno.example/bonus"><s>[url=https://kasyno.example/bonus]</s> <e>[/url]</e></URL></r>'))['post_id'];
		self::assertSame(array(), $this->gateway->requests);

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(1, $this->board->notifications->delivered(self::POST_IN_QUEUE, self::EMAIL));
		self::assertSame(1, $this->queue_emails());
	}

	public function testWithoutAUsableConfigurationAHeldPostIsEmailedAtOnce(): void
	{
		$this->board->configure(array(), array(settings::API_KEY => ''));

		$this->board->posting('Zwykły testowy wpis.');

		self::assertSame(1, $this->queue_emails(), 'fail-closed at once: the moderators decide');
	}

	public function testAnEditThatTakesAPublishedPostBackSendsNoEmailUntilTheOutcome(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$id = $this->board->posting('Pierwsza wersja wpisu.')['post_id'];
		$this->board->sweeper()->sweep(time() + 20 * 60 + 1);
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility'], 'published unassessed');

		$this->board->edit($id, 'Druga wersja wpisu.');
		self::assertSame(ITEM_REAPPROVE, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(0, $this->queue_emails(), 'back in the queue while it is assessed: no e-mail');

		$this->board->deliver(array('id' => 'phpbb:' . $id . '.1', 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne'));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(0, $this->queue_emails());
	}

	public function testSwitchedOffTheModeratorsGetTheEmailOfEveryPostStillWaiting(): void
	{
		$waiting = $this->board->posting('Wpis czekający na werdykt.')['post_id'];
		$published = $this->board->posting('Wpis już opublikowany.')['post_id'];
		$this->safe($published);
		self::assertSame(0, $this->queue_emails());

		$this->save_assessment(false);

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($waiting)['post_visibility']);
		self::assertSame(1, $this->queue_emails(), 'the waiting post, not the published one');

		$this->save_assessment(true);
		$this->save_assessment(false);
		self::assertSame(1, $this->queue_emails(), 'once: phpBB remembers whom it e-mailed');
	}

	public function testAPostTheExtensionDoesNotHoldKeepsPhpbbsOwnEmail(): void
	{
		$this->board->auth->global['f_noapprove'] = false;

		$this->board->posting('Zwykły testowy wpis.');

		self::assertSame(1, $this->board->notifications->delivered(self::TOPIC_IN_QUEUE, self::EMAIL), 'phpBB\'s queue, phpBB\'s e-mail');
	}

	/**
	 * The ACP form, saved with the assessment switched on or off.
	 */
	private function save_assessment(bool $on): void
	{
		$GLOBALS['minos_test_form_key_valid'] = true;
		$controller = new acp($this->board->settings, $this->board->store, $this->board->forum, new FormRequest(array(
			'submit'            => 'Wyślij',
			'minos_enabled'     => $on ? '1' : '0',
			'minos_gateway_url' => Board::GATEWAY,
			'minos_profile'     => 'forum_adult',
			'minos_fail_mode'   => settings::FAIL_CLOSED,
			'minos_timeout_min' => '20',
			'minos_censored'    => settings::CENSORED_PUBLISH,
			'minos_blocked'     => settings::HOLD,
		)), new MemoryTemplate(), $this->board->language, $this->board->log, new \phpbb\user(), new \phpbb\controller\helper(), './', 'php');
		$controller->display('index.php?i=minos');
		unset($GLOBALS['minos_test_form_key_valid']);
		self::assertSame($on, $this->board->settings->enabled());
	}

	/**
	 * E-mails about a post or a topic in the queue.
	 */
	private function queue_emails(): int
	{
		return $this->board->notifications->delivered(self::TOPIC_IN_QUEUE, self::EMAIL)
			+ $this->board->notifications->delivered(self::POST_IN_QUEUE, self::EMAIL);
	}

	/**
	 * Notification calls by kind.
	 *
	 * @return array<string,int>
	 */
	private function tally(): array
	{
		$tally = array();
		foreach ($this->board->notifications->calls as $call)
		{
			$key = $call[0] . ' ' . $call[1];
			$tally[$key] = (isset($tally[$key]) ? $tally[$key] : 0) + 1;
		}
		ksort($tally);
		return $tally;
	}

	private function safe(int $id): void
	{
		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne')));
	}
}
