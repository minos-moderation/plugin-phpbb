<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's request for a POSTed form. Like phpBB, `variable()` trims strings and escapes
 * them with htmlspecialchars; `raw_variable()` returns them as sent.
 */
class FormRequest implements \phpbb\request\request_interface
{
	/** @var array<string,mixed> */
	protected $post;

	/** @param array<string,mixed> $post */
	public function __construct(array $post = array())
	{
		$this->post = $post;
	}

	public function variable($var_name, $default, $multibyte = false, $super_global = self::REQUEST)
	{
		if (!isset($this->post[$var_name]))
		{
			return $default;
		}
		$value = $this->post[$var_name];
		if (is_array($default))
		{
			return array_map('intval', (array) $value);
		}
		if (is_int($default))
		{
			return (int) $value;
		}
		return htmlspecialchars(trim((string) $value), ENT_COMPAT, 'UTF-8');
	}

	public function raw_variable($var_name, $default, $super_global = self::REQUEST)
	{
		return isset($this->post[$var_name]) ? $this->post[$var_name] : $default;
	}

	public function is_set_post($name)
	{
		return isset($this->post[$name]);
	}
}
