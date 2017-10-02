<?php

/**
 * Hooks for Collabora app
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\collabora;

class Hooks {
	/**
	 * Allow collabora host as frame src, otherwise opening the editor fails
	 * 
	 * @return array
	 */
	public static function csp_frame_src()
	{
		$config = \EGroupware\Api\Config::read('collabora');
		$frm_srcs = array();
		if (($host = parse_url($config['server'], PHP_URL_HOST) . ':' . parse_url($config['server'], PHP_URL_PORT)))
		{
			$frm_srcs[] = $host;
		}
		return $frm_srcs;
	}

}
