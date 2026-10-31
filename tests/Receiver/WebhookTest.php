<?php

namespace minos\moderation\tests\Receiver;

use minos\moderation\controller\webhook;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use Minos\Client\Signature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The webhook through the real controller, receiver and applier, with deliveries signed the
 * way the gateway signs them (`Signature::sign` of the bundled client).
 */
final class WebhookTest extends TestCase
{
	/** @var Board */
	private $board;

	protected function setUp(): void
	{
		$this->board = Board::open();
	}

	public function testASafeVerdictPublishesThePost(): void
	{
		$id = $this->pending();

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::PUBLISHED, $this->board->row($id)['status']);
		self::assertContains(array('add', 'notification.type.topic', $id), $this->board->notifications->calls);
		self::assertContains(array('delete', 'notification.type.post_in_queue', $id), $this->board->notifications->calls);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_PUBLISHED'));
	}

	public function testASafeReplyNotifiesTheTopicsWatchers(): void
	{
		$topic = $this->board->write_post($this->board->xml('Pierwszy wpis tematu.'), ITEM_APPROVED);
		$this->board->db->sql_query('UPDATE phpbb_topics SET topic_posts_approved = 1');
		$reply = $this->board->posting('Odpowiedź testowa.', 'reply', array('topic_id' => (int) $this->board->post($topic)['topic_id']));

		$this->board->deliver(self::verdict($reply['post_id'], 'bezpieczne'));

		self::assertContains(array('add', 'notification.type.post', $reply['post_id']), $this->board->notifications->calls);
		self::assertContains(array('add', 'notification.type.forum', $reply['post_id']), $this->board->notifications->calls);
		self::assertNotContains(array('add', 'notification.type.topic', $reply['post_id']), $this->board->notifications->calls);
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function forgedDeliveries(): array
	{
		return array(
			'a wrong secret'           => array(array('secret' => 'inny-sekret-niz-ten-z-klucza-00')),
			'a stale signature'        => array(array('t' => time() - 600)),
			'a signature from later'   => array(array('t' => time() + 600)),
			'no signature'             => array(array('header' => '')),
			'a malformed header'       => array(array('header' => 'v1=' . str_repeat('a', 64))),
			'another body signed'      => array(array('header' => Signature::sign(Board::SECRET, '{"id":"phpbb:1"}', time()))),
		);
	}

	/**
	 * @dataProvider forgedDeliveries
	 * @param array<string,mixed> $options
	 */
	public function testAForgedOrStaleDeliveryIs401AndChangesNothing(array $options): void
	{
		$id = $this->pending();

		self::assertSame(401, $this->board->deliver(self::verdict($id, 'bezpieczne'), $options));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testTheReceiverVerifiesTheGatewaysOwnTestVector(): void
	{
		$vector = json_decode((string) file_get_contents(__DIR__ . '/../../vendor/minos-moderation/client-php/vectors/signature.json'), true);
		$this->board->configure(array(), array(settings::WEBHOOK_SECRET => $vector['secret']));

		// A valid signature on a payload of another plugin: accepted, then dropped as not ours.
		self::assertSame(array(200, null), $this->board->receiver->receive($vector['body'], $vector['header'], (int) $vector['timestamp']));
		self::assertSame(401, $this->board->receiver->receive($vector['body'] . ' ', $vector['header'], (int) $vector['timestamp'])[0]);
		self::assertSame(401, $this->board->receiver->receive($vector['body'], $vector['header'], (int) $vector['timestamp'] + 301)[0]);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function unusableSecrets(): array
	{
		return array('empty' => array(''), 'blank' => array('   '), 'short' => array('krotki'));
	}

	/**
	 * @dataProvider unusableSecrets
	 */
	public function testWithoutAUsableStoredSecretEveryDeliveryIsRefused(string $secret): void
	{
		$id = $this->pending();
		$this->board->configure(array(), array(settings::WEBHOOK_SECRET => $secret));

		// Signed with the very secret stored: an HMAC keyed with "" is one anybody can compute.
		self::assertSame(401, $this->board->deliver(self::verdict($id, 'bezpieczne'), array('secret' => $secret)));
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testSwitchedOffTheWebhookAnswers404AndRecordsNothing(): void
	{
		$id = $this->pending();
		$this->board->configure(array(settings::ENABLED => 0));

		self::assertSame(404, $this->board->deliver(self::verdict($id, 'bezpieczne')));

		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'the post waits for a moderator');
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testASignedBodyThatIsNotAPayloadIs400(): void
	{
		$this->pending();

		self::assertSame(400, $this->board->deliver('to nie jest JSON'));
		self::assertSame(400, $this->board->deliver('{"status":"ocenione"}'));
		self::assertSame(array(), $this->board->visibility->calls);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function foreignIds(): array
	{
		return array(
			'another plugin\'s id' => array('k-1027'),
			'a post never held'    => array('phpbb:999999'),
			'a zero post id'       => array('phpbb:0'),
			'a revision never sent' => array('phpbb:1.7'),
		);
	}

	/**
	 * @dataProvider foreignIds
	 */
	public function testAnIdThisForumDoesNotWaitForIs200AndChangesNothing(string $id): void
	{
		$this->pending();

		self::assertSame(200, $this->board->deliver(array('id' => $id) + self::verdict(1, 'zablokowane')));
		self::assertSame(array(), $this->board->visibility->calls);
		self::assertSame(pending_store::PENDING, $this->board->row(1)['status']);
	}

	public function testARepeatedDeliveryIsAppliedOnce(): void
	{
		$id = $this->pending();

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));
		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));
		self::assertSame(200, $this->board->deliver(self::verdict($id, 'zablokowane')));

		self::assertCount(1, $this->board->visibility->calls);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_PUBLISHED'));
		self::assertSame('bezpieczne', $this->board->row($id)['verdict']);
	}

	public function testASecondDeliveryBeforeTheFirstIsAppliedChangesNothing(): void
	{
		$id = $this->pending();
		foreach (array('bezpieczne', 'zablokowane') as $qualification)
		{
			$body = json_encode(self::verdict($id, $qualification));
			$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
				$this->board->receiver, $this->board->deferred, $this->board->dispatcher);
			self::assertSame(200, $controller->handle()->getStatusCode());
		}

		$this->board->dispatcher->terminate();

		self::assertSame('bezpieczne', $this->board->row($id)['verdict']);
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testAVerdictThatOvertakesTheGatewaysAcceptanceStillChecksTheTextSent(): void
	{
		// Audit probe 3 A, the other way round: the webhook arrives before the extension handles
		// its own 202, after the text changed on a path the extension does not see.
		$gateway = new class() extends \minos\moderation\tests\Fake\RecordingTransport {
			/** @var callable|null */
			public $before_answer;

			public function post($url, array $headers, $body, $timeout_s)
			{
				$answer = parent::post($url, $headers, $body, $timeout_s);
				if ($this->before_answer !== null)
				{
					call_user_func($this->before_answer, json_decode($body, true)['elementy'][0]['id']);
				}
				return $answer;
			}
		};
		$board = Board::open(':memory:', $gateway);
		$gateway->before_answer = static function (string $id) use ($board) {
			$board->db->sql_query("UPDATE phpbb_posts SET post_text = '<t>Treść podmieniona, której brama nie widziała.</t>' WHERE post_id = " . (int) substr($id, 6));
			$board->deliver(array('id' => $id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne'));
		};

		$id = $board->posting('Pierwsza, oceniana treść.')['post_id'];

		self::assertSame(ITEM_UNAPPROVED, (int) $board->post($id)['post_visibility'], 'the changed text is not published');
		self::assertSame(pending_store::SUPERSEDED, $board->row($id)['status']);
	}

	public function testAVerdictForARevisionWhoseTextWasNeverSentIsNotApplied(): void
	{
		$id = $this->pending();
		// A row without the digest of a text sent (as no row of this version can be).
		$this->board->db->sql_query("UPDATE phpbb_minos_pending SET sent_md5 = '' WHERE post_id = " . $id);

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(array(pending_store::PENDING, ''), array($this->board->row($id)['status'], $this->board->row($id)['verdict']),
			'nothing is recorded: the row keeps waiting for its time-out');
		$this->board->sweeper()->sweep(time() + 20 * 60 + 1);
		self::assertSame(array(pending_store::HELD, pending_store::VERDICT_TIMEOUT), array($this->board->row($id)['status'], $this->board->row($id)['verdict']),
			'the failure mode, the default fail-closed');
	}

	public function testARecordedVerdictWithoutTheDigestOfTheTextSentIsNotApplied(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$id = $this->pending();
		$body = json_encode(self::verdict($id, 'bezpieczne'));
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
			$this->board->receiver, $this->board->deferred, $this->board->dispatcher);
		self::assertSame(200, $controller->handle()->getStatusCode());
		self::assertSame(pending_store::RECEIVED, $this->board->row($id)['status'], 'recorded, not applied yet');
		$this->board->db->sql_query("UPDATE phpbb_minos_pending SET sent_md5 = '' WHERE post_id = " . $id);

		$this->board->dispatcher->terminate();

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'an outcome about no known text publishes nothing');
		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
	}

	public function testCensoredWithPublishReplacesTheTextAndKeepsTheOriginal(): void
	{
		$id = $this->pending('To jest brzydkie słowo w teście.');
		$original = $this->board->post($id)['post_text'];

		$title = $this->board->title($id);

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array(
			'kategorie'    => array('wulgaryzmy'),
			'ocenzurowany' => $title . "\n\nTo jest ████████ słowo w teście.",
		)));

		$post = $this->board->post($id);
		self::assertSame(ITEM_APPROVED, (int) $post['post_visibility']);
		self::assertSame($this->board->xml('To jest ████████ słowo w teście.'), $post['post_text'], 'the post\'s text, without the title');
		self::assertSame($title, $this->board->title($id), 'the title stays as it was');
		self::assertSame('', $post['bbcode_uid']);
		$row = $this->board->row($id);
		self::assertSame(pending_store::MASKED, $row['status']);
		self::assertSame($original, $row['original_text']);
		self::assertSame('wulgaryzmy', $row['categories']);
		$parse = end($this->board->parser->parsed);
		self::assertSame(array('bbcodes' => false, 'magic_url' => false, 'smilies' => false), $parse['enabled'],
			'the masked text is stored with BBCode, links and smilies off');
		self::assertSame(array('bbcodes' => true, 'magic_url' => true, 'smilies' => true), $this->board->parser->enabled,
			'the parser is left as it was found');
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function failingTextUpdates(): array
	{
		return array(
			'the update is ignored' => array('BEFORE UPDATE OF post_text ON phpbb_posts BEGIN SELECT RAISE(IGNORE); END'),
			'the stored text differs' => array("AFTER UPDATE OF post_text ON phpbb_posts BEGIN UPDATE phpbb_posts SET post_text = '<t>inny</t>' WHERE post_id = NEW.post_id; END"),
		);
	}

	/**
	 * @dataProvider failingTextUpdates
	 */
	public function testAMaskedTextThatCannotBeStoredKeepsThePostQueuedAndNeverPublishesTheOriginal(string $trigger): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN, settings::CENSORED => settings::CENSORED_PUBLISH));
		$id = $this->pending('To jest brzydkie słowo w teście.');
		$original = $this->board->post($id)['post_text'];
		$this->board->db->pdo->exec('CREATE TRIGGER minos_test_text ' . $trigger);

		$masked = $this->board->title($id) . "\n\nTo jest ████████ słowo w teście.";
		self::assertSame(200, $this->board->deliver(self::verdict($id, 'ocenzurowane', array('ocenzurowany' => $masked))));

		$post = $this->board->post($id);
		self::assertSame(ITEM_UNAPPROVED, (int) $post['post_visibility']);
		self::assertSame($original, $post['post_text'], 'the attempt is rolled back');
		self::assertSame(array(), $this->board->visibility->calls, 'nothing is approved');
		$row = $this->board->row($id);
		self::assertSame(pending_store::HELD, $row['status']);
		self::assertSame('mask_failed', $row['error_code']);
		self::assertSame($masked, $row['masked_text'], 'kept for the moderator');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_HELD'));
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_POST_MASKED'));
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function masksThatDoNotFit(): array
	{
		$plain = '<t>To jest brzydkie słowo.</t>';
		return array(
			'shorter than the text sent' => array($plain, 'To jest ███ słowo.', 'To jest brzydkie słowo.'),
			'longer than the text sent'  => array($plain, 'To jest ████████ słowo. Dopisane.', 'To jest brzydkie słowo.'),
			'a changed character that is not the mask' => array($plain, 'To jest ████████ słowa.', 'To jest brzydkie słowo.'),
			'a post with BBCode' => array('<r>To jest <B><s>[b]</s>brzydkie<e>[/b]</e></B> słowo.</r>', 'To jest ████████ słowo.', 'To jest brzydkie słowo.'),
			'a post with a quote' => array('<r><QUOTE author="Ala"><s>[quote=Ala]</s>brzydkie<e>[/quote]</e></QUOTE></r>',
				"Ala napisał(a):\n████████", "Ala napisał(a):\nbrzydkie"),
		);
	}

	/**
	 * @dataProvider masksThatDoNotFit
	 */
	public function testAMaskedTextThatDoesNotFitThePostIsLeftToAModerator(string $xml, string $masked, string $sent): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN, settings::CENSORED => settings::CENSORED_PUBLISH));
		$posted = $this->board->posting('', 'post', array('xml' => $xml));
		$id = $posted['post_id'];
		self::assertSame($sent, $this->board->forum->plain_text($xml));
		$masked = $this->board->title($id) . "\n\n" . $masked;

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array('ocenzurowany' => $masked)));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($xml, $this->board->post($id)['post_text']);
		$row = $this->board->row($id);
		self::assertSame(array(pending_store::HELD, 'mask_manual', $masked), array($row['status'], $row['error_code'], $row['masked_text']));

		$details = new \phpbb\event\data(array('post_id' => $id, 'post_data' => array()));
		$this->board->listener->show_queue_details($details);
		self::assertTrue($details['post_data']['S_MINOS_MASK_MANUAL']);
		self::assertNotSame('', $details['post_data']['MINOS_MASKED'], 'the masked text is there for the moderator');
	}

	public function testCensoredWithHoldKeepsThePostQueuedWithTheMaskedTextForTheModerator(): void
	{
		$this->board->configure(array(settings::CENSORED => settings::HOLD));
		$id = $this->pending('To jest brzydkie słowo w teście.');

		$masked = $this->board->title($id) . "\n\nTo jest ████████ słowo w teście.";

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array('ocenzurowany' => $masked)));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertStringContainsString('brzydkie', $this->board->post($id)['post_text']);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
		self::assertSame($masked, $this->board->row($id)['masked_text']);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_HELD'));
	}

	public function testCensoredWithoutMaskedTextIsHeld(): void
	{
		$id = $this->pending();

		$this->board->deliver(self::verdict($id, 'ocenzurowane'));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function bothFailureModes(): array
	{
		return array('fail-open' => array(settings::FAIL_OPEN), 'fail-closed' => array(settings::FAIL_CLOSED));
	}

	/**
	 * @dataProvider bothFailureModes
	 */
	public function testCensoredTextOfAPostLongerThanTheAssessedPartIsNeverPublished(string $mode): void
	{
		$this->board->configure(array(settings::FAIL_MODE => $mode, settings::CENSORED => settings::CENSORED_PUBLISH));
		$id = $this->pending(str_repeat('Długi testowy wpis. ', 200));

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array('ocenzurowany' => str_repeat('█', 3000))));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'],
			'publishing the masked 3000 characters would cut the rest of the post off');
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
		self::assertSame('1', $this->board->row($id)['truncated']);
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testSafeAboutTheBeginningOfALongerPostIsNotAVerdictOnThePost(string $mode, int $visibility, string $status): void
	{
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$id = $this->pending(str_repeat('Długi testowy wpis. ', 200));

		$this->board->deliver(self::verdict($id, 'bezpieczne'));

		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($status, $this->board->row($id)['status']);
		self::assertSame('1', $this->board->row($id)['truncated']);
	}

	public function testAHeldLongerPostCarriesANoteForTheModerator(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_CLOSED));
		$id = $this->pending(str_repeat('Długi testowy wpis. ', 200));
		$this->board->deliver(self::verdict($id, 'bezpieczne'));

		$queue = new \phpbb\event\data(array('row' => array('post_id' => $id), 'post_row' => array('POST_SUBJECT' => 'Temat')));
		$this->board->listener->mark_queue_row($queue);
		$details = new \phpbb\event\data(array('post_id' => $id, 'post_data' => array()));
		$this->board->listener->show_queue_details($details);

		self::assertStringContainsString($this->board->language->lang('MINOS_MCP_TRUNCATED'), $queue['post_row']['POST_SUBJECT'], 'the note on the queue row');
		self::assertTrue($details['post_data']['S_MINOS_TRUNCATED']);
	}

	public function testABlockOfTheBeginningOfALongerPostStillBlocks(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN, settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending(str_repeat('Długi testowy wpis. ', 200));

		$this->board->deliver(self::verdict($id, 'zablokowane'));

		self::assertSame(ITEM_DELETED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testAPostOfExactly3000CharactersIsAssessedWhole(): void
	{
		// The title, the blank line and the text: 3000 characters together.
		$id = $this->board->posting(str_repeat('ż', 3000 - mb_strlen("Temat\n\n")), 'post', array('title' => 'Temat'))['post_id'];

		$this->board->deliver(self::verdict($id, 'bezpieczne'));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame('0', $this->board->row($id)['truncated']);
	}

	public function testATitleCountsWithinThe3000Characters(): void
	{
		$id = $this->board->posting(str_repeat('ż', 3000), 'post', array('title' => 'Temat'))['post_id'];

		$this->board->deliver(self::verdict($id, 'bezpieczne'));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'the end of the text was not assessed');
		self::assertSame('1', $this->board->row($id)['truncated']);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:int,3:string}>
	 */
	public function masksAndTitles(): array
	{
		return array(
			'a mask of the text only' => array("Temat rozmowy\n\nTo jest ████████ słowo.", 'To jest ████████ słowo.', ITEM_APPROVED, pending_store::MASKED),
			'a mask in the title'     => array("Temat ███████\n\nTo jest brzydkie słowo.", 'To jest brzydkie słowo.', ITEM_UNAPPROVED, pending_store::HELD),
			'a mask in both'          => array("Temat ███████\n\nTo jest ████████ słowo.", 'To jest brzydkie słowo.', ITEM_UNAPPROVED, pending_store::HELD),
			'a mask of the blank line' => array("Temat rozmowy██To jest ████████ słowo.", 'To jest brzydkie słowo.', ITEM_UNAPPROVED, pending_store::HELD),
		);
	}

	/**
	 * @dataProvider masksAndTitles
	 */
	public function testAMaskThatTouchesTheTitleIsLeftToAModeratorAndTheTitleIsNeverChanged(string $masked, string $text, int $visibility, string $status): void
	{
		$this->board->configure(array(settings::CENSORED => settings::CENSORED_PUBLISH));
		$id = $this->board->posting('To jest brzydkie słowo.', 'post', array('title' => 'Temat rozmowy'))['post_id'];

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array('ocenzurowany' => $masked)));

		self::assertSame(array($visibility, $status), array((int) $this->board->post($id)['post_visibility'], $this->board->row($id)['status']));
		self::assertSame($this->board->xml($text), $this->board->post($id)['post_text']);
		self::assertSame('Temat rozmowy', $this->board->title($id), 'the extension never changes a title');
		self::assertSame('Temat rozmowy', $this->board->post($id)['post_subject']);
		if ($status === pending_store::HELD)
		{
			self::assertSame('mask_manual', $this->board->row($id)['error_code']);
			$details = new \phpbb\event\data(array('post_id' => $id, 'post_data' => array()));
			$this->board->listener->show_queue_details($details);
			self::assertSame($masked, html_entity_decode(strip_tags($details['post_data']['MINOS_MASKED']), ENT_QUOTES, 'UTF-8'),
				'the moderator sees the whole mask, title included');
		}
	}

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function masksAndPolls(): array
	{
		return array(
			'a mask of the text only' => array("Temat\n\nPytanie?\nTak\nNie\n\nTo jest ████████ słowo.", ITEM_APPROVED, pending_store::MASKED),
			'a mask in an option'     => array("Temat\n\nPytanie?\n███\nNie\n\nTo jest brzydkie słowo.", ITEM_UNAPPROVED, pending_store::HELD),
			'a mask in the question'  => array("Temat\n\n████████\nTak\nNie\n\nTo jest ████████ słowo.", ITEM_UNAPPROVED, pending_store::HELD),
		);
	}

	/**
	 * @dataProvider masksAndPolls
	 */
	public function testAMaskThatTouchesThePollIsLeftToAModeratorAndThePollIsNeverChanged(string $masked, int $visibility, string $status): void
	{
		$this->board->configure(array(settings::CENSORED => settings::CENSORED_PUBLISH));
		$id = $this->board->posting('To jest brzydkie słowo.', 'post', array('title' => 'Temat',
			'poll' => array('question' => 'Pytanie?', 'options' => array('Tak', 'Nie'))))['post_id'];

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array('ocenzurowany' => $masked)));

		self::assertSame(array($visibility, $status), array((int) $this->board->post($id)['post_visibility'], $this->board->row($id)['status']));
		$poll = $this->board->forum->poll_of($this->board->forum->load_post($id));
		self::assertSame("Pytanie?\nTak\nNie", $poll, 'the extension never changes a poll');
		if ($status === pending_store::HELD)
		{
			self::assertSame('mask_manual', $this->board->row($id)['error_code']);
		}
	}

	public function testBlockedIsHeldByDefault(): void
	{
		$id = $this->pending();

		$this->board->deliver(self::verdict($id, 'zablokowane', array('kategorie' => array('nekanie'))));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testBlockedWithDeleteIsSoftDeletedWithoutSkewingPhpbbsCounters(): void
	{
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending();

		$this->board->deliver(self::verdict($id, 'zablokowane'));

		self::assertSame(ITEM_DELETED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::DELETED, $this->board->row($id)['status']);
		$calls = $this->board->visibility->calls;
		$deletion = end($calls);
		self::assertSame(ITEM_DELETED, $deletion['visibility']);
		self::assertSame(array($id => ITEM_APPROVED), $deletion['before'],
			'phpBB accounts a deletion as if the post had been approved');
		self::assertSame('Minos: treść zablokowana', $deletion['reason']);
		self::assertNotContains(array('add', 'notification.type.topic', $id), $this->board->notifications->calls,
			'nobody is notified of a post that is deleted at once');
		self::assertSame(array('begin', 'commit'), $this->board->db->transactions, 'the approval and the deletion share one transaction');
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function blockedSettings(): array
	{
		return array('hold' => array(settings::HOLD), 'delete' => array(settings::BLOCKED_DELETE));
	}

	/**
	 * @dataProvider blockedSettings
	 */
	public function testABlockedPostWithSupportIsHeldForTheModeratorsAndNeverDeleted(string $blocked): void
	{
		// The plugins' shared rule A7: `wsparcie` is a signal for the moderators, never an
		// automatic removal. Under "Usuń miękko" such a post used to be soft-deleted.
		$this->board->configure(array(settings::BLOCKED => $blocked));
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$notified = count($this->board->notifications->calls);

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'zablokowane', array('kategorie' => array('samookaleczenie'), 'wsparcie' => true))));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'still in the approval queue');
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->visibility->calls, 'never deleted');
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_POST_DELETED'));
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_HELD'));
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
		$kinds = array_unique(array_column(array_slice($this->board->notifications->calls, $notified), 1));
		self::assertSame(array('notification.type.topic_in_queue'), array_values($kinds),
			'the moderators are told of the post in their queue, and nobody else of anything');

		$queue = new \phpbb\event\data(array('row' => array('post_id' => $id), 'post_row' => array('POST_SUBJECT' => 'Temat')));
		$this->board->listener->mark_queue_row($queue);
		self::assertStringContainsString('potrzebne wsparcie', $queue['post_row']['POST_SUBJECT'], 'the mark on the queue row');
		$details = new \phpbb\event\data(array('post_id' => $id, 'post_data' => array()));
		$this->board->listener->show_queue_details($details);
		self::assertTrue($details['post_data']['S_MINOS_SUPPORT'], 'the note above the post');
		self::assertSame('samookaleczenie', $details['post_data']['MINOS_CATEGORIES'], 'the categories above the post');
	}

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function failureModes(): array
	{
		return array(
			'fail-open'   => array(settings::FAIL_OPEN, ITEM_APPROVED, pending_store::PUBLISHED),
			'fail-closed' => array(settings::FAIL_CLOSED, ITEM_UNAPPROVED, pending_store::HELD),
		);
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testUnassessedFollowsTheFailureMode(string $mode, int $visibility, string $status): void
	{
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$id = $this->pending();

		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'nieocenione')));

		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($status, $this->board->row($id)['status']);
		self::assertSame('nieocenione', $this->board->row($id)['verdict']);
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testAQualificationTheClientDoesNotKnowIsNeverAVerdict(string $mode, int $visibility, string $status): void
	{
		$this->board->configure(array(settings::FAIL_MODE => $mode, settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending();

		$this->board->deliver(self::verdict($id, 'usuniete_calkowicie'));

		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($status, $this->board->row($id)['status']);
	}

	public function testASafePostWithSupportIsPublishedAndReportedToItsModerators(): void
	{
		// The plugins' shared rule A7: an active signal in the moderators' own queue. A
		// published post is not in the approval queue, so it is a report in the MCP's
		// "Reported posts" (before, only the moderator log said so).
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->as_the_webhooks_guest();
		$notified = count($this->board->notifications->calls);

		$this->board->deliver(self::verdict($id, 'bezpieczne', array('kategorie' => array('samookaleczenie'), 'wsparcie' => true)));

		$row = $this->board->row($id);
		self::assertSame(array('1', 'samookaleczenie'), array($row['support'], $row['categories']));
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility'], 'support is a cue, not a verdict');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
		$reports = $this->board->reports->of($id);
		self::assertCount(1, $reports, 'one report');
		self::assertSame(array((string) ANONYMOUS, '4', '0'), array($reports[0]['user_id'], $reports[0]['reason_id'], $reports[0]['report_closed']),
			'filed by the guest, reason "other", open');
		self::assertStringContainsString('potrzebne wsparcie', $reports[0]['report_text']);
		self::assertStringContainsString('bezpieczne', $reports[0]['report_text']);
		self::assertStringNotContainsString('złym dniu', $reports[0]['report_text'], 'the extension\'s text, never the post\'s');
		self::assertSame('1', $this->board->post($id)['post_reported']);
		self::assertContains(array('add', 'notification.type.report_post', $id), array_slice($this->board->notifications->calls, $notified),
			'phpBB tells the moderators of the report');
		self::assertSame(array(ANONYMOUS), $this->board->reports->notified_as);
		self::assertSame(array(ANONYMOUS), $this->board->auth->loaded_for, 'the guest\'s request needs no change of user');
	}

	public function testAPublishedMaskWithSupportIsReportedToo(): void
	{
		$id = $this->pending('To jest brzydkie słowo w teście.');
		$title = $this->board->title($id);

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array(
			'kategorie'    => array('samookaleczenie'),
			'wsparcie'     => true,
			'ocenzurowany' => $title . "\n\nTo jest ████████ słowo w teście.",
		)));

		self::assertSame(pending_store::MASKED, $this->board->row($id)['status']);
		self::assertCount(1, $this->board->reports->of($id));
	}

	public function testARepeatedDeliveryWithSupportFilesOneReport(): void
	{
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$payload = self::verdict($id, 'bezpieczne', array('kategorie' => array('samookaleczenie'), 'wsparcie' => true));

		self::assertSame(200, $this->board->deliver($payload));
		self::assertSame(200, $this->board->deliver($payload));

		self::assertCount(1, $this->board->reports->of($id));
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
	}

	public function testAPostSomeoneReportedAlreadyIsNotReportedAgain(): void
	{
		// phpBB refuses a second open report of a post: the moderators have one in their queue.
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->auth->local['f_list'][Board::FORUM] = true;
		$this->board->auth->local['f_read'][Board::FORUM] = true;
		$this->board->auth->local['f_report'][Board::FORUM] = true;
		$this->board->reports->add_report($id, 2, '', 0);

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne', array('wsparcie' => true))));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(array((string) Board::USER), array_column($this->board->reports->of($id), 'user_id'), 'the member\'s report only');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));
		self::assertSame(array(), $this->board->log->of('LOG_MINOS_SUPPORT_REPORT_FAILED'), 'not a failure: the report is there');
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function refusalCodes(): array
	{
		return array(
			'a language key'      => array('NOT_AUTHORISED_BY_ANOTHER_EXTENSION', 'NOT_AUTHORISED_BY_ANOTHER_EXTENSION'),
			'not a language key'  => array('Odmowa z opisem, który mógłby cokolwiek zawierać', 'report_permission_denied_exception'),
		);
	}

	/**
	 * @dataProvider refusalCodes
	 */
	public function testAReportAnotherExtensionRefusesIsInTheErrorLogAndThePostStaysPublished(string $message, string $code): void
	{
		// Review of #8, minor 2: another extension's listener of core.report_post_auth, run after
		// this one, adds a check the guest fails; the signal must not vanish without a trace.
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$ours = $this->board->reports->on_auth;
		$this->board->reports->on_auth = static function (\phpbb\event\data $event) use ($ours, $message) {
			call_user_func($ours, $event);
			$checks = $event['acl_check_ary'];
			$checks['u_minos_test_other_extension'] = $message;
			$event['acl_check_ary'] = $checks;
		};

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne', array('wsparcie' => true))));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility'], 'published all the same');
		self::assertSame(pending_store::PUBLISHED, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->reports->of($id));
		$failures = $this->board->log->of('LOG_MINOS_SUPPORT_REPORT_FAILED');
		self::assertCount(1, $failures, 'the lost signal is in the error log');
		self::assertSame(array('critical', array($id, $code)), array($failures[0]['mode'], $failures[0]['data']),
			'the post and phpBB\'s code, nothing of its text');
	}

	public function testOnlyTheExtensionsOwnReportSkipsPhpbbsPermissionCheck(): void
	{
		// The board's users have no `f_report` here, as phpBB's guests by default.
		$id = $this->pending();

		try
		{
			$this->board->reports->add_report($id, 4, 'Zgłoszenie testowe', 0);
			self::fail('a report without f_report is refused');
		}
		catch (\phpbb\report\exception\report_permission_denied_exception $e)
		{
			// phpBB's own refusal of a reporter without the forum's permissions.
		}
		self::assertSame(array(), $this->board->reports->of($id));
		self::assertSame(0, $this->board->forum->reporting(), 'the extension is reporting nothing now');
	}

	public function testTheOwnReportOpensPhpbbsCheckForItsPostOnly(): void
	{
		// Review of #8, minor 1: while the extension files its report, a report of another post
		// (here by a listener of the report's notification) is checked as phpBB checks it.
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$other = $this->pending();
		$board = $this->board;
		$previous = $board->notifications->on_add;
		$refused = false;
		$board->notifications->on_add = static function (\phpbb\event\data $event) use ($board, $previous, $other, &$refused) {
			call_user_func($previous, $event);
			if ($event['notification_type_name'] !== 'notification.type.report_post')
			{
				return;
			}
			try
			{
				$board->reports->add_report($other, 4, 'Zgłoszenie testowe', 0);
			}
			catch (\phpbb\report\exception\report_permission_denied_exception $e)
			{
				$refused = true;
			}
		};

		$this->board->deliver(self::verdict($id, 'bezpieczne', array('wsparcie' => true)));

		self::assertCount(1, $this->board->reports->of($id), 'the extension\'s own report');
		self::assertTrue($refused, 'the other post\'s report met phpBB\'s check');
		self::assertSame(array(), $this->board->reports->of($other));
	}

	public function testAReportThatFailsLeavesPhpbbsCheckClosedAndTheUserRestored(): void
	{
		// Review of #8, minor 1: whatever add_report() throws, the extension stops reporting.
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$this->board->user->data = array('user_id' => Board::USER, 'user_permissions' => '');
		$this->board->reports->fail_with = new \RuntimeException('a database error');

		$this->board->deliver(self::verdict($id, 'bezpieczne', array('wsparcie' => true)));
		$this->board->reports->fail_with = null;

		self::assertSame(0, $this->board->forum->reporting());
		self::assertSame(array('user_id' => Board::USER, 'user_permissions' => ''), $this->board->user->data, 'the member, back');
		self::assertSame(pending_store::RECEIVED, $this->board->row($id)['status'], 'rolled back, for the cron task');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_ROW_FAILED'));
		try
		{
			$this->board->reports->add_report($id, 4, 'Zgłoszenie testowe', 0);
			self::fail('the member\'s report without f_report is refused');
		}
		catch (\phpbb\report\exception\report_permission_denied_exception $e)
		{
			// phpBB's own refusal, as before the failed report.
		}
		self::assertSame(array(), $this->board->reports->of($id));
	}

	public function testAVerdictAppliedInAMembersRequestIsReportedAsTheGuestAndTheMemberIsRestored(): void
	{
		// The cron task runs in whichever request triggers it, also a member's page view:
		// phpBB names the request's user as the reporter.
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');
		$body = json_encode(self::verdict($id, 'bezpieczne', array('wsparcie' => true)));
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
			$this->board->receiver, $this->board->deferred, $this->board->dispatcher);
		$controller->handle();
		$this->board->user->data = array('user_id' => Board::USER, 'user_permissions' => 'a member\'s');

		$this->board->sweeper()->sweep(time());

		self::assertSame(array((string) ANONYMOUS), array_column($this->board->reports->of($id), 'user_id'));
		self::assertSame(array(ANONYMOUS), $this->board->reports->notified_as, 'the notification names the guest too');
		self::assertSame(array('user_id' => Board::USER, 'user_permissions' => 'a member\'s'), $this->board->user->data, 'the member, back');
		self::assertSame(array(ANONYMOUS, Board::USER), $this->board->auth->loaded_for, 'and her permissions, reloaded');
	}

	public function testAPostAModeratorDealtWithFirstIsLeftAlone(): void
	{
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending();
		$this->board->db->sql_query('UPDATE phpbb_posts SET post_visibility = ' . ITEM_APPROVED . ' WHERE post_id = ' . $id);

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'zablokowane')));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testAVerdictForTheTextBeforeAnEditIsIgnored(): void
	{
		$id = $this->pending('Pierwsza wersja wpisu.');
		$this->board->edit($id, 'Druga wersja wpisu.');

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);

		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id . '.1') + self::verdict($id, 'bezpieczne')));
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testTheAnswerIsGivenBeforeTheVerdictIsApplied(): void
	{
		$id = $this->pending();
		$body = json_encode(self::verdict($id, 'bezpieczne'));
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
			$this->board->receiver, $this->board->deferred, $this->board->dispatcher);

		self::assertSame(200, $controller->handle()->getStatusCode());
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'nothing slow before the answer');
		self::assertSame(pending_store::RECEIVED, $this->board->row($id)['status']);

		$this->board->dispatcher->terminate();
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testAVerdictLeftUnappliedIsAppliedByTheCronTask(): void
	{
		$id = $this->pending();
		$body = json_encode(self::verdict($id, 'bezpieczne'));
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
			$this->board->receiver, $this->board->deferred, $this->board->dispatcher);
		$controller->handle();
		// The process ends before kernel.terminate runs.

		$this->board->sweeper()->sweep(time());

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::PUBLISHED, $this->board->row($id)['status']);
	}

	/**
	 * The request's user as in a webhook delivery: the guest, with the guest's permissions.
	 */
	private function as_the_webhooks_guest(): void
	{
		$this->board->user->data = array('user_id' => ANONYMOUS, 'user_permissions' => '');
	}

	/**
	 * A post held and accepted by the gateway.
	 */
	private function pending(string $text = 'Zwykły testowy wpis o pogodzie.'): int
	{
		$posted = $this->board->posting($text);
		self::assertSame(pending_store::PENDING, $this->board->row($posted['post_id'])['status']);
		return $posted['post_id'];
	}

	/**
	 * A payload as the gateway builds it.
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private static function verdict(int $post_id, string $qualification, array $extra = array()): array
	{
		return $extra + array(
			'id'           => 'phpbb:' . $post_id,
			'status'       => 'ocenione',
			'kwalifikacja' => $qualification,
			'kategorie'    => array(),
			'wsparcie'     => false,
			'wersja'       => '3f0c9a41d2b7e8c5',
		);
	}
}
