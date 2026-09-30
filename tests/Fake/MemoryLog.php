<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's log, recording every entry.
 */
class MemoryLog implements \phpbb\log\log_interface
{
	/** @var array<int,array{mode:string,operation:string,data:array}> */
	public $entries = array();

	public function add($mode, $user_id, $log_ip, $log_operation, $log_time = false, $additional_data = array())
	{
		$this->entries[] = array('mode' => $mode, 'operation' => $log_operation, 'data' => $additional_data);
		return count($this->entries);
	}

	/**
	 * @param string $operation
	 * @return array<int,array{mode:string,operation:string,data:array}>
	 */
	public function of($operation)
	{
		return array_values(array_filter($this->entries, static function (array $entry) use ($operation) {
			return $entry['operation'] === $operation;
		}));
	}
}
