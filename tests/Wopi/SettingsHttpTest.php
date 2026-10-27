<?php

/**
 * Tests of the settings endpoint over HTTP, the way Collabora's settings page uses it
 *
 * The other settings tests call Settings directly.  This goes through the webserver with a WOPI token, as
 * Collabora does, so it also covers the routing of /wopi/settings, the token, the content of an upload and the
 * link Collabora is given to download a file again.
 *
 * @link http://www.egroupware.org
 * @package collabora
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora\Wopi;

// test base providing the Egw environment, since we need the VFS and the DB
require_once realpath(__DIR__ . '/../../../api/tests/LoggedInTest.php');

use EGroupware\Api;
use EGroupware\Api\Egw;
use EGroupware\Api\LoggedInTest;
use EGroupware\Api\Vfs;
use EGroupware\Collabora\Wopi;

class SettingsHttpTest extends LoggedInTest
{
	/**
	 * The demo user's settings directory
	 */
	protected string $dir;

	/**
	 * We made the directory, so it goes again
	 */
	protected bool $created_dir = false;

	protected string $token = '';

	/**
	 * A request of this class timed out, the server is most likely still blocked: do not wait for it again
	 */
	protected static bool $server_stuck = false;

	protected function setUp() : void
	{
		parent::setUp();

		$this->dir = (new Settings())->getSettingsPath('userconfig');
		if(!Vfs::is_dir($this->dir))
		{
			Vfs::mkdir($this->dir, null, STREAM_MKDIR_RECURSIVE);
			$this->created_dir = true;
		}
		// as Admin::config() and the preferences get it
		$share = Wopi::create('', $this->dir, Wopi::WOPI_WRITABLE, 'settings', '', ['share_writable' => true]);
		$this->token = $share['share_token'] ?? '';
		$this->assertNotEmpty($this->token, 'Could not create a token for ' . $this->dir);
	}

	protected function tearDown() : void
	{
		if($this->created_dir)
		{
			Vfs::remove($this->dir);
		}
		else
		{
			foreach(Vfs::find($this->dir, ['type' => 'f', 'name' => 'zz-collabora-test*']) as $file)
			{
				Vfs::unlink($file);
			}
		}
		parent::tearDown();
	}

	/**
	 * Send a request to the endpoint
	 *
	 * @param string $method GET, POST or DELETE
	 * @param array $query
	 * @param string|null $body
	 * @return array [status, body, server error header]
	 */
	protected function request(string $method, array $query, ?string $body = null, bool $session = true) : array
	{
		$url = Api\Framework::getUrl(Egw::link('/collabora/index.php/wopi/settings')) . '?' . http_build_query($query);
		if(self::$server_stuck)
		{
			$this->fail("$method " . preg_replace('/access_token=[^&]*/', 'access_token=...', $url) . " not sent: an earlier request of this class timed out and the server is most likely still blocked." . self::stuckHint());
		}
		$headers = [];
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => 30,	// a request takes well under a second, a longer one is blocked
			CURLOPT_HEADERFUNCTION => function($curl, $line) use (&$headers)
			{
				$headers[] = trim($line);
				return strlen($line);
			},
		]);
		if($session)
		{
			// The endpoint opens OUR session, and PHP locks a session file while it is open here: hand it over,
			// as RewriteTest does
			curl_setopt($curl, CURLOPT_COOKIE, 'XDEBUG_SESSION=PHPSTORM;' . Api\Session::EGW_SESSION_NAME . '=' . $GLOBALS['egw']->session->sessionid);
			$GLOBALS['egw']->session->commit_session();
		}
		if($body !== null)
		{
			// the content of a file, not a form: with a form content type PHP takes the body for form fields
			curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream']);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}
		$content = curl_exec($curl);
		if($content === false)
		{
			$error = curl_error($curl);
			// no webserver at all is not our doing, anything else (a timeout!) is a failure, not a reason to skip
			if(curl_errno($curl) === CURLE_COULDNT_CONNECT)
			{
				$this->markTestSkipped('No webserver: ' . $error);
			}
			$errno = curl_errno($curl);
			if($errno === CURLE_OPERATION_TIMEOUTED)
			{
				self::$server_stuck = true;
			}
			$this->fail("$method " . preg_replace('/access_token=[^&]*/', 'access_token=...', $url) . " got no answer: $error (curl error $errno)" . ($errno === CURLE_OPERATION_TIMEOUTED ? self::stuckHint() : ''));
		}
		$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);

		$error = '';
		foreach($headers as $header)
		{
			if(stripos($header, 'X-WOPI-ServerError:') === 0)
			{
				$error = trim(substr($header, strlen('X-WOPI-ServerError:')));
			}
		}
		return [$status, $content, $error];
	}

	/**
	 * What to tell about a request that never gets answered
	 *
	 * PHP's built-in webserver (php -S) handles one request at a time.  A push backend that sends its messages over
	 * HTTP to this same server (SwoolePush does, to <instance>/push) then waits for an answer the server can only
	 * give once the request that waits is finished, until PHP's default_socket_timeout of 60 s is over.  Every
	 * change in the VFS and every login made by a request of the server pushes, so these tests are affected.
	 */
	protected static function stuckHint() : string
	{
		$backends = array_filter(Api\Hooks::process('push-backends', [], true));
		return !$backends ? '' : "\nA push backend is installed (" . implode(', ', $backends) . "): if the webserver is PHP's built-in one, " .
			'it can not answer the push backend while it handles this request. Start it with PHP_CLI_SERVER_WORKERS=4 ' .
			'(php -S needs more than one worker), or without the push backend.';
	}

	protected function setting(string $id) : array
	{
		return ['access_token' => $this->token, 'fileId' => $id];
	}

	/**
	 * What Collabora asks for when a document opens
	 */
	public function testFetchAnswersWithTheSettingsOfTheUser()
	{
		[$status, $body] = $this->request('GET', ['access_token' => $this->token, 'type' => 'userconfig', 'fileId' => -1]);

		$this->assertEquals(200, $status, $body);
		$settings = json_decode($body, true);
		$this->assertIsArray($settings, "Not JSON: $body");
		$this->assertEquals('user', $settings['kind']);
		foreach(['autotext', 'xcu', 'browsersetting'] as $type)
		{
			$this->assertArrayHasKey($type, $settings, 'Collabora expects a list for every type, even without files');
			$this->assertIsArray($settings[$type]);
		}
	}

	/**
	 * Without a session, only a valid token gets anything
	 *
	 * (A logged in session is a credential too, so this one is sent without ours.)
	 */
	public function testWithoutAValidTokenThereAreNoSettings()
	{
		foreach(['userconfig', 'systemconfig'] as $type)
		{
			$query = ['type' => $type, 'fileId' => -1];
			[$status] = $this->request('GET', $query, null, false);
			$this->assertContains($status, [401, 403], "$type was given without a token");

			[$status] = $this->request('GET', $query + ['access_token' => 'not-a-valid-token'], null, false);
			$this->assertContains($status, [401, 403], "$type was given for a token that does not exist");
		}

		[$status] = $this->request('POST', ['fileId' => '/settings/userconfig/wordbook/zz-collabora-test.dic'], 'x', false);
		$this->assertContains($status, [401, 403], 'An upload was accepted without a token');
		$this->assertFalse(Vfs::file_exists($this->dir . '/wordbook/zz-collabora-test.dic'));
	}

	/**
	 * What Collabora's settings page does with a dictionary: put it there, find it in the list, download it
	 * through the link it is given, delete it
	 */
	public function testUploadListDownloadAndDelete()
	{
		$content = "OOoUserDict1\nlang: <none>\ntype: positive\n---\nZzcollaboratest\n";
		$id = '/settings/userconfig/wordbook/zz-collabora-test.dic';

		[$status, $body, $error] = $this->request('POST', $this->setting($id), $content);
		$this->assertEquals(200, $status, "Upload failed: $body $error");
		$this->assertEquals('success', json_decode($body, true)['status'] ?? null, $body);
		$this->assertEquals($content, file_get_contents(Vfs::PREFIX . $this->dir . '/wordbook/zz-collabora-test.dic'));

		[$status, $body] = $this->request('GET', ['access_token' => $this->token, 'type' => 'userconfig', 'fileId' => -1]);
		$this->assertEquals(200, $status, $body);
		$wordbooks = json_decode($body, true)['wordbook'] ?? [];
		$this->assertCount(1, $wordbooks, "The uploaded dictionary is not in the list: $body");
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $wordbooks[0]['stamp']);

		// Collabora's server downloads it from that link, without our session
		$curl = curl_init($wordbooks[0]['uri']);
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
		$downloaded = curl_exec($curl);
		$download_status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);
		$this->assertEquals(200, $download_status, 'Collabora can not download the file from the link in the list');
		$this->assertEquals($content, $downloaded);

		[$status, $body, $error] = $this->request('DELETE', $this->setting($id));
		$this->assertEquals(200, $status, "Delete failed: $body $error");
		$this->assertEquals('success', json_decode($body, true)['status'] ?? null, $body);
		$this->assertFalse(Vfs::file_exists($this->dir . '/wordbook/zz-collabora-test.dic'));

		[, $body] = $this->request('GET', ['access_token' => $this->token, 'type' => 'userconfig', 'fileId' => -1]);
		$this->assertEmpty(json_decode($body, true)['wordbook'] ?? [], "The deleted dictionary is still in the list: $body");
	}

	/**
	 * Saving again replaces the file, as the settings page does for the interface settings
	 */
	public function testUploadReplacesAnExistingFile()
	{
		$id = '/settings/userconfig/browsersetting/zz-collabora-test.json';
		$this->assertEquals(200, $this->request('POST', $this->setting($id), '{"darkTheme":true}')[0]);
		$this->assertEquals(200, $this->request('POST', $this->setting($id), '{"darkTheme":false}')[0]);

		$this->assertEquals('{"darkTheme":false}', file_get_contents(Vfs::PREFIX . $this->dir . '/browsersetting/zz-collabora-test.json'));
	}

	/**
	 * Ids that lead anywhere but into a known directory are refused with a 400 and write nothing
	 */
	public function testBadIdsAreRefused()
	{
		foreach(['/settings/userconfig/../zz-collabora-test.dic', '/settings/userconfig/other/zz-collabora-test.dic',
					'/settings/other/wordbook/zz-collabora-test.dic', '/settings/userconfig/wordbook/..'] as $id)
		{
			[$status, , $error] = $this->request('POST', $this->setting($id), 'x');
			$this->assertEquals(400, $status, "$id was not refused");
			$this->assertNotEmpty($error);
		}
		$this->assertEquals([], Vfs::find($this->dir, ['type' => 'f', 'name' => 'zz-collabora-test*']));
		$this->assertFalse(Vfs::file_exists(dirname($this->dir) . '/zz-collabora-test.dic'));
	}
}
