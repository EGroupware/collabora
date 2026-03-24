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
	 * Process a request to the settings endpoint
	 *
	 * @return Array Map of information as response to the request
	 */
	public function process()
	{
		error_log("\n" . __METHOD__ . ' ' . $_SERVER['REQUEST_METHOD']);
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

		$prefs = [];
		$response = ['kind'     => $type == 'systemconfig' ? 'shared' : 'user',
					 'autotext' => [], 'xcu' => [], 'browsersetting' => []
		];

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
		$settings_path = $this->getSettingsPath($type);
		$path = $settings_path . "/" . $setting_name;
		$response = [
			'status'   => 'failure',
			'filename' => $file_name
		];
		error_log("\nTarget path: " . $path . " exists: " . Vfs::is_dir($path));
		if(Vfs::is_dir($path) || Vfs::mkdir($path, null, STREAM_MKDIR_RECURSIVE))
		{
			$path = Vfs::PREFIX . $path . '/' . $file_name;
			error_log("Target path: " . $path);
			$content = fopen('php://input', 'r');
			if(False === file_put_contents($path, $content, 0))
			{
				http_response_code(500);
				header('X-WOPI-ServerError: Unable to write file');
				return;
			}
			$response['status'] = 'success';
			$response['details'] = $this->getFileInfo($path);
		}

		error_log(array2string($response));
		return $response;
	}

	protected function delete()
	{
		list(, , $type, $setting_name, $file_name) = explode('/', $_REQUEST['fileId']);

		$settings_path = $this->getSettingsPath($type);

		// Collabora doesn't use our filename, so get the full path from token
		// $path = $settings_path . '/' . $setting_name . '/' . $file_name;

		$share = [];
		Wopi::check_token(true, $share, explode('=', $file_name)[1]);
		$path = $share['share_path'];

		if(unlink($path))
		{
			$response = [
				'status'  => 'success',
				'message' => lang("%1 deleted", Vfs::basename($path))
			];
		}

		return $response;
	}

	protected function getFileInfo($file)
	{
		$mtime = new DateTime(filemtime(Vfs::PREFIX . $file), DateTime::$server_timezone);
		$mtime->setTimezone(new \DateTimeZone('UTC'));
		$token = Bo::get_token($file);
		return [
			// Shows up and downloads but doesn't actually work inside Collabora
			'uri'   => Vfs\Sharing::share2link($token['token']) . '?access_token=' . $_GET['access_token'] . '&file_name=' . urlencode(Vfs::basename($file)),
			// Shows up with correct name, but path is wrong so download fails
			//  'uri'   => Vfs\Sharing::share2link($token['token']). '/' . Vfs::basename($file),

			// Shows up as file ID, errors due to share failure when downloading
			//'uri'   => Framework::getUrl(Egw::link('/collabora/index.php/wopi/files/' . Wopi::get_file_id($file, $token) . '/contents/' . Vfs::basename($file), ['access_token' => $token['token'], 'name/'        => Vfs::basename($file)])),
			// Shows up as encoded name but file is unavailable (not shared)
			//'uri'   => Framework::getUrl(Vfs::download_url($file)),
			'stamp' => $mtime->format(Files::DATE_FORMAT)
		];
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
				$config = new Config('collabora')->read_repository();
				return ($config['settings_directory'][0] ?? '');
				break;
		}
		return $path;
	}
}