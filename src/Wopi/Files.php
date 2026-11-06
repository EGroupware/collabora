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

use EGroupware\Api;
use EGroupware\Api\Accounts;
use EGroupware\Api\Mail;
use EGroupware\Api\Vfs;
use EGroupware\Collabora\Wopi;

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
			$this->set_http_response_code(404);
			header('X-WOPI-ServerError: Missing file ID');
			return;
		}
		$path = Wopi::get_path_from_token();
		if($path == '')
		{
			$path = Wopi::get_path_from_id($id);
			error_log(__METHOD__."($id) _REQUEST=".array2string($_REQUEST).", X-WOPI-Override=".$this->header('X-WOPI-Override').", path (from token) = $path");
		}
		else if(Wopi::DEBUG)
		{
			error_log(__METHOD__."($id) _REQUEST=".array2string($_REQUEST).", X-WOPI-Override=".$this->header('X-WOPI-Override').", path (from id $id) = $path");
		}

		if ($path[0] === '/') $path = Vfs::PREFIX.$path;
		// used the resolved path for locking, so the original file is locked, not the sharing-url
		$lockpath = Vfs::resolve_url($path) ?: $path;
		Vfs::load_wrapper(Vfs::parse_url($path, PHP_URL_SCHEME));
		if(!$path || is_dir($path))
		{
			$this->set_http_response_code(404);
			header('X-WOPI-ServerError: Unable to find file / path is invalid');
			return;
		}

		switch ($this->header('X-WOPI-Override'))
		{
			case 'LOCK':
				$this->lock($lockpath);
				return;
			case 'GET_LOCK':
				$this->get_lock($lockpath);
				return;
			case 'REFRESH_LOCK':
				$this->refresh_lock($lockpath);
				return;
			case 'UNLOCK':
				$this->unlock($lockpath);
				return;
			case 'PUT':
				return $this->put($path, $lockpath);
			case 'PUT_RELATIVE':
				return $this->put_relative_file($path, $lockpath);
			case 'DELETE':
				return $this->delete_file($path, $lockpath);
			case 'RENAME_FILE':
				return $this->rename_file($path);
			default:
				if(preg_match('#/wopi/([[:alpha:]]+)/(-?[[:digit:]]+)/contents#',$_SERVER['REQUEST_URI']))
				{
					return $this->get_file($path);
				}
				$data = $this->check_file_info($path);
		}

		if($data == null)
		{
			$this->set_http_response_code(404);
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
		$origin = preg_replace('#(https://[^/]+).*$#', '$1', Api\Framework::getUrl($GLOBALS['egw_info']['server']['webserver_url']));
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

			// Support locking - Collabora does not
			//'SupportsGetLock'   => true,
			//'SupportsLocks'     => true,

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
				'avatar'	    => Api\Framework::getUrl(Api\Framework::link('/api/avatar.php?account_id='.Vfs::$user)),
				'mail'          => $GLOBALS['egw_info']['user']['account_email'],
			),
			'IsAdminUser'       => !empty($GLOBALS['egw_info']['user']['apps']['admin']),
			'IsAnonymousUser'   => $GLOBALS['egw_info']['user']['account_lid'] === 'anonymous' || $GLOBALS['egw']->session->session_flags == 'A',
		);

		if($path)
		{
			$data['BaseFileName'] = Vfs::basename($path);
		}
		if($path)
		{
			$stat = stat($path);
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

		// Settings (autotext, dictionaries, browser settings) the editor loads from our settings endpoint
		$settings = new Settings();
		// before the stamps, as it may create a settings file
		$user_info = $this->user_private_info($settings);
		foreach(['UserSettings' => 'userconfig', 'SharedSettings' => 'systemconfig'] as $key => $type)
		{
			$data[$key] = [
				'uri'   => Api\Framework::getUrl(Api\Framework::link(
					'/collabora/index.php/wopi/settings',
					['access_token' => Wopi::get_token(), 'type' => $type, 'fileId' => '-1']
				)),
				'stamp' => $settings->getStamp($type)
			];
		}

		if($user_info)
		{
			$data['UserPrivateInfo'] = $user_info;
		}
		if(($server_info = $this->server_private_info()))
		{
			$data['ServerPrivateInfo'] = $server_info;
		}

		return $data;
	}

	/**
	 * Signing certificate, key and CA for the editor to sign documents with
	 *
	 * Anything the user stored in Collabora's own settings is used as it is.  Only if there is nothing
	 * there, the user's S/MIME certificate from the Mail app is tried, with the passphrase the user unlocked it
	 * with in this session, or without one if there is none.  No usable certificate is no signing, without any
	 * message.  Collabora ignores the certificate of a user who has no settings file, so one is created if needed.
	 * Collabora then keeps the certificate and key in that file, where it counts as the user's own setting: to use
	 * a changed Mail certificate, the user clears the signing fields in their Collabora settings.
	 *
	 * Collabora only uses them for signing when the integration passes them back as UserPrivateInfo.
	 *
	 * @param Settings $settings
	 * @return string[] SignatureCert, SignatureKey and SignatureCa, or empty if there is no certificate and key
	 */
	protected function user_private_info(Settings $settings) : array
	{
		$stored = $this->stored_signing_settings($settings);
		if($stored)
		{
			return empty($stored['signatureCert']) || empty($stored['signatureKey']) ? [] : array_filter([
				'SignatureCert' => $stored['signatureCert'],
				'SignatureKey'  => $stored['signatureKey'],
				'SignatureCa'   => $stored['signatureCa'] ?? ''
			]);
		}
		$info = $this->mail_smime_certificate();
		if($info)
		{
			// Collabora ignores the certificate for a user without a settings file
			$settings->ensureViewSetting();
		}
		return $info;
	}

	/**
	 * The signing settings the user stored in Collabora's own settings (viewsetting file)
	 *
	 * @param Settings $settings
	 * @return array stored values, empty if the user has set no certificate or key
	 */
	protected function stored_signing_settings(Settings $settings) : array
	{
		$dir = $settings->getSettingsPath('userconfig') . '/viewsetting';
		if(!Vfs::is_dir($dir))
		{
			return [];
		}
		foreach(Vfs::find($dir, ['type' => 'f']) as $file)
		{
			$view = json_decode(file_get_contents(Vfs::PREFIX . $file), true);
			if(is_array($view) && (!empty($view['signatureCert']) || !empty($view['signatureKey'])))
			{
				return $view;
			}
		}
		return [];
	}

	/**
	 * The user's S/MIME certificate from the Mail app, if it opens with the passphrase cached in the session
	 * when the user unlocked it, or without one if nothing is cached
	 *
	 * The request runs in the user's own (restored) session, so a certificate stored with their login password
	 * can be read and the passphrase Mail cached for the session is there too.  Whether the PKCS#12 file itself
	 * is protected by a passphrase is a separate matter.
	 *
	 * A certificate that is stored but can not be used is not told to the user, it is written to the error log
	 * for the admin, see log_smime_problem().  Not having one at all is not a problem.
	 *
	 * @return string[] SignatureCert, SignatureKey and SignatureCa, or empty
	 */
	protected function mail_smime_certificate() : array
	{
		$account_id = (int)Vfs::$user;
		if($account_id <= 0)
		{
			return [];
		}
		$acc_id = 0;
		try
		{
			$rows = Mail\Credentials::get_db()->select(Mail\Credentials::TABLE, 'acc_id', [
				'account_id' => $account_id,
				'(cred_type & ' . Mail\Credentials::SMIME . ') > 0',
			], __LINE__, __FILE__, false, 'ORDER BY acc_id', Mail\Credentials::APP);
			// the passphrase the user unlocked with, if there is one, otherwise none: a user who unlocked their
			// certificate has one, and the cache is a single slot for all of the user's accounts
			$passphrase = Api\Cache::getSession('mail', 'smime_passphrase');
			$passphrase = is_string($passphrase) ? $passphrase : '';
			foreach($rows as $row)
			{
				$acc_id = (int)$row['acc_id'];
				$on_login = null;
				$smime = Mail\Credentials::read($acc_id, Mail\Credentials::SMIME, $account_id, $on_login, null, false);
				$pkcs12 = $smime['acc_smime_password'] ?? '';
				if($pkcs12 === '')
				{
					continue;	// no certificate stored, nothing to report
				}
				if($pkcs12 === Mail\Credentials::UNAVAILABLE)
				{
					$this->log_smime_problem($account_id, $acc_id, 'it can not be decrypted in this session');
					continue;
				}
				$error = null;
				if(($info = static::smime_certificate($pkcs12, $passphrase, $error)))
				{
					return $info;
				}
				$this->log_smime_problem($account_id, $acc_id, 'it does not open ' . ($passphrase === '' ?
					'without a passphrase and none is cached in the session' : 'with the passphrase cached in the session') .
					($error ? " ($error)" : ''));
			}
		}
		catch(\Throwable $e)
		{
			// no certificate we can use: no signing, nothing to tell the user, but the admin can find out why
			$this->log_smime_problem($account_id, $acc_id, 'reading it failed: ' . get_class($e) . ': ' . $e->getMessage());
		}
		return [];
	}

	/**
	 * Key under which the last logged problem with a user's S/MIME certificate is remembered
	 *
	 * @param int $account_id
	 * @param int $acc_id mail account, 0 if it is not known
	 * @return string
	 */
	public static function smime_problem_key(int $account_id, int $acc_id) : string
	{
		return 'smime-problem-' . $account_id . '-' . $acc_id;
	}

	/**
	 * Tell the admin why a user's S/MIME certificate can not be used for signing
	 *
	 * Written to the PHP error log, once an hour for the same problem of the same user and mail account: the
	 * editor asks for the file info several times for every document it opens.  Never contains the passphrase
	 * or any part of the certificate.
	 *
	 * @param int $account_id
	 * @param int $acc_id mail account, 0 if it is not known
	 * @param string $reason
	 */
	protected function log_smime_problem(int $account_id, int $acc_id, string $reason) : void
	{
		$key = static::smime_problem_key($account_id, $acc_id);
		if(Api\Cache::getInstance('collabora', $key) === $reason)
		{
			return;
		}
		Api\Cache::setInstance('collabora', $key, $reason, 3600);
		error_log("Collabora: no signing for user #$account_id, their S/MIME certificate" .
			($acc_id ? " of mail account #$acc_id" : '') . " can not be used: $reason");
	}

	/**
	 * Certificate, unencrypted key and CA chain from a PKCS#12 file
	 *
	 * @param string $pkcs12 content of the p12 file
	 * @param string $passphrase passphrase of the file, '' for one without
	 * @param ?string& $error on failure why it did not open, as far as OpenSSL says
	 * @return string[] SignatureCert, SignatureKey and SignatureCa, or empty if the passphrase is wrong or the file is not usable
	 */
	public static function smime_certificate(string $pkcs12, string $passphrase = '', ?string &$error = null) : array
	{
		$certs = [];
		while(openssl_error_string())
		{
		}
		$opened = $pkcs12 !== '' && @openssl_pkcs12_read($pkcs12, $certs, $passphrase);
		// do not leave OpenSSL's complaints about the wrong passphrase for the next caller
		$errors = [];
		while(($message = openssl_error_string()))
		{
			$errors[] = $message;
		}
		if(!$opened || empty($certs['cert']) || empty($certs['pkey']))
		{
			$error = $errors ? implode('; ', $errors) : 'not a usable PKCS#12 file';
			return [];
		}
		return array_filter([
			'SignatureCert' => $certs['cert'],
			'SignatureKey'  => $certs['pkey'],
			'SignatureCa'   => implode("\n", array_map('trim', (array)($certs['extracerts'] ?? [])))
		]);
	}

	/**
	 * Credentials of the eIDeasy service for Collabora's electronic signing
	 *
	 * @return string[] ESignatureBaseUrl, ESignatureClientId and ESignatureSecret, or empty if not configured
	 */
	protected function server_private_info() : array
	{
		$config = Api\Config::read('collabora');
		if(empty($config['esig_url']))
		{
			return [];
		}
		return [
			// Collabora appends "/api/..." itself, a trailing slash would give "//api/..." which eIDeasy rejects
			'ESignatureBaseUrl'  => rtrim($config['esig_url'], '/'),
			'ESignatureClientId' => $config['esig_client_id'] ?? '',
			'ESignatureSecret'   => $config['esig_secret'] ?? ''
		];
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
		$stat = stat($path);

		// send a content-disposition header, so browser knows how to name downloaded file
		Api\Header\Content::disposition(Vfs::basename($path), false);
		header('Content-Length: ' . $stat['size']);
		header('Content-Type: ' . $stat['mime']);
		readfile($path, null, Vfs\StreamWrapper::userContext($path));
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
			$this->set_http_response_code(400);
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
			if(Wopi::DEBUG)
			{
				error_log(__METHOD__ . "($path) unable to lock, already locked with " . array2string($old_lock));
			}
			header('X-WOPI-Lock: ' . $lock['token']);
			$this->set_http_response_code(409);
			return;
		}
		else
		{
			if ($old_lock && $old_lock == $lock['token'])
			{
				Vfs::unlock($path, $old_lock);
			}
		}

		// Lock the file, refresh if the tokens match
		$result = Vfs::lock($path, $token, $timeout, $owner, $scope, $type, $lock['token'] == $token);

		if(Wopi::DEBUG)
		{
			error_log(__METHOD__ . "($path) " . ($result ? "successfully" : "failed!  Not") . " locked");
		}

		header('X-WOPI-Lock: ' . $token);
		$this->set_http_response_code($result ? 200 : 409);
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

		header('X-WOPI-Lock: ' . $lock['token']);
		$this->set_http_response_code(200);
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
			$this->set_http_response_code(400);
		}

		$timeout = static::LOCK_DURATION;
		$owner = $GLOBALS['egw_info']['user']['account_id'];
		$scope = 'exclusive';
		$type = 'write';

		$result = Vfs::lock($path, $token, $timeout, $owner, $scope, $type, true);

		header('X-WOPI-Lock: ' . $token);
		$this->set_http_response_code($result ? 200 : 409);
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
			$this->set_http_response_code(400);
		}

		$lock = Vfs::checkLock($path);
		if(!$lock)
		{
			// File was already unlocked
			$this->set_http_response_code(200);
			return;
		}

		if($lock['token'] != $token)
		{
			header('X-WOPI-Lock: ' . $lock['token']);
			// Conflict
			$this->set_http_response_code(409);
		}

		$result = Vfs::unlock($path, $token);

		$this->set_http_response_code($result ? 200 : 409);
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
	 * @param string $lockpath =null resolved path for locking, default $path
	 */
	public function put($path, $lockpath=null)
	{
		// Check if file _can_ be written to, before we bother with anything else
		if(!Vfs::is_writable($path))
		{
			$this->set_http_response_code(404);
			header('X-WOPI-ServerError: File is readonly');
			return;
		}

		// Lock token, might not be there for new files
		$token = $this->header('X-WOPI-Lock');

		// Check lock
		$lock = Vfs::checkLock($lockpath ?: $path);
		if(!$lock)
		{
			/* Collabora Online does not support locking, and never locks
			 * so we skip this check to make saving work

			// Check file size
			$stat = Vfs::stat($path);
			if($stat['size'] != 0)
			{
				// Conflict
				$this->set_http_response_code(409);
				return;
			}

			 */
		}
		else if ($token && $lock['token'] !== $token)
		{
			// Conflict
			$this->set_http_response_code(409);
			header('X-WOPI-Lock: ' . $lock['token']);
			return;
		}
/*
		$api_config = Api\Config::read('phpgwapi');
		$GLOBALS['egw_info']['server']['vfs_fstab'] = $api_config['vfs_fstab'];
		Vfs\StreamWrapper::init_static();
		Vfs::clearstatcache();
*/
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
		$context = Vfs\StreamWrapper::userContext($path, [
			Vfs::SCHEME => [
				'versioning' => [
					'disable' => $is_autosaved,
					'min_version' => 0,	// do NOT stop explicit versioning for non autosave and new opened of files
				],
			],
		]);
		//error_log(__METHOD__."('$path') prop=".array2string($prop).", is_autosaved=".array2string($is_autosaved).", X-LOOL-WOPI-IsAutosave={$this->header('X-LOOL-WOPI-IsAutosave')} --> context=".array2string($c));

		if (False === file_put_contents($path, $content, 0, $context))
		{
			$this->set_http_response_code(500);
			header('X-WOPI-ServerError: Unable to write file');
			return;
		}
		// mark version as autosaved by storing it's modification TS
		Vfs::proppatch($path, array(array(
			'ns' => Vfs::DEFAULT_PROP_NAMESPACE,
			'name' => self::PROP_AUTOSAVE_TS,
			'val' => $this->header('X-LOOL-WOPI-IsAutosave') === 'true' ? filemtime($path) : null,
		)));

		$stat = stat($path);
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
	 * @param string $lockpath =null resolved path for locking, default $path
	 * @return array|void
	 */
	public function put_relative_file($url, $lockpath=null)
	{
		$path = Vfs::parse_url($url, PHP_URL_PATH);

		// Lock token, might not be there
		$token = $this->header('X-WOPI-Lock');
		$lock = Vfs::checkLock($lockpath ?: $path);
		$dirname = Vfs::dirname($url);

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
			$this->set_http_response_code(501);
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

		/* Need access to full Vfs to check for existing files
		$api_config = Api\Config::read('phpgwapi');
		$GLOBALS['egw_info']['server']['vfs_fstab'] = $api_config['vfs_fstab'];
		Vfs\StreamWrapper::init_static();
		Vfs::clearstatcache();*/

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
				$this->set_http_response_code(400);
				header('X-WOPI-ValidRelativeTarget: ' . $clean);
				if (Wopi::DEBUG)
				{
					error_log(__METHOD__ . "() clean_filename('$relative_target')='$clean' --> 400 Bad Request");
				}
				return;
			}
			if (Vfs::file_exists($relative_target))
			{
				if(!$overwrite)
				{
					$this->set_http_response_code(409); // Conflict
					if($lock)
					{
						header('X-WOPI-Lock: ' . $lock['token']);
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
					$this->set_http_response_code(409);
					if($lock)
					{
						header('X-WOPI-Lock: ' . $lock['token']);
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

		// Ok, check target - rely on Vfs for permissions
		if(!Vfs::is_writable(dirname($target)))
		{
			// User not authorised, 401 is used for invalid token
			$this->set_http_response_code(404);
			if(Wopi::DEBUG)
			{
				error_log(__METHOD__."() Vfs::is_writable(Vfs::dirname('$target')) = ". array2string(Vfs::is_writable(Vfs::dirname($target))).' --> 404 Not Found');
			}
			return;
		}

		// Read the contents of the file from the POST body and store.
		$content = $this->get_sent_content();
		if ($target[0] === '/') $target = Vfs::PREFIX.$target;
		if(False === file_put_contents($target, $content, 0, Vfs\StreamWrapper::userContext($target)))
		{
			$this->set_http_response_code(500);
			header('X-WOPI-ServerError: Unable to write file');
			return;
		}
		// remove evtl. set autosave TS, to force a new version
		Vfs::proppatch($path, array(array('ns' => Vfs::DEFAULT_PROP_NAMESPACE, 'name' => self::PROP_AUTOSAVE_TS, 'val' => null)));

		// This creates a new share for the new file.  Need to create it before Wopi::get_file_id(), or it doesn't always find the ID (SMB)
		$share = \EGroupware\Collabora\Bo::get_token($target);

		$url = Api\Framework::getUrl(Api\Framework::link('/collabora/index.php/wopi/files/'.Wopi::get_file_id($target))).
			'?access_token=' . $share['token'];
		$response = array('Name' => Vfs::basename($target), 'Url' => $url);

		if(Wopi::DEBUG)
		{
			error_log(__METHOD__."() saved as $target   " . array2string($response));
		}
		return $response;
	}

	/**
	 * Delete a file
	 *
	 * @see https://wopi.readthedocs.io/projects/wopirest/en/latest/files/DeleteFile.html#deletefile
	 *
	 * @param $path
	 * @param string $lockpath =null resolved path for locking, default $path
	 */
	public function delete_file($path, $lockpath=null)
	{
		if(Wopi::DEBUG)
		{
			error_log(__METHOD__."('$path', '$lockpath') ");
		}

		if(!$this->check_lock($lockpath ?: $path))
		{
			return;
		}
		unlink($path);
	}

	/**
	 * Rename a file
	 *
	 * The requested file name only has the file name, no path or extension.
	 * While the Url response property is not mentioned in the docs, Collabora
	 * complains if it is not there.
	 *
	 * @see https://wopi.readthedocs.io/projects/wopirest/en/latest/files/RenameFile.html
	 *
	 * @param String $original_path
	 * @return array
	 */
	public function rename_file($original_path)
	{
		$suggested_target = Api\Translation::convert($this->header('X-WOPI-RequestedName'), 'utf-7', 'utf-8');
		$target = $this->clean_filename(pathinfo($original_path, PATHINFO_DIRNAME) . '/' . $suggested_target . '.' . pathinfo($original_path, PATHINFO_EXTENSION));

		if(Wopi::DEBUG)
		{
			error_log(__METHOD__ . "('$original_path' -> $target) ");
		}
		// Unlock the old filename
		$lock = Vfs::checkLock($original_path);
		Vfs::unlock($original_path, $lock['token']);

		rename($original_path, $target);

		$url = Api\Framework::getUrl(Api\Framework::link('/collabora/index.php/wopi/files/' . Wopi::get_file_id($target))) .
			'?access_token=' . \EGroupware\Collabora\Bo::get_token($target)['token'];

		return array('Name' => Vfs::basename($target), 'Url' => $url);
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
		while($modify_filename && file_exists($path.'/'.$file) && $dupe_count < 1000)
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

	/**
	 * Checks the lock and sets the appropriate header (409) if there's a mismatch.
	 *
	 * @return bool
	 */
	protected function check_lock($path)
	{
		// Lock token, might not be there
		$token = $this->header('X-WOPI-Lock');
		$lock = Vfs::checkLock(Vfs::parse_url($path, PHP_URL_PATH));

		if(!$token && $lock || $token && $lock['token'] !== $token)
		{
			// Conflict
			$this->set_http_response_code(409);
			if ($lock)
			{
				header('X-WOPI-Lock: ' . $lock['token']);
			}
			if (Wopi::DEBUG)
			{
				error_log(__METHOD__ . "() '$path' is locked --> 409 Conflict");
			}
			return false;
		}
		return true;
	}

	protected function set_http_response_code($code)
	{
		http_response_code($code);
	}
}