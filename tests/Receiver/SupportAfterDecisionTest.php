<?php

namespace minos\moderation\tests\Receiver;

use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use Minos\Client\Signature;
use PHPUnit\Framework\TestCase;

/**
 * `wsparcie` in a verdict that arrives after a person decided on the post (the plugins'
 * shared rule A7, "whatever the verdict"; the owner's decision of 2026-11-01: the signal
 * always, the post's visibility as the person left it). Each case through the webhook, with
 * deliveries signed as the gateway signs them.
 */
final class SupportAfterDecisionTest extends TestCase
{
	/** @var Board */
	private $board;

	/** @var int The moment of the time-out. */
	private $timeout;

	protected function setUp(): void
	{
		$this->board = Board::open();
		$this->timeout = time() + 20 * 60 + 1;
	}

	/**
	 * @return array<string,array{0:callable,1:int}>
	 */
	public function decisions(): array
	{
		// What a moderator did before the verdict arrived, and the visibility it left.
		return array(
			'a moderator approved the waiting post' => array(static function (Board $board, int $id) {
				$board->visibility->set_post_visibility(ITEM_APPROVED, array($id), 100, Board::FORUM, 5, time(), '', true, true);
			}, ITEM_APPROVED),
			'a moderator soft-deleted the waiting post' => array(static function (Board $board, int $id) {
				$board->visibility->set_post_visibility(ITEM_DELETED, array($id), 100, Board::FORUM, 5, time(), 'moderator', true, true);
			}, ITEM_DELETED),
		);
	}

	/**
	 * @dataProvider decisions
	 */
	public function testALateBlockWithSupportAfterAModeratorsDecisionIsReportedAndChangesNothing(callable $decide, int $visibility): void
	{
		// Before: the verdict was superseded whole, `wsparcie` with it - no report, no log.
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$decide($this->board, $id);
		$before = $this->board->post($id);
		$calls = count($this->board->visibility->calls);
		$notified = count($this->board->notifications->calls);

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', true)));

		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status'], 'the decision stands');
		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($before['post_text'], $this->board->post($id)['post_text']);
		self::assertSame(array(), array_slice($this->board->visibility->calls, $calls), 'neither taken back nor deleted');
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_POST_DELETED'));
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'), 'the moderator log');
		$reports = $this->board->reports->of($id);
		self::assertCount(1, $reports, 'an active signal: a report in the MCP');
		self::assertStringContainsString('po decyzji moderatora', $reports[0]['report_text']);
		self::assertStringContainsString('zablokowane', $reports[0]['report_text']);
		self::assertStringNotContainsString('złym dniu', $reports[0]['report_text'], 'the extension\'s text, never the post\'s');
		self::assertContains(array('add', 'notification.type.report_post', $id), array_slice($this->board->notifications->calls, $notified),
			'phpBB tells the moderators of the report');
	}

	public function testARepeatedDeliveryAfterTheDecisionFilesNoSecondSignal(): void
	{
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->visibility->set_post_visibility(ITEM_APPROVED, array($id), 100, Board::FORUM, 5, time(), '', true, true);
		$payload = $this->verdict($id, 'zablokowane', true);
		self::assertSame(200, $this->board->deliver($payload));
		self::assertCount(1, $this->board->reports->of($id));
		// A moderator closes the report: phpBB would take a new one now.
		$this->board->db->sql_query('UPDATE phpbb_reports SET report_closed = 1 WHERE post_id = ' . $id);
		$this->board->db->sql_query('UPDATE phpbb_posts SET post_reported = 0 WHERE post_id = ' . $id);

		self::assertSame(200, $this->board->deliver($payload));

		self::assertCount(1, $this->board->reports->of($id), 'still one report');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'), 'still one entry');
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testALateBlockWithoutSupportAfterTheDecisionSignalsNothing(): void
	{
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->visibility->set_post_visibility(ITEM_APPROVED, array($id), 100, Board::FORUM, 5, time(), '', true, true);

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', false)));

		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(array(), $this->board->reports->of($id));
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_SUPPORT'));
	}

	public function testALateVerdictWithSupportForAPostAModeratorChangedAfterTheFailOpenPublicationIsReported(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN, settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->board->posting('Testowy wpis o bardzo złym dniu.')['post_id'];
		$this->board->sweeper()->sweep($this->timeout);
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility'], 'published by the failure mode');
		$this->board->visibility->set_post_visibility(ITEM_DELETED, array($id), 100, Board::FORUM, 5, $this->timeout + 5, 'moderator', true, true);
		$calls = count($this->board->visibility->calls);

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', true)));

		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
		self::assertSame(ITEM_DELETED, (int) $this->board->post($id)['post_visibility'], 'the moderator\'s deletion stands');
		self::assertSame(array(), array_slice($this->board->visibility->calls, $calls));
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
		$reports = $this->board->reports->of($id);
		self::assertCount(1, $reports);
		self::assertStringContainsString('po decyzji moderatora', $reports[0]['report_text']);
	}

	public function testALateMaskWithSupportForAHeldPostAModeratorApprovedIsReportedAndNotApplied(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_CLOSED));
		$id = $this->board->posting('To jest brzydkie słowo w teście.')['post_id'];
		$this->board->sweeper()->sweep($this->timeout);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status'], 'held by the failure mode');
		$this->board->visibility->set_post_visibility(ITEM_APPROVED, array($id), 100, Board::FORUM, 5, $this->timeout + 5, '', true, true);
		$text = $this->board->post($id)['post_text'];

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'ocenzurowane', true, array(
			'ocenzurowany' => $this->board->title($id) . "\n\nTo jest ████████ słowo w teście.",
		))));

		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
		self::assertSame(array(ITEM_APPROVED, $text), array((int) $this->board->post($id)['post_visibility'], $this->board->post($id)['post_text']),
			'neither masked nor taken back');
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_POST_MASKED'));
		self::assertCount(1, $this->board->reports->of($id));
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
	}

	public function testSupportForAPostAModeratorDisapprovedIsInTheErrorLog(): void
	{
		// phpBB's "disapprove" deletes a queued post: nothing is left to report.
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->db->sql_query('DELETE FROM phpbb_posts WHERE post_id = ' . $id);

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', true)));

		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
		$gone = $this->board->log->of('LOG_MINOS_SUPPORT_POST_GONE');
		self::assertCount(1, $gone);
		self::assertSame(array('critical', array($id)), array($gone[0]['mode'], $gone[0]['data']), 'the post\'s id only');
		self::assertSame(array(), $this->board->reports->of($id));

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', true)));
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT_POST_GONE'), 'once');
	}

	public function testSupportForTheTextBeforeAnEditIsReportedOnceAndTheNewRevisionIsAssessedAsUsual(): void
	{
		// Before: a verdict for an earlier revision was answered 200 and dropped whole.
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->edit($id, 'Poprawiony wpis testowy.');
		self::assertSame(array('1', pending_store::PENDING), array($this->board->row($id)['revision'], $this->board->row($id)['status']));
		$calls = count($this->board->visibility->calls);
		$payload = $this->verdict($id, 'zablokowane', true);

		self::assertSame(200, $this->board->deliver($payload));

		self::assertSame(array('1', pending_store::PENDING), array($this->board->row($id)['revision'], $this->board->row($id)['status']),
			'the old verdict is not applied');
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(array(), array_slice($this->board->visibility->calls, $calls));
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
		$reports = $this->board->reports->of($id);
		self::assertCount(1, $reports);
		self::assertStringContainsString('po edycji postu', $reports[0]['report_text']);

		// The gateway delivers again, after a moderator closed the report.
		$this->board->db->sql_query('UPDATE phpbb_reports SET report_closed = 1 WHERE post_id = ' . $id);
		$this->board->db->sql_query('UPDATE phpbb_posts SET post_reported = 0 WHERE post_id = ' . $id);
		self::assertSame(200, $this->board->deliver($payload));
		self::assertCount(1, $this->board->reports->of($id), 'once');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));

		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id . '.1') + $this->verdict($id, 'bezpieczne', false)));
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility'], 'the new text\'s own verdict');
		self::assertSame(pending_store::PUBLISHED, $this->board->row($id)['status']);
	}

	public function testAnOlderRevisionsSupportAfterANewerOnesAddsNoSecondSignal(): void
	{
		// The decision on the order (S6e minor 2): the claim moves forward only. Once the
		// moderators have the signal for a text before an edit, a verdict for a text further
		// back adds none - the post is in their reports already, and phpBB keeps one open
		// report per post. A newer revision's after an older one's is still raised.
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->edit($id, 'Pierwsza poprawka wpisu testowego.');
		$this->board->edit($id, 'Druga poprawka wpisu testowego.');
		self::assertSame('2', (string) $this->board->row($id)['revision']);

		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id . '.1') + $this->verdict($id, 'zablokowane', true)));
		self::assertCount(1, $this->board->reports->of($id));
		$this->board->db->sql_query('UPDATE phpbb_reports SET report_closed = 1 WHERE post_id = ' . $id);
		$this->board->db->sql_query('UPDATE phpbb_posts SET post_reported = 0 WHERE post_id = ' . $id);

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', true)));

		self::assertCount(1, $this->board->reports->of($id), 'no second report for the older text');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
		self::assertSame('2', (string) $this->board->row($id)['support_signalled']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testAVerdictWithoutSupportForTheTextBeforeAnEditSignalsNothing(): void
	{
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->edit($id, 'Poprawiony wpis testowy.');

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', false)));

		self::assertSame(array(), $this->board->reports->of($id));
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_SUPPORT'));
		self::assertSame('0', $this->board->row($id)['support_signalled']);
	}

	public function testSupportForTheTextBeforeAnEditOfAFailOpenPublicationIsReported(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$id = $this->board->posting('Testowy wpis o bardzo złym dniu.')['post_id'];
		$this->board->sweeper()->sweep($this->timeout);
		$this->board->edit($id, 'Poprawiony wpis testowy.');
		self::assertSame(ITEM_REAPPROVE, (int) $this->board->post($id)['post_visibility'], 'back in the queue for its new text');

		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', true)));

		self::assertSame(ITEM_REAPPROVE, (int) $this->board->post($id)['post_visibility']);
		self::assertCount(1, $this->board->reports->of($id));
	}

	public function testASignalThatCannotBeFiledIsClaimedAgainOnTheNextDelivery(): void
	{
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->edit($id, 'Poprawiony wpis testowy.');
		$payload = $this->verdict($id, 'zablokowane', true);
		$this->board->reports->fail_with = new \RuntimeException('a database error');

		try
		{
			$this->board->deliver($payload);
			self::fail('the failure reaches the webhook, which answers with an error');
		}
		catch (\RuntimeException $e)
		{
			self::assertSame('a database error', $e->getMessage());
		}
		$this->board->reports->fail_with = null;
		self::assertSame('0', $this->board->row($id)['support_signalled'], 'the claim rolled back');

		self::assertSame(200, $this->board->deliver($payload));
		self::assertCount(1, $this->board->reports->of($id), 'filed on the gateway\'s next delivery');
	}

	public function testAnEditThatOvertakesARecordedVerdictStillRaisesItsSupport(): void
	{
		// Before: the verdict was recorded (`received`), the edit came before it was applied,
		// and the restart zeroed `support` with the verdict: the signal was lost for good,
		// since the gateway had its 200 and never delivers again.
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->record($this->verdict($id, 'zablokowane', true));
		self::assertSame(array(pending_store::RECEIVED, '1'), array($this->board->row($id)['status'], (string) $this->board->row($id)['support']));
		$calls = count($this->board->visibility->calls);

		$this->board->edit($id, 'Poprawiony wpis testowy.');

		$row = $this->board->row($id);
		self::assertSame(array('1', pending_store::PENDING), array((string) $row['revision'], $row['status']), 'the new text is sent');
		self::assertSame('phpbb:' . $id . '.1', $this->board->transport->items(1)[0]['id']);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'), 'the moderator log');
		$reports = $this->board->reports->of($id);
		self::assertCount(1, $reports, 'the active signal');
		self::assertStringContainsString('po edycji postu', $reports[0]['report_text']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'the old verdict is not applied');
		self::assertSame(array(), array_slice($this->board->visibility->calls, $calls), 'neither deleted nor published');

		// What the webhook would have applied after its answer, and a repeated delivery.
		$this->board->dispatcher->terminate();
		$this->board->sweeper()->sweep(time());
		self::assertSame(200, $this->board->deliver($this->verdict($id, 'zablokowane', true)));
		self::assertCount(1, $this->board->reports->of($id), 'once');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testAnEditThatOvertakesARecordedVerdictWithoutSupportSignalsNothing(): void
	{
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->record($this->verdict($id, 'zablokowane', false));

		$this->board->edit($id, 'Poprawiony wpis testowy.');

		self::assertSame(array('1', pending_store::PENDING), array((string) $this->board->row($id)['revision'], $this->board->row($id)['status']));
		self::assertSame(array(), $this->board->reports->of($id));
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_SUPPORT'));
	}

	public function testASignalTheEditCannotFileIsLoggedAndTheEditIsStillSent(): void
	{
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->record($this->verdict($id, 'zablokowane', true));
		$this->board->reports->fail_with = new \RuntimeException('a database error');

		$this->board->edit($id, 'Poprawiony wpis testowy.');

		self::assertSame(array('1', pending_store::PENDING), array((string) $this->board->row($id)['revision'], $this->board->row($id)['status']),
			'the author\'s edit is not broken by the report');
		$lost = $this->board->log->of('LOG_MINOS_SUPPORT_LOST');
		self::assertCount(1, $lost, 'the error log says so');
		self::assertSame(array('critical', array($id)), array($lost[0]['mode'], $lost[0]['data']), 'the post\'s id, nothing else');
	}

	/**
	 * A delivery recorded and answered, before `app.php` applies it after the answer.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function record(array $payload): void
	{
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$answer = $this->board->receiver->receive($body, Signature::sign(Board::SECRET, $body, time()), time());
		self::assertSame(array(200, (int) substr((string) $payload['id'], strlen('phpbb:'))), $answer, 'recorded, to be applied after the answer');
	}

	/**
	 * A post held and accepted by the gateway.
	 */
	private function pending(string $text): int
	{
		$id = $this->board->posting($text)['post_id'];
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		return $id;
	}

	/**
	 * A payload as the gateway builds it.
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function verdict(int $post_id, string $qualification, bool $support, array $extra = array()): array
	{
		return $extra + array(
			'id'           => 'phpbb:' . $post_id,
			'status'       => 'ocenione',
			'kwalifikacja' => $qualification,
			'kategorie'    => $support ? array('samookaleczenie') : array(),
			'wsparcie'     => $support,
			'wersja'       => '3f0c9a41d2b7e8c5',
		);
	}
}
