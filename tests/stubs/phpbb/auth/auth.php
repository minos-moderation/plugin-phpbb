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

	public function acl_get($opt, $f = 0)
	{
		if ($f && isset($this->local[$opt][$f]))
		{
			return $this->local[$opt][$f];
		}
		return !empty($this->global[$opt]);
	}
}
