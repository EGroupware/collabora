<?php
/**
 * Hooks for Collabora app
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

use EGroupware\Api;

class Hooks {
	/**
	 * Allow collabora host as frame src, otherwise opening the editor fails
	 *
	 * @return array
	 */
	public static function csp_frame_src()
	{
		$config = Api\Config::read('collabora');
		$frm_srcs = array();
		if (!empty($config['server']))
		{
			$frm_srcs[] = $config['server'];
		}
		return $frm_srcs;
	}

	/**
	 * Gets links for open handler of collabora supported mime types
	 *
	 * @return array
	 */
	public static function getEditorLink()
	{
		try {
			$discover = Bo::discover();
		}
		catch (\Exception $e) {
			unset($e);
			return;
		}
		// Allow extra extensions
		$extraExt = array (
			'pdf'
		);
		foreach($discover as $mime => &$val)
		{
			if ($val['name'] != 'edit' && !in_array($val['ext'], $extraExt)) unset($discover[$mime]);
			$val['mime_popup'] = ''; // try to avoid mime_open exception
		}
		return array (
			'edit' => array(
				'menuaction' => 'collabora.EGroupware\\Collabora\\Ui.editor',
			),
			'mime' => $discover
		);
	}

	public static function isCollaborable($_mime)
	{
		try {
			$discover = Bo::discover();
		}
		catch (\Exception $e) {
			unset($e);
			return false;
		}
		return array_key_exists($_mime, $discover)?? false;
	}
}
