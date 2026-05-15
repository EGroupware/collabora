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

require_once __DIR__ . '/../WopiBase.php';

use \EGroupware\Api\Vfs;

/**
 * Tests for the WOPI API Files endpoint
 *
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 *
 * @author nathan
 */
class FilesTest extends WopiBase
{

	// Collabora does not actually use locking last I heard (2017)
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingACLTest::class)]
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingHooksTest::class)]
	public function testLock()
	{
		$this->markTestIncomplete();
	}
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingACLTest::class)]
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingHooksTest::class)]
	public function testUnlock()
	{
		$this->markTestIncomplete();
	}
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingACLTest::class)]
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingHooksTest::class)]
	public function testRefreshLock()
	{
		$this->markTestIncomplete();
	}
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingACLTest::class)]
	#[\PHPUnit\Framework\Attributes\DependsOnClass(\EGroupware\Api\Vfs\SharingHooksTest::class)]
	public function testUnlockAndRelock()
	{
		$this->markTestIncomplete();
	}
}
