<?php

namespace minos\moderation\tests\Cron;

use minos\moderation\controller\webhook;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\RecordingTransport;
use Minos\Client\Signature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * One row that fails does not stop the others: it is logged by step and post id - never by
 * the error's message - and left for the next run.
 */
final class FailureIsolationTest extends TestCase
{
	/** What the failing row's error says: it must reach no log. */
	const MESSAGE = 'tajna treść posta w komunikacie błędu';

	/** @var Board */
	private $board;

	/** @var RecordingTransport */
	private $gateway;

	/** @var array<int,int> */
	private $posts = array();

	protected function setUp(): void
	{
		$this->gateway = new RecordingTransport();
		$this->board = Board::open(':memory:', $this->gateway);
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
	}

	public function testATimeOutThatFailsLeavesTheOtherTimeOutsToRun(): void
	{
		$this->three_posts();
		$this->break_row($this->posts[1]);

		$this->board->sweeper()->sweep(time() + 20 * 60 + 1);

		$this->assert_only_the_broken_row_is_left(pending_store::PENDING, 'timeout');
	}

	public function testALeftoverVerdictThatFailsLeavesTheOthersToBeApplied(): void
	{
		$this->three_posts();
		foreach ($this->posts as $id)
		{
			$this->record_without_applying($id);
		}
		$this->break_row($this->posts[1]);

		$this->board->sweeper()->sweep(time());

		$this->assert_only_the_broken_row_is_left(pending_store::RECEIVED, 'apply');
	}

	public function testADeferredVerdictThatFailsLeavesTheOthersToBeApplied(): void
	{
		$this->three_posts();
		foreach ($this->posts as $id)
		{
			$this->record_without_applying($id);
		}
		$this->break_row($this->posts[1]);

		$this->board->dispatcher->terminate();

		$this->assert_only_the_broken_row_is_left(pending_store::RECEIVED, 'apply');
	}

	public function testASubmissionThatFailsForOneRowLeavesTheRestOfTheBatch(): void
	{
		for ($i = 0; $i < 3; $i++)
		{
			$this->gateway->refuse(503, 'kolejka_niedostepna');
		}
		$this->three_posts(pending_store::QUEUED);
		$this->break_row($this->posts[1]);

		$this->board->sweeper()->sweep(time() + 61);

		self::assertCount(3, $this->gateway->items(3), 'one batch');
		self::assertSame(pending_store::PENDING, $this->board->row($this->posts[0])['status']);
		self::assertSame(pending_store::QUEUED, $this->board->row($this->posts[1])['status']);
		self::assertSame(pending_store::PENDING, $this->board->row($this->posts[2])['status']);
		$this->assert_logged('submit');
	}

	/**
	 * Three posts, each waiting in a status.
	 */
	private function three_posts(string $status = pending_store::PENDING): void
	{
		for ($i = 0; $i < 3; $i++)
		{
			$this->posts[] = $id = $this->board->posting('Testowy wpis numer ' . $i . '.')['post_id'];
			self::assertSame($status, $this->board->row($id)['status']);
		}
	}

	/**
	 * Makes every change of one post's row fail, as a database error would.
	 */
	private function break_row(int $post_id): void
	{
		$this->board->db->pdo->exec('CREATE TRIGGER minos_test_broken BEFORE UPDATE ON phpbb_minos_pending WHEN OLD.post_id = ' . $post_id
			. " BEGIN SELECT RAISE(ABORT, '" . self::MESSAGE . "'); END");
	}

	/**
	 * A verdict the webhook recorded, before `kernel.terminate` applied it.
	 */
	private function record_without_applying(int $post_id): void
	{
		$body = json_encode(array('id' => 'phpbb:' . $post_id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne'));
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
			$this->board->receiver, $this->board->deferred, $this->board->dispatcher);
		self::assertSame(200, $controller->handle()->getStatusCode());
	}

	private function assert_only_the_broken_row_is_left(string $status, string $step): void
	{
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($this->posts[0])['post_visibility']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($this->posts[1])['post_visibility']);
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($this->posts[2])['post_visibility']);
		self::assertSame($status, $this->board->row($this->posts[1])['status'], 'left for the next run');
		$this->assert_logged($step);
	}

	private function assert_logged(string $step): void
	{
		$failures = $this->board->log->of('LOG_MINOS_ROW_FAILED');
		self::assertCount(1, $failures);
		self::assertSame('critical', $failures[0]['mode']);
		self::assertSame(array($step, $this->posts[1]), $failures[0]['data']);
		self::assertStringNotContainsString('tajna', json_encode($this->board->log->entries, JSON_UNESCAPED_UNICODE));
	}
}
