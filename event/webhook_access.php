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

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lets the gateway's deliveries through while the board is disabled.
 *
 * `app.php` runs `user::setup()` before any route; on a disabled board ("Disable board",
 * `board_disable`) and on a board over its load limit (`limit_load`) it answers a guest -
 * the gateway is one - with phpBB's own page and `200`, so the gateway takes the delivery as
 * done and the verdict is lost (seen on a live board). The extension applies a verdict as a
 * moderator would, and readers see nothing of it until the board is enabled again, so the
 * webhook stays open: for `POST /minos/webhook` only, on `core.user_setup` (which phpBB
 * triggers before both checks), it defines `SKIP_CHECK_DISABLED` - phpBB's own way past the
 * first check (`ucp.php`, `memberlist.php`) - and sets `limit_load` to 0 for this request
 * only (in memory; phpBB itself changes `board_disable` in memory there). No other route
 * passes. The check for phpBB's `install/` directory (an update of phpBB in progress) still
 * answers the webhook with phpBB's page: see the README.
 *
 * Kept apart from {@see listener}: phpBB triggers `core.user_setup` on every page, and this
 * needs nothing but the request and the configuration.
 */
class webhook_access implements EventSubscriberInterface
{
	/** The webhook's path (config/routing.yml), as phpBB's request reads it. */
	const PATH = '/minos/webhook';

	/** @var Request */
	protected $request;

	/** @var \phpbb\config\config */
	protected $config;

	/**
	 * @param Request              $request The current request (phpBB's `symfony_request`).
	 * @param \phpbb\config\config $config  The board's configuration.
	 */
	public function __construct(Request $request, \phpbb\config\config $config)
	{
		$this->request = $request;
		$this->config = $config;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			'core.user_setup' => 'let_deliveries_in',
		);
	}

	/**
	 * Opens a disabled or overloaded board to a delivery of the gateway, and to nothing else.
	 *
	 * @param \phpbb\event\data $event `core.user_setup`.
	 * @return void
	 */
	public function let_deliveries_in($event)
	{
		if ($this->request->getPathInfo() !== self::PATH || !$this->request->isMethod('POST'))
		{
			return;
		}
		if (!defined('SKIP_CHECK_DISABLED'))
		{
			define('SKIP_CHECK_DISABLED', true);
		}
		$this->config['limit_load'] = '0';
	}
}
