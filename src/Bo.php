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
use EGroupware\Api\DateTime;
use EGroupware\Api\Egw;
use EGroupware\Api\Vfs;

/**
 * Description of Bo
 *
 * @author nathan
 */
class Bo {

	const DISCOVERY_CACHE_TIME = 86400; // 1 day

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

			if(@function_exists('curl_version'))
			{
				$response = static::get_remote_data($server_url, false, true);
				if($response['info']['http_code'] == 200)
				{
					$response_xml_data = $response['data'];
				}
				else
				{
					throw new \EGroupware\Api\Exception\WrongParameter('Unable to load ' . $server_url);
				}
			}
			// No cURL, fallback
			else if (($response_xml_data = file_get_contents($server_url))===false)
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

	/**
	 * Get the contents of a page
	 *
	 * From https://stackoverflow.com/questions/5971398/php-get-contents-of-a-url-or-page
	 *
	 * @param string $url
	 * @param Array $post_parameters
	 * @param boolean  $return_full_array
	 * @return string
	 */
	protected static function get_remote_data($url, $post_parameters=false, $return_full_array=false)
	{
		$c = curl_init();
		curl_setopt($c, CURLOPT_URL, $url);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		//if parameters were passed to this function, then transform into POST method.. (if you need GET request, then simply change the passed URL)
		if($post_parameters)
		{
			curl_setopt($c, CURLOPT_POST,TRUE);
			curl_setopt($c, CURLOPT_POSTFIELDS, "var1=bla&".$post_parameters );
		}
		curl_setopt($c, CURLOPT_SSL_VERIFYHOST,false);
		curl_setopt($c, CURLOPT_SSL_VERIFYPEER,false);

		curl_setopt($c, CURLOPT_MAXREDIRS, 10);

		//if SAFE_MODE or OPEN_BASEDIR is set,then FollowLocation cant be used.. so...
		$follow_allowed = ( ini_get('open_basedir') || ini_get('safe_mode')) ? false:true;
		if ($follow_allowed)
		{
			curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
		}
		curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 9);
		curl_setopt($c, CURLOPT_REFERER, $url);
		curl_setopt($c, CURLOPT_TIMEOUT, 10);
		curl_setopt($c, CURLOPT_AUTOREFERER, true);
		curl_setopt($c, CURLOPT_ENCODING, 'gzip,deflate');
		$data=curl_exec($c);
		$status=curl_getinfo($c);
		curl_close($c);

		// if redirected, then get that redirected page
		if($status['http_code']==301 || $status['http_code']==302)
		{
			//if we FOLLOWLOCATION was not allowed, then re-get REDIRECTED URL
			//p.s. WE dont need "else", because if FOLLOWLOCATION was allowed, then we wouldnt have come to this place, because 301 could already auto-followed by curl  :)
			if (!$follow_allowed){
				//if REDIRECT URL is found in HEADER
				if(empty($redirURL)){if(!empty($status['redirect_url'])){$redirURL=$status['redirect_url'];}}
				//if REDIRECT URL is found in RESPONSE
				if(empty($redirURL)){preg_match('/(Location:|URI:)(.*?)(\r|\n)/si', $data, $m);                 if (!empty($m[2])){ $redirURL=$m[2]; } }
				//if REDIRECT URL is found in OUTPUT
				if(empty($redirURL)){preg_match('/moved\s\<a(.*?)href\=\"(.*?)\"(.*?)here\<\/a\>/si',$data,$m); if (!empty($m[1])){ $redirURL=$m[1]; } }
				//if URL found, then re-use this function again, for the found url
				if(!empty($redirURL)){$t=debug_backtrace(); return call_user_func( $t[0]["function"], trim($redirURL), $post_paramtrs);}
			}
		}
		// if not redirected,and nor "status 200" page, then error..
		else if ( $status['http_code'] != 200 )
		{
			$data =  "ERRORCODE22 with $url<br/><br/>Last status codes:".json_encode($status)."<br/><br/>Last data got:$data";
		}

		return ( $return_full_array ? array('data'=>$data,'info'=>$status) : $data);
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
			'share_expires'	=>	time() + Wopi::TOKEN_TTL
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
	 * @param Array $token Token information from get_token()
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

		$stat = Vfs::stat($path);
		$action = $discovery[Vfs::mime_content_type($path)];
		$url = Egw::link('/collabora/index.php/wopi/files/'.$stat['ino']);

		if ($url{0} == '/') {
			$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
			$url = $protocol.($GLOBALS['egw_info']['server']['hostname'] ? $GLOBALS['egw_info']['server']['hostname'] : $_SERVER['HTTP_HOST']).$url;
		}
		$url = $action['urlsrc'] .
				'WOPISrc=' . urlencode($url);
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
