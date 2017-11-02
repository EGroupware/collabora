<?php
/**
 * EGroupware - Collabora - setup
 *
 * @link http://www.egroupware.org
 * @package collabora
 */

// configure default server
EGroupware\Api\Config::save_value('server', 'https://collabora.egroupware.org', 'collabora');

// Create anonymous user required for Collabora to access VFS
// do NOT set a new password for an existing anon user (random password for new anonymous user)
$GLOBALS['egw_setup']->add_account('NoGroup', 'No', 'Rights', false, false);
$anonymous = $GLOBALS['egw_setup']->add_account('anonymous', 'Anonymous', 'User', '*unchanged*', 'NoGroup');
$GLOBALS['egw_setup']->add_acl('phpgwapi', 'anonymous', $anonymous);

// Give Default group access to Collabora app
$defaultgroup = $GLOBALS['egw_setup']->add_account('Default', 'Default', 'Group', False, False);
$GLOBALS['egw_setup']->add_acl('collabora', 'run', $defaultgroup, 1);
