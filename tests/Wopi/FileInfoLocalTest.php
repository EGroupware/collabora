<?php

/**
 * Tests for the WOPI API Files endpoint
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once __DIR__ . '/../SharingBase.php';

use \EGroupware\Api\Vfs;

/**
 * Tests for the WOPI API Files FileInfo endpoint
 *
 * This group of tests operates locally and under the original login to check
 * that the VFS gives FileInfo that matches expectations stored in tests/fixtures/info.
 * This isn't how Collabora accesses it (via webserver), but it tests the API to
 * make sure it's giving what's expected.
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 */
class FileInfoLocalTest extends SharingBase
{
	// Use consistant content since we're checking file size
	const CONTENT = 'Test file for Collabora File tests';

	/**
	 * Check the file info against saved results
	 */
	public function testCheckFileOnSqlfs()
	{
		$this->files = $this->addFiles(Vfs::get_home_dir(), static::CONTENT);
		foreach($this->files as $file)
		{
			// Check fhe FileInfo
			$this->checkFileInfo($file);
		}
	}

	public function testCheckFileInfoOnVersioning()
	{
		$this->files[] = $dir = Vfs::get_home_dir().'/versioned/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->mountVersioned($dir);

		$this->files = $this->addFiles($dir, static::CONTENT);
		foreach($this->files as $file)
		{
			$this->checkFileInfo($file);
		}
	}

	public function testCheckFileInfoOnFilesystem()
	{
		$this->files[] = $dir = Vfs::get_home_dir().'/filesystem/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->mountFilesystem($dir);

		$this->files = $this->addFiles($dir, static::CONTENT);
		foreach($this->files as $file)
		{
			$this->checkFileInfo($file);
		}
	}

	public function testCheckFileInfoOnLink()
	{
		// Create an infolog entry for testing purposes
		$info_id = $this->make_infolog();
		$bo = new \infolog_bo();
		$dir = "/apps/infolog/$info_id/";

		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");

		$this->files = $this->addFiles($dir, static::CONTENT);
		foreach($this->files as $file)
		{
			$this->checkFileInfo($file);
		}
	}

	public function testCheckFileInfoOnMerge()
	{
		if(!class_exists("\EGroupware\Stylite\Vfs\Merge\StreamWrapper"))
		{
			$this->markTestSkipped();
			return;
		}
		$this->files[] = $dir = Vfs::get_home_dir().'/merged/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->mountMerge($dir);

		$this->files = $this->addFiles($dir, static::CONTENT);
		foreach($this->files as $file)
		{
			$this->checkFileInfo($file);
		}
	}

	/**
	 * Check the file info for the given path against what is saved in the
	 * fixture file
	 *
	 * @param string $_file Path of the file
	 */
	protected function checkFileInfo($_file)
	{
		// Ignore directories
		if(Vfs::is_dir($_file) || !$_file) return;

		$files = new Wopi\Files();
		$info = $this->clean_info($files->check_file_info($_file));

		$file = basename($_file);
		$stored = file_get_contents($this->get_info_fixture($file));
		if(!$stored)
		{
			trigger_error("Missing fixture for $file created.", E_USER_NOTICE);
			file_put_contents($this->get_info_fixture($file), $info);
		}
		$this->assertEquals($stored, $info, "Did you update the text fixture? (".$this->get_info_fixture($file).')');
	}

	/**
	 * Get the fixture file for the given path
	 * @param string $file
	 * @return string
	 */
	protected function get_info_fixture($file, $test='info')
	{
		return __DIR__ . "/../fixtures/$test/" . $file . '.json';
	}


	/**
	 * Clean file info of stuff
	 */
	protected function clean_info($info)
	{
		// These are always going to be different
		unset($info['PostMessageOrigin']);
		unset($info['LastModifiedTime']);

		// This is different on Travis (User, Admin vs Account, Demo)
		unset($info['UserFriendlyName']);

		return json_encode($info);
	}
}
