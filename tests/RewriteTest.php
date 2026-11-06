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
		// The WOPI endpoint opens OUR session - Wopi::create_session() verifies the sessionid
		// carried in the share.  PHP locks a session file exclusively, so while this process
		// still holds it open the webserver blocks in session_start() until the request times
		// out and get_headers() returns false - which read as "No webserver" below.  Hand the
		// session over before asking the webserver to use it.
		$GLOBALS['egw']->session->commit_session();

		$headers = get_headers($url, 1, $context);

		if($headers === FALSE)
		{
			$this->markTestSkipped('No webserver');
		}

		$status = substr($headers[0], 9, 3);

		// A 401 here is not this endpoint's doing: it is Wopi::create_session() failing to
		// verify our session, and the exception handler turning that into a basic-auth
		// challenge.  The usual cause is PHPUnit running as root, which writes the session
		// file 0600 root - unreadable to the www-data the webserver runs as.
		$this->assertNotEquals('401', $status,
			"The webserver could not verify this test's session (run PHPUnit as www-data, not root): $url");

		// /home is a directory, which is invalid - files only
		$this->assertEquals('404', $status, "Testing home directory $url");
	}

	protected function fixLink($url)
	{
		return Api\Framework::getUrl($url);
	}
}
