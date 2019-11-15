<?php
/**
 * EGroupware - Collabora Admin functions
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package Collabora
 * @copyright (c) 2017  Nathan Gray
 */

namespace EGroupware\Collabora;

use EGroupware\Api;
use EGroupware\Api\Egw;

/**
 * Edit the settings for this app.
 * We use the standard admin interface from the admin app, so not much needs
 * to be here
 */
class Admin
{
	/**
	 * Collabora config managed by EGroupware
	 */
	const LOOLWSD_CONFIG = '/var/lib/egroupware/default/loolwsd/loolwsd.xml';

	/**
	 * Admin sidebox tree leaves
	 */
	public static function admin_sidebox($data)
	{
		$appname = 'collabora';

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				'Site Configuration' => Egw::link('/index.php','menuaction=admin.admin_config.index&appname=' . $appname,'&ajax=true')
			);
			if ($data['location'] == 'admin')
			{
				display_section($appname,$file);
			}
			else
			{
				display_sidebox($appname,lang('Admin'),$file);
			}
		}
	}

	/**
	 * Set configuration defaults
	 *
	 * @param Array $data
	 */
	public static function config($data)
	{
		if(!array_key_exists('server', $data))
		{
			$data['server'] = self::get_default_server();
		}

		// Check server by trying discovery url and report supported
		try
		{
			$discovery = Bo::discover($data['server']);
			$data['server_status'] = lang('%1 supported document types', count($discovery));
			$data['server_status_class'] = count($discovery) ? 'ok' : 'error';
		}
		catch (\Exception $e)
		{
			$data['server_status'] = $e->getMessage();
			$data['server_status_class'] = 'error';
		}
		// check if we have an (active) anonymous user, required for Collabora / sharing
		if (Api\Accounts::is_active('anonymous'))
		{
			$data['anonymous_status'] = lang("Active user 'anonymous' found.");
			$data['anonymous_status_class'] = 'ok';
		}
		else
		{
			$data['anonymous_status'] = lang("No active user 'anonymous' found!");
			$data['anonymous_status_class'] = 'error';
		}

		// disable local Collabora configuration, if not manged by EGroupware
		if (!file_exists(dirname(self::LOOLWSD_CONFIG)) || !file_exists(self::LOOLWSD_CONFIG))
		{
			$data['no_managed_collabora'] = true;
		}
		// if Collabora server is managed by EGroupware (egroupware-collabora-key package) and URL is the default one
		elseif ($data['server'] === self::get_default_server())
		{
			$server = self::get_managed_server();

			if ($server !== $data['server'])
			{
				// try the managed server under EGroupware's own URL
				try {
					$discovery = Bo::discover($server);
					if (count($discovery))
					{
						$data['server'] = $server;
						$data['server_status'] = lang('%1 supported document types', count($discovery));
						$data['server_status_class'] = count($discovery) ? 'ok' : 'error';
					}
				}
				catch (\Exception $ex) {
					// ignore exception --> stay with default server
				}
			}
		}
		//error_log(__METHOD__."() returning ".array2string($data));
		return $data;
	}

	/**
	 * Validate the configuration
	 *
	 * @param Array $data
	 */
	public static function validate($data)
	{
		// Validate server by checking discovery
		Api\Cache::setInstance('collabora', 'discovery', false);

		if (!isset($data['server']) && ($server = self::get_managed_server()))
		{
			$_POST['newsettings']['server'] = $data['server'] = $server;
		}

		try
		{
			$discovery = Bo::discover($data['server']);
		}
		catch (\Exception $e)
		{
			$error = $e->getMessage();
		}
		if(!$discovery && !$error)
		{
			$error = lang('Unable to connect');
		}
		// check if we have a local Collabora instance managed by EGroupware
		if (!$error && $data['location'] === 'config_validate' &&
			file_exists(dirname(self::LOOLWSD_CONFIG)) && file_exists(self::LOOLWSD_CONFIG))
		{
			$server_parsed = parse_url($data['server']);
			$error = self::update_loolwsd_config(array(
				'support_key' => $data['support_key'],
				// we proxy and do ssl termination outside of container!
				'termination' => $server_parsed['scheme'] === 'https' ? 'true' : 'false',
				'host' => str_replace('.', '\\.', $server_parsed['host']),
				'username' => $data['username'],
				'password' => $data['password'],
			));
		}
		if($error)
		{
			Api\Etemplate::set_validation_error('server', $error, 'newsettings');
			$GLOBALS['config_error'] = $error;
		}
	}

	/**
	 * Update config of managed Collbora at self::LOOLWSD_CONFIG
	 *
	 * File is only updated, when there is a real change to avoid unnecessary reloads.
	 *
	 * @param array $data key => value pairs to replace
	 * @return string error-message or null on success
	 */
	protected static function update_loolwsd_config(array $data)
	{
		if (!($content = file_get_contents(self::LOOLWSD_CONFIG)))
		{
			return lang('Can NOT read Collobora configuration in %1!', self::LOOLWSD_CONFIG);
		}
		$md5_before = md5($content);
		foreach($data as $name => $value)
		{
			$name_quoted = preg_quote($name, '|');
			$value_escaped = htmlspecialchars($value, ENT_XML1);

			$matches = null;
			if (!preg_match("|<$name_quoted([^>]*)>(.*)</$name_quoted>|", $content, $matches))
			{
				$content = preg_replace('|</config>|',
					"    <$name>$value_escaped</$name>\n</config>", $content);
			}
			else
			{
				$value_regexp = $name !== 'host' ? ')>.*' : 'allow="true")>.+';
				$content = preg_replace("|^((\s*<{$name_quoted}[^>]*$value_regexp</$name_quoted>\n)+|m",
					"\\2>$value_escaped</$name>\n", $content);
			}
		}
		// do NOT update, if there is no change (as it caused Collabora to restart unnecessary)
		if (md5($content) !== $md5_before && !file_put_contents(self::LOOLWSD_CONFIG, $content))
		{
			return lang('Failed to update Collabora configuration in %1!', self::LOOLWSD_CONFIG);
		}
	}

	/**
	 * Get the default value for the server address
	 *
	 * @return String Default server address
	 */
	public static function get_default_server()
	{
		return 'https://collabora.egroupware.org';
	}

	/**
	 * Get url of managed Collabora server (egroupware-collabora-key package)
	 *
	 * @return string url of managed server or null
	 */
	public static function get_managed_server()
	{
		// disable local Collabora configuration, if not managed by EGroupware
		if (!file_exists(dirname(self::LOOLWSD_CONFIG)) || !file_exists(self::LOOLWSD_CONFIG))
		{
			return null;
		}
		$server = substr(Api\Header\Http::fullUrl('/'), 0, -1);		// remove trailing slash

		return $server;
	}
}
