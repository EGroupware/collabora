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

require_once __DIR__ . '/SharingBase.php';

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
		$this->files[] = $dir = Vfs::get_home_dir().'/merged/';

		// Create versioned directory
		if(Vfs::is_dir($dir)) Vfs::remove($dir);
		Vfs::mkdir($dir);
		$this->assertTrue(Vfs::is_writable($dir), "Unable to write to '$dir' as expected");
		$this->mountMerge($dir);

		$this->checkDirectory($dir, Wopi::WOPI_WRITABLE);
	}
}
