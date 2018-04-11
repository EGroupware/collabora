<?php

/**
 * Tests for the WOPI API Files PutRelativeFile operation
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once __DIR__ . '/SharingBase.php';

use \EGroupware\Api\Vfs;

/**
 * Tests for the WOPI API Files PutRelativeFile endpoint which is used for 'Save As'.
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 *
 * These tests use Sharing to access the Vfs as Collabora does
 *
 */
class PutRelativeTest extends SharingBase
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

	public function setUp()
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
		// Set headers for this test
		$header_map = array_merge($this->header_map, array(
			'X-WOPI-RelativeTarget' => Vfs::get_home_dir() . '/'. $this->new_filename,
		));

		// Set up mock for header()
		$files = $this->getMockBuilder(Wopi\Files::class)
				->setMethods(array('header','get_sent_content'))
				->getMock();
		$files->method('header')
				// Headers that will trigger specific mode - no changes allowed
				->will($this->returnCallback(
						function($header) use ($header_map)
						{
							return $header_map[$header];
						}
				));

		// Mock the file contents, since nobody sent them
		$files->expects($this->once())
				->method('get_sent_content')
				->will($this->returnValue($this->file_contents));

		// Create test file
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;
		$this->files[] = $target = Vfs::get_home_dir() . '/'. $this->new_filename;
		file_put_contents(Vfs::PREFIX.$url, $this->file_contents);
		$this->assertTrue(Vfs::file_exists($url), "Test file $url is missing");

		// Create and use link
		$extra = array();
		$mode = Wopi::WOPI_WRITABLE;
		$this->getShareExtra($url, $mode, $extra);
		$this->shareLink($url, $mode, $extra);

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
		// Set headers for this test
		$header_map = array_merge($this->header_map, array(
			'X-WOPI-RelativeTarget' => Vfs::get_home_dir() . '/'. $this->new_filename
		));

		// Set up mock for header()
		$files = $this->getMockBuilder(Wopi\Files::class)
				->setMethods(array('header','get_sent_content'))
				->getMock();
		$files->method('header')
				// Headers that will trigger specific mode - no changes allowed
				->will($this->returnCallback(
						function($header) use ($header_map)
						{
							return $header_map[$header];
						}
				));

		// Should not look at content, since it will conflict on name
		$files->expects($this->never())
				->method('get_sent_content');

		// Create test files
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;
		$this->files[] = $target = Vfs::get_home_dir() . '/'. $this->new_filename;
		file_put_contents(Vfs::PREFIX.$url, $this->file_contents);
		file_put_contents(Vfs::PREFIX.$target, 'Do not overwrite me');
		$this->assertTrue(Vfs::file_exists($target));

		// Create and use link
		$extra = array();
		$mode = Wopi::WOPI_WRITABLE;
		$this->getShareExtra($url, $mode, $extra);
		$this->shareLink($url, $mode, $extra);

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
		// Set headers for this test
		$header_map = array_merge($this->header_map, array(
			'X-WOPI-SuggestedTarget' => Vfs::get_home_dir() . '/'. $this->new_filename
		));
		// Set up mock for header()
		$files = $this->getMockBuilder(Wopi\Files::class)
				->setMethods(array('header','get_sent_content'))
				->getMock();
		$files->method('header')
				// Headers that will trigger suggested mode
				->will($this->returnCallback(
						function($header) use ($header_map)
						{
							return $header_map[$header];
						}
				));
		// Mock the file contents, since nobody sent them
		$files->expects($this->once())
				->method('get_sent_content')
				->will($this->returnValue($this->file_contents));

		// Create test files
		$this->files[] = $url = Vfs::get_home_dir() .'/' . $this->original_filename;
		$this->files[] = $target = Vfs::get_home_dir() . '/'. $this->new_filename;
		file_put_contents(Vfs::PREFIX.$url, $this->file_contents);
		file_put_contents(Vfs::PREFIX.$target, $this->file_contents);
		$this->assertTrue(Vfs::file_exists($target));

		// Create and use link
		$extra = array();
		$mode = Wopi::WOPI_WRITABLE;
		$this->getShareExtra($url, $mode, $extra);
		$this->shareLink($url, $mode, $extra);

		$response = $files->put_relative_file($this->original_filename);

		// Response code should be 200, which we set in setUp
		$this->assertEquals(200, http_response_code());
		// File is already there, should generate a new name
		$this->assertNotNull($response['Name']);
		$this->assertNotEquals($this->new_filename, $response['Name']);
		$this->assertEquals(file_get_contents(Vfs::PREFIX.$url), file_get_contents(Vfs::PREFIX.$target));

		$this->files[] = Vfs::get_home_dir().'/'.$response['Name'];
	}

}
