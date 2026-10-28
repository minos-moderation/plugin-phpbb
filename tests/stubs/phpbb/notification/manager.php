<?php

namespace phpbb\notification;

/**
 * Stub and fake of phpBB's notification manager: records every call the extension makes
 * (`calls`), and delivers each notification the way phpBB 3.3's manager does (`sent`).
 *
 * Delivery, as `manager::add_notifications()` and `add_notifications_for_users()` do it:
 * the recipients phpBB would find (`recipients`, user id => the methods each one chose) go
 * through the `core.notification_manager_add_notifications` listeners (`on_add`, given the
 * event's data: `notification_type_name`, `data`, `notify_users`, `options`), then every
 * user already notified about the item by any method is skipped, and each method left
 * delivers. The board method's notifications go with `delete_notifications()`; the e-mail
 * method's record (phpBB 3.3's `notification_emails`) does not.
 */
class manager
{
	/** The board method. */
	const BOARD = 'notification.method.board';

	/** The e-mail method. */
	const EMAIL = 'notification.method.email';

	/** @var array<int,array{0:string,1:string,2:int}> [action, type, post or topic id]. */
	public $calls = array();

	/** @var array<int,array<int,string>> Who phpBB finds to notify (a moderator), and by which methods. */
	public $recipients = array(5 => array(self::BOARD, self::EMAIL));

	/** @var callable|null The listener of `core.notification_manager_add_notifications`. */
	public $on_add = null;

	/** @var array<int,array{0:string,1:int,2:int,3:string}> Deliveries: [type, item id, user id, method]. */
	public $sent = array();

	/** @var array<string,array<int,bool>> Notified users by method and item. */
	protected $notified = array();

	public function add_notifications($notification_type_name, $data, array $options = array())
	{
		foreach ((array) $notification_type_name as $type)
		{
			$this->calls[] = array('add', $type, (int) $data['post_id']);
			$this->deliver($type, $data, $options);
		}
		return array();
	}

	public function delete_notifications($notification_type_name, $item_id, $parent_id = false, $user_id = false)
	{
		foreach ((array) $notification_type_name as $type)
		{
			$this->calls[] = array('delete', $type, (int) $item_id);
			unset($this->notified[self::BOARD . ' ' . $type . ' ' . (int) $item_id]);
		}
	}

	/**
	 * What phpBB's own `submit_post()` asks for, outside the extension: delivered, not
	 * recorded among the extension's calls.
	 *
	 * @param string              $type
	 * @param array<string,mixed> $data
	 * @return void
	 */
	public function submit_post_notification($type, array $data)
	{
		$this->deliver($type, $data, array());
	}

	/**
	 * Deliveries of one kind.
	 *
	 * @param string $type
	 * @param string $method
	 * @return int
	 */
	public function delivered($type, $method)
	{
		$count = 0;
		foreach ($this->sent as $delivery)
		{
			$count += ($delivery[0] === $type && $delivery[3] === $method) ? 1 : 0;
		}
		return $count;
	}

	/**
	 * @param string              $type
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $options
	 * @return void
	 */
	protected function deliver($type, array $data, array $options)
	{
		$notify_users = $this->recipients;
		if ($this->on_add !== null)
		{
			$event = new \phpbb\event\data(array(
				'notification_type_name' => $type,
				'data'                   => $data,
				'notify_users'           => $notify_users,
				'options'                => $options,
			));
			call_user_func($this->on_add, $event);
			$notify_users = $event['notify_users'];
		}
		// `get_item_id()`: a topic type's item is the topic, any other type's the post.
		$item = in_array($type, array('notification.type.topic', 'notification.type.topic_in_queue'), true)
			? (int) $data['topic_id'] : (int) $data['post_id'];
		foreach ((array) $notify_users as $user => $methods)
		{
			if (isset($this->notified[self::BOARD . ' ' . $type . ' ' . $item][$user]) || isset($this->notified[self::EMAIL . ' ' . $type . ' ' . $item][$user]))
			{
				continue;
			}
			foreach ($methods as $method)
			{
				$this->sent[] = array($type, $item, (int) $user, $method);
				$this->notified[$method . ' ' . $type . ' ' . $item][$user] = true;
			}
		}
	}
}
