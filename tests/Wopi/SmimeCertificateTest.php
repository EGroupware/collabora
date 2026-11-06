<?php

/**
 * Tests for opening a user's S/MIME certificate for signing documents in the editor
 *
 * @link http://www.egroupware.org
 * @package collabora
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora\Wopi;

require_once __DIR__ . '/SmimeFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EGroupware\Collabora\Wopi\Files::smime_certificate()
 *
 * Needs no EGroupware environment: it only reads the PKCS#12 fixtures, see SmimeFixtures.
 */
class SmimeCertificateTest extends TestCase
{
	use SmimeFixtures;

	protected function setUp() : void
	{
		$this->assertFixtureCertificatesNotExpired();
	}

	public function testExpiredFixtureIsReportedClearly()
	{
		// a time after the certificates expire, as if they had
		$this->expectException(AssertionFailedError::class);
		$this->expectExceptionMessageMatches('/has expired.*valid until 21.*not because of the code.*make new fixtures/');

		$this->assertFixtureCertificatesNotExpired(strtotime('2200-01-01'));
	}

	public function testCertificateWithoutPassphraseIsOpened()
	{
		$info = Files::smime_certificate($this->certificate());

		$this->assertEqualsCanonicalizing(['SignatureCert', 'SignatureKey', 'SignatureCa'], array_keys($info));
		$this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $info['SignatureCert']);
		$this->assertSame('Collabora Test Fixture (not a real certificate)', openssl_x509_parse($info['SignatureCert'])['subject']['CN']);
		// Collabora can not be asked for a passphrase, the key has to be handed over unencrypted
		$this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $info['SignatureKey']);
		$this->assertStringNotContainsString('ENCRYPTED', $info['SignatureKey']);
		// ... and has to be the key of that certificate
		$this->assertTrue(openssl_x509_check_private_key($info['SignatureCert'], $info['SignatureKey']));
	}

	public function testChainIsPassedAlong()
	{
		$info = Files::smime_certificate($this->certificate());

		$this->assertSame('Collabora Test Fixture Chain (not a real certificate)', openssl_x509_parse($info['SignatureCa'])['subject']['CN']);
		$this->assertNotSame($info['SignatureCert'], $info['SignatureCa']);
	}

	public function testCertificateWithPassphraseNeedsIt()
	{
		$p12 = $this->certificate(true);

		$this->assertSame([], Files::smime_certificate($p12), 'opened without the passphrase');
		$this->assertSame([], Files::smime_certificate($p12, 'wrong'), 'opened with a wrong passphrase');

		$info = Files::smime_certificate($p12, $this->fixturePassphrase());
		$this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $info['SignatureCert']);
		// the key is handed over unencrypted, whatever protected the file
		$this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $info['SignatureKey']);
		$this->assertTrue(openssl_x509_check_private_key($info['SignatureCert'], $info['SignatureKey']));
	}

	public function testBothFixturesHoldTheSameCertificate()
	{
		$this->assertSame(
			Files::smime_certificate($this->certificate())['SignatureCert'],
			Files::smime_certificate($this->certificate(true), $this->fixturePassphrase())['SignatureCert']
		);
	}

	public function testPassphraseIsNotNeededButWrongForAFileWithout()
	{
		// a passphrase given to a file that has none is wrong, not ignored
		$this->assertSame([], Files::smime_certificate($this->certificate(), $this->fixturePassphrase()));
	}

	#[DataProvider('unusableProvider')]
	public function testUnusableContentGivesNothing(string $content)
	{
		$this->assertSame([], Files::smime_certificate($content));
	}

	public static function unusableProvider() : array
	{
		return [
			'empty'                  => [''],
			'text'                   => ['not a certificate'],
			'the unavailable marker' => ['**unavailable**'],
			'a PEM, not a p12'       => ["-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----"],
		];
	}
}
