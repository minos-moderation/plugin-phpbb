<?php

namespace phpbb\auth;

/**
 * Stub of phpBB's permissions: global and per-forum grants set by the test.
 */
class auth
{
	/** @var array<string,bool> Global grants. */
	public $global = array();

	/** @var array<string,array<int,bool>> Per-forum grants. */
	public $local = array();

	/** @var array<int,int> Whose permissions were loaded, in order (`acl()`). */
	public $loaded_for = array();

	/**
	 * phpBB loads a user's permissions here; the stub records whose (its grants stay the
	 * test's).
	 *
	 * @param array<string,mixed> $userdata
	 * @return void
	 */
	public function acl(&$userdata)
	{
		$this->loaded_for[] = (int) $userdata['user_id'];
	}

	public function acl_get($opt, $f = 0)
	{
		if ($f && isset($this->local[$opt][$f]))
		{
			return $this->local[$opt][$f];
		}
		return !empty($this->global[$opt]);
	}
}
