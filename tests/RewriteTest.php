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

use EGroupware\Api;
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
		
		// Exception handler catches the 404 and gives us a 401
		$this->assertEquals('401', substr($headers[0], 9, 3), "Testing invalid URL $url");
	}

	/**
	 * Try home - just testing the endpoint, which should work for our default / test
	 * user
	 */
	public function testHomeUrl()
	{
		$path = '/home';

		$share = Wopi::create('', $path,
							  Wopi::READONLY,
							  '', '', array(
								  'share_expires'  => time() + Wopi::TOKEN_TTL,
								  'share_writable' => Api\Vfs::is_writable($path) ? Wopi::WOPI_WRITABLE : Wopi::WOPI_READONLY
							  )
		);
		$token = Bo::get_token($path, $share);

		// home dir gets ID 2 normally
		$url = $this->fixLink(Egw::link('/collabora/index.php/wopi/files/2?access_token=' . urlencode($token['token'])));

		// Need to include our session ID
		$context = stream_context_create(
		    array(
		        'http' => array(
		            'method' => 'GET',
				        'header' => "Cookie: XDEBUG_SESSION=PHPSTORM;".Api\Session::EGW_SESSION_NAME.'=' . $GLOBALS['egw']->session->sessionid
		        )
		    )
		);
		$headers = get_headers($url, 1, $context);

		if($headers === FALSE)
		{
			$this->markTestSkipped('No webserver');
		}

		// /home is a directory, which is invalid - files only
		$this->assertEquals('404', substr($headers[0], 9, 3), "Testing home directory $url");
	}

	protected function fixLink($url)
	{
		return Api\Framework::getUrl($url);
	}
}
