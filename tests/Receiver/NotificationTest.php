<?php

namespace minos\moderation\tests\Receiver;

use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Nobody is notified twice: the extension notifies only for what it publishes itself,
 * once, and never for a post phpBB's own approval path (the MCP) published.
 */
final class NotificationTest extends TestCase
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
