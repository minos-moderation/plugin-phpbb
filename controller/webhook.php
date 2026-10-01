<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\controller;

use minos\moderation\event\deferred_verdicts;
use minos\moderation\service\receiver;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The webhook route, `POST /minos/webhook` (`app.php/minos/webhook` without URL rewriting).
 *
 * It reads the body from the Symfony request (the raw bytes of `php://input`, never phpBB's
 * escaped parameters), answers at once, and leaves applying the verdict to
 * {@see deferred_verdicts}, which runs after the answer was sent.
 */
class webhook
{
	/** @var Request */
	protected $request;

	/** @var receiver */
	protected $receiver;

	/** @var deferred_verdicts */
	protected $deferred;

	/** @var EventDispatcherInterface */
	protected $dispatcher;

	/** @var bool Whether {@see deferred_verdicts} is registered for this request's end. */
	protected $registered = false;

	/** The terminate event's priority: before phpBB's own subscriber, which runs last and exits. */
	const TERMINATE_PRIORITY = 10;

	/**
	 * @param Request                  $request    The current request (phpBB's `symfony_request`).
	 * @param receiver                 $receiver   The webhook's logic.
	 * @param deferred_verdicts        $deferred   Verdicts to apply after the answer.
	 * @param EventDispatcherInterface $dispatcher phpBB's event dispatcher.
	 */
	public function __construct(Request $request, receiver $receiver, deferred_verdicts $deferred, EventDispatcherInterface $dispatcher)
	{
		$this->request = $request;
		$this->receiver = $receiver;
		$this->deferred = $deferred;
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Handles one delivery.
	 *
	 * @return Response `200`, `400`, `401` or `404` (switched off), with an empty body (the
	 *     gateway discards it).
	 */
	public function handle()
	{
		list($status, $post_id) = $this->receiver->receive(
			(string) $this->request->getContent(),
			(string) $this->request->headers->get(receiver::SIGNATURE_HEADER, ''),
			time()
		);
		if ($post_id !== null)
		{
			$this->deferred->add($post_id);
			if (!$this->registered)
			{
				$this->dispatcher->addListener('kernel.terminate', array($this->deferred, 'on_kernel_terminate'), self::TERMINATE_PRIORITY);
				$this->registered = true;
			}
		}
		return new Response('', $status, array(
			'Content-Type'  => 'text/plain; charset=utf-8',
			'Cache-Control' => 'no-store',
		));
	}
}
