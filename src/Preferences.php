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

	/**
	 * Hidden fields of the settings form, rebuilt on every request and never to be stored as preferences
	 *
	 * The token belongs to the current session, a stored one is stale and Collabora rejects it with
	 * "Settings are only available to signed-in users".
	 */
	const EPHEMERAL = ['collabora_settings_iframe', 'token', 'wopi_url', 'iframe_type'];

	/**
	 * Verify hook: keep the form's hidden fields out of the saved preferences
	 *
	 * @param array $hook_data 'prefs' is the (by reference) array of the app's preferences about to be saved
	 * @return false no error
	 */
	public static function verify_settings($hook_data)
	{
		foreach(self::EPHEMERAL as $key)
		{
			unset($hook_data['prefs'][$key]);
		}
		return false;
	}

	/**
	 * Settings hook: the user's own Collabora settings, edited in Collabora's settings iframe
	 *
	 * The iframe stores into the current user's own settings directory, so it can not edit default or forced
	 * preferences.  Those get a note pointing to the system-wide settings in the admin configuration instead.
	 *
	 * @param array $hook_data
	 * @return array
	 */
	public static function settings($hook_data = [])
	{
		if(($hook_data['type'] ?? 'user') !== 'user')
		{
			return [[
				'type'    => 'section',
				'title'   => lang('Collabora settings'),
				'tab'     => 'collabora.collabora-config-admin',
				'no_lang' => true,
				'xmlrpc'  => False,
				'admin'   => False
			]];
		}
		// Stored copies saved before verify_settings() existed would win over the fresh values
		foreach(self::EPHEMERAL as $key)
		{
			unset($GLOBALS['egw']->preferences->user['collabora'][$key]);
		}
		$settings = new Settings();
		$settings_directory = $settings->getSettingsPath("userconfig");
		// the iframe needs a directory to put the user's files into
		if(!Vfs::is_dir($settings_directory))
		{
			Vfs::mkdir($settings_directory, null, STREAM_MKDIR_RECURSIVE);
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
