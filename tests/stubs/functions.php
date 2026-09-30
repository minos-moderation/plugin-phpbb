<?php

/*
 * Stubs of the phpBB global functions the ACP page calls. `check_form_key()` answers what
 * the test put into $GLOBALS['minos_test_form_key_valid'].
 */

function add_form_key($form_name, $template_variable_suffix = '')
{
}

function check_form_key($form_name, $timespan = false)
{
	return !isset($GLOBALS['minos_test_form_key_valid']) || $GLOBALS['minos_test_form_key_valid'];
}

function make_forum_select($select_id = false, $ignore_id = false, $ignore_acl = false, $ignore_nonpost = false, $ignore_emptycat = true, $only_acl_post = false, $return_array = false)
{
	$html = '';
	foreach (array(2 => 'Ogólne', 3 => 'Dla młodzieży') as $id => $name)
	{
		$selected = (is_array($select_id) && in_array($id, $select_id, true)) ? ' selected="selected"' : '';
		$html .= '<option value="' . $id . '"' . $selected . '>' . $name . '</option>';
	}
	return $html;
}

function append_sid($url, $params = false, $is_amp = true, $session_id = false, $is_route = false)
{
	return $url . (($params !== false) ? '?' . $params : '');
}
