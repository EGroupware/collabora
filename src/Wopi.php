<?php
/**
 * EGroupware - Collabora Wopi protocol
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once(__DIR__.'/../../api/src/Vfs/Sharing.php');

use EGroupware\Api;
use EGroupware\Api\Vfs\Sharing;
use EGroupware\Api\Vfs\Sqlfs\StreamWrapper as Sql_Stream;


/**
 * Description of Wopi
 *
 */
class Wopi extends Sharing
{
	// Debug flag
	const DEBUG = false;

	/**
	 * Lifetime of WOPI shares: 1 day
	 */
	const TOKEN_TTL = 86400;
	/**
	 * writable (normal) WOPI share, to be able to supress it from list of shares
	 */
	const WOPI_WRITABLE = 3;
	/**
	 * readonly WOPI share, to be able to supress it from list of shares
	 */
	const WOPI_READONLY = 4;
	/**
	 * Writable WOPI share, used for sharing a single file with others but
	 * restricts file system access - no save as
	 */
	const WOPI_SHARED = 5;

	public $public_functions = array(
		'index'	=> TRUE
	);

	// Access credentials if we need to get to a password
	static $credentials = null;

	/**
	 * Entry point for the WOPI API
	 *
	 * Here we check the required parameters, and pass off the the appropriate
	 * endpoint handler.
	 *
	 * @see https://wopirest.readthedocs.io/en/latest/index.html
	 */
	public static function index()
	{
		// Check access token, start session
		static::create_session(true);

		// Determine the endpoint, get the ID
		$matches = array();
		preg_match('#/wopi/([[:alpha:]]+)/(-?[[:digit:]]+)?/?(contents)?#', $_SERVER['REQUEST_URI'], $matches);
		list(, $endpoint, $id) = $matches;

		// need to create a new session, if the file_id changes, eg. after a PUT_RELATIVE
		if (($last_id = Api\Cache::getSession(__CLASS__, 'file_id')) && $last_id != $id)
		{
			static::create_session(null);
		}

		$endpoint_class = __NAMESPACE__ . '\Wopi\\'. filter_var(
				ucfirst($endpoint),
				FILTER_SANITIZE_SPECIAL_CHARS,
				FILTER_FLAG_STRIP_LOW + FILTER_FLAG_STRIP_HIGH
		);
		$data = array();
		if($endpoint_class && class_exists($endpoint_class))
		{
			$instance = new $endpoint_class();
			$data = $instance->process($id);
			Api\Cache::setSession(__CLASS__, 'file_id', $id);
		}
		else
		{
			// Unknown endpoint - not found
			http_response_code(404);
			exit;
		}

		if(!headers_sent() && $data)
		{
			$response = json_encode($data);
			header('X-WOPI-ServerVersion: ' . $GLOBALS['egw_info']['apps']['collabora']['version']);
			header('X-WOPI-MachineName: ' . 'Egroupware');
			header('Content-Length:'.strlen($response));
			header('Content-Type: application/json;charset=utf-8');
			echo $response;
		}
		exit;
	}

	/**
	 * Create a new share for Collabora to use while editing
	 *
	 * @param string $path either path in temp_dir or vfs with optional vfs scheme
	 * @param string $mode self::LINK: copy file in users tmp-dir or self::READABLE share given vfs file,
	 *	if no vfs behave as self::LINK
	 * @param string $name filename to use for $mode==self::LINK, default basename of $path
	 * @param string|array $recipients one or more recipient email addresses
	 * @param array $extra =array() extra data to store
	 * @throw Api\Exception\NotFound if $path not found
	 * @throw Api\Exception\AssertionFailed if user temp. directory does not exist and can not be created
	 * @return array with share data, eg. value for key 'share_token'
	 */
	public static function create($path, $mode, $name, $recipients, $extra=array())
	{

		$result = parent::create($path, $mode, $name, $recipients, $extra);


		// If path needs password, get credentials and add on the ID so we can
		// actually open the path with the anon user
		if(static::path_needs_password($path))
		{
			$cred_id = Credentials::read($result);
			if(!$cred_id)
			{
				$cred_id = Credentials::write($result);
			}

			$result['share_token'] .= ':'.$cred_id;
		}

		return $result;
	}

	/**
	 * Collabora server does not have the share password, and we don't want to
	 * pass it.  Check to see if the share needs a password, and if it does
	 * we create a new share with no password and use it for the Collabora server.
	 *
	 * This is used for writable collabora shares (sent via URL), not normal
	 * logged in users.  It's in Wopi instead of Bo for access to protected
	 * variables.
	 *
	 * @param Array $share
	 * @return Array share without password
	 */
	public static function get_no_password_share(Array $share)
	{
		if(!$share['passwd'])
		{
			return $share;
		}
		$pwd_share = $GLOBALS['egw']->sharing->share;
		$fstab = $GLOBALS['egw_info']['server']['vfs_fstab'];
		$writable = Api\Vfs::is_writable($path) && $share['writable'] & 1;
		Bo::reset_vfs();
		$share = Wopi::create($share['path'],
			$writable ? Wopi::WRITABLE : Wopi::READONLY,
			'', '', array(
			'share_passwd' => null,
			'share_expires'  =>  time() + Wopi::TOKEN_TTL,
			'share_writable' =>  $writable ? Wopi::WOPI_WRITABLE : Wopi::WOPI_READONLY,
		));
		$GLOBALS['egw_info']['server']['vfs_fstab'] = $fstab;
		$GLOBALS['egw']->sharing->share = $pwd_share;

		// Cleanup to match expected
		foreach($share as $key => $value)
		{
			if(substr($key, 0, 6) == 'share_')
			{
				$key = str_replace('share_', '', $key);
			}
			$token[$key] = $value;
		}
		return $token;
	}

	/**
	 * Get token from url
	 */
	public static function get_token()
	{
		// Access token is encoded, as it may have + in it
		$token = urldecode(filter_var($_GET['access_token'],FILTER_SANITIZE_SPECIAL_CHARS));

		// Strip out possible credentials ID if path needs password
		list($token, self::$credentials) = explode(':', $token);

		return $token;
	}

	/**
	 * If credentials are required to access the file, load & set what is needed
	 *
	 * @param boolean $keep_session
	 * @param Array $share
	 */
	public static function setup_share($keep_session, &$share)
	{
		parent::setup_share($keep_session, $share);

		if(self::$credentials && $share)
		{
			$access = Credentials::read_credential(self::$credentials);

			$GLOBALS['egw_info']['user']['account_lid'] = Api\Accounts::id2name($share['share_owner'], 'account_lid');
			$GLOBALS['egw_info']['user']['passwd'] = $access['password'];
		}
	}

	/**
	 * Get the namespaced class for the given share
	 *
	 * @param string $share
	 */
	protected static function get_share_class($share)
	{
		return __CLASS__;
	}

	/**
	 * Get the current share object, if set
	 *
	 * @return array
	 */
	public static function get_share()
	{
		return isset($GLOBALS['egw']->sharing) ? $GLOBALS['egw']->sharing->share : array();
	}

	public static function get_path_from_token()
	{
		return $GLOBALS['egw']->sharing->share['share_path'];
	}

	/**
	 * Parent just throws an exception if you try, here we return boolean so
	 * we can take action and make sure the credentials are available
	 *
	 * @param string $path
	 * @return boolean
	 */
	public static function path_needs_password($path)
	{
		try
		{
			parent::path_needs_password($path);
		}
		catch (Api\Exception\WrongParameter $e)
		{
			return true;
		}
		return false;
	}

	public static function open_from_share($share, $path)
	{
		if($share['root'] && Api\Vfs::is_dir($share['root']))
		{
			// Editing file in a shared directory, need to have share for just
			// the file
			$dir_share = $GLOBALS['egw']->sharing->share;
			$fstab = $GLOBALS['egw_info']['server']['vfs_fstab'];
			$writable = Api\Vfs::is_writable($path) && $share['writable'] & 1;
			Bo::reset_vfs();
			$share = Wopi::create($share['path'].$path,
				$writable ? Wopi::WRITABLE : Wopi::READONLY,
				'', '', array(
				'share_expires'  =>  time() + Wopi::TOKEN_TTL,
				'share_writable' =>  $writable ? Wopi::WOPI_WRITABLE : Wopi::WOPI_READONLY,
			));
			$GLOBALS['egw_info']['server']['vfs_fstab'] = $fstab;
			$GLOBALS['egw']->sharing->share = $dir_share;

			return $share;
		}
	}

	/**
	 * Find out if the share is writable (regardless of file permissions)
	 *
	 * @return boolean
	 */
	public static function is_writable()
	{
		$share = static::get_share();
		return ((intval($share['share_writable']) & 1));
	}

	/**
	 * Get a WOPI file ID from a path
	 *
	 * File ID is the lowest fs_id for the path, if available.  If no fs_id is
	 * available (eg: samba mount) we use the ID of the lowest active share
	 * for a file.  To deal with versioning, we use the lowest fs_id since for
	 * a new version a new fs_id will be generated, and the original file will
	 * be moved to the attic, but the lowest share ID should stay the same.
	 *
	 * @param string $path Full file path
	 *
	 * @param Integer File ID, (0 if not found)
	 */
	public static function get_file_id($path)
	{
		$path = str_replace(Api\Vfs::PREFIX, '', $path);
		$file_id = Api\Vfs::get_minimum_file_id($path);

		// No fs_id?  Fall back to the earliest valid share ID
		if (!$file_id)
		{
			self::so();

			$where = array(
				'share_path' => Api\Vfs::PREFIX.$path,
				'(share_expires IS NULL OR share_expires > '.$GLOBALS['egw']->db->quote(time(), 'date').')',
			);
			$append = 'ORDER BY share_id ASC';
			foreach($GLOBALS['egw']->db->select(self::TABLE, 'share_id', $where,
					__LINE__, __FILE__,false,$append,false,1) as $row)
			{
				$file_id = -1*$row['share_id'];
			}
		}

		return (int)$file_id;
	}

	/**
	 * Get the full file path for the given file ID
	 *
	 * We also take into account the current token permissions, to make sure
	 * the file matches what the token has access for.  File IDs with '-' prefixed
	 * (negative numbers) use the share ID, positive numbers are found in SQLfs.
	 *
	 * @param int $file_id
	 *
	 * @return String the path
	 *
	 * @throws Api\Exception\NotFound if it cannot be found or no permission
	 */
	public static function get_path_from_id($file_id)
	{
		$path = false;

		if(abs((int)$file_id) == (int)$file_id)
		{
			$path = Sql_Stream::id2path((int)$file_id);
		}
		else if(strpos($file_id,'-') === 0)
		{
			$where = array(
				'share_id' => abs((int)$file_id)
			);

			self::so();
			foreach($GLOBALS['egw']->db->select(self::TABLE, 'share_path', $where, __LINE__, __FILE__) as $row)
			{
				$path = $row['share_path'];
			}
		}

		if($path && isset($GLOBALS['egw']->sharing) && $path != ($token_path=self::get_path_from_token())
				&& !Api\Vfs::is_dir($token_path) && !Api\Vfs::is_link($token_path)
		)
		{
			// id2path fails with old revisions
			$versioned_name = $file_id . ' - '.Api\Vfs::basename($path);
			if(Api\Vfs::basename($token_path) == $versioned_name && strpos($token_path, '/.versions'))
			{
				return $token_path;
			}
		}
		return $path;
	}
	/**
	 * Generate link to collabora editor from share or share-token
	 *
	 * @param string|array $share share or share-token
	 * @return string full Url incl. schema and host
	 */
	public static function share2link($share)
	{
		return Api\Vfs\Sharing::share2link($share) .
				($GLOBALS['egw_info']['user']['apps']['stylite'] ? '?edit&cd=no' : '');
	}

	/**
	 * Delete specified shares and remove credentials, if needed
	 *
	 * @param int|array $keys
	 * @return int number of deleted shares
	 */
	public static function delete($keys)
	{
		self::$db = $GLOBALS['egw']->db;

		if (is_scalar($keys))
		{
			$keys = array('share_id' => $keys);
		}

		// Delete credentials, if there
		Credentials::delete($keys);

		return parent::delete($keys);
	}
}
