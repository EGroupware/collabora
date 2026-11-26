<?php
/**
 * Tests for the credentials Collabora stores for shares
 *
 * @link https://www.egroupware.org
 * @package collabora
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once __DIR__.'/../../api/tests/LoggedInTest.php';

use EGroupware\Api\LoggedInTest;

/**
 * Credentials::read_credential() selects only credentials stored for Collabora by a bit of their
 * type, a condition it builds as SQL itself: the database layer has to accept it.
 */
class CredentialsTest extends LoggedInTest
{
	public function testReadingNotExistingCredentialGivesNothing()
	{
		$this->assertSame([], Credentials::read_credential(0));
	}
}
