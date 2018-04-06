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
