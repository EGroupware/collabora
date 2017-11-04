<?php
/**
 * EGroupware - Collabora / WOPI endpoint
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

$GLOBALS['egw_info'] = array(
	'flags' => array(
		'disable_Template_class' => true,
		'noheader'  => true,
		'nonavbar' => 'always',	// true would cause eTemplate to reset it to false for non-popups!
		'currentapp' => 'filemanager',
		'autocreate_session_callback' => 'EGroupware\\collabora\\Wopi::create_session',
		'no_exception_handler' => 'basic_auth',	// we use a basic auth exception handler (sends exception message as basic auth realm)
	)
);

include_once '../header.inc.php';

require_once EGW_INCLUDE_ROOT.'/collabora/src/Wopi.php';
\EGroupware\collabora\Wopi::index();