<?php

/**
 * EGroupware - Test the Collabora editor, make sure we're giving what we expect
 *
 * Check that the content and permissions are as expected.
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @copyright (c) 2018  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once __DIR__ . '/WopiBase.php';

use EGroupware\Api\Exception;
use EGroupware\Api\LoggedInTest as LoggedInTest;
use EGroupware\Api\Vfs;
use EGroupware\Collabora\Wopi;
use EGroupware\Collabora\Bo;


class EditTest extends WopiBase
{
	/**
	 * What the editor link actually answered, for a failure message
	 */
	protected $editor_response = '(no response captured)';

	/**
	 * The body the editor link answered with
	 */
	protected $editor_body = '';

	/**
	 * Test that a share link goes to the editor, and at least the etemplate is loaded.
	 * We can't really test Collabora here, but we can test our side.
	 */
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingACLTest::class)]
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingHooksTest::class)]
	public function testEditorTemplateIsLoaded()
	{
		// Whether a share opens in the editor is decided by the link, not by whether a Collabora
		// backend is running: Wopi::share2link() appends "?edit" only for a user who has the
		// stylite (EPL) app, and get_share_class() routes to Wopi only for WOPI_SHARED, which
		// this share is not.  So on a public install a share of an editable file is served as
		// the file, and that is the right answer - this test requires whichever of the two the
		// install can actually produce.
		$expect_editor = !empty($GLOBALS['egw_info']['user']['apps']['stylite']);
		$dir = Vfs::get_home_dir().'/';

		// Plain text file
		$file = $dir.'test_file.txt';
		$content = 'Testing that sharing a single (non-editable) file gives us the editor.';
		$this->assertTrue(
			file_put_contents(Vfs::PREFIX.$file, $content) !== FALSE,
			'Unable to write test file "' . Vfs::PREFIX . $file .'" - check file permissions for CLI user'
		);
		$this->files[] = $file;

		$mimetype = Vfs::mime_content_type($file);

		// Create and use link
		$extra = array();
		$this->getShareExtra($file, Wopi::WOPI_READONLY, $extra);

		$share = $this->createShare($file, Wopi::WOPI_READONLY, $extra);
		$link = Wopi::share2link($share);

		// Log out & clear cache
		LoggedInTest::tearDownAfterClass();

		$data = array();
		$editor_nodes = $this->getEditor($link, $data, $expect_editor);

		if(!$expect_editor)
		{
			// No backend to hand it to, so the share must deliver the file itself - intact, and
			// not some error page that happens not to be the editor
			$this->assertNull($editor_nodes, "Got the editor on an install without EPL: " . $this->editor_response);
			$this->assertEquals($content, $this->editor_body,
				"Without EPL the share link does not ask to edit, so it must serve the file itself.\n" . $this->editor_response);
			return;
		}

		if(!$editor_nodes)
		{
			$this->fail("This install has EPL, so the share link asks to edit and had to open in the editor.\n" . $this->editor_response);
		}

		// Check for etemplate
		$this->assertEquals('collabora.editor', $data->name);

		// Check we got some kind of target in the URL
		$url = $data->data->content->url;
		$this->assertNotEmpty($url, "Target URL is missing.  Usually caused by file issues, check Bo::get_action_url()");
		$query = array();
		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		$this->assertNotEmpty($query['WOPISrc'], "WOPISrc is missing from url '$url'");
	}

	/**
	 * Fetch a share link and return the editor template's form, or null if it was not one
	 *
	 * @param string $link
	 * @param mixed $data etemplate data, on return
	 * @param bool $require_editor true: fail if the response is not the editor
	 * @return \DOMNode|null
	 */
	public function getEditor($link, &$data, $require_editor = true)
	{
		// Set up curl
		$curl = curl_init($link);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		// Setting this lets us debug the request too
		$cookie = 'XDEBUG_SESSION=PHPSTORM';
		curl_setopt($curl, CURLOPT_COOKIE, $cookie);

		// Keep the response: when the editor does not come back the body is what says why -
		// "Didn't find editor" on its own names the symptom and nothing else
		$response_headers = [];
		curl_setopt($curl, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$response_headers)
		{
			if(trim($header) !== '') $response_headers[] = trim($header);
			return strlen($header);
		});

		$html = curl_exec($curl);
		$http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$effective_url = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
		$curl_error = curl_error($curl);
		curl_close($curl);

		if(!$html)
		{
			// Nothing answered at all - no webserver here, which the caller reports as skipped,
			// with the reason rather than a guess at it
			if($http_code === 0)
			{
				$this->editor_response = "no response from '$link'" . ($curl_error ? " (curl: $curl_error)" : '');
				return;
			}
			$this->fail("Editor link '$link' returned no content (HTTP $http_code, '$effective_url')"
				. ($curl_error ? " curl: $curl_error" : ''));
		}
		$this->editor_response = "HTTP $http_code at '$effective_url'\nResponse headers:\n  "
			. implode("\n  ", $response_headers) . "\nFirst 500 bytes of the body:\n" . substr($html, 0, 500);
		$this->editor_body = $html;

		// Parse & check for nextmatch
		$dom = new \DOMDocument();
		@$dom->loadHTML($html);
		$xpath = new \DOMXPath($dom);
		$form = $xpath->query ('//form')->item(0);
		if(!$form && static::LOG_LEVEL)
		{
			echo "Didn't find editor\n";
			if(static::LOG_LEVEL > 1)
			{
				echo "Got this instead:\n".($form?$form:$html)."\n\n";
			}
		}
		if(!$form)
		{
			// The caller decides whether this is a failure: without a Collabora backend the
			// share serves the file, which has no form in it and is the right answer
			if($require_editor)
			{
				$this->fail("Didn't find editor - the share link did not return the editor template.\n" . $this->editor_response);
			}
			return null;
		}
		$data = json_decode($form->getAttribute('data-etemplate'));

		return $form;
	}
}
