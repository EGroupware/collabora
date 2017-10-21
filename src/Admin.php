<?php

/**
 * Collabora Admin functions
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package Collabora
 * @copyright (c) 2017  Nathan Gray
 */

namespace EGroupware\collabora;

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
			$data['server'] = static::get_default_server();
		}

		// Try to check server status here - it should respond with 'OK'
		$ctx = stream_context_create(array(
			'http' => array(
				'timeout' => 5
				)
			)
		);
		if (!($status = file_get_contents($data['server'], false, $ctx, 0, 20)))
		{
			$status = lang('Unable to connect');
		}
		$data['server_status'] = $status;
		$data['server_status_class'] = $status == 'OK' ? 'ok' : 'error';

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

		return $data;
	}

	/**
	 * Validate the configuration
	 *
	 *
	 * @param Array $data
	 */
	public static function validate($data)
	{
		// Validate server by checking discovery
		Api\Cache::setInstance('collabora', 'discovery', false);
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
		if($error)
		{
			Api\Etemplate::set_validation_error('server', $error, 'newsettings');
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
}