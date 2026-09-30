<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\cron;

use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\service\submitter;
use minos\moderation\service\verdict_applier;

/**
 * The cron task, every five minutes.
 *
 * 1. applies verdicts the webhook recorded but did not apply (a process that stopped);
 * 2. gives the failure mode to posts that waited longer than the timeout without a verdict
 *    (the gateway may give up on a delivery, and keeps no verdict after its TTL);
 * 3. sends again the posts whose retry is due;
 * 4. forgets finished rows after 30 days.
 *
 * It runs even when the administrator switched the extension off, so that no post it held
 * stays in the queue for good.
 */
class sweeper extends \phpbb\cron\task\base
{
	/** Seconds between runs. */
	const INTERVAL_S = 300;

	/** Seconds a finished row is kept (the ACP list, the MCP notes, the original text). */
	const RETENTION_S = 2592000;

	/** Rows handled per step and run, so that one run stays short. */
	const BATCH = 100;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var settings */
	protected $settings;

	/** @var pending_store */
	protected $store;

	/** @var submitter */
	protected $submitter;

	/** @var verdict_applier */
	protected $applier;

	/**
	 * @param \phpbb\config\config $config    phpBB's configuration (the last run).
	 * @param settings             $settings  The administrator's settings.
	 * @param pending_store        $store     The pending rows.
	 * @param submitter            $submitter Sends posts to the gateway.
	 * @param verdict_applier      $applier   Applies outcomes.
	 */
	public function __construct(\phpbb\config\config $config, settings $settings, pending_store $store, submitter $submitter, verdict_applier $applier)
	{
		$this->config = $config;
		$this->settings = $settings;
		$this->store = $store;
		$this->submitter = $submitter;
		$this->applier = $applier;
	}

	/**
	 * Whether the last run is at least {@see INTERVAL_S} old.
	 *
	 * @return bool
	 */
	public function should_run()
	{
		return (int) $this->config[settings::LAST_SWEEP] <= time() - self::INTERVAL_S;
	}

	/**
	 * Runs the task.
	 *
	 * @return void
	 */
	public function run()
	{
		$now = time();
		$this->config->set(settings::LAST_SWEEP, $now, false);
		$this->sweep($now);
	}

	/**
	 * The four steps.
	 *
	 * @param int $now Unix seconds.
	 * @return array<string,int> How many rows each step touched.
	 */
	public function sweep($now)
	{
		$done = array('applied' => 0, 'timed_out' => 0, 'resubmitted' => 0, 'pruned' => 0);

		foreach ($this->store->received(self::BATCH) as $row)
		{
			$done['applied'] += ($this->applier->apply((int) $row['post_id'], $now) !== null) ? 1 : 0;
		}

		foreach ($this->store->overdue($now - $this->settings->timeout_seconds(), self::BATCH) as $row)
		{
			$done['timed_out'] += ($this->applier->fail($row, pending_store::VERDICT_TIMEOUT, '', $now) !== null) ? 1 : 0;
		}

		if ($this->settings->is_ready())
		{
			$due = $this->store->due($now, self::BATCH);
			$this->submitter->submit($due, $now);
			$done['resubmitted'] = count($due);
		}

		$done['pruned'] = $this->store->prune($now - self::RETENTION_S);
		return $done;
	}
}
