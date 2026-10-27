<?php

/**
 * Tests for the WOPI settings endpoint, which stores the files of Collabora's settings page
 * (dictionaries, autotext, document and interface settings, ...) in the VFS
 *
 * @link http://www.egroupware.org
 * @package collabora
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora\Wopi;

// test base providing the Egw environment, since we need the VFS and the DB
require_once realpath(__DIR__ . '/../../../api/tests/LoggedInTest.php');

use EGroupware\Api\LoggedInTest;
use EGroupware\Api\Vfs;
use EGroupware\Collabora\Wopi;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for EGroupware\Collabora\Wopi\Settings
 *
 * The settings directories are redirected into a scratch directory in the demo user's home, so nothing
 * here touches the settings directory configured for the real Collabora.
 *
 * The request is read from $_GET / $_REQUEST (and php://input for the content of an upload), so
 * those are set here and put back afterwards.  An upload that actually writes a file can not be tested, as
 * php://input can not be replaced: the parts of it that can be tested are the rejections.
 */
class SettingsTest extends LoggedInTest
{
	/**
	 * Scratch directory in the VFS, holds one directory per config type
	 *
	 * Made once for the class and not for every test: every change in a user's home is pushed to everyone who
	 * can see it, and on a big instance that is a row per account and a long time per change.
	 */
	protected static string $root = '';

	protected string $dir;

	protected Settings $settings;

	protected array $saved_globals = [];

	/**
	 * Files a test made, they are removed after it
	 */
	protected array $created = [];

	protected function setUp() : void
	{
		parent::setUp();
		$this->saved_globals = [$_GET, $_REQUEST];

		if(!static::$root)
		{
			static::$root = Vfs::get_home_dir() . '/zz-collabora-settings-test';
			if(Vfs::is_dir(static::$root))
			{
				Vfs::remove(static::$root);
			}
			Vfs::mkdir(static::$root, null, STREAM_MKDIR_RECURSIVE);
		}
		$this->dir = static::$root;
		$this->created = [];

		$this->settings = $this->settingsFor($this->dir);
	}

	protected function tearDown() : void
	{
		foreach($this->created as $file)
		{
			if(Vfs::file_exists($file))
			{
				Vfs::unlink($file);
			}
		}
		[$_GET, $_REQUEST] = $this->saved_globals;
		parent::tearDown();
	}

	public static function tearDownAfterClass() : void
	{
		if(static::$root && Vfs::is_dir(static::$root))
		{
			Vfs::remove(static::$root);
		}
		static::$root = '';
		parent::tearDownAfterClass();
	}

	/**
	 * Settings that keep both config types below $dir, or have no directory at all if $dir is ''
	 */
	protected function settingsFor(string $dir) : Settings
	{
		return new class($dir) extends Settings
		{
			public function __construct(protected string $base)
			{
			}

			public function getSettingsPath($type)
			{
				return in_array($type, ['userconfig', 'systemconfig']) && $this->base ?
					$this->base . '/' . $type : [];
			}
		};
	}

	/**
	 * Call a protected method of Settings
	 */
	protected function call(Settings $settings, string $method, ...$args)
	{
		return (new \ReflectionMethod($settings, $method))->invoke($settings, ...$args);
	}

	/**
	 * Put a file into the scratch settings directory
	 *
	 * @param string $type 'userconfig' or 'systemconfig'
	 * @return string VFS path of the file
	 */
	protected function addSetting(string $type, string $setting, string $name, string $content = 'x') : string
	{
		$dir = $this->dir . '/' . $type . '/' . $setting;
		if(!Vfs::is_dir($dir))
		{
			Vfs::mkdir($dir, null, STREAM_MKDIR_RECURSIVE);
		}
		$this->assertNotFalse(file_put_contents(Vfs::PREFIX . $dir . '/' . $name, $content), 'Unable to create test file');
		$this->created[] = $dir . '/' . $name;
		return $dir . '/' . $name;
	}

	/**
	 * Every sub-directory Collabora writes to is accepted, and a file name with spaces and umlauts is kept as it is
	 */
	public function testSettingPathAcceptsKnownParts()
	{
		foreach(Settings::SETTING_DIRECTORIES as $setting)
		{
			$this->assertEquals(
				$this->dir . '/systemconfig/' . $setting . '/Mein Wörterbuch.dic',
				$this->call($this->settings, 'getSettingPath', 'systemconfig', $setting, 'Mein Wörterbuch.dic')
			);
			$this->assertEquals(
				$this->dir . '/userconfig/' . $setting . '/a.dic',
				$this->call($this->settings, 'getSettingPath', 'userconfig', $setting, 'a.dic')
			);
		}
	}

	public static function badPartsProvider() : array
	{
		return [
			'unknown type'           => ['other', 'wordbook', 'a.dic'],
			'empty type'             => ['', 'wordbook', 'a.dic'],
			'unknown directory'      => ['systemconfig', 'other', 'a.dic'],
			'directory case differs' => ['systemconfig', 'Wordbook', 'a.dic'],
			'directory up'           => ['systemconfig', '..', 'a.dic'],
			'empty directory'        => ['systemconfig', '', 'a.dic'],
			'file is parent'         => ['systemconfig', 'wordbook', '..'],
			'file is directory'      => ['systemconfig', 'wordbook', '.'],
			'empty file'             => ['systemconfig', 'wordbook', ''],
			'no file'                => ['systemconfig', 'wordbook', null],
		];
	}

	/**
	 * The parts come from the client: anything that is not a known sub-directory and a plain file name is refused
	 */
	#[DataProvider('badPartsProvider')]
	public function testSettingPathRejectsBadParts($type, $setting, $file)
	{
		$this->assertNull($this->call($this->settings, 'getSettingPath', $type, $setting, $file));
	}

	/**
	 * Without a settings directory (not configured) there is no path, so nothing can end up at the VFS root
	 */
	public function testSettingPathNeedsASettingsDirectory()
	{
		$none = $this->settingsFor('');
		$this->assertNull($this->call($none, 'getSettingPath', 'systemconfig', 'wordbook', 'a.dic'));
	}

	public function testUserSettingsPathIsInTheHomeDirectory()
	{
		$this->assertEquals(Vfs::get_home_dir() . '/.config/collabora', (new Settings())->getSettingsPath('userconfig'));
	}

	public function testUnknownSettingsTypeHasNoPath()
	{
		$this->assertEmpty((new Settings())->getSettingsPath('other'));
	}

	/**
	 * Nothing configured: an empty answer, and no search of the whole VFS (which would hand out a share for every file)
	 */
	public function testFetchWithoutDirectoryIsEmpty()
	{
		$_GET['type'] = 'systemconfig';
		$this->assertEquals(
			['kind' => 'shared', 'autotext' => [], 'xcu' => [], 'browsersetting' => []],
			$this->call($this->settingsFor(''), 'fetch')
		);
	}

	public function testFetchOfAMissingDirectoryIsEmpty()
	{
		$_GET['type'] = 'userconfig';
		$result = $this->call($this->settingsFor($this->dir . '/does-not-exist'), 'fetch');
		$this->assertEquals('user', $result['kind']);
		$this->assertEquals(['kind', 'autotext', 'xcu', 'browsersetting'], array_keys($result));
	}

	/**
	 * A file shows up under the name of its sub-directory, with a link and the time of the file
	 */
	public function testFetchListsFilesBySetting()
	{
		$_GET['type'] = 'systemconfig';
		$_GET['access_token'] = 'not-a-real-token';
		$file = $this->addSetting('systemconfig', 'wordbook', 'zz test.dic');
		Vfs::touch($file, 1700000000);

		$result = $this->call($this->settings, 'fetch');

		$this->assertEquals('shared', $result['kind']);
		$this->assertCount(1, $result['wordbook']);
		$this->assertEquals('2023-11-14T22:13:20.000000Z', $result['wordbook'][0]['stamp']);
		$this->assertStringContainsString('file_name=zz+test.dic', $result['wordbook'][0]['uri']);
		$this->assertEmpty($result['autotext']);
	}

	/**
	 * The user's files are not in the system list and the other way round
	 */
	public function testFetchKeepsUserAndSystemApart()
	{
		$_GET['access_token'] = 'not-a-real-token';
		$this->addSetting('userconfig', 'wordbook', 'user.dic');
		$this->addSetting('systemconfig', 'wordbook', 'system.dic');

		$_GET['type'] = 'userconfig';
		$user = $this->call($this->settings, 'fetch');
		$_GET['type'] = 'systemconfig';
		$system = $this->call($this->settings, 'fetch');

		$this->assertStringContainsString('user.dic', $user['wordbook'][0]['uri']);
		$this->assertCount(1, $user['wordbook']);
		$this->assertStringContainsString('system.dic', $system['wordbook'][0]['uri']);
		$this->assertCount(1, $system['wordbook']);
	}

	public function testDeleteRemovesTheFile()
	{
		$file = $this->addSetting('systemconfig', 'wordbook', 'delete me.dic');
		$_REQUEST['fileId'] = '/settings/systemconfig/wordbook/delete me.dic';

		$result = $this->call($this->settings, 'delete');

		$this->assertEquals('success', $result['status']);
		$this->assertFalse(Vfs::file_exists($file));
	}

	/**
	 * Deleting a file must show in the stamp, or Collabora keeps using the copy it has
	 */
	public function testDeleteChangesTheStamp()
	{
		$a = $this->addSetting('userconfig', 'wordbook', 'a.dic');
		$b = $this->addSetting('userconfig', 'wordbook', 'b.dic');
		// everything in the past, so the time of the deletion is the newest thing there
		foreach([$a, $b, dirname($a), dirname(dirname($a)), $this->dir . '/userconfig'] as $path)
		{
			Vfs::touch($path, 1700000000);
		}
		$before = $this->settings->getStamp('userconfig');
		$this->assertEquals('2023-11-14T22:13:20.000000Z', $before);

		$_REQUEST['fileId'] = '/settings/userconfig/wordbook/b.dic';
		$this->assertEquals('success', $this->call($this->settings, 'delete')['status']);

		$this->assertGreaterThan($before, $this->settings->getStamp('userconfig'));
		$this->assertTrue(Vfs::file_exists($a), 'The other file has to stay');
	}

	/**
	 * Ids that point anywhere but at a known setting
	 */
	public static function badFileIds() : array
	{
		return [
			'/settings/systemconfig/wordbook/..',
			'/settings/systemconfig/other/keep.dic',
			'/settings/systemconfig/../keep.dic',
			'/settings/other/wordbook/keep.dic',
			'/settings/systemconfig/wordbook',
		];
	}

	/**
	 * A fileId that points anywhere but at a known setting deletes nothing
	 */
	public function testDeleteRefusesBadFileIds()
	{
		$keep = $this->addSetting('systemconfig', 'wordbook', 'keep.dic');
		$outside = $this->dir . '/keep.dic';
		file_put_contents(Vfs::PREFIX . $outside, 'x');
		$this->created[] = $outside;

		foreach(static::badFileIds() as $fileId)
		{
			$_REQUEST['fileId'] = $fileId;
			$this->assertNull($this->call($this->settings, 'delete'), "$fileId was not refused");
			$this->assertTrue(Vfs::file_exists($keep), "$fileId deleted the setting file");
			$this->assertTrue(Vfs::file_exists($outside), "$fileId deleted a file outside of the settings");
		}
	}

	public function testDeleteOfAMissingFileFails()
	{
		$_REQUEST['fileId'] = '/settings/systemconfig/wordbook/never-there.dic';
		$this->assertNull($this->call($this->settings, 'delete'));
	}

	/**
	 * An upload to anywhere but a known setting is refused before any content is read, and creates nothing
	 */
	public function testUploadRefusesBadFileIds()
	{
		foreach(static::badFileIds() as $fileId)
		{
			$_REQUEST['fileId'] = $fileId;
			$this->assertNull($this->call($this->settings, 'upload'), "$fileId was not refused");
		}
		$this->assertEquals([], Vfs::find($this->dir, ['type' => 'f']));
	}

	/**
	 * Without files the stamp is a fixed time, so Collabora has nothing to download again
	 */
	public function testStampWithoutFilesIsFixed()
	{
		$missing = $this->settingsFor($this->dir . '/does-not-exist');
		$stamp = $missing->getStamp('systemconfig');
		$this->assertEquals('1970-01-01T00:00:01.000000Z', $stamp);
		$this->assertEquals($stamp, $missing->getStamp('systemconfig'));
		$this->assertEquals('1970-01-01T00:00:01.000000Z', $this->settingsFor('')->getStamp('systemconfig'));
	}

	/**
	 * The stamp is the newest change of any file, not the time of the request
	 */
	public function testStampIsTheNewestChange()
	{
		$old = $this->addSetting('systemconfig', 'xcu', 'old.xcu');
		$new = $this->addSetting('systemconfig', 'wordbook', 'new.dic');
		foreach([dirname($old), dirname($new), $this->dir . '/systemconfig'] as $dir)
		{
			Vfs::touch($dir, 1600000000);
		}
		Vfs::touch($old, 1650000000);
		Vfs::touch($new, 1700000000);

		$this->assertEquals('2023-11-14T22:13:20.000000Z', $this->settings->getStamp('systemconfig'));
		// the other type has its own directory, which none of this touched
		$this->assertEquals('1970-01-01T00:00:01.000000Z', $this->settingsFor($this->dir . '/does-not-exist')->getStamp('userconfig'));
	}

	/**
	 * CheckFileInfo tells Collabora where to get both kinds of settings
	 */
	public function testCheckFileInfoOffersSettings()
	{
		$_GET['access_token'] = 'not-a-real-token';
		$file = Vfs::get_home_dir() . '/zz-collabora-settings-info-test.txt';
		file_put_contents(Vfs::PREFIX . $file, 'Test');
		try
		{
			$info = (new Files())->check_file_info(Vfs::resolve_url_symlinks($file));
		}
		finally
		{
			Vfs::unlink($file);
		}

		foreach(['UserSettings' => 'userconfig', 'SharedSettings' => 'systemconfig'] as $key => $type)
		{
			$this->assertArrayHasKey($key, $info);
			$this->assertStringContainsString('/collabora/index.php/wopi/settings', $info[$key]['uri']);
			$this->assertStringContainsString('type=' . $type, $info[$key]['uri']);
			$this->assertStringContainsString('fileId=-1', $info[$key]['uri']);
			$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $info[$key]['stamp']);
		}
	}
}
