<?php

namespace EGroupware\Collabora\Wopi;

use EGroupware\Api\Config;
use EGroupware\Api\DateTime;
use EGroupware\Api\Egw;
use EGroupware\Api\Framework;
use EGroupware\Api\Preferences;
use EGroupware\Api\Sharing;
use EGroupware\Api\Vfs;
use EGroupware\Collabora\Bo;
use EGroupware\Collabora\Wopi;

/**
 * Settings endpoint
 *
 * @see https://sdk.collaboraonline.com/docs/advanced_integration.html?highlight=browser#setup-settings-iframe
 */
class Settings
{
	const settings = ['autotextFile', 'wordbookFile', 'BrowserSettingsFile'];

	/**
	 * Sub-directories of a settings directory Collabora may write to
	 */
	const SETTING_DIRECTORIES = ['autotext', 'wordbook', 'xcu', 'browsersetting', 'viewsetting', 'themes', 'extensions', 'spif'];

	/**
	 * Process a request to the settings endpoint
	 *
	 * @return Array Map of information as response to the request
	 */
	public function process()
	{
		if($_SERVER['REQUEST_METHOD'] == 'GET' && $_GET['type'] && $_GET['fileId'] == -1)
		{
			return $this->fetch();
		}
		elseif($_SERVER['REQUEST_METHOD'] == 'POST' && $_REQUEST['fileId'] && !is_numeric($_REQUEST['fileId']))
		{
			return $this->upload();
		}
		elseif($_SERVER['REQUEST_METHOD'] == 'DELETE')
		{
			return $this->delete();
		}
	}

	protected function fetch()
	{
		// userconfig or systemconfig
		$type = $_GET['type'];
		$path = $this->getSettingsPath($type);

		$response = ['kind'     => $type == 'systemconfig' ? 'shared' : 'user',
					 'autotext' => [], 'xcu' => [], 'browsersetting' => []
		];
		// no (valid) settings directory: nothing to offer, and do not search the whole VFS
		if(!$path || !Vfs::is_dir($path))
		{
			return $response;
		}

		// Get files
		$files = Vfs::find($path, ['type' => 'f',]);
		foreach($files as $file)
		{
			$setting = str_replace($path, '', Vfs::dirname($file));
			if(!$setting)
			{
				continue;
			}
			$setting = substr($setting, 1);
			if(empty($response[$setting]))
			{
				$response[$setting] = [];
			}
			$response[$setting][] = $this->getFileInfo($file);
		}
		return $response;
	}

	protected function upload()
	{
		list(, , $type, $setting_name, $file_name) = explode('/', $_REQUEST['fileId']);
		$path = $this->getSettingPath($type, $setting_name, $file_name);
		if(!$path)
		{
			http_response_code(400);
			header('X-WOPI-ServerError: Invalid setting');
			return;
		}
		$response = [
			'status'   => 'failure',
			'filename' => $file_name
		];
		if(Vfs::is_dir(Vfs::dirname($path)) || Vfs::mkdir(Vfs::dirname($path), null, STREAM_MKDIR_RECURSIVE))
		{
			$content = fopen('php://input', 'r');
			if(False === file_put_contents(Vfs::PREFIX . $path, $content, 0))
			{
				http_response_code(500);
				header('X-WOPI-ServerError: Unable to write file');
				return;
			}
			$response['status'] = 'success';
			$response['details'] = $this->getFileInfo($path);
		}

		return $response;
	}

	protected function delete()
	{
		list(, , $type, $setting_name, $file_name) = explode('/', $_REQUEST['fileId']);
		$path = $this->getSettingPath($type, $setting_name, $file_name);
		if(!$path)
		{
			http_response_code(400);
			header('X-WOPI-ServerError: Invalid setting');
			return;
		}
		if(!Vfs::file_exists($path) || !Vfs::unlink($path))
		{
			http_response_code(500);
			header('X-WOPI-ServerError: Unable to delete file');
			return;
		}
		// the directory's time is how a deleted file reaches getStamp(), no remaining file shows it
		Vfs::touch(Vfs::dirname($path));
		return [
			'status'  => 'success',
			'message' => lang("%1 deleted", $file_name)
		];
	}

	/**
	 * Get the VFS path of a setting file from the parts of a client supplied fileId
	 *
	 * The parts are untrusted: only a known setting type and sub-directory are accepted, and the file name
	 * can not point anywhere but into that sub-directory.
	 *
	 * @param string $type 'userconfig' or 'systemconfig'
	 * @param string $setting_name one of self::SETTING_DIRECTORIES
	 * @param string $file_name name of the file in that directory
	 * @return string|null null if any part is not acceptable
	 */
	protected function getSettingPath($type, $setting_name, $file_name)
	{
		$settings_path = $this->getSettingsPath($type);
		if(!$settings_path || !in_array($setting_name, self::SETTING_DIRECTORIES, true) ||
			$file_name === null || $file_name === '' || $file_name === '.' || $file_name === '..')
		{
			return null;
		}
		return $settings_path . '/' . $setting_name . '/' . $file_name;
	}

	protected function getFileInfo($file)
	{
		$mtime = new DateTime(filemtime(Vfs::PREFIX . $file), DateTime::$server_timezone);
		$mtime->setTimezone(new \DateTimeZone('UTC'));
		$token = Bo::get_token($file);
		return [
			'uri'   => Vfs\Sharing::share2link($token['token']) . '?access_token=' . $_GET['access_token'] . '&file_name=' . urlencode(Vfs::basename($file)),
			'stamp' => $mtime->format(Files::DATE_FORMAT)
		];
	}

	/**
	 * Time of the last change in a settings directory, in the format Collabora expects as stamp
	 *
	 * Collabora downloads the settings again when the stamp is newer than the one it has.  Directories are
	 * included, as their time changes when a file is deleted, which no remaining file can show.
	 *
	 * @param string $type 'userconfig' or 'systemconfig'
	 * @return string
	 */
	public function getStamp($type)
	{
		$newest = 0;
		$path = $this->getSettingsPath($type);
		if($path && Vfs::is_dir($path))
		{
			foreach(array_merge([$path], Vfs::find($path)) as $file)
			{
				$newest = max($newest, (int)(Vfs::stat($file)['mtime'] ?? 0));
			}
		}
		// never 0, that is not a time to DateTime
		$stamp = new DateTime($newest ?: 1, DateTime::$server_timezone);
		$stamp->setTimezone(new \DateTimeZone('UTC'));
		return $stamp->format(Files::DATE_FORMAT);
	}

	public function getSettingsPath($type)
	{
		$path = [];
		switch($type)
		{
			case 'userconfig':
				return Vfs::get_home_dir() . '/.config/collabora';
				break;
			case 'systemconfig':
				$config = (new Config('collabora'))->read_repository();
				return ($config['settings_directory'][0] ?? '');
				break;
		}
		return $path;
	}
}