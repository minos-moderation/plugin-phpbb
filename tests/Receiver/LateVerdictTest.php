<?php

namespace minos\moderation\tests\Receiver;

use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * A verdict that arrives after the timeout: applied to what the failure mode did, unless
 * someone changed the post since.
 */
final class LateVerdictTest extends TestCase
{
	/** @var Board */
	private $board;

	/** @var RecordingTransport */
	private $gateway;

	/** @var int The moment of the time-out. */
	private $timeout;

	protected function setUp(): void
	{
		$this->gateway = new RecordingTransport();
		$this->board = Board::open(':memory:', $this->gateway);
		$this->timeout = time() + 20 * 60 + 1;
	}

	public function testTheFailOpenModesOwnApprovalIsMarked(): void
	{
		$id = $this->published_by_fail_open();

		$row = $this->board->row($id);
		self::assertSame(pending_store::PUBLISHED, $row['status']);
		self::assertSame((string) $this->timeout, $row['approved_at']);
		self::assertSame(md5($this->board->post($id)['post_text']), $row['approved_md5']);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_PUBLISHED_FAIL_OPEN'));
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_POST_PUBLISHED'), 'not logged as a verdict');
	}

	public function testALateBlockTakesThePostBackToTheQueue(): void
	{
		$id = $this->published_by_fail_open();
		$notified = count($this->board->notifications->calls);

		self::assertSame(200, $this->late($id, 'zablokowane'));

		self::assertSame(ITEM_REAPPROVE, (int) $this->board->post($id)['post_visibility']);
		$row = $this->board->row($id);
		self::assertSame(pending_store::HELD, $row['status']);
		self::assertSame('0', $row['approved_at']);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_RETURNED'));
		self::assertSame(array(array('add', 'notification.type.topic_in_queue', $id)),
			array_slice($this->board->notifications->calls, $notified), 'the moderators are told, once');
	}

	public function testALateBlockDeletesThePostWhenTheSettingSaysSo(): void
	{
		$id = $this->published_by_fail_open();
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$calls = count($this->board->visibility->calls);

		$this->late($id, 'zablokowane');

		self::assertSame(ITEM_DELETED, (int) $this->board->post($id)['post_visibility']);
		$changes = array_slice($this->board->visibility->calls, $calls);
		self::assertCount(1, $changes, 'an approved post is deleted directly');
		self::assertSame(array($id => ITEM_APPROVED), $changes[0]['before']);
	}

	public function testALateSafeVerdictConfirmsThePostWithoutNotifyingAgain(): void
	{
		$id = $this->published_by_fail_open();
		$notified = count($this->board->notifications->calls);
		$calls = count($this->board->visibility->calls);

		$this->late($id, 'bezpieczne');

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::PUBLISHED, $this->board->row($id)['status']);
		self::assertSame('0', $this->board->row($id)['approved_at'], 'no longer a fail-open approval');
		self::assertCount($notified, $this->board->notifications->calls);
		self::assertCount($calls, $this->board->visibility->calls);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_CONFIRMED'));
	}

	public function testALateCensoredVerdictMasksThePublishedPost(): void
	{
		$this->board->configure(array(settings::CENSORED => settings::CENSORED_PUBLISH));
		$id = $this->published_by_fail_open('To jest brzydkie słowo w teście.');
		$calls = count($this->board->visibility->calls);
		$notified = count($this->board->notifications->calls);

		$this->late($id, 'ocenzurowane', array('ocenzurowany' => 'To jest ████████ słowo w teście.'));

		$post = $this->board->post($id);
		self::assertSame(ITEM_APPROVED, (int) $post['post_visibility']);
		self::assertStringNotContainsString('brzydkie', $post['post_text']);
		self::assertSame(pending_store::MASKED, $this->board->row($id)['status']);
		self::assertCount($calls, $this->board->visibility->calls, 'already approved');
		self::assertCount($notified, $this->board->notifications->calls);
	}

	public function testALateMaskThatCannotBeStoredTakesThePostBackToTheQueue(): void
	{
		$this->board->configure(array(settings::CENSORED => settings::CENSORED_PUBLISH));
		$id = $this->published_by_fail_open('To jest brzydkie słowo w teście.');
		$original = $this->board->post($id)['post_text'];
		$this->board->db->pdo->exec('CREATE TRIGGER minos_test_text BEFORE UPDATE OF post_text ON phpbb_posts BEGIN SELECT RAISE(IGNORE); END');

		$this->late($id, 'ocenzurowane', array('ocenzurowany' => 'To jest ████████ słowo w teście.'));

		$post = $this->board->post($id);
		self::assertSame(ITEM_REAPPROVE, (int) $post['post_visibility'], 'the unmasked original does not stay published');
		self::assertSame($original, $post['post_text']);
		self::assertSame('mask_failed', $this->board->row($id)['error_code']);
	}

	public function testALateOutcomeThatIsNoVerdictLeavesThePublishedPostAlone(): void
	{
		$id = $this->published_by_fail_open();
		$calls = count($this->board->visibility->calls);

		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'nieocenione')));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertCount($calls, $this->board->visibility->calls);
		self::assertSame((string) $this->timeout, $this->board->row($id)['approved_at'], 'still marked');
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public function changesSinceTheApproval(): array
	{
		return array(
			'a moderator soft-deleted it' => array(static function (Board $board, int $id, int $at) {
				$board->visibility->set_post_visibility(ITEM_DELETED, array($id), 100, Board::FORUM, 5, $at + 5, 'moderator', true, true);
			}),
			'a moderator deleted and restored it' => array(static function (Board $board, int $id, int $at) {
				$board->visibility->set_post_visibility(ITEM_DELETED, array($id), 100, Board::FORUM, 5, $at + 5, 'moderator', true, true);
				$board->visibility->set_post_visibility(ITEM_APPROVED, array($id), 100, Board::FORUM, 5, $at + 6, '', true, true);
			}),
			'its text was edited' => array(static function (Board $board, int $id, int $at) {
				$board->db->sql_query("UPDATE phpbb_posts SET post_text = '<t>Poprawiony wpis.</t>' WHERE post_id = " . $id);
			}),
		);
	}

	/**
	 * @dataProvider changesSinceTheApproval
	 */
	public function testAChangeSinceTheFailOpenApprovalStands(callable $change): void
	{
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->published_by_fail_open();
		$change($this->board, $id, $this->timeout);
		$before = $this->board->post($id);
		$calls = count($this->board->visibility->calls);

		self::assertSame(200, $this->late($id, 'zablokowane'));

		self::assertSame($before, $this->board->post($id));
		self::assertCount($calls, $this->board->visibility->calls);
		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
	}

	public function testALateVerdictForAPostHeldByTheFailClosedModeIsApplied(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_CLOSED));
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];
		$this->board->sweeper()->sweep($this->timeout);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);

		$this->late($id, 'bezpieczne');

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_PUBLISHED'));
	}

	public function testALateVerdictForAHeldPostAModeratorApprovedIsIgnored(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_CLOSED, settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];
		$this->board->sweeper()->sweep($this->timeout);
		$this->board->visibility->set_post_visibility(ITEM_APPROVED, array($id), 100, Board::FORUM, 5, $this->timeout + 5, '', true, true);

		$this->late($id, 'zablokowane');

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
	}

	public function testARepeatedLateVerdictChangesNothing(): void
	{
		$id = $this->published_by_fail_open();
		$this->late($id, 'zablokowane');
		$calls = count($this->board->visibility->calls);
		$notified = count($this->board->notifications->calls);

		self::assertSame(200, $this->late($id, 'bezpieczne'));

		self::assertSame(ITEM_REAPPROVE, (int) $this->board->post($id)['post_visibility']);
		self::assertCount($calls, $this->board->visibility->calls);
		self::assertCount($notified, $this->board->notifications->calls);
	}

	public function testAPostTheGatewayRefusedGetsNoLateVerdict(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$this->gateway->refuse(403, 'brak_webhooka');
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertNotSame('0', $this->board->row($id)['approved_at'], 'a fail-open approval, marked');

		self::assertSame(200, $this->late($id, 'zablokowane'));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility'], 'no verdict can follow a refusal');
		self::assertSame(pending_store::VERDICT_REFUSED, $this->board->row($id)['verdict']);
	}

	/**
	 * A post the fail-open mode published at {@see $timeout}.
	 */
	private function published_by_fail_open(string $text = 'Zwykły testowy wpis.'): int
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$id = $this->board->posting($text)['post_id'];
		$this->board->sweeper()->sweep($this->timeout);
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		return $id;
	}

	/**
	 * @param array<string,mixed> $extra
	 */
	private function late(int $post_id, string $qualification, array $extra = array()): int
	{
		return $this->board->deliver($extra + array(
			'id'           => 'phpbb:' . $post_id,
			'status'       => 'ocenione',
			'kwalifikacja' => $qualification,
			'kategorie'    => array(),
			'wsparcie'     => false,
			'wersja'       => '3f0c9a41d2b7e8c5',
		));
	}
}
