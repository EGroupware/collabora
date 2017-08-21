<?php

/**
 * Test for being able to access Egroupware files in a way that collabora
 * likes.
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 */

namespace EGroupware\collabora;

// test base providing Egw environment, since we need the DB
require_once realpath(__DIR__.'/../../../api/src/test/LoggedInTest.php');

use EGroupware\Api;
use EGroupware\Api\Vfs\Sharing;


class RewriteTest extends \EGroupware\Api\LoggedInTest {

	protected static $wopi_endpoint;

	public function setUp()
	{
		static::$wopi_endpoint = Api\Egw::link( Bo::WOPI_ENDPOINT );
		if(strpos(static::$wopi_endpoint, $GLOBALS['egw_info']['server']['hostname']) === false)
		{
			static::$wopi_endpoint = 'http://' . $GLOBALS['egw_info']['server']['hostname'] . static::$wopi_endpoint;
		}
	}

	/**
	 * Try something invalid, make sure it fails
	 */
	public function testInvalidUrl()
	{
		$url = static::$wopi_endpoint . 'files/totally_invalid';
		$headers = get_headers($url, TRUE);
		$this->assertEquals('404', substr($headers[0], 9, 3), "Testing invalid URL $url");
	}

	/**
	 * Try home - just testing the endpoint, which should work for our default / test
	 * user
	 */
	public function testHomeUrl()
	{

		$token = Bo::get_token('/home');

		// home dir gets ID 2 normally
		$url = static::$wopi_endpoint . 'files/2?access_token=' . urlencode($token['token']);

		$headers = get_headers($url, TRUE);
		$this->assertEquals('200', substr($headers[0], 9, 3), "Testing home directory $url");
	}
}
