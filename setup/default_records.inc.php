<?php
/**
 * EGroupware - Collabora - setup
 *
 * @link http://www.egroupware.org
 * @package collabora
 */

// Set default Collabora server
$GLOBALS['egw_setup']->db->insert($GLOBALS['egw_setup']->config_table,array(
		'config_value' => EGroupware\Collabora\Admin::get_default_server(),
		'config_app'   => 'collabora',
	),array(
		'config_name'  => 'server',
	),__LINE__,__FILE__);

// Create anonymous user required for Collabora to access VFS
$GLOBALS['egw_setup']->add_account('NoGroup', 'No', 'Rights', false, false);
$anonymous = $GLOBALS['egw_setup']->add_account('anonymous', 'Anonymous', 'User', 'anonymous', 'NoGroup');
$GLOBALS['egw_setup']->add_acl('phpgwapi', 'anonymous', $anonymous);

// Give Default group access to Collabora app
$defaultgroup = $GLOBALS['egw_setup']->add_account('Default', 'Default', 'Group', False, False);
$GLOBALS['egw_setup']->add_acl('collabora', 'run', $defaultgroup, 1);
