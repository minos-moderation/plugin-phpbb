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
 * webhook stays open: on `core.user_setup` (which phpBB triggers before both checks), for a
 * request that is all three - phpBB's front controller `app.php` as the script, the path
 * `/minos/webhook` after it, the method `POST` - it defines `SKIP_CHECK_DISABLED` - phpBB's
 * own way past the first check (`ucp.php`, `memberlist.php`) - and sets `limit_load` to 0 for
 * this request only (in memory; phpBB itself changes `board_disable` in memory there).
 *
 * The script matters: the path is what follows the script's name, so
 * `viewtopic.php/minos/webhook` has the same path and must stay closed (review of #7, N1:
 * a disabled board showed a topic that way). `SCRIPT_NAME` is the script the server runs -
 * PHP's built-in server, Apache and nginx alike, and `/app.php` (under the board's path)
 * when URL rewriting sends `/minos/webhook` there, as phpBB's `.htaccess` and nginx sample
 * do; a server that leaves the path in it gives another name, and the webhook stays closed.
 * No other script, route or method passes. The check for phpBB's `install/` directory (an
 * update of phpBB in progress) still answers the webhook with phpBB's page: see the README.
 *
 * Kept apart from {@see listener}: phpBB triggers `core.user_setup` on every page, and this
 * needs nothing but the request and the configuration.
 */
class webhook_access implements EventSubscriberInterface
{
	/** The webhook's path (config/routing.yml), as phpBB's request reads it. */
	const PATH = '/minos/webhook';

	/** phpBB's front controller, the only script that serves the webhook's route. */
	const FRONT_CONTROLLER = 'app.php';

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
	 * Opens a disabled or overloaded board to a delivery of the gateway - `POST` to
	 * `app.php/minos/webhook` - and to nothing else.
	 *
	 * @param \phpbb\event\data $event `core.user_setup`.
	 * @return void
	 */
	public function let_deliveries_in($event)
	{
		if (basename((string) $this->request->getScriptName()) !== self::FRONT_CONTROLLER
			|| $this->request->getPathInfo() !== self::PATH || !$this->request->isMethod('POST'))
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
