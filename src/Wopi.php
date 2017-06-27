<?php

/**
 * App
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package 
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\collabora;

require_once(__DIR__.'/../../api/src/Vfs/Sharing.php');

use EGroupware\Api\Vfs\Sharing;


/**
 * Description of Wopi
 *
 */
class Wopi extends Sharing {

	const TOKEN_TTL = 86400; // One day
	
	public $public_functions = array(
		'index'	=> TRUE
	);
	
	/**
	 * Entry point for the WOPI API
	 *
	 * Here we check the required parameters, and pass off the the appropriate
	 * endpoint handler.
	 *
	 * @see https://wopirest.readthedocs.io/en/latest/index.html
	 */
	public static function index()
	{
		// Check access token, start session
		static::create_session(true);

		// Get ID
		$id = filter_var($_REQUEST['id'],FILTER_SANITIZE_NUMBER_INT);

		// Determine the endpoint
		$endpoint_class = __NAMESPACE__ . '\Wopi\\'. filter_var(
				ucfirst($_REQUEST['endpoint']),
				FILTER_SANITIZE_SPECIAL_CHARS,
				FILTER_FLAG_STRIP_LOW + FILTER_FLAG_STRIP_HIGH
		);
		$data = array();
		if($endpoint_class && class_exists($endpoint_class))
		{
			$data = $endpoint_class::process($id);
		}
		else
		{
			// Unknown endpoint - not found
			http_response_code(404);
			exit;
		}

		if(!headers_sent() && $data)
		{
			header('X-WOPI-ServerVersion: ' . $GLOBALS['egw_info']['apps']['collabora']['version']);
			header('X-WOPI-MachineName: ' . 'Egroupware');
			header('Content-Type: application/json;charset=utf-8');
			echo json_encode($data);
		}
		exit;
	}

	/**
	 * Get token from url
	 */
	public static function get_token()
	{
		$token = filter_var($_GET['access_token'],FILTER_SANITIZE_SPECIAL_CHARS);
		return $token;
	}
}
