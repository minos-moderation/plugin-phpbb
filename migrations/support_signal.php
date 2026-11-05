<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\migrations;

/**
 * Which earlier revision's `wsparcie` the moderators already got (`support_signalled`: that
 * revision + 1, 0 for none): a verdict with `wsparcie` for the text before an edit raises the
 * signal once (the plugins' shared rule A7), however often the gateway delivers it.
 */
class support_signal extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_column_exists($this->table_prefix . 'minos_pending', 'support_signalled');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\minos\moderation\migrations\signature_trace');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_columns' => array(
				$this->table_prefix . 'minos_pending' => array(
					'support_signalled' => array('USINT', 0),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_schema()
	{
		return array(
			'drop_columns' => array(
				$this->table_prefix . 'minos_pending' => array('support_signalled'),
			),
		);
	}
}
