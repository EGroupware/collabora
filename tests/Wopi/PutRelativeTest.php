<?php

/**
 * Tests for the WOPI API Files PutRelativeFile operation
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2018  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once __DIR__ . '/../WopiBase.php';

use \EGroupware\Api\Vfs;

/**
 * Tests for the WOPI API Files PutRelativeFile endpoint which is used for 'Save As'.
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 *
 * These tests use Sharing to access the Vfs as Collabora does
 *
 */
class PutRelativeTest extends WopiBase
{
	protected $file_contents = 'Test file - delete me if left over from testing';
	protected $original_filename = 'testfile.txt';
	protected $new_filename = 'newfile.txt';

	/**
	 * Default headers to be overridden as needed for the individual tests
	 */
	protected $header_map = array(
		'X-WOPI-Lock' => false,
		'X-WOPI-SuggestedTarget' => false,
		'X-WOPI-RelativeTarget' => false,
		'X-WOPI-OverwriteRelativeTarget' => false
	);

	protected function setUp() : void
	{
		parent::setUp();

		// Since we're checking response codes, it's a good idea to clear it first
		http_response_code(200);
	}
	/**
	 * Test save as - this one should work and copy the file to a new name
	 */
	public function testPutRelativeFileSpecific()
	{
		// Create test file
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;
		$this->files[] = $target = Vfs::get_home_dir() . '/'. $this->new_filename;
		file_put_contents(Vfs::PREFIX.$url, $this->file_contents);
		$this->assertTrue(Vfs::file_exists($url), "Test file $url is missing");

		// Set headers for this test
		$header_map = array_merge($this->header_map, array(
			'X-WOPI-RelativeTarget' => $target,
		));

		// Mock Files
		$files = $this->mock_files($header_map, $this->file_contents);

		$response = $files->put_relative_file($url);

		// Response code should be 200, which we set in setUp
		$this->assertEquals(200, http_response_code());
		$this->assertEquals($this->new_filename, $response['Name']);
		$this->assertNotEmpty(file_get_contents(Vfs::PREFIX.$target));
		$this->assertEquals(file_get_contents(Vfs::PREFIX.$url), file_get_contents(Vfs::PREFIX.$target));
	}

	/**
	 * Put the file someplace that already exists, should give 409 and not change
	 * anything
	 */
	public function testPutRelativeFileConflict()
	{
		// Create test files
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;
		$this->files[] = $target = Vfs::get_home_dir() . '/'. $this->new_filename;
		file_put_contents(Vfs::PREFIX.$url, $this->file_contents);
		file_put_contents(Vfs::PREFIX.$target, 'Do not overwrite me');
		$this->assertTrue(Vfs::file_exists($target));

		// Set headers for this test
		$header_map = array_merge($this->header_map, array(
			'X-WOPI-RelativeTarget' => $target
		));

		// Mock Files - no content, since it should conflict on name
		$files = $this->mock_files($header_map);

		$response = $files->put_relative_file($this->original_filename);

		// Response code should be 409 Conflict, since the target is already there
		$this->assertEquals(409, http_response_code());
		$this->assertNull($response);
		$this->assertNotEquals(file_get_contents(Vfs::PREFIX.$url), file_get_contents(Vfs::PREFIX.$target));
	}

	/**
	 * Put the file someplace, but let the system adjust the name since file
	 * is already there.  Should save to a different file name.
	 */
	public function testPutRelativeFileSuggested()
	{
		// Create test files
		$home_dir = Vfs::get_home_dir();
		$this->files[] = $url = $home_dir .'/' . $this->original_filename;
		$this->files[] = $target = $home_dir . '/'. $this->new_filename;
		file_put_contents(Vfs::PREFIX.$url, $this->file_contents);
		file_put_contents(Vfs::PREFIX.$target, $this->file_contents);
		$this->assertTrue(Vfs::file_exists($target));

		// Set headers for this test
		$header_map = array_merge($this->header_map, array(
			'X-WOPI-SuggestedTarget' => $target
		));

		// Mock Files
		$files = $this->mock_files($header_map, $this->file_contents);

		$response = $files->put_relative_file($this->original_filename);

		// Response code should be 200, which we set in setUp
		$this->assertEquals(200, http_response_code());
		// File is already there, should generate a new name
		$this->assertNotNull($response['Name']);
		$this->assertEquals($this->new_filename, $response['Name']);
		$this->assertEquals(file_get_contents(Vfs::PREFIX.$url), file_get_contents(Vfs::PREFIX.$target));

		$this->files[] = $home_dir.'/'.$response['Name'];
	}

	/**
	 * Test what happens if we try to save to an invalid place
	 */
	public function testPutRelativeInvalid()
	{
		// Create test files
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;
		// Invalid path
		$target = Vfs::get_home_dir() . '/../../'. $this->new_filename;

		// Set headers for this test
		$header_map = array_merge($this->header_map, array(
			'X-WOPI-RelativeTarget' => $target
		));

		// Mock Files - no content, should stop at path check
		$files = $this->mock_files($this->header_map);

		$response = $files->put_relative_file($this->original_filename);

		// Response code should be 404, target not found/valid
		$this->assertEquals(404, http_response_code());
		$this->assertNull($response);
		$this->assertFalse(Vfs::file_exists($target));
	}
}
