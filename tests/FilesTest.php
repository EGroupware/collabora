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

namespace EGroupware\collabora;

require_once __DIR__ . '/../../api/tests/Vfs/SharingBase.php';

use \EGroupware\Api\Vfs;

/**
 * Tests for the WOPI API Files endpoint
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 *
 * @author nathan
 */
class FilesTest extends\ EGroupware\Api\Vfs\SharingBase
{
	// Use consistant content since we're checking file size
	const CONTENT = 'Test file for Collabora File tests';

	/**
	 * Check the file info against saved results
	 */
	public function testCheckFileInfo()
	{
		$this->files = $this->addFiles(Vfs::get_home_dir(), static::CONTENT);
		{
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

	public function testPutRelativeFile()
	{
		$this->markTestIncomplete();
	}
	public function testLock()
	{
		$this->markTestIncomplete();
	}
	public function testUnlock()
	{
		$this->markTestIncomplete();
	}
	public function testRefreshLock()
	{
		$this->markTestIncomplete();
	}
	public function testUnlockAndRelock()
	{
		$this->markTestIncomplete();
	}
	public function testDeleteFile()
	{
		$this->markTestIncomplete();
	}
	public function RenameFile()
	{
		$this->markTestIncomplete();
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

		$info = $this->clean_info(Wopi\Files::check_file_info($_file));

		$file = basename($_file);
		$stored = file_get_contents($this->get_info_fixture($file));
		if(!$stored)
		{
			trigger_error("Missing fixture for $file created.", E_USER_NOTICE);
			file_put_contents($this->get_info_fixture($file), $info);
		}
		$this->assertEquals($stored, $info);
	}

	/**
	 * Get the fixture file for the given path
	 * @param string $file
	 * @return string
	 */
	protected function get_info_fixture($file, $test='info')
	{
		return __DIR__ . "/fixtures/$test/" . $file . '.json';
	}


	/**
	 * Clean file info of stuff
	 */
	protected function clean_info($info)
	{
		unset($info['PostMessageOrigin']);
		unset($info['LastModifiedTime']);

		return json_encode($info);
	}
}
