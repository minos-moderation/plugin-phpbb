<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's event dispatcher, for `kernel.terminate` only: what `app.php` runs after the
 * response was sent.
 */
class RecordingDispatcher implements \Symfony\Component\EventDispatcher\EventDispatcherInterface
{
	/** @var array<string,array<int,array{0:callable,1:int}>> */
	public $listeners = array();

	public function addListener($eventName, $listener, $priority = 0)
	{
		$this->listeners[$eventName][] = array($listener, $priority);
	}

	/**
	 * Runs and forgets the `kernel.terminate` listeners, highest priority first.
	 *
	 * @return void
	 */
	public function terminate()
	{
		$listeners = isset($this->listeners['kernel.terminate']) ? $this->listeners['kernel.terminate'] : array();
		unset($this->listeners['kernel.terminate']);
		usort($listeners, static function (array $a, array $b) {
			return $b[1] - $a[1];
		});
		foreach ($listeners as $listener)
		{
			call_user_func($listener[0]);
		}
	}
}
