<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\event;

use minos\moderation\service\verdict_applier;

/**
 * Applies the verdicts the webhook recorded, after its answer was sent.
 *
 * The webhook controller registers {@see on_kernel_terminate} for `kernel.terminate` only
 * when it recorded something, so other requests never build the services behind it. phpBB's
 * `app.php` sends the response and then dispatches `kernel.terminate`; with PHP-FPM the
 * gateway already has its `200` by then. Approving a post can send notifications and
 * e-mails, which must not eat into the 10 seconds the gateway gives a delivery. A verdict
 * this step leaves unapplied (an error, a stopped process) stays `received` and the cron
 * task applies it.
 */
class deferred_verdicts
{
	/** @var verdict_applier */
	protected $applier;

	/** @var \minos\moderation\platform\forum */
	protected $forum;

	/** @var array<int,int> Posts whose recorded outcome waits for this request's end. */
	protected $post_ids = array();

	/**
	 * @param verdict_applier                    $applier Applies one recorded outcome.
	 * @param \minos\moderation\platform\forum $forum   The error log for a row that fails.
	 */
	public function __construct(verdict_applier $applier, \minos\moderation\platform\forum $forum)
	{
		$this->applier = $applier;
		$this->forum = $forum;
	}

	/**
	 * Remembers a post whose recorded outcome is to be applied.
	 *
	 * @param int $post_id The post.
	 * @return void
	 */
	public function add($post_id)
	{
		$this->post_ids[(int) $post_id] = (int) $post_id;
	}

	/**
	 * Applies what was remembered. A failure leaves the row for the cron task.
	 *
	 * @return void
	 */
	public function on_kernel_terminate()
	{
		$post_ids = $this->post_ids;
		$this->post_ids = array();
		foreach ($post_ids as $post_id)
		{
			try
			{
				$this->applier->apply($post_id, time());
			}
			catch (\Throwable $e)
			{
				// The transaction was rolled back; the row stays `received` for the cron task.
				$this->forum->log_failure('apply', $post_id);
			}
		}
	}
}
