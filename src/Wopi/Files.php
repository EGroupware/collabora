<?php
/**
 * EGroupware - Collabora WOPI File access endpoint
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora\Wopi;

use EGroupware\Collabora\Wopi;
use EGroupware\Api;
use EGroupware\Api\Accounts;
use EGroupware\Api\Vfs;

/**
 * File enpoint
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 */
class Files
{
	const LOCK_DURATION = 1800; // 30 Minutes, per WOPI spec


	// WOPI spec says ISO 8601, specifically this format
	// https://wopi.readthedocs.io/projects/wopirest/en/latest/files/CheckFileInfo.html#term-lastmodifiedtime
	const DATE_FORMAT = 'Y-m-d\TH:i:s.u\Z';

	/**
	 * Process a request to the files endpoint
	 *
	 * @param int $id The ID of the file being accessed
	 *
	 * @return Array Map of information as response to the request
	 */
	public function process($id)
	{
		if(!$id)
		{
			http_response_code(404);
			header('X-WOPI-ServerError', 'Missing file ID');
			return;
		}
		$path = Wopi::get_path_from_id($id);
		if($path == '')
		{
			$path = \EGroupware\collabora\Wopi::get_path_from_token();
			error_log(__METHOD__."($id) _REQUEST=".array2string($_REQUEST).", X-WOPI-Override=".$this->header('X-WOPI-Override').", path (from token) = $path");
		}
		else if(Wopi::DEBUG)
		{
			error_log(__METHOD__."($id) _REQUEST=".array2string($_REQUEST).", X-WOPI-Override=".$this->header('X-WOPI-Override').", path (from id $id) = $path");
		}

		if(!$path || Vfs::is_dir($path))
		{
			http_response_code(404);
			header('X-WOPI-ServerError', 'Unable to find file / path is invalid');
			return;
		}

		switch ($this->header('X-WOPI-Override'))
		{
			case 'LOCK':
				$this->lock($path);
				return;
			case 'GET_LOCK':
				$this->get_lock($path);
				return;
			case 'REFRESH_LOCK':
				$this->refresh_lock($path);
				return;
			case 'UNLOCK':
				$this->unlock($path);
				return;
			case 'PUT':
				return $this->put($path);
			case 'PUT_RELATIVE':
				return $this->put_relative_file($path);
			default:
				if(preg_match('#/wopi/([[:alpha:]]+)/(-?[[:digit:]]+)/contents#',$_SERVER['REQUEST_URI']))
				{
					return $this->get_file($path);
				}
				$data = $this->check_file_info($path);
		}

		if($data == null)
		{
			http_response_code(404);
			return null;
		}


		return $data;
	}

	/**
	 * Return HTTP header(s) of the request
	 *
	 * @param string $name =null name of header or default all
	 * @return array|string|NULL array with all headers or value of specified header oder NULL wenn nicht gesetzt
	 */
	function header($name = null)
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
			if(Wopi::DEBUG)
			{
				error_log(__METHOD__."() header=".array2string($header));
			}
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
	 * Get the contents of the file as sent from the client
	 *
	 * @return resource
	 */
	public function get_sent_content()
	{
		return fopen('php://input', 'r');
	}

	/**
	 * Get file information
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/CheckFileInfo.html#checkfileinfo
	 *
	 * @param string $path VFS path of file we're operating on
	 * @return Array|null
	 */
	public function check_file_info($path)
	{
		$origin = Api\Framework::getUrl($GLOBALS['egw_info']['server']['webserver_url']);
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
			// enables Save As acton in File menu
			'UserCanNotWriteRelative' => !$this->allow_save_as($path),

			// User permissions
			// ----------------
			'ReadOnly'          => !Vfs::is_writable($path),
			'UserCanRename'     => $this->allow_save_as($path),

			// Insert images from VFS
			'EnableInsertRemoteImage' => true,

			// Sharing
			'EnableShare'       => true,
			// Other miscellaneous properties
			// ------------------------------
			'DisablePrint'      => false,
			'DisableExport'     => false,
			'DisableCopy'       => false,
			'UserExtraInfo'		=> array(
				'avatar'	=> $origin.'/api/avatar.php?account_id='.Vfs::$user
			)
		);

		if($path)
		{
			$data['BaseFileName'] = Vfs::basename($path);
		}
		if($path)
		{
			$stat = Vfs::stat($path);
		}
		if($stat)
		{
			$data['OwnerId'] = ''.$stat['uid'];
			$data['Size'] = ''.$stat['size'];
			$mtime = new Api\DateTime($stat['mtime']);
			$mtime->setTimezone(new \DateTimeZone('UTC'));
			$data['LastModifiedTime'] = $mtime->format(static::DATE_FORMAT);
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
	public function get_file($path)
	{
		$stat = Vfs::stat($path);

		// send a content-disposition header, so browser knows how to name downloaded file
		Api\Header\Content::disposition(Vfs::basename(Vfs::PREFIX . $path), false);
		header('Content-Length: ' . $stat['size']);
		header('Content-Type: ' . $stat['mime']);
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
	public function lock($path)
	{
		// Required
		if(!($token = $this->header('X-WOPI-Lock')))
		{
			http_response_code(400);
			return;
		}
		// Optional old lock code
		$old_lock = $this->header('X-WOPI-OldLock');

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
	public function get_lock($path)
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
	public function refresh_lock($path)
	{
		$token = $this->header('X-WOPI-Lock');
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
	public function unlock($path)
	{
		$token = $this->header('X-WOPI-Lock');
		if(!$token)
		{
			// Bad request
			http_response_code(400);
		}

		$lock = Vfs::checkLock($path);
		if(!$lock)
		{
			// File was already unlocked
			http_response_code(200);
			return;
		}

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
	 * Name of property for autosave time-stamp
	 */
	const PROP_AUTOSAVE_TS = 'AutosaveTS';
	/**
	 * Maximum time / age to skip versioning of autosave
	 */
	const MAX_SKIP_AUTOSAVE_AGE = 7200;

	/**
	 * Update a file's binary contents
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/PutFile.html
	 *
	 * @param string $path VFS path of the file we're operating on
	 */
	public function put($path)
	{
		// Check if share _can_ be written to, before we bother with anything else
		if(!Wopi::is_writable())
		{
			http_response_code(404);
			header('X-WOPI-ServerError', 'Share is readonly');
			return;
		}

		// Lock token, might not be there for new files
		$token = $this->header('X-WOPI-Lock');

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
			return;
		}

		$api_config = Api\Config::read('phpgwapi');
		$GLOBALS['egw_info']['server']['vfs_fstab'] = $api_config['vfs_fstab'];
		Vfs\StreamWrapper::init_static();
		Vfs::clearstatcache();

		// Read the contents of the file from the POST body and store.
		$content = $this->get_sent_content();
		if (strpos($path, '://') === false) $path = Vfs::PREFIX.$path;

		// check if current file-version is from an autosave (modification TS matches autosave TS)
		$is_autosaved = false;
		foreach((Array)Vfs::propfind($path) as $prop)
		{
			if ($prop['name'] === self::PROP_AUTOSAVE_TS)
			{
				$is_autosaved = $prop['val'] == filemtime($path) &&
					// only consider autosaves less then MAX_SKIP_AUTOSAVE_AGE (2h) old
					$prop['val']+self::MAX_SKIP_AUTOSAVE_AGE > time();
				break;
			}
		}
		// let VFS via context know, to NOT create a new version for consecutive autosaves
		// or explicit save after an autosave (minimize automatic versions)
		$context = stream_context_create($c=array(
			Vfs::SCHEME => array(
				'versioning' => array(
					'disable' => $is_autosaved,
					'min_version' => 0,	// do NOT stop explicit versioning for non autosave and new opened of files
				),
			),
		));
		//error_log(__METHOD__."('$path') prop=".array2string($prop).", is_autosaved=".array2string($is_autosaved).", X-LOOL-WOPI-IsAutosave={$this->header('X-LOOL-WOPI-IsAutosave')} --> context=".array2string($c));

		if (False === file_put_contents($path, $content, 0, $context))
		{
			http_response_code(500);
			header('X-WOPI-ServerError', 'Unable to write file');
			return;
		}
		// mark version as autosaved by storing it's modification TS
		Vfs::proppatch($path, array(array(
			'ns' => Vfs::DEFAULT_PROP_NAMESPACE,
			'name' => self::PROP_AUTOSAVE_TS,
			'val' => $this->header('X-LOOL-WOPI-IsAutosave') === 'true' ? filemtime($path) : null,
		)));

		$stat = Vfs::stat($path);
		$data = array('status' => 'success');
		if($stat)
		{
			$data['Size'] = ''.$stat['size'];
			$mtime = new Api\DateTime($stat['mtime']);
			$mtime->setTimezone(new \DateTimeZone('UTC'));
			$data['LastModifiedTime'] = $mtime->format(static::DATE_FORMAT);
		}
		return $data;
	}

	/**
	 * Create a new file based on an existing file (Save As)
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/PutRelativeFile.html
	 *
	 * @param string $url VFS url (Vfs::PREFIX+path) of the file we're operating on
	 */
	public function put_relative_file($url)
	{
		// Check if share _can_ be written to, before we bother with anything else
		if(!Wopi::is_writable())
		{
			http_response_code(404);
			header('X-WOPI-ServerError', 'Share is readonly');
			return;
		}

		$path = Vfs::parse_url($url, PHP_URL_PATH);

		// Lock token, might not be there
		$token = $this->header('X-WOPI-Lock');
		$lock = Vfs::checkLock($path);
		$dirname = Vfs::dirname($path);

		$suggested_target = Api\Translation::convert($this->header('X-WOPI-SuggestedTarget'), 'utf-7', 'utf-8');
		$relative_target = Api\Translation::convert($this->header('X-WOPI-RelativeTarget'), 'utf-7', 'utf-8');
		$overwrite = boolval($this->header('X-WOPI-OverwriteRelativeTarget'));
		//$size = intval($this->header('X-WOPI-Size'));
		if(Wopi::DEBUG)
		{
			error_log(__METHOD__."('$path') X-WOPI-SuggestedTarget='$suggested_target', X-WOPI-RelativeTarget='$relative_target', X-WOPI-OverwriteRelativeTarget=$overwrite");
		}

		// File name or extension
		if($suggested_target && $relative_target)
		{
			// Specifying both is invalid, we give Not Implemented
			http_response_code(501);
			if(Wopi::DEBUG)
			{
				error_log(__METHOD__."() RelativeTarget='$relative_target' AND SuggestedTarget='$suggested_target' is invalid --> 501 Not implemented");
			}
			return;
		}

		// Process the suggestion - modify as needed
		if ($suggested_target && $suggested_target[0] == '.')
		{
			// Only an extension, use current file name
			$info = pathinfo($path);
			$suggested_target = basename($path, '.'.$info['extension']) . $suggested_target;
		}
		if(strpos($suggested_target, '//') === 0)
		{
			// Sometimes Collabora suggested target has // at the front instead of /
			$suggested_target = substr($suggested_target, 2);
		}

		if(Wopi::DEBUG)
		{
			error_log(__METHOD__."() Directory: $dirname RelativeTarget='$relative_target' AND SuggestedTarget='$suggested_target'");
		}

		// Need access to full Vfs to check for existing files
		$api_config = Api\Config::read('phpgwapi');
		$GLOBALS['egw_info']['server']['vfs_fstab'] = $api_config['vfs_fstab'];
		Vfs\StreamWrapper::init_static();
		Vfs::clearstatcache();

		// seems targets can be relative
		if (!empty($suggested_target) && $suggested_target[0] != '/') $suggested_target = Vfs::concat ($dirname, $suggested_target);
		if (!empty($relative_target) && $relative_target[0] != '/') $relative_target = Vfs::concat ($dirname, $relative_target);

		if ($suggested_target)
		{
			// check for multiple extensions and only keep last one
			$matches = null;
			if (preg_match('/^(.*)\.([a-z0-9]{3,4})(\.[a-z0-9]{3,4})$/', $suggested_target, $matches) &&
				(!is_numeric($matches[2]) || in_array((int)$matches, array(123, 602))))
			{
				$suggested_target = $matches[1].$matches[3];
			}
			$target = $this->clean_filename($suggested_target);
			if(Wopi::DEBUG)
			{
				error_log(__METHOD__ . "() Suggested: $suggested_target Target: $target");
			}
		}

		if ($relative_target)
		{
			if(Wopi::DEBUG)
			{
				error_log(__METHOD__ . "() Relative: $relative_target (no changes allowed)");
			}

			// Can't modify this one, but we can check & fail it
			$clean = $this->clean_filename($relative_target, false);
			if($relative_target !== $clean)
			{
				http_response_code(400);
				header('X-WOPI-ValidRelativeTarget', $clean);
				if(Wopi::DEBUG)
				{
					error_log(__METHOD__."() clean_filename('$relative_target')='$clean' --> 400 Bad Request");
				}
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
					if(Wopi::DEBUG)
					{
						error_log(__METHOD__."() Vfs::file_exists('$relative_target') --> 409 Conflict");
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
					if(Wopi::DEBUG)
					{
						error_log(__METHOD__."() '$relative_target' is locked --> 409 Conflict");
					}
					return;
				}
			}
			$target = $relative_target;
		}

		// Ok, check target
		if(!Vfs::is_writable(dirname($target)))
		{
			// User not authorised, 401 is used for invalid token
			http_response_code(404);
			if(Wopi::DEBUG)
			{
				error_log(__METHOD__."() Vfs::is_writable(Vfs::dirname('$target')) = ". array2string(Vfs::is_writable(Vfs::dirname($target))).' --> 404 Not Found');
			}
			return;
		}

		// Read the contents of the file from the POST body and store.
		$content = $this->get_sent_content();
		if(False === file_put_contents(Vfs::PREFIX . $target, $content))
		{
			http_response_code(500);
			header('X-WOPI-ServerError', 'Unable to write file');
			return;
		}
		// remove evtl. set autosave TS, to force a new version
		Vfs::proppatch($path, array(array('ns' => Vfs::DEFAULT_PROP_NAMESPACE, 'name' => self::PROP_AUTOSAVE_TS, 'val' => null)));

		$url = Api\Framework::getUrl(Api\Framework::link('/collabora/index.php/wopi/files/'.Wopi::get_file_id($target))).
			'?access_token='. \EGroupware\Collabora\Bo::get_token($target)['token'];
		$response = array('Name' => Vfs::basename($target), 'Url' => $url);

		if(Wopi::DEBUG)
		{
			error_log(__METHOD__."() saved as $target   " . array2string($response));
		}
		return $response;
	}

	/**
	 * Make a filename valid, even if we have to modify it a little.
	 *
	 * @param String $original_path
	 * @param boolean $modify_filename = true Modify the filename to make it work
	 * @return String modified path
	 */
	protected function clean_filename($original_path, $modify_filename = true)
	{
		// Sanitize the characters -
		// Remove anything which isn't a word, whitespace, number
		// or any of the following caracters -_~,;[]().
		// Thanks, Sean Vieira
		$name = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', Vfs::basename($original_path));
		// Remove any runs of periods
		$file = mb_ereg_replace("([\.]{2,})", '', $name);

		$basename = pathinfo($file, PATHINFO_FILENAME);
		$extension = pathinfo($file, PATHINFO_EXTENSION);
		$path = Vfs::dirname($original_path);

		// Avoid duplicates
		$dupe_count = 0;
		while($modify_filename && Vfs::file_exists($path.'/'.$file) && $dupe_count < 1000)
		{
			$dupe_count++;
			$file = $basename .
				' ('.($dupe_count + 1).')' . '.' .
				$extension;
		}

		return Vfs::concat($path, $file);
	}

	/**
	 * Check if we allow the user to rename or Save As
	 *
	 * If the file is read-only, neither are allowed.
	 * We do not allow files shared as editable to rename either.  Only files
	 * opened directly for editing or opened from a shared (writable) directory
	 * are allowed to be renamed.
	 */
	protected function allow_save_as($path)
	{
		unset($path);	// not used, but required by function signature

		$share = Wopi::get_share();
		return $share['share_writable'] == Wopi::WOPI_WRITABLE;
	}
}
