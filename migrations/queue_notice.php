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
 * Whether the moderators were already told, by every method, that the extension left a post
 * in the queue for them (`announced`): they are told once per post, whatever happens to it
 * afterwards (a late verdict, an edit, the assessment switched off and on).
 */
class queue_notice extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_column_exists($this->table_prefix . 'minos_pending', 'announced');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\minos\moderation\migrations\verdict_times');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_columns' => array(
				$this->table_prefix . 'minos_pending' => array(
					'announced' => array('BOOL', 0),
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
				$this->table_prefix . 'minos_pending' => array('announced'),
			),
		);
	}
}
