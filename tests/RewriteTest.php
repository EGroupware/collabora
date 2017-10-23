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
require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

use EGroupware\Api\Egw;

class RewriteTest extends \EGroupware\Api\LoggedInTest {

	/**
	 * Try something invalid, make sure it fails
	 */
	public function testInvalidUrl()
	{
		$url = $this->fixLink(Egw::link('/collabora/index.php/wopi/files/totally_invalid'));
		$headers = get_headers($url, TRUE);
		if($headers === FALSE)
		{
			$this->markTestSkipped('No webserver');
		}
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
		$url = $this->fixLink(Egw::link('/collabora/index.php/wopi/files/2?access_token=' . urlencode($token['token'])));

		$headers = get_headers($url, TRUE);
		
		if($headers === FALSE)
		{
			$this->markTestSkipped('No webserver');
		}

		$this->assertEquals('200', substr($headers[0], 9, 3), "Testing home directory $url");
	}

	protected function fixLink($url)
	{
		if ($url{0} == '/') {
			$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
			$url = $protocol.($GLOBALS['egw_info']['server']['hostname'] ? $GLOBALS['egw_info']['server']['hostname'] : $_SERVER['HTTP_HOST']).$url;
		}
		return $url;
	}
}
