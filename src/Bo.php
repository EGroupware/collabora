<?php

/**
 * App
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package 
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\collabora;

use EGroupware\Api\Cache;
use EGroupware\Api\Config;

/**
 * Description of Bo
 *
 * @author nathan
 */
class Bo {

	const DISCOVERY_CACHE_TIME = 30;//86400; // 1 day

	// These for the collabora server
	const DISCOVERY_URL = '/hosting/discovery';

	// EGroupware
	const WOPI_ENDPOINT = '/collabora/wopi/';

	/**
	 * Contact the collabora server and find out what it can do.
	 * Response is cached.
	 *
	 * Discovery information is stored in an array with key = mime.
	 *
	 * @param String $server Server url (with protocol).  If not provided, the
	 *	configured server will be used.
	 * 
	 * @return String[] List of types that can be handled, with mimetypes as keys.
	 *
	 * @throws \EGroupware\Api\Exception\WrongParameter If the server cannot be
	 *	contacted.
	 * @throws \EGroupware\Api\Exception\AssertionFailed if the server responds,
	 *	but the XML cannot be parsed
	 */
	public static function discover($server = '')
	{
		$discovery = Cache::getInstance('collabora', 'discovery');
		if($discovery)
		{
			return $discovery;
		}

		$server_url = ($server ? $server : static::get_server()) . self::DISCOVERY_URL;
		$discovery = array();

		try
		{
			if (($response_xml_data = file_get_contents($server_url))===false)
			{
				throw new \EGroupware\Api\Exception\WrongParameter('Unable to load ' . $server_url);
			} 
			libxml_use_internal_errors(true);
			$data = simplexml_load_string($response_xml_data);
			if (!$data) {
				$msg = "Error loading XML\n";
				foreach(libxml_get_errors() as $error) {
					$msg .= "\t". $error->message;
				}
				throw new \EGroupware\Api\Exception\AssertionFailed($msg);
			}

			// Iterate through & extract the data
			foreach($data->{'net-zone'}->app as $app)
			{
				$info = array();
				foreach($app->action[0]->attributes() as $name => $value)
				{
					$info[$name] = (string)$value;
				}
				$discovery[(string)$app['name']] = $info;
			}
			Cache::setInstance('collabora', 'discovery', $discovery, self::DISCOVERY_CACHE_TIME);
			return $discovery;
		}
		catch (Exception $e)
		{
			Cache::setInstance('collabora', 'discovery', false, self::DISCOVERY_CACHE_TIME);
			throw $e;
		}
	}

	public static function get_server()
	{
		$config = Config::read('collabora');
		return $config['server'];
	}
}
