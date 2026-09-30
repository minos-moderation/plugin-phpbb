<?php

namespace Symfony\Component\EventDispatcher;

/**
 * Stub of Symfony's event dispatcher interface: the member the extension calls.
 */
interface EventDispatcherInterface
{
	public function addListener($eventName, $listener, $priority = 0);
}
