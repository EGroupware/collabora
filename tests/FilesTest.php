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
 * @see http://wopi.readthedocs.io/projects/wopirest/en/latest/endpoints.html#files-endpoint
 *
 * @author nathan
 */
class FilesTest extends SharingBase
{

	// Collabora does not actually use locking last I heard (2017)
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
}
