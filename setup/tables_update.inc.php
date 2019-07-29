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
