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
class Files {

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
			http_response_code(404);
			exit;
		}

		if($_REQUEST['endpoint'] !== 'files') return;
		if(array_key_exists('contents', $_REQUEST))
		{
			return static::get_file($path);
		}

		switch ($_SERVER['HTTP_X-WOPI-Override'])
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
			default:
				$data = static::check_file_info($path);

		}

		if($data == null)
		{
			http_response_code(404);
			exit;
		}

		// Additional, optional things we support
		$data['UserFriendlyName'] = Accounts::format_username();

		return $data;
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

		// Required response from http://wopi.readthedocs.io/projects/wopirest/en/latest/files/CheckFileInfo.html#checkfileinfo
		$data = array(
			// The string name of the file, including extension, without a path. Used for display in user interface (UI), and determining the extension of the file.
			'BaseFileName'	=> '',

			// A string that uniquely identifies the owner of the file.
			'OwnerId'		=> '',
			
			// The size of the file in bytes, expressed as a long, a 64-bit signed integer.
			'Size'			=> '',

			// A string value uniquely identifying the user currently accessing the file.
			'UserId'		=> ''.$GLOBALS['egw_info']['user']['account_id'],

			// The current version of the file based on the server’s file version schema, as a string.
			//'Version'		=> '1'
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
		}
		else
		{
			// Not found
			return null;
		}

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
			\EGroupware\Api\Header\Content::disposition(Vfs::basename($GLOBALS['egw']->sharing->get_path()), false);
			header('Content-Length: ' . filesize(Vfs::PREFIX . $path));
		}
		readfile(Vfs::PREFIX . $path);
		return;
	}

	/**
	 * Lock a file
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/Lock.html
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/UnlockAndRelock.html
	 *
	 * @param string $path VFS path of file we're operating on
	 */
	public static function lock($path)
	{
		// Required
		if($_SERVER['HTTP_X-WOPI-Lock'])
		{
			$token = $_SERVER['HTTP_X-WOPI-Lock'];
		}
		else
		{
			http_response_code(400);
			return;
		}
		// Optional old lock code
		if($_SERVER['HTTP_X-WOPI-OldLock'])
		{
			$old_lock = $_SERVER['HTTP_X-WOPI-OldLock'];
		}

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
		$token = $_SERVER['HTTP_X-WOPI-Lock'];
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
		$token = $_SERVER['HTTP_X-WOPI-Lock'];
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
		$token = $_SERVER['HTTP_X-WOPI-Lock'];

		// Check lock
		$lock = Vfs::checkLock($path);
		if(!$lock)
		{
			// Check file size
			$stat = Vfs::stat($path);
			if($stat['size'] != 0)
			{
				// Conflict
				http_response_code(409);
				return;
			}
		}
		else if ($token && $lock['token'] !== $token)
		{
			// Conflict
			http_response_code(409);
			header('X-WOPI-Lock', $lock['token']);
		}

		// TODO: Store the file
		http_response_code(501); // Not implemented
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
		$token = $_SERVER['HTTP_X-WOPI-Lock'];

		// File name / extension
		$suggested_target = $_SERVER['HTTP_X-WOPI-SuggestedTarget'];
		$relative_target = $_SERVER['HTTP_X-WOPI-RelativeTarget'];

		$overwrite = boolval($_SERVER['HTTP_X-WOPI-OverwriteRelativeTarget']);
		$size = intval($_SERVER['HTTP_X-WOPI-Size']);
		
	}
}
