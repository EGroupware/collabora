<?php

/**
 * Manage any credentials needed for accessing shares
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package Collabora
 * @copyright (c) 2019  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

use EGroupware\Api;


class Credentials extends Api\Mail\Credentials {

	// Defined in parent as COLLABORA
	// const CREDENTIAL_TYPE = 64;

	const CREDENTIAL_ACCOUNT = -1;
	const CREDENTIAL_PREFIX = '';

	/**
	 * Write credentials needed to access the share.
	 *
	 * We use the share token to make sure this credential can be uniquely identified
	 * and store the user's password
	 *
	 * @param Array $share
	 * @return int Credential ID
	 */
	public static function write($share)
	{
		$cred_id = parent::write(
			$share['share_owner'],
			$share['share_token'],
			base64_decode(Api\Cache::getSession('phpgwapi', 'password')),
			parent::COLLABORA,
			self::CREDENTIAL_ACCOUNT
		);

		return $cred_id;
	}

	/**
	 * Read the credential ID for a given share
	 *
	 * @param Array $share
	 * @return int
	 */
	public static function read($share)
	{
		if(!$share['share_token'])
		{
			throw new \EGroupware\Api\Exception\WrongParameter("Missing share token");
		}
		static::$type2prefix[parent::COLLABORA] = self::CREDENTIAL_PREFIX;

		$cred_id = 0;
		$access = parent::read($share['share_owner'], parent::COLLABORA, self::CREDENTIAL_ACCOUNT);
		if($access[self::CREDENTIAL_PREFIX.'username'] == $share['share_token'])
		{
			// Existing credentials found
			$cred_id = $access[self::CREDENTIAL_PREFIX.'cred_id'];
		}

		return $cred_id;
	}

	/**
	 * Get the credential information from a given credential ID
	 *
	 * @param int $cred_id
	 * @return Array
	 */
	public static function read_credential($cred_id)
	{
		$rows = self::get_db()->select(self::TABLE, '*', array(
				'cred_id' => (int)$cred_id,
				'account_id' => self::CREDENTIAL_ACCOUNT,
				'(cred_type & '.(int)parent::COLLABORA.') > 0',	// postgreSQL require > 0, or gives error as it expects boolean
			), __LINE__, __FILE__, false,
			'ORDER BY account_id ASC', self::APP
		);

		$results = array();
		foreach($rows as $row)
		{
			$password = self::decrypt($row);
			$results['username'] = $row['cred_username'];
			$results['password'] = $password;
			$results['cred_id'] = $row['cred_id'];
			$results['account_id'] = $row['account_id'];
			$results['pw_enc'] = $row['cred_pw_enc'];
		}
		return $results;
	}

	/**
	 * Delete credentials from database
	 *
	 * @param Array $share
	 * @return int number of rows deleted
	 */
	public static function delete($share)
	{
		if(!$share['share_token'])
		{
			throw new \EGroupware\Api\Exception\WrongParameter("Missing share token");
		}
		$where = array(
			'cred_username' => $share['share_token'],
			'account_id' => self::CREDENTIAL_ACCOUNT,
		);

		self::get_db()->delete(self::TABLE, $where, __LINE__, __FILE__, self::APP);

		$ret = self::get_db()->affected_rows();
		//error_log(__METHOD__."($acc_id, ".array2string($account_id).", $type) affected $ret rows");
		return $ret;
	}
}
