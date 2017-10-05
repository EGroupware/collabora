<?php

/**
 * WOPI File access endpoint
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\collabora\Wopi;

use \EGroupware\Api\Accounts;
use \EGroupware\Api\Vfs;
use \EGroupware\Api\Vfs\Sqlfs\StreamWrapper as Sql_Stream;

/**
 * File enpoint
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 */
class Files
{
	const LOCK_DURATION = 1800; // 30 Minutes, per WOPI spec

	/**
	 * Process a request to the files endpoint
	 *
	 * @param int $id The ID of the file being accessed
	 *
	 * @return Array Map of information as response to the request
	 */
	public static function process($id)
	{

		$path = Sql_Stream::id2path((int)$id);
		if(!$path)
		{
			$path = \EGroupware\collabora\Wopi::get_path_from_token();
			error_log(__METHOD__."($id) _REQUEST=".array2string($_REQUEST).", X-WOPI-Override=".self::header('X-WOPI-Override').", path (from token) = $path");
		}
		else error_log(__METHOD__."($id) _REQUEST=".array2string($_REQUEST).", X-WOPI-Override=".self::header('X-WOPI-Override').", path (from id $id) = $path");

		if(!$path)
		{
			http_response_code(404);
			exit;
		}

		switch (self::header('X-WOPI-Override'))
		{
			case 'LOCK':
				static::lock($path);
				exit;
			case 'GET_LOCK':
				static::get_lock($path);
				exit;
			case 'REFRESH_LOCK':
				static::refresh_lock($path);
				exit;
			case 'UNLOCK':
				static::unlock($path);
				exit;
			case 'PUT':
				static::put($path);
				exit;
			case 'PUT_RELATIVE':
				static::put_relative($path);
				exit;
			default:
				if(preg_match('#/wopi/([[:alpha:]]+)/(-?[[:digit:]]+)/contents#',$_SERVER['REQUEST_URI']))
				{
					return static::get_file($path);
				}
				$data = static::check_file_info($path);
		}

		if($data == null)
		{
			http_response_code(404);
			exit;
		}


		return $data;
	}

	/**
	 * Return HTTP header(s) of the request
	 *
	 * @param string $name =null name of header or default all
	 * @return array|string|NULL array with all headers or value of specified header oder NULL wenn nicht gesetzt
	 */
	static function header($name = null)
	{
		static $header = array();

		if (!$header)
		{
			foreach($_SERVER as $h => $v)
			{
				list($type, $h) = explode('_', $h, 2);
				if ($type == 'HTTP' || $type == 'CONTENT')
				{
					$header[str_replace(' ','-', strtolower(($type == 'HTTP' ? '' : $type.' ').str_replace('_', ' ', $h)))] =
							$h == 'AUTHORIZATION' ? 'Basic ***************' : $v;
				}
			}
			error_log(__METHOD__."() header=".array2string($header));
		}
		if (empty($name))
		{
			return $header;
		}
		if (!isset($header[$name]))
		{
			$name = strtolower($name);
		}
		//error_log(__METHOD__."('$name') header=".array2string($header).' returning '.array2string($header[$name]));
		return $header[$name];
	}

	/**
	 * Get file information
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/CheckFileInfo.html#checkfileinfo
	 *
	 * @param string $path VFS path of file we're operating on
	 * @return Array|null
	 */
	protected static function check_file_info($path)
	{
		$origin = $GLOBALS['egw_info']['server']['webserver_url'];
		if ($origin[0] == '/')
		{
			$origin = ($_SERVER['HTTPS'] ? 'https://' : 'http://').$_SERVER['HTTP_HOST'];
		}
		else
		{
			$origin = parse_url($origin, PHP_URL_SCHEME).'://'.parse_url($origin, PHP_URL_HOST);
		}
		// Required response from http://wopi.readthedocs.io/projects/wopirest/en/latest/files/CheckFileInfo.html#checkfileinfo
		$data = array(
			// The string name of the file, including extension, without a path. Used for display in user interface (UI), and determining the extension of the file.
			'BaseFileName'	=> '',

			// A string that uniquely identifies the owner of the file.
			'OwnerId'		=> '',

			// The size of the file in bytes, expressed as a long, a 64-bit signed integer.
			'Size'			=> '',

			// A string value uniquely identifying the user currently accessing the file.
			'UserId'		=> ''.Vfs::$user,

			// The current version of the file based on the server’s file version schema, as a string.
			//'Version'		=> '1'

			// Optional, for additional features
			// ---------------------------------

			// Messaging
			'PostMessageOrigin' => $origin,

			// Support locking
			'SupportsGetLock'   => true,
			'SupportsLocks'     => true,

			// Support Save As
			'SupportsUpdate'    => true,
			'SupportsRename'	=> true,

			// User permissions
			// ----------------
			'ReadOnly'          => !Vfs::is_writable($path),
			'UserCanRename'     => true,

			// Other miscellaneous properties
			// ------------------------------
			'DisablePrint'      => false,
			'DisableExport'     => false,
			'DisableCopy'       => false,
		);

		if($path)
		{
			$data['BaseFileName'] = basename($path);
		}
		if($path)
		{
			$stat = Vfs::stat($path);
		}
		if($stat)
		{
			$data['OwnerId'] = ''.$stat['uid'];
			$data['Size'] = ''.$stat['size'];
			$data['LastModifiedTime'] = \EGroupware\Api\DateTime::to($stat['mtime'], \DateTime::ISO8601);
		}
		else
		{
			// Not found
			return null;
		}
		$data['UserCanWrite'] = Vfs::is_writable($path);

		// Additional, optional things we support
		$data['UserFriendlyName'] = Accounts::username(Vfs::$user);

		return $data;
	}

	/**
	 * Get the contents of a file
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/GetFile.html#
	 *
	 * @param string $path VFS path of file we're operating on
	 */
	public static function get_file($path)
	{
		// send a content-disposition header, so browser knows how to name downloaded file
		if (!Vfs::is_dir($GLOBALS['egw']->sharing->get_root()))
		{
			\EGroupware\Api\Header\Content::disposition(Vfs::basename(Vfs::PREFIX . $path), false);
			header('Content-Length: ' . filesize(Vfs::PREFIX . $path));
		}
		readfile(Vfs::PREFIX . $path);
		return;
	}

	/**
	 * Lock a file, or unlock and relock a file
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/Lock.html
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/UnlockAndRelock.html
	 *
	 * @param string $path VFS path of file we're operating on
	 */
	public static function lock($path)
	{
		// Required
		if(!($token = self::header('X-WOPI-Lock')))
		{
			http_response_code(400);
			return;
		}
		// Optional old lock code
		$old_lock = self::header('X-WOPI-OldLock');

		$timeout = static::LOCK_DURATION;
		$owner = $GLOBALS['egw_info']['user']['account_id'];
		$scope = 'exclusive';
		$type = 'write';
		$lock = Vfs::checkLock($path);

		// Unlock and relock if old lock is provided
		if($old_lock && $old_lock !== $lock['token'])
		{
			// Conflict
			header('X-WOPI-Lock', $lock['token']);
			http_response_code(409);
			return;
		}
		else if ($old_lock && $old_lock == $lock['token'])
		{
			Vfs::unlock($path, $old_lock);
		}

		// Lock the file, refresh if the tokens match
		$result = Vfs::lock($path,$token,$timeout,$owner,$scope,$type,$lock['token'] == $token);

		header('X-WOPI-Lock', $token);
		http_response_code($result ? 200 : 409);
	}

	/**
	 * Get a lock on a file
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/GetLock.html
	 *
	 * @param string $path VFS path of file we're operating on
	 */
	public static function get_lock($path)
	{
		$lock = Vfs::checkLock($path);

		header('X-WOPI-Lock', $lock['token']);
		http_response_code(200);
	}

	/**
	 * Refresh a lock on a file
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/RefreshLock.html
	 *
	 * @param string $path VFS path of the file we're operating on
	 */
	public static function refresh_lock($path)
	{
		$token = self::header('X-WOPI-Lock');
		if(!$token)
		{
			// Bad request
			http_response_code(400);
		}

		$timeout = static::LOCK_DURATION;
		$owner = $GLOBALS['egw_info']['user']['account_id'];
		$scope = 'exclusive';
		$type = 'write';

		$result = Vfs::lock($path,$token,$timeout,$owner,$scope,$type, true);

		header('X-WOPI-Lock', $token);
		http_response_code($result ? 200 : 409);
	}

	/**
	 * Unlock a file
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/Unlock.html
	 *
	 * @param string $path VFS path of the file we're operating on
	 */
	public static function unlock($path)
	{
		$token = self::header('X-WOPI-Lock');
		if(!$token)
		{
			// Bad request
			http_response_code(400);
		}

		$lock = Vfs::checkLock($path);

		if($lock['token'] != $token)
		{
			header('X-WOPI-Lock', $lock['token']);
			// Conflict
			http_response_code(409);
		}

		$result = Vfs::unlock($path, $token);

		http_response_code($result ? 200 : 409);
	}

	/**
	 * Update a file's binary contents
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/PutFile.html
	 *
	 * @param string $path VFS path of the file we're operating on
	 */
	public static function put($path)
	{
		// Lock token, might not be there for new files
		$token = self::header('X-WOPI-Lock');

		// Check lock
		$lock = Vfs::checkLock($path);
		if(!$lock)
		{
			/* Collabora Online does not support locking, and never locks
			 * so we skip this check to make saving work

			// Check file size
			$stat = Vfs::stat($path);
			if($stat['size'] != 0)
			{
				// Conflict
				http_response_code(409);
				return;
			}

			 */
		}
		else if ($token && $lock['token'] !== $token)
		{
			// Conflict
			http_response_code(409);
			header('X-WOPI-Lock', $lock['token']);
		}
		// Read the contents of the file from the POST body and store.
		$content = fopen('php://input', 'r');

		file_put_contents(Vfs::PREFIX . $path, $content);
	}

	/**
	 * Create a new file based on an existing file (Save As)
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/PutRelativeFile.html
	 *
	 * @param string $path VFS path of the file we're operating on
	 */
	public static function put_relative_file($path)
	{
		// Lock token, might not be there
		$token = self::header('X-WOPI-Lock');
		$lock = Vfs::checkLock($path);

		$suggested_target = self::header('X-WOPI-SuggestedTarget');
		$relative_target = self::header('X-WOPI-RelativeTarget');
		$overwrite = boolval(self::header('X-WOPI-OverwriteRelativeTarget'));
		$size = intval(self::header('X-WOPI-Size'));

		// File name or extension
		if($suggested_target && $relative_target)
		{
			// Specifying both is invalid, we give Not Implemented
			http_response_code(501);
			return;
		}

		// Process the suggestion - modify as needed
		if($suggested_target && $suggested_target[0] =='.')
		{
			// Only an extension, use current file name
			$info = pathinfo($path);
			$suggested_target = basename($path, '.'.$info['extension']) . $suggested_target;
		}
		if($suggested_target)
		{
			$target = self::clean_filename($suggested_target);
		}

		if($relative_target)
		{
			// Can't modify this one, but we can check & fail it
			$clean = self::clean_filename($relative_target);
			if($relative_target !== $clean)
			{
				http_response_code(400);
				header('X-WOPI-ValidRelativeTarget', $clean);
				return;
			}
			if(Vfs::file_exists($relative_target))
			{
				if(!$overwrite)
				{
					http_response_code(409); // Conflict
					if($lock)
					{
						header('X-WOPI-Lock', $lock['token']);
					}
					return;
				}
				if(!$token && $lock || $token && $lock['token'] !== $token)
				{
					// Conflict
					http_response_code(409);
					if($lock)
					{
						header('X-WOPI-Lock', $lock['token']);
					}
					return;
				}
			}
			$target = $relative_target;
		}

		// Ok, check target
		if(!Vfs::check_access($target, Vfs::WRITABLE) || !Vfs::is_writable(dirname($target)))
		{
			// User not authorised, 401 is used for invalid token
			http_response_code(404);
		}

		// Read the contents of the file from the POST body and store.
		$content = fopen('php://input', 'r');
		file_put_contents(Vfs::PREFIX . $target, $content);

		http_response_code(200);
	}

	/**
	 * Make a filename valid, even if we have to modify it a little.
	 *
	 * @param String $original_path
	 * @return String modified path
	 */
	protected static function clean_filename($original_path)
	{
		$file = basename($original_path);

		// Sanitize the characters -
		// Remove anything which isn't a word, whitespace, number
		// or any of the following caracters -_~,;[]().
		// Thanks, Sean Vieira
		$file = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $file);
		// Remove any runs of periods
		$file = mb_ereg_replace("([\.]{2,})", '', $file);

		$basename = pathinfo($file, PATHINFO_FILENAME);
		$extension = pathinfo($file, PATHINFO_EXTENSION);
		$path = dirname($original_path);

		// Avoid duplicates
		$dupe_count = 0;
		while(Vfs::file_exists($path.$file))
		{
			$dupe_count++;
			$file = $basename .
				' ('.($dupe_count + 1).')' . '.' .
				$extension;
		}

		return $path.$file;
	}
}
