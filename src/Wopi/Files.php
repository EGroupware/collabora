<?php

/**
 * WOPI File access endpoint
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\collabora\Wopi;

use \EGroupware\Api\Accounts;
use \EGroupware\Api\Vfs;
use \EGroupware\Api\Vfs\Sqlfs\StreamWrapper as Sql_Stream;

/**
 * File enpoint
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 */
class Files {

	/**
	 * Process a request to the files endpoint
	 *
	 * @param int $id The ID of the file being accessed
	 *
	 * @return Array Map of information as response to the request
	 */
	public static function process($id)
	{
		switch($_GET['endpoint'])
		{
			case 'files':
			default:
				$data = static::checkFileInfo($id);

		}
		
		if($data == null)
		{
			http_response_code(404);
			exit;
		}

		// Additional, optional things we support
		$data['UserFriendlyName'] = Accounts::format_username();

		return $data;
	}

	/**
	 * Get file information
	 *
	 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/files/CheckFileInfo.html#checkfileinfo
	 *
	 * @param string $id
	 * @return Array|null
	 */
	protected static function checkFileInfo($id)
	{
		$path = 'false';
		
		// Required response from http://wopi.readthedocs.io/projects/wopirest/en/latest/files/CheckFileInfo.html#checkfileinfo
		$data = array(
			// The string name of the file, including extension, without a path. Used for display in user interface (UI), and determining the extension of the file.
			'BaseFileName'	=> '',

			// A string that uniquely identifies the owner of the file.
			'OwnerId'		=> '',
			
			// The size of the file in bytes, expressed as a long, a 64-bit signed integer.
			'Size'			=> '',

			// A string value uniquely identifying the user currently accessing the file.
			'UserId'		=> ''.$GLOBALS['egw_info']['user']['account_id'],

			// The current version of the file based on the server’s file version schema, as a string.
			'Version'		=> '1'	
		);

		if($id)
		{
			$path = Sql_Stream::id2path($id);
			$data['BaseFileName'] = basename($path);
		}
		if($path)
		{
			$stat = Vfs::stat($path);
		}
		if($stat)
		{
			$data['OwnerId'] = ''.$stat['uid'];
			$data['Size'] = ''.$stat['size'];
		}
		else
		{
			// Not found
			return null;
		}

		return $data;
	}
}
