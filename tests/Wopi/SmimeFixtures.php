<?php

/**
 * The self-signed S/MIME certificates the tests of the editor's signing certificate use
 *
 * @link http://www.egroupware.org
 * @package collabora
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora\Wopi;

use PHPUnit\Framework\Assert;

/**
 * Two PKCS#12 files in tests/fixtures/smime/, one with and one without a passphrase
 *
 * Both hold the same self-signed certificate and key plus one more self-signed certificate standing in for a
 * chain; the passphrase of smime-test-passphrase.p12 is "secret", smime-test.p12 has none.  They are throwaway
 * test data valid for a hundred years, made like this:
 *
 *   openssl req -x509 -newkey rsa:2048 -nodes -keyout leaf.key -out leaf.pem -days 36500 -config leaf.cnf
 *   openssl req -x509 -newkey rsa:2048 -nodes -keyout chain.key -out chain.pem -days 36500 -config chain.cnf
 *   openssl pkcs12 -export -inkey leaf.key -in leaf.pem -certfile chain.pem -out smime-test.p12 -passout pass:
 *   openssl pkcs12 -export -inkey leaf.key -in leaf.pem -certfile chain.pem -out smime-test-passphrase.p12 -passout pass:secret
 *
 * where leaf.cnf has the subject "Collabora Test Fixture (not a real certificate)", an email address and the key
 * usages of an S/MIME certificate, and chain.cnf has the subject "Collabora Test Fixture Chain (not a real
 * certificate)" as a CA.
 */
trait SmimeFixtures
{
	/**
	 * Passphrase of the fixture protected by one
	 */
	protected function fixturePassphrase() : string
	{
		return 'secret';
	}

	/**
	 * Content of the fixture without or with a passphrase
	 *
	 * @param bool $with_passphrase true: the one protected by the passphrase "secret"
	 * @return string content of the p12 file
	 */
	protected function certificate(bool $with_passphrase = false) : string
	{
		return file_get_contents(__DIR__ . '/../fixtures/smime/' . ($with_passphrase ? 'smime-test-passphrase.p12' : 'smime-test.p12'));
	}

	/**
	 * Fail with the reason if a fixture certificate has expired, instead of letting that show up as something
	 * else going wrong
	 *
	 * Meant for setUp(), so every test in the class says it.
	 *
	 * @param ?int $now time to check against, default now
	 */
	protected function assertFixtureCertificatesNotExpired(?int $now = null) : void
	{
		$now ??= time();
		foreach([false, true] as $with_passphrase)
		{
			$certs = [];
			$opened = @openssl_pkcs12_read($this->certificate($with_passphrase), $certs, $with_passphrase ? $this->fixturePassphrase() : '');
			while(openssl_error_string())
			{
			}
			Assert::assertTrue($opened, 'The S/MIME test fixture ' . ($with_passphrase ? 'smime-test-passphrase.p12' : 'smime-test.p12') .
				' can not be read, see the docblock of the SmimeFixtures trait for how it is made');
			foreach(array_merge([$certs['cert']], (array)($certs['extracerts'] ?? [])) as $pem)
			{
				$info = openssl_x509_parse($pem);
				Assert::assertLessThan($info['validTo_time_t'], $now, 'The S/MIME test fixture ' .
					($with_passphrase ? 'smime-test-passphrase.p12' : 'smime-test.p12') . ' has expired: its certificate "' .
					($info['subject']['CN'] ?? '?') . '" was valid until ' . date('Y-m-d', $info['validTo_time_t']) .
					'.  The tests below fail because of that, not because of the code: make new fixtures as described in the ' .
					'docblock of the SmimeFixtures trait.');
			}
		}
	}
}
