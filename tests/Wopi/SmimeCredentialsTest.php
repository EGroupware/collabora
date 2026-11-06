<?php

/**
 * Tests for finding the user's Mail S/MIME certificate for signing documents in the editor
 *
 * @link http://www.egroupware.org
 * @package collabora
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora\Wopi;

// test base providing the Egw environment, since we need the DB
require_once realpath(__DIR__ . '/../../../api/tests/LoggedInTest.php');
require_once __DIR__ . '/SmimeFixtures.php';

use EGroupware\Api\Cache;
use EGroupware\Api\LoggedInTest;
use EGroupware\Api\Mail\Credentials;
use EGroupware\Api\Vfs;

/**
 * Tests for EGroupware\Collabora\Wopi\Files::mail_smime_certificate()
 *
 * The certificates are the PKCS#12 fixtures of SmimeCertificateTest, stored under a mail account id no real
 * account has and removed again, so nothing here touches a real user's S/MIME certificate.
 */
class SmimeCredentialsTest extends LoggedInTest
{
	use SmimeFixtures;

	/**
	 * Mail account id for the test certificates, far above any real account
	 */
	const ACC_ID = 987654;

	protected int $account_id;
	protected ?int $was_user = null;

	protected function setUp() : void
	{
		$this->assertFixtureCertificatesNotExpired();
		$this->account_id = (int)$GLOBALS['egw_info']['user']['account_id'];
		$this->was_user = Vfs::$user;
		Vfs::$user = $this->account_id;
		Credentials::delete(self::ACC_ID, $this->account_id);
	}

	protected function tearDown() : void
	{
		Credentials::delete(self::ACC_ID, $this->account_id);
		Cache::unsetSession('mail', 'smime_passphrase');
		foreach([self::ACC_ID, 0] as $acc_id)
		{
			Cache::unsetInstance('collabora', Files::smime_problem_key($this->account_id, $acc_id));
		}
		Vfs::$user = $this->was_user;
	}

	/**
	 * What the last find() wrote to the error log
	 */
	protected string $log = '';

	/**
	 * Call the protected method that looks for the certificate
	 *
	 * Whatever it writes to the error log is kept in $this->log instead of showing up in the test output.
	 */
	protected function find() : array
	{
		$file = tempnam(sys_get_temp_dir(), 'smime-log');
		$was = ini_set('error_log', $file);
		try
		{
			$method = new \ReflectionMethod(Files::class, 'mail_smime_certificate');
			return $method->invoke(new Files());
		}
		finally
		{
			ini_set('error_log', $was === false ? '' : $was);
			$this->log = file_get_contents($file);
			unlink($file);
		}
	}

	public function testCertificateStoredWithTheSystemKeyIsFound()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(), Credentials::SMIME, $this->account_id, null, true);

		$info = $this->find();

		$this->assertArrayHasKey('SignatureCert', $info);
		$this->assertTrue(openssl_x509_check_private_key($info['SignatureCert'], $info['SignatureKey']));
	}

	public function testCredentialsThatCannotBeDecryptedGiveNothing()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(), Credentials::SMIME, $this->account_id, null, true);
		// claim it was stored with the user's login password: the content was made with the system key, so it
		// does not decrypt, and that is no reason to complain
		Credentials::get_db()->update(Credentials::TABLE, ['cred_pw_enc' => Credentials::USER_AES], [
			'acc_id' => self::ACC_ID, 'account_id' => $this->account_id
		], __LINE__, __FILE__, Credentials::APP);

		$this->assertSame([], $this->find());
	}

	public function testCertificateWithAPassphraseGivesNothingWithoutTheCachedOne()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(true), Credentials::SMIME, $this->account_id, null, true);

		$this->assertSame([], $this->find());
	}

	public function testCertificateWithAPassphraseIsOpenedWithTheCachedOne()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(true), Credentials::SMIME, $this->account_id, null, true);
		Cache::setSession('mail', 'smime_passphrase', $this->fixturePassphrase(), 60);

		$info = $this->find();

		$this->assertArrayHasKey('SignatureCert', $info);
		$this->assertTrue(openssl_x509_check_private_key($info['SignatureCert'], $info['SignatureKey']));
	}

	public function testWrongCachedPassphraseGivesNothing()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(true), Credentials::SMIME, $this->account_id, null, true);
		Cache::setSession('mail', 'smime_passphrase', 'not the passphrase', 60);

		$this->assertSame([], $this->find());
	}

	public function testCachedPassphraseIsTheOnlyOneTried()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(), Credentials::SMIME, $this->account_id, null, true);
		Cache::setSession('mail', 'smime_passphrase', 'something else', 60);

		// a certificate without a passphrase is not tried once there is a cached one: that is the one to use
		$this->assertSame([], $this->find());
	}

	/**
	 * Run the lookup and return what it wrote to the error log
	 */
	protected function findAndGetLog() : string
	{
		$this->find();
		return $this->log;
	}

	public function testCertificateThatCanNotBeOpenedIsLoggedForTheAdmin()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(true), Credentials::SMIME, $this->account_id, null, true);

		$log = $this->findAndGetLog();

		$this->assertStringContainsString('Collabora: no signing for user #' . $this->account_id, $log);
		$this->assertStringContainsString('mail account #' . self::ACC_ID, $log);
		$this->assertStringContainsString('without a passphrase and none is cached', $log);
		// what OpenSSL says is what tells the admin it is the passphrase
		$this->assertStringContainsString('mac verify failure', $log);
	}

	public function testWrongCachedPassphraseIsLoggedWithoutTheValue()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(true), Credentials::SMIME, $this->account_id, null, true);
		Cache::setSession('mail', 'smime_passphrase', 'a passphrase that is wrong', 60);

		$log = $this->findAndGetLog();

		$this->assertStringContainsString('with the passphrase cached in the session', $log);
		$this->assertStringNotContainsString('a passphrase that is wrong', $log);
	}

	public function testTheSameProblemIsLoggedOnlyOnce()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(true), Credentials::SMIME, $this->account_id, null, true);

		$this->assertNotSame('', $this->findAndGetLog());
		$this->assertSame('', $this->findAndGetLog(), 'logged again for every request');
	}

	public function testAChangedProblemIsLoggedAgain()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(true), Credentials::SMIME, $this->account_id, null, true);
		$this->assertNotSame('', $this->findAndGetLog());

		Cache::setSession('mail', 'smime_passphrase', 'now a wrong one is cached', 60);

		$this->assertStringContainsString('with the passphrase cached in the session', $this->findAndGetLog());
	}

	public function testNothingIsLoggedWhenThereIsNoCertificate()
	{
		$this->assertSame('', $this->findAndGetLog());
	}

	public function testNothingIsLoggedWhenTheCertificateOpens()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(), Credentials::SMIME, $this->account_id, null, true);

		$this->assertSame('', $this->findAndGetLog());
	}

	/**
	 * Settings that keep the user's settings in a scratch directory instead of the real ones
	 */
	protected function scratchSettings(string $dir) : Settings
	{
		return new class($dir) extends Settings
		{
			public function __construct(protected string $dir)
			{
			}

			public function getSettingsPath($type)
			{
				return $type === 'userconfig' ? $this->dir : [];
			}
		};
	}

	public function testSettingsFileIsCreatedForACertificateFromMail()
	{
		$dir = Vfs::get_home_dir() . '/zz-collabora-smime-test';
		Vfs::remove($dir);
		Vfs::mkdir($dir, null, STREAM_MKDIR_RECURSIVE);
		try
		{
			Credentials::write(self::ACC_ID, 'smime', $this->certificate(), Credentials::SMIME, $this->account_id, null, true);
			$method = new \ReflectionMethod(Files::class, 'user_private_info');

			$info = $method->invoke(new Files(), $this->scratchSettings($dir));

			$this->assertArrayHasKey('SignatureCert', $info);
			$this->assertTrue(Vfs::file_exists($dir . '/viewsetting/viewsetting.json'), 'Collabora ignores the certificate without it');
		}
		finally
		{
			Vfs::remove($dir);
		}
	}

	public function testNoSettingsFileIsCreatedWithoutACertificate()
	{
		$dir = Vfs::get_home_dir() . '/zz-collabora-smime-test';
		Vfs::remove($dir);
		Vfs::mkdir($dir, null, STREAM_MKDIR_RECURSIVE);
		try
		{
			$method = new \ReflectionMethod(Files::class, 'user_private_info');

			$this->assertSame([], $method->invoke(new Files(), $this->scratchSettings($dir)));
			$this->assertFalse(Vfs::file_exists($dir . '/viewsetting/viewsetting.json'));
		}
		finally
		{
			Vfs::remove($dir);
		}
	}

	public function testNoCertificateGivesNothing()
	{
		$this->assertSame([], $this->find());
	}

	public function testNoUserGivesNothing()
	{
		Credentials::write(self::ACC_ID, 'smime', $this->certificate(), Credentials::SMIME, $this->account_id, null, true);
		Vfs::$user = 0;

		$this->assertSame([], $this->find());
	}
}
