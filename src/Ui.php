<?php
/**
 * EGroupware - Collabora integration user interface
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 */

namespace EGroupware\Collabora;

use EGroupware\Api;
use EGroupware\Api\Framework;
use EGroupware\Api\Json\Response;
use EGroupware\Api\Etemplate;
use EGroupware\Api\Vfs;

/**
 * User interface for collabora integration
 */
class Ui {

	public $public_functions = array(
		'editor' => TRUE,
		'merge_edit' => TRUE
	);

	/**
	 * New actions
	 * @var array
	 */
	static $new_actions = array (
		'document' => array (
			'caption' => 'Document',
			'icon' => 'mime128_application_vnd.oasis.opendocument.text.png',
			'onExecute' => 'javaScript:app.filemanager.create_new',
		),
		'spreadsheet' => array (
			'caption' => 'Spreadsheet',
			'icon' => 'mime128_application_vnd.openxmlformats-officedocument.spreadsheetml.sheet.png',
			'onExecute' => 'javaScript:app.filemanager.create_new',
		),
		'presentation' => array (
			'caption' => 'Presentation',
			'icon' => 'mime128_application_vnd.oasis.opendocument.presentation.png',
			'onExecute' => 'javaScript:app.filemanager.create_new',
		),
		'more' => array (
			'caption' => 'More ...',
			'icon' => 'mime128_unknown.png',
			'onExecute' => 'javaScript:app.filemanager.create_new',
		),
	);

	/**
	 * Add the actions needed to filemanager index to open files in collabora.
	 *
	 * This is hooked into the etemplate exec call, we're only interested in filemanager
	 * index though.
	 *
	 * @param String[] $data The content parameter from the Etemplate::exec() call, as
	 *	well as some additional keys:
	 *	'hook_location':		'etemplate2_before_exec'
	 *	'location_name':		The name of the etemplate, could be anything but we're only
	 *		interested in 'filemanager.index'
	 *	'location_object'		The Etemplate object
	 *
	 * @return String[] Modifications to make to the response.  These changes are
	 * made before any processing is done on the template exec().
	 *  String[] 'data':		Changes to the content
	 *  String[] 'readonlys':	Changes to the readonlys
	 *	String[] 'preserve':	Changes to preserve
	 */
	public static function index($data)
	{
		if($data['location_name'] != 'filemanager.index') return;

		Framework::includeJS('.','app','collabora',true);

		try
		{
			$discovery = Bo::discover();
		}
		catch (Api\Exception\WrongUserinput $e)
		{
			// Collabora is installed, but not configured --> ignore that
		}
		catch (\Exception $e)
		{
			$discovery = array();
			Response::get()->call('egw.message', lang('Unable to contact collabora server') . "\n\n" . $e->getMessage(), 'error');
		}
		if(!$discovery) return;

		// Send what the server said to the client, so we can use it there
		Response::get()->call('app.filemanager.set_discovery', $discovery);

		$changes = array();
		$changes['data']['is_collabora'] = true;
		$changes['data']['nm'] = $data['nm'];
		$changes['data']['nm']['actions']['new']['children'] = self::$new_actions
				+ array('openasnew' => array (
						'caption' => 'Open as new',
						'group' => 1,
						'icon' => 'copy',
						'enabled' => 'javaScript:app.filemanager.isEditable',
						'onExecute' => 'javaScript:app.filemanager.create_new',
				));
		$changes['sel_options']['new'] = \filemanager_ui::convertActionsToselOptions(self::$new_actions);

		return $changes;
	}

	/**
	 * Generate & send the needed HTML to properly open the editor
	 *
	 * @see https://wopi.readthedocs.io/en/latest/hostpage.html#
	 *
	 * @param string $path Path to the file to be edited
	 */
	public function editor($path = false)
	{
		if(!$path && $_GET['path'])
		{
			$path = $_GET['path'];
		}
		Framework::includeJS('.','app','collabora',true);
		$template = new Etemplate('collabora.editor');

		$content = array(
			'url'	=> Bo::get_action_url($path),
			'filename' => Vfs::basename($path),
		) + Bo::get_token($path);

		// Revision list
		if(Bo::is_versioned($path))
		{
			$fileinfo = Vfs::getExtraInfo($path);
			foreach($fileinfo as $tab)
			{
				if($tab['label'] == lang('Versions'))
				{
					$content['revisions'] = $tab['data']['versions'];
					$content['revisions'][0] = $content;
					unset($content['revisions'][0]['revisions']);
				}
			}
		}

		$template->exec('collabora.'.__CLASS__.'.editor', $content, array(), array(), array(), 3);
	}

	/**
	 * Merge the selected IDs into the given document, save it to the VFS, then
	 * open it in the editor.
	 */
	public static function merge_edit()
	{
		if(class_exists($_REQUEST['merge']) && is_subclass_of($_REQUEST['merge'], 'EGroupware\\Api\\Storage\\Merge'))
		{
			$document_merge = new $_REQUEST['merge']();
		}
		else
		{
			$document_merge = new Api\Contacts\Merge();
		}

		if(($error = $document_merge->check_document($_REQUEST['document'],'')))
		{
			$response->error($error);
			return;
		}

		$filename = '';
		$result = $document_merge->merge_file($_REQUEST['document'], explode(',',$_REQUEST['id']), $filename, '', $header);

		if(is_file($result) && is_readable($result))
		{
			// Put it into the vfs
			$target = $_target = "/home/{$GLOBALS['egw_info']['user']['account_lid']}/$filename";
			$dupe_count = 0;
			while(is_file(Vfs::PREFIX.$target))
			{
				$dupe_count++;
				$target = Vfs::dirname($_target) . '/' .
					pathinfo($filename, PATHINFO_FILENAME) .
					' ('.($dupe_count + 1).')' . '.' .
					pathinfo($filename, PATHINFO_EXTENSION);
			}
			copy($result, Vfs::PREFIX.$target);
			unlink($result);
			\Egroupware\Api\Egw::redirect_link('/index.php', array(
				'menuaction' => 'collabora.EGroupware\\Collabora\\Ui.editor',
				'path'=> $target
			));
		}
		else
		{
			echo $result;
		}
	}

	/**
	 * Get the required information to load the editor.
	 *
	 * This includes the file ID and token primarily, and is used when we want
	 * to keep the current editor window, and point it to a different file, such
	 * as a different revision.
	 *
	 * @param string $path Path to the file to be edited
	 */
	public static function ajax_getInfo($path)
	{
		$content = array(
			'url'	=> Bo::get_action_url($path),
			'filename' => Vfs::basename($path),
		) + Bo::get_token($path);


		$response = Api\Json\Response::get();
		if($content)
		{
			$response->data($content);
		}
	}

	/**
	 * Function to create new file for given filename and extension
	 *
	 * @param string $ext file extension
	 * @param string $dir directory
	 * @param string $name filename
	 * @param string $openasnew path of the file to be opened as new
	 *
	 */
	public static function ajax_createNew ($ext, $dir, $name, $openasnew)
	{
		$response = Api\Json\Response::get();
		$data = array ();
		if (!Api\Vfs::is_writable($dir))
		{
			$response->data(array(
				'message' => lang ('Failed to create the file! Because you do not have enough permission to %1 folder!', $dir)
			));
		}
		$file = ($dir === '/' ? $dir : $dir.'/').$name.'.'.$ext;
		$temp_url = $GLOBALS['egw_info']['server']['webserver_url'].
				'/collabora/assets/template_'.$ext.'.'.$ext;
		if ($temp_url[0] == '/')
		{
			$temp_url = ($_SERVER['SERVER_PORT'] == 443 || !empty($_SERVER['HTTPS'])
					&& $_SERVER['HTTPS'] !== 'off' || $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https://': 'http://'.
					$_SERVER['HTTP_HOST'].$temp_url;
		}
		$template = file_get_contents($temp_url);
		if (Api\Vfs::file_exists($file))
		{
			$data['message'] = lang('Failed to create file %1: file already exists!', $file);
		}
		elseif ($openasnew) // file opened as new
		{
			if (Api\Vfs::copy($openasnew, $file))
			{
				$data['message'] = lang('File %1 has been created successfully.', $file);
				$data['path'] = $file;
			}
			else
			{
				$data['message'] = lang('Failed to create file %1!',$file);
			}
		}
		else if (!($fp = Api\Vfs::fopen($file,'wb')) || !fwrite($fp, $template? $template: ' '))
		{
			$data['message'] = lang('Failed to create file %1!',$file);
		}
		else
		{
			$data['message'] = lang('File %1 has been created successfully.', $file);
			$data['path'] = $file;
		}
		if ($fp) fclose($fp);
		$response->data($data);
	}
}
