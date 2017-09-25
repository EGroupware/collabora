<?php

/**
 * Collabora integration user interface, or at least as much as Egroupware is
 * responsible for
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 */

namespace EGroupware\collabora;

use EGroupware\Api\Framework;
use EGroupware\Api\Json\Response;
use EGroupware\Api\Etemplate;

/**
 * User interface for collabora integration
 */
class Ui {

	public $public_functions = array(
		'editor' => TRUE
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
		catch (Exception $e)
		{
			$discovery = array();
			Response::get()->message(lang('unable to contact collabora server') . "\n" . $e->message, 'error');
		}
		if(!$discovery) return;

		// Send what the server said to the client, so we can use it there
		Response::get()->call('app.filemanager.set_discovery', $discovery);

		$changes['data']['is_collabora'] = true;

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
			'url'	=> Bo::get_action_url($path)
		) + Bo::get_token($path);

		$template->exec('collabora.'.__CLASS__.'.editor', $content, array(), array(), array(), 3);
	}
}
