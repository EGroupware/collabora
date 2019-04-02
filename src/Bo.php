<?php
/**
 * EGroupware - Collabora business object
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

use EGroupware\Api;
use EGroupware\Api\Cache;
use EGroupware\Api\Config;
use EGroupware\Api\DateTime;
use EGroupware\Api\Egw;
use EGroupware\Api\Vfs;

/**
 * Description of Bo
 *
 * @author nathan
 */
class Bo {

	const DISCOVERY_CACHE_TIME = 3600; // 1 hour, until we can trigger a new discovery from client-side, if opening of iframe fails

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
	 * @trhows Api\Exception\WrongUserinput if Collabora is not configured
	 * @throws Api\Exception\WrongParameter if the server cannot be contacted
	 * @throws Api\Exception\AssertionFailed if the server responds,
	 *	but the XML cannot be parsed
	 */
	public static function discover($server = '')
	{
		if (empty($server)) $server = self::get_server();

		if (empty($server))
		{
			throw new Api\Exception\WrongUserinput(lang('Collabora is not configured!'));
		}

		// if server is configured server AND we have a cached discovery --> use it
		if ($server === self::get_server() &&
			($cached = Cache::getInstance('collabora', 'discovery')))
		{
			return $cached;
		}

		$server_url = $server . self::DISCOVERY_URL;
		$discovery = array();

		try
		{
			// dont use proxy for localhost or private IPs and don't try to verify a certificate
			if (preg_match('#^https?://(localhost|((10|127)\.\d{1,3}|192\.168)\.\d{1,3}\.\d{1,3})(:\d+)?$#', $server))
			{
				$no_ssl_verify = stream_context_create(array(
					'ssl' => array(
						'verify_peer' => false,
						'verify_peer_name' => false,
				)));
				$response_xml_data = file_get_contents($server_url, false, $no_ssl_verify);
			}
			else
			{
				$response_xml_data = file_get_contents($server_url, false, Api\Framework::proxy_context());
			}
			if ($response_xml_data  === false)
			{
				throw new Api\Exception\WrongParameter('Unable to load ' . $server_url);
			}
			libxml_use_internal_errors(true);
			$data = simplexml_load_string($response_xml_data);
			if (!$data) {
				$msg = "Error loading XML\n";
				foreach(libxml_get_errors() as $error) {
					$msg .= "\t". $error->message;
				}
				throw new Api\Exception\AssertionFailed($msg);
			}

			// Iterate through & extract the data
			foreach($data->{'net-zone'}->app as $app)
			{
				$info = array();
				foreach($app->action[0]->attributes() as $name => $value)
				{
					$info[$name] = (string)$value;
				}
				$name = (string)$app['name'];
				if (!isset($discovery[$name]))
				{
					$discovery[$name] = $info;
				}
				else
				{
					switch($info['ext'])
					{
						case 'ppt':	// prefer these main extensions over their template conterparts
						case 'xls':
						case 'doc':
							$extra_extensions = (array)$discovery[$name]['extra_extensions'];
							$extra_extensions[] = $discovery[$name]['ext'];
							$discovery[$name] = $info;
							$discovery[$name]['extra_extensions'] = $extra_extensions;
							break;
						default:
							$discovery[$name]['extra_extensions'][] = $info['ext'];
					}
				}
			}
			Cache::setInstance('collabora', 'discovery', $discovery, self::DISCOVERY_CACHE_TIME);
			return $discovery;
		}
		catch (\Exception $e)
		{
			Cache::setInstance('collabora', 'discovery', false, self::DISCOVERY_CACHE_TIME);
			throw $e;
		}
	}

	/**
	 * Get the configured server URL
	 *
	 * @return string
	 */
	public static function get_server()
	{
		$config = Config::read('collabora');
		return $config['server'];
	}

	public static function get_token($path)
	{
		$share = Wopi::create($path, Wopi::WRITABLE, '', '', array(
			'share_expires'	=>	time() + Wopi::TOKEN_TTL,
			'share_writable' => Vfs::is_writable($path) ? Wopi::WOPI_WRITABLE : Wopi::WOPI_READONLY,
		));

		$token = array();

		foreach($share as $key => $value)
		{
			if(substr($key, 0, 6) == 'share_')
			{
				$key = str_replace('share_', '', $key);
			}
			$token[$key] = $value;
		}

		// Token can have + in it
		$token['token'] = urlencode($token['token']);

		// Make sure expiry is timestamp
		if(!is_numeric($token['expires']))
		{
			$token['expires'] = DateTime::to($token['expires'], 'ts');

			// Note that this is _not_ time to live, but expiry (per WOPI spec)
			$share['access_token_ttl'] = $token['expires'];
		}

		return $token;
	}

	/**
	 * Get an action URL for editing a file
	 *
	 * @see https://wopi.readthedocs.io/en/latest/discovery.html#action-urls
	 *
	 * @param string $path Location of the file in the VFS
	 *
	 * @return String Action URL
	 */
	public static function get_action_url($path)
	{
		$discovery = self::discover();
		if(!$path || !Vfs::check_access($path, Vfs::READABLE))
		{
			return '';
		}

		$action = $discovery[Vfs::mime_content_type($path)];
		$url = Api\Framework::getUrl(Egw::link('/collabora/index.php/wopi/files/'.Wopi::get_file_id($path)));

		$url = $action['urlsrc'] . 'WOPISrc=' . urlencode($url);
		$query = array(
			'closebutton' => 1
		);
		if(static::is_versioned($path))
		{
			$query['revisionhistory'] = true;
		}
		if(!Vfs::is_writable($path))
		{
			$query['permission'] = 'view';
		}
		$url .= '&' . http_build_query($query);

		return $url;
	}

	/**
	 * Determine if the path is versioned
	 *
	 * @param String $path
	 * @return boolean
	 */
	public static function is_versioned($path)
	{
		$fileinfo = Vfs::getExtraInfo($path);
		foreach($fileinfo as $tab)
		{
			if($tab['label'] == lang('Versions'))
			{
				return true;
			}
		}
		return false;
	}
}
