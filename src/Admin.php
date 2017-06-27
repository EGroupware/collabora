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

use EGroupware\Api\Egw;
use EGroupware\Api\Framework;

/**
 * Edit the settings for this app.
 * We use the standard admin interface from the admin app, so not much needs
 * to be here
 *
 * Start CODE with
 * docker run -t -d -p 127.0.0.1:9980:9980 -e "domain=nathan.dev" -e "username=admin" -e "password=S3cRet" --restart always --name="collabora" --cap-add MKNOD --net host collabora/code
 * where domain is the egw domain
 */
class Admin {
	
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

		// TODO: If possible, would be good to check server status here

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
		\EGroupware\Api\Cache::setInstance('collabora', 'discovery', false);
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
			\EGroupware\Api\Etemplate::set_validation_error('server',$error,'newsettings');
		}
	}

	/**
	 * Get the default value for the server address
	 *
	 * @return String Default server address
	 */
	public static function get_default_server()
	{
		return 'collabora.'.$GLOBALS['egw_info']['server']['hostname'].':9980';
	}
}