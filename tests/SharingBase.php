<?php

/**
 * Base for sharing with Collabora
 * Mostly just adds in stuff to deal with the extra Collabora share types
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package
 * @copyright (c) 2018  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once __DIR__ . '/../../api/tests/Vfs/SharingBase.php';

use \EGroupware\Api\Vfs;

class SharingBase extends \EGroupware\Api\Vfs\SharingBase
{
	/**
	 * Mock the Files object for testing, overriding the header & get_sent_content
	 * methods so we can specify what headers & content it uses.
	 *
	 * @param Array $header_map Map of Header => Value
	 * @param String $file_contents = null Specify the content 'sent' from the client
	 * @return MockedFiles
	 */
	protected function mock_files($header_map, $file_contents = null)
	{
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
		if($file_contents)
		{
			$files->expects($this->once())
					->method('get_sent_content')
					->will($this->returnValue($this->file_contents));
		}
		else
		{
			$files->expects($this->never())
					->method('get_sent_content');
		}

		return $files;
	}

	/**
	 * Check the access permissions & file info for one file
	 *
	 * @param string $file
	 * @param string $mode
	 */
	protected function checkOneFile($file, $mode)
	{
		if(static::LOG_LEVEL > 1)
		{
			$stat = Vfs::stat($file);
			echo "\t".Vfs::int2mode($stat['mode'])."\t$file\n";
		}

		// Skip directories
		if(Vfs::is_dir($file))
		{
			return parent::checkOneFile($file, $mode);
		}

		$info = Wopi\Files::check_file_info($file);
		if(static::LOG_LEVEL > 1)
		{
			error_log($file . ' FileInfo: ' .array2string($info));
		}

		// Check permissions
		switch($mode)
		{
			case Wopi::WOPI_READONLY:
				$this->assertFalse(Vfs::is_writable($file));
				// We expect this to fail
				$this->assertFalse(@file_put_contents(Vfs::PREFIX.$file, 'Writable check'));

				// Check FileInfo perms too
				$this->assertTrue($info['ReadOnly']);
				$this->assertFalse($info['UserCanWrite']);
				break;
			case Wopi::WOPI_WRITABLE:

				$this->assertTrue(Vfs::is_writable($file), $file . ' was not writable');
				$this->assertNotFalse(file_put_contents(Vfs::PREFIX.$file, 'Writable check'));

				// Check FileInfo perms too
				$this->assertFalse($info['ReadOnly']);
				$this->assertTrue($info['UserCanWrite']);
				break;
			default:
				return parent::checkOneFile($file, $mode);
		}
	}

	/**
	 * Get the extra information required to create a share link for the given
	 * directory, with the given mode
	 *
	 * @param string $dir Share target
	 * @param int $mode Share mode
	 * @param Array $extra
	 */
	protected function getShareExtra($dir, $mode, &$extra)
	{
		parent::getShareExtra($dir, $mode, $extra);
		switch($mode)
		{
			case Wopi::WOPI_WRITABLE:
				$extra['share_writable'] = Wopi::WOPI_WRITABLE;
				break;
			case Wopi::WOPI_READONLY:
				$extra['share_writable'] = Wopi::WOPI_READONLY;
				break;
		}
	}


	protected function checkDirectory($dir, $mode)
	{
		if(static::LOG_LEVEL)
		{
			echo "\n".__METHOD__ . "($dir, $mode)\n";
		}
		parent::checkDirectory($dir, $mode);
	}

}
