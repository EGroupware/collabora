<?php
/**
 * EGroupware - Collabora preferences
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

use EGroupware\Api\Config;
use EGroupware\Api\Vfs;
use EGroupware\Collabora\Wopi\Settings;

/**
 * Description of Preferences
 *
 * @author nathan
 */
class Preferences {

	public static function settings()
	{
		$settings = new Settings();
		$settings_directory = $settings->getSettingsPath("userconfig");
		if(!Vfs::file_exists($settings_directory))
		{
			Vfs::mkdir($settings_directory);
		}
		$config = Admin::config(array_merge(Config::read('collabora'), ['settings_directory' => [$settings_directory]]));
		$hidden = array('collabora_settings_iframe', 'token', 'wopi_url');
		$settings = array(
			array(
				'type'    => 'section',
				'title'   => lang('Collabora settings'),
				'tab'     => 'collabora.collabora-config',
				'no_lang' => true,
				'xmlrpc'  => False,
				'admin'   => False
			),
			'collabora_settings' => array(
				'type'       => 'iframe',
				'name'       => 'collabora_settings',
				'label'      => lang('Collabora settings'),
				'no_lang'    => true,
				'attributes' => array('src' => $config['collabora_settings_iframe'])
			),
			'iframe_type'        => array(
				'name'       => 'iframe_type',
				'type'       => 'hidden',
				'attributes' => array('value' => 'user')
			)
		);
		foreach($hidden as $key)
		{
			$settings[$key] = array(
				'name'       => $key,
				'type'       => 'hidden',
				'attributes' => array('value' => $config[$key])
			);
		}
		return $settings;
	}
}
