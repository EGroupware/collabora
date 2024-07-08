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

require_once __DIR__ . '/WopiBase.php';

use \EGroupware\Api\Vfs;

/**
 * Test for File ID
 *
 * This group of tests creates shares, but without logging out since it's not
 * necessary to get the file ID.
 *
 * @TODO We're really testing that we get _something_ for the ID right now, not that multiple
 *  shares for the same file get the same ID
 */
class FileIDTest extends WopiBase
{
	public function testSqlfs()
	{
		$this->checkDirectory(Vfs::get_home_dir(), Wopi::READONLY);
	}

	public function testVersioning()
	{
		$this->files[] = $dir = Vfs::get_home_dir().'/versioned/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->mountVersioned($dir);

		$this->checkDirectory($dir, Wopi::READONLY);
	}

	public function testFilesystem()
	{
		$this->markTestSkipped("Travis doesn't like this one");
		// Don't add to files list or it deletes the folder from filesystem
		$dir = '/filesystem/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->mountFilesystem($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");

		$this->checkDirectory($dir);
	}

	public function testLink()
	{
		// Create an infolog entry for testing purposes
		$info_id = $this->make_infolog();
		$bo = new \infolog_bo();
		$dir = "/apps/infolog/$info_id/";

		$this->mountLinks("/apps");

		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");

		$this->checkDirectory($dir, Wopi::READONLY);
	}

	public function testMerge()
	{
		if(!class_exists("\EGroupware\Stylite\Vfs\Merge\StreamWrapper"))
		{
			$this->markTestSkipped();
			return;
		}
		$this->files[] = $dir = Vfs::get_home_dir().'/merged/';

		// Create versioned directory
		if(Vfs::is_dir($dir))
		{
			Vfs::remove($dir);
		}
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->mountMerge($dir);

		$this->checkDirectory($dir, Wopi::READONLY);
	}

	/**
	 * Test that if we share a file (Writable Collabora Online link) that
	 * the share and the original file both use the same file ID
	 *
	 * @return void
	 */
	public function testShareHasSameFileID()
	{
		// Create files
		$this->files[] = $dir = Vfs::get_home_dir() . '/shared_dir/';

		// Create versioned directory
		if(Vfs::is_dir($dir))
		{
			Vfs::remove($dir);
		}
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->checkDirectory($dir, Wopi::WRITABLE);

		// Test file
		$test_path = $dir . 'test_file.txt';

		// Get file ID
		$original_file_id = Wopi::get_file_id($test_path);

		// Create share & verify it
		$mode = Wopi::WOPI_WRITABLE;
		$extra = array();
		$this->getShareExtra($test_path, $mode, $extra);
		$this->shareLink($test_path, $mode, $extra);

		// Check file ID
		$share_file_id = Wopi::get_file_id($test_path);
		$this->assertEquals($original_file_id, $share_file_id, "Share is using a different file ID from original");
	}

	/**
	 * Check a given directory to see all the files in it give a file ID
	 *
	 * @param string $dir
	 * @param string $mode
	 */
	protected function checkDirectory($dir, $mode)
	{
		if(static::LOG_LEVEL)
		{
			echo "\n" . __METHOD__ . "($dir, $mode)\n";
		}
		if(substr($dir, -1) != '/')
		{
			$dir .= '/';
		}
		$this->files += $this->addFiles($dir);

		$files = Vfs::find($dir, static::VFS_OPTIONS);

		// Make sure all are there
		foreach($this->files as $file)
		{
			if(Vfs::is_dir($file)) continue;

			$this->checkOneFile($file, $mode);
		}
	}

	protected function checkOneFile($file, $mode)
	{
		// Create and use link
		$extra = array();
		$this->getShareExtra($file, $mode, $extra);

		$share = $this->createShare($file, $mode, $extra);


		// Check to see that it can find a File ID
		$file_id = Wopi::get_file_id($file);
		$this->assertTrue(is_numeric($file_id));
		$this->assertNotEquals(0, $file_id, 'No File ID for ' . $file);

		// Check the other way, but $file is missing the Vfs prefix
		$resolved_file = Vfs::stat($file)['url'] ?: $file;
		$this->assertStringContainsString(Wopi::get_path_from_id($file_id), $resolved_file);
	}
}
