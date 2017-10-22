<?php
/**
 * EGroupware - Collabora - setup
 *
 * @link http://www.egroupware.org
 * @package collabora
 */

$setup_info['collabora']['name']    = 'collabora';
$setup_info['collabora']['title']   = 'Collabora';
$setup_info['collabora']['version'] = '17.1';
$setup_info['collabora']['app_order'] = 1;
$setup_info['collabora']['enable']  = 2;
$setup_info['collabora']['autoinstall'] = true;	// install automatically on update

$setup_info['collabora']['author'] = 'Nathan Gray';
$setup_info['collabora']['maintainer'] = array(
	'name'  => 'EGroupware GmbH',
	'url'   => 'http://www.egroupware.org',
);
$setup_info['collabora']['license']  = 'GPL';
$setup_info['collabora']['description'] = 'Online document editing with Collabora Libre Office Online';

/* The hooks this app includes, needed for hooks registration */
$setup_info['collabora']['hooks']['settings'] = 'EGroupware\Collabora\Preferences::settings';
$setup_info['collabora']['hooks']['admin'] = 'EGroupware\Collabora\Admin::admin_sidebox';
$setup_info['collabora']['hooks']['config'] = 'EGroupware\Collabora\Admin::config';
$setup_info['collabora']['hooks']['config_validate'] = 'EGroupware\Collabora\Admin::validate';
$setup_info['collabora']['hooks']['csp-frame-src'] = 'EGroupware\Collabora\Hooks::csp_frame_src';
$setup_info['collabora']['hooks']['filemanager-editor-link'] = 'EGroupware\Collabora\Hooks::getEditorLink';
/* Tie into filemanager */
$setup_info['collabora']['hooks']['etemplate2_before_exec'] = 'EGroupware\Collabora\Ui::index';


/* Dependencies for this app to work */
$setup_info['collabora']['depends'][] = array(
	'appname' => 'filemanager',
	'versions' => array('17.1')
);
$setup_info['collabora']['depends'][] = array(
	'appname' => 'api',
	'versions' => array('17.1')
);