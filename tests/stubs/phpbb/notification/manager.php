<?php

namespace phpbb\notification;

/**
 * Stub and fake of phpBB's notification manager: records every call.
 */
class manager
{
	/** @var array<int,array{0:string,1:string,2:int}> [action, type, post or topic id]. */
	public $calls = array();

	public function add_notifications($notification_type_name, $data, array $options = array())
	{
		foreach ((array) $notification_type_name as $type)
		{
			$this->calls[] = array('add', $type, (int) $data['post_id']);
		}
		return array();
	}

	public function delete_notifications($notification_type_name, $item_id, $parent_id = false, $user_id = false)
	{
		foreach ((array) $notification_type_name as $type)
		{
			$this->calls[] = array('delete', $type, (int) $item_id);
		}
	}
}
