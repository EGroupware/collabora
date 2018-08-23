<?php

/**
 * Tests for the WOPI API Files PutFile operation
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2018  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once __DIR__ . '/../SharingBase.php';

use \EGroupware\Api\Vfs;

/**
 * Tests for the WOPI API Files PutFile endpoint which is used for Save.
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 *
 * These tests use Sharing to access the Vfs as Collabora does
 *
 */
class PutTest extends SharingBase
{
	protected $file_contents = 'Test file - delete me if left over from testing';
	protected $original_filename = 'testfile.txt';
	protected $new_filename = 'newfile.txt';

	/**
	 * Default headers to be overridden as needed for the individual tests
	 */
	protected $header_map = array(
		'X-WOPI-Lock' => false
	);

	public function setUp()
	{
		parent::setUp();

		// Since we're checking response codes, it's a good idea to clear it first
		http_response_code(200);
	}
	/**
	 * Test save as - this one should work and copy the file to a new name
	 */
	public function testPutFile()
	{
		// Mock Files
		$files = $this->mock_files($this->header_map, $this->file_contents);

		// Create test file
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;

		$this->assertFalse(Vfs::file_exists($url));

		// Create and use link
		$extra = array();
		$mode = Wopi::WOPI_WRITABLE;
		$this->getShareExtra($url, $mode, $extra);
		$this->shareLink($url, $mode, $extra);

		$response = $files->put($url);

		// Get modified time
		$stat = Vfs::stat($url);
		$mtime = new \EGroupware\Api\DateTime($stat['mtime']);
		$mtime->setTimezone(new \DateTimeZone('UTC'));
		$mtime = $mtime->format(Wopi\Files::DATE_FORMAT);

		// Response code should be 200, which we set in setUp
		$this->assertEquals(200, http_response_code());
		// Response has modified time
		$this->assertEquals($mtime, $response['LastModifiedTime']);
		$this->assertTrue(Vfs::file_exists($url), "Test file $url is missing");
		$this->assertNotEmpty(file_get_contents(Vfs::PREFIX.$url));
	}

	/**
	 * Put the file someplace that already exists, should happily overwrite
	 */
	public function testPutAlreadyExisting()
	{
		// Mock Files
		$files = $this->mock_files($this->header_map, $this->file_contents);

		// Create test files
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;
		file_put_contents(Vfs::PREFIX.$url, 'Overwrite me');
		$this->assertTrue(Vfs::file_exists($url));

		// Create and use link
		$extra = array();
		$mode = Wopi::WOPI_WRITABLE;
		$this->getShareExtra($url, $mode, $extra);
		$this->shareLink($url, $mode, $extra);

		$response = $files->put($url);

		// Get modified time
		$stat = Vfs::stat($url);
		$mtime = new \EGroupware\Api\DateTime($stat['mtime']);
		$mtime->setTimezone(new \DateTimeZone('UTC'));
		$mtime = $mtime->format(Wopi\Files::DATE_FORMAT);

		// Response code should be 200, happily overwriting
		$this->assertEquals(200, http_response_code());
		// Response has modified time
		$this->assertEquals($mtime, $response['LastModifiedTime']);
		$this->assertEquals($this->file_contents, file_get_contents(Vfs::PREFIX.$url));
	}

	/**
	 * Test that writing to a readonly share fails
	 */
	public function testPutReadonly()
	{
		// Mock Files - no content, it will fail before that
		$files = $this->mock_files($this->header_map);

		// Create test files
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;
		$this->assertFalse(Vfs::file_exists($url));

		// Create and use link
		$extra = array();
		$mode = Wopi::WOPI_READONLY;
		$this->getShareExtra($url, $mode, $extra);
		$this->shareLink($url, $mode, $extra);

		$response = $files->put($url);

		// Response code should be 404 - Resource not found/user unauthorized
		$this->assertEquals(404, http_response_code());
		$this->assertNull($response);
		$this->assertFalse(Vfs::file_exists($url));
	}
}
