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
 * Tests for the WOPI API Files endpoint
 *
 * This group of tests operates through Sharing, logging out to make sure we get
 * the correct information when accessed through the share.
 * This isn't how Collabora accesses it (via webserver), but it tests the API to
 * make sure it's giving what's expected.
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 */
class FileInfoSharingTest extends SharingBase
{
	public function testCheckFileOnSqlfsReadonly()
	{
		$this->checkDirectory(Vfs::get_home_dir(), Wopi::WOPI_READONLY);
	}

	public function testCheckFileOnSqlfsWritable()
	{
		$this->checkDirectory(Vfs::get_home_dir(), Wopi::WOPI_WRITABLE);
	}

	public function testCheckFileInfoOnVersioningReadonly()
	{
		$this->files[] = $dir = Vfs::get_home_dir().'/versioned/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->mountVersioned($dir);

		$this->checkDirectory($dir, Wopi::WOPI_READONLY);
	}

	public function testCheckFileInfoOnVersioningWritable()
	{
		$this->files[] = $dir = Vfs::get_home_dir().'/versioned/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->mountVersioned($dir);

		$this->checkDirectory($dir, Wopi::WOPI_WRITABLE);
	}

	public function testCheckFileInfoOnFilesystemReadonly()
	{
		// Don't add to files list or it deletes the folder from filesystem
		$dir = '/filesystem/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->mountFilesystem($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");

		$this->checkDirectory($dir, Wopi::WOPI_READONLY);
	}

	public function testCheckFileInfoOnFilesystemWritable()
	{
		// Don't add to files list or it deletes the folder from filesystem
		$dir = '/filesystem/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->mountFilesystem($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");

		$this->checkDirectory($dir, Wopi::WOPI_WRITABLE);
	}

	public function testCheckFileInfoOnLinkReadonly()
	{
		// Create an infolog entry for testing purposes
		$info_id = $this->make_infolog();
		$bo = new \infolog_bo();
		$dir = "/apps/infolog/$info_id/";

		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");

		$this->checkDirectory($dir, Wopi::WOPI_READONLY);
	}

	public function testCheckFileInfoOnLinkWritable()
	{
		// Create an infolog entry for testing purposes
		$info_id = $this->make_infolog();
		$bo = new \infolog_bo();
		$dir = "/apps/infolog/$info_id/";

		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");

		$this->checkDirectory($dir, Wopi::WOPI_WRITABLE);
	}

	public function testCheckFileInfoOnMergeReadonly()
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

		$this->checkDirectory($dir, Wopi::WOPI_READONLY);
	}

	public function testCheckFileInfoOnMergeWritable()
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

		$this->checkDirectory($dir, Wopi::WOPI_WRITABLE);
	}

	/**
	 * Check the access permissions & file info for one file
	 *
	 * @param string $file
	 * @param string $mode
	 */
	protected function checkOneFile($file, $mode)
	{
		$parent = parent::checkOneFile($file, $mode);

		$files = new Wopi\Files();
		$info = $files->check_file_info($file);

		// Check additional things
		// - Readonly or sharing a single file does not give save as
		switch($mode)
		{
			case Wopi::WOPI_READONLY:
				$this->assertTrue($info['UserCanNotWriteRelative'], "Readonly share allows Save As");
				$this->assertFalse($info['UserCanRename'], "Readonly allows rename");
				break;
			case Wopi::WOPI_WRITABLE:
				$this->assertFalse($info['UserCanNotWriteRelative'], "Writable does not allow Save As");
				$this->assertTrue($info['UserCanRename'], "Writable does not allow rename");
				break;
			case Wopi::WOPI_SHARED:
				// Same as readonly
				$this->assertTrue($info['UserCanNotWriteRelative'], "Writable share allows Save As");
				$this->assertFalse($info['UserCanRename'], "Writable share allows rename");
				break;

		}
	}
}
