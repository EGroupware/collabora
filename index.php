<?php

$GLOBALS['egw_info'] = array(
	'flags' => array(
		'disable_Template_class' => true,
		'noheader'  => true,
		'nonavbar' => 'always',	// true would cause eTemplate to reset it to false for non-popups!
		'currentapp' => 'filemanager',
		//'autocreate_session_callback' => 'EGroupware\\Api\\Vfs\\Sharing::create_session',
		'autocreate_session_callback' => 'EGroupware\\collabora\\Wopi::create_session',
		'no_exception_handler' => 'basic_auth',	// we use a basic auth exception handler (sends exception message as basic auth realm)
	)
);

error_log('HEY');
include_once '../header.inc.php';


require_once EGW_INCLUDE_ROOT.'/collabora/src/Wopi.php';
\EGroupware\collabora\Wopi::index();