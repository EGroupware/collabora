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

namespace EGroupware\Api\Vfs;

require_once __DIR__ . '/SharingBase.php';

use EGroupware\Api\Exception;
use EGroupware\Api\LoggedInTest as LoggedInTest;
use EGroupware\Api\Vfs;
use EGroupware\Collabora\Wopi;
use EGroupware\Collabora\Bo;


class EditTest extends SharingBase
{
	/**
	 * Test that a share link goes to the editor, and at least the etemplate is loaded.
	 * We can't really test Collabora here, but we can test our side.
	 */
	public function testEditorTemplateIsLoaded()
	{
		try
		{
			$discover = Bo::discover();
		}
		catch (Exception $e)
		{
			$discover = false;
		}
		if(!$discover)
		{
			$this->markTestSkipped("No Collabora server");
		}
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
		$editor_nodes = $this->getEditor($link, $data);
		$this->assertNotNull($editor_nodes, 'Could not load the editor');

		// Check for etemplate
		$this->assertEquals('collabora.editor', $data->name);

		// Check we got some kind of target in the URL
		$url = $data->data->content->url;
		$this->assertNotEmpty($url, "Target URL is missing.  Usually caused by file issues, check Bo::get_action_url()");
		$query = array();
		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		$this->assertNotEmpty($query['WOPISrc'], "WOPISrc is missing from url '$url'");
	}

	public function getEditor($link, &$data)
	{
		// Set up curl
		$curl = curl_init($link);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		// Setting this lets us debug the request too
		$cookie = 'XDEBUG_SESSION=PHPSTORM';
		curl_setopt($curl, CURLOPT_COOKIE, $cookie);
		$html = curl_exec($curl);
		curl_close($curl);

		if(!$html)
		{
			// No response - could mean something is terribly wrong, or it could
			// mean we're running on Travis with no webserver to answer the
			// request
			return;
		}

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
		$this->assertNotNull($form, "Didn't find editor");
		$data = json_decode($form->getAttribute('data-etemplate'));

		return $form;
	}
}
