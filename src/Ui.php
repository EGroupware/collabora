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
use EGroupware\Api\Etemplate;
use EGroupware\Api\Framework;
use EGroupware\Api\Json\Response;
use EGroupware\Api\Vfs;

/**
 * User interface for collabora integration
 */
class Ui {

	public $public_functions = array(
		'editor' => TRUE
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
		'drawing' => array (
			'caption' => 'Drawing',
			'icon' => 'mime128_application_vnd.koan.png',
			'onExecute' => 'javaScript:app.filemanager.create_new',
		),
		'more' => array (
			'caption' => 'More',
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

		Framework::includeJS('.', 'app.min', 'collabora', true);
		Api\Translation::add_app('collabora');

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

		// Translate captions
		foreach (self::$new_actions as &$action)
		{
			$action['caption'] = lang($action['caption']);
		}

		$changes = array();
		$changes['data']['is_collabora'] = true;
		$changes['data']['nm'] = $data['nm'];
		$changes['data']['nm']['actions']['new']['children'] = self::$new_actions
				+ array('openasnew' => array (
						'caption' => lang('Open as new'),
						'group' => 1,
						'icon' => 'copy',
						'enabled' => 'javaScript:app.filemanager.isEditable',
						'onExecute' => 'javaScript:app.filemanager.create_new',
				));
		if($GLOBALS['egw_info']['user']['apps']['stylite'])
		{
			$changes['data']['nm']['actions']['share']['children']['shareCollaboraLink'] = array(
				'caption'        => lang('Writable Collabora link'),
				'group'          => 1,
				'icon'           => 'view',
				'enabled'        => 'javaScript:app.filemanager.isSharableFile',
				'hideOnDisabled' => true,
				'order'          => 12,
				'onExecute'      => 'javaScript:app.filemanager.share_collabora_link'
			);
			$changes['data']['nm']['actions']['share']['children']['share_mail']['children']['mail_collabora'] = array(
				'caption'   => lang('Writable collabora share'),
				'icon'      => 'api/link',
				'hint'      => lang('Link is appended to email allowing recipients to edit the file'),
				'group'     => 2,
				'order'     => 12,
				'enabled'   => 'javaScript:app.filemanager.isSharableFile',
				'onExecute' => 'javaScript:app.filemanager.mail',
			);

			$changes['data']['nm']['actions']['convert_to'] = array(
				'caption'   => lang('Convert to'),
				'group'     => 2,
				'onExecute' => 'javaScript:app.filemanager.convert_to',
				'enabled'   => 'javaScript:app.filemanager.isEditable',
				'children'  => array(
					'pdf' => array('caption' => 'PDF'),
					'png' => array('caption' => 'PNG')
				)
			);
		}

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
		Framework::includeJS('/api/config.php');
		Framework::includeJS('/api/images.php');
		Framework::includeJS('/api/user.php');
		Framework::includeCSS('collabora', 'app');

		//Allow Collabora frame to pass the CSP check
		Api\Header\ContentSecurityPolicy::add('frame-src', array(Bo::get_server()));

		$template = new Etemplate('collabora.editor');

		$token = Bo::get_token($path);
		$token = Wopi::get_no_password_share($token);
		$resolved_path = $token['root'] && !Vfs::is_dir($token['root']) ? $token['root'] : $path;
		$content = array(
				'url'      => Bo::get_action_url($resolved_path, $token),
				'filename' => Vfs::basename($path),
			) + $token;

		// No permissions or Vfs could not resolve a file mounted to root
		/*if(!$content['url'] && $token['root'] == '/' && !is_dir($token['root']))
		{
			$parsed_path = Vfs::parse_url($token['path']);
			$parsed_url = Vfs::parse_url($token['resolve_url']);
			if($parsed_path['path'] == $parsed_url['path'])
			{
				$content['url'] = Bo::get_action_url($token['root']);
			}
		}

		// Check if editing a file in a shared directory
		if($token['root'] && Vfs::is_dir($token['root']))
		{
			$file_share = Wopi::open_from_share($token, $path);
			$content = Bo::get_token($path, $file_share) + array(
				'url'	=> Bo::get_action_url(str_replace(Vfs::PREFIX,'',$file_share['share_path']))
			) + $content;
		}*/

		// Revision list
		if(Bo::is_versioned($path))
		{
			$fileinfo = Vfs::getExtraInfo($path);
			foreach($fileinfo as $tab)
			{
				if($tab['label'] == 'Versions')
				{
					$content['revisions'] = $tab['data']['versions'];
					$content['revisions'][0] = $content;
					unset($content['revisions'][0]['revisions']);
				}
			}
		}

		// remove evtl. set autosave TS, to force a new version
		Vfs::proppatch($path, array(array('ns' => Vfs::DEFAULT_PROP_NAMESPACE, 'name' => Wopi\Files::PROP_AUTOSAVE_TS, 'val' => null)));

		$template->exec('collabora.'.__CLASS__.'.editor', $content, array(), array(), array(), 3);
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
		$template = file_get_contents(($GLOBALS['egw_info']['user']['apps']['stylite'] ?
			Api\Vfs::PREFIX.'/templates/collabora/' :
			EGW_SERVER_ROOT.'/collabora/assets/').'template_'.$ext.'.'.$ext);
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

	/**
	 * Create a sharable link that leads directly to the collabora editor
	 *
	 * return array/object with values for keys 'msg', 'errs', 'dirs', 'files'
	 *
	 * @param string $action eg. 'delete', ...
	 * @param string $selected selected path
	 * @param string $dir=null current directory
	 * @see static::action()
	 */
	public static function ajax_share_link($action, $selected)
	{
		$response = Api\Json\Response::get();

		$arr = array(
			'msg' => '',
			'action' => $action,
			'errs' => 0,
			'dirs' => 0,
			'files' => 0,
		);

		// Create a token for access
		// Use WOPI_SHARED to limit filesystem (Save As)
		$token = Bo::get_token($selected, Api\Vfs\Sharing::create('', $selected, Vfs::is_writable($selected) ? Wopi::WOPI_WRITABLE : Wopi::WOPI_READONLY, '', '', array(
				'share_writable' => Vfs::is_writable($selected) ? Wopi::WOPI_SHARED : Wopi::WOPI_READONLY,
		)));

		$arr['share_link'] = Wopi::share2link($token['token']);
		// Send the filename as title for mail
		$arr['title'] = $action == 'mail_collabora' ?
				($GLOBALS['egw_info']['user']['apps']['stylite'] ? lang('Edit %1 in Collabora',Api\Vfs::basename($selected)) : Api\Vfs::basename($selected))  :
			lang("Writable Collabora link");
		$arr['template'] = Api\Etemplate\Widget\Template::rel2url('/filemanager/templates/default/share_dialog.xet');

		$response->data($arr);
		//error_log(__METHOD__."('$action',".array2string($selected).') returning '.array2string($arr));
		return $arr;
	}
}