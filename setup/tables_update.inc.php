<?php
/**
 * EGroupware - Calendar setup
 *
 * @link http://www.egroupware.org
 * @package calendar
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

use EGroupware\Api;


function collabora_upgrade17_1()
{
	return $GLOBALS['setup_info']['collabora']['currentver'] = '19.1';
}

/**
 * Change credential type from 32 (now used by two factor authentication) to 64
 *
 * @return string
 */
function collabora_upgrade19_1()
{
	$table = 'egw_ea_credentials';
	$field = 'cred_type';

	$GLOBALS['egw_setup']->oProc->query(
			"UPDATE $table SET $field=". \EGroupware\Collabora\Credentials::COLLABORA .
			" WHERE $field='32' AND account_id = " . \EGroupware\Collabora\Credentials::CREDENTIAL_ACCOUNT
	);
	return $GLOBALS['setup_info']['collabora']['currentver'] = '19.1.001';
}

/**
 * Bump version to 20.1
 *
 * @return string
 */
function collabora_upgrade19_1_001()
{
	return $GLOBALS['setup_info']['collabora']['currentver'] = '20.1';
}

/**
 * Bump version to 21.1
 *
 * @return string
 */
function collabora_upgrade20_1()
{
	return $GLOBALS['setup_info']['collabora']['currentver'] = '21.1';
}

/**
 * Bump version to 23.1
 *
 * @return string
 */
function collabora_upgrade21_1()
{
	return $GLOBALS['setup_info']['collabora']['currentver'] = '23.1';
}