<?php

/**
 * Tests of what we rely on Collabora itself for, to notice when a Collabora update changes it on us
 *
 * The settings page, the types of setting files it writes and the way it asks for them are all Collabora's: we
 * only serve what it asks for.  Nothing in our own code can tell when that changes, so this asks the Collabora server
 * the tests run against.
 *
 * Needs a reachable Collabora server.  Where there is none, the tests are skipped, unless this runs in CI (the
 * CI variable is set), where there has to be one and not finding it is a failure.
 *
 * @link http://www.egroupware.org
 * @package collabora
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Collabora;

require_once realpath(__DIR__ . '/../../api/tests/LoggedInTest.php');

use EGroupware\Api\LoggedInTest;
use EGroupware\Collabora\Wopi\Settings;

class CollaboraContractTest extends LoggedInTest
{
	protected string $server = '';

	protected function setUp() : void
	{
		parent::setUp();
		try
		{
			$this->server = Bo::get_server();
			Bo::discover($this->server, true);
		}
		catch(\Throwable $e)
		{
			$message = 'No Collabora server to ask: ' . $e->getMessage();
			if(getenv('CI'))
			{
				$this->fail($message);
			}
			$this->markTestSkipped($message);
		}
	}

	/**
	 * GET or POST to the Collabora server
	 *
	 * @param string $url
	 * @param array|null $post fields to post
	 * @return array [status, content-type, body]
	 */
	protected function ask(string $url, ?array $post = null) : array
	{
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 30,
			// as Bo::discover(): a Collabora on this machine or the private network is no public host
			CURLOPT_SSL_VERIFYPEER => !preg_match('#^https?://(localhost|((10|127)\.\d{1,3}|192\.168)\.\d{1,3}\.\d{1,3})(:\d+)?/#', $url),
		]);
		if($post !== null)
		{
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		}
		$body = curl_exec($curl);
		$this->assertNotFalse($body, "Could not get $url: " . curl_error($curl));
		$result = [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE), $body];
		curl_close($curl);
		return $result;
	}

	/**
	 * The names of the form fields init_settings_iframe() in js/app.ts posts to the settings page
	 *
	 * Read from the source, so this follows what we really send
	 *
	 * @return string[]
	 */
	protected function postedFields() : array
	{
		$source = file_get_contents(__DIR__ . '/../js/app.ts');
		$start = strpos($source, 'init_settings_iframe(');
		$this->assertNotFalse($start, 'init_settings_iframe() is gone from js/app.ts');
		$method = substr($source, $start, strpos($source, "\n\t}\n", $start) - $start);
		preg_match_all('/name:\s*"(\w+)"/', $method, $matches);
		$this->assertNotEmpty($matches[1], 'init_settings_iframe() posts no fields, or js/app.ts changed its form: ' . $method);
		return $matches[1];
	}

	/**
	 * Without this Collabora does not know the settings page at all
	 */
	public function testCapabilitiesAdvertiseTheSettingsPage()
	{
		[$status, , $body] = $this->ask($this->server . '/hosting/capabilities');
		$this->assertEquals(200, $status, 'Capabilities of the Collabora server are gone');
		$capabilities = json_decode($body, true);
		$this->assertIsArray($capabilities, "Capabilities are not JSON any more: $body");
		$this->assertTrue(
			$capabilities['hasSettingIframeSupport'] ?? false,
			'This Collabora has no settings page (hasSettingIframeSupport) - ' . ($capabilities['productVersion'] ?? '?')
		);
	}

	/**
	 * Bo::settings() takes the URL of the settings page from the discovery
	 */
	public function testDiscoveryNamesTheSettingsPage()
	{
		$settings = Bo::settings($this->server);
		$this->assertNotEmpty($settings['iframe'] ?? '',
			'The discovery has no Settings > iframe action, Bo::discover() reads //net-zone/app[@name="Settings"]/action: ' . json_encode($settings));
		$this->assertMatchesRegularExpression('#^https?://.+/adminIntegratorSettings\.html$#', $settings['iframe']);
	}

	/**
	 * The page answers the form we post to it, and brings its script
	 */
	public function testSettingsPageAcceptsOurForm()
	{
		$fields = $this->postedFields();
		foreach(['access_token', 'wopi_setting_base_url', 'iframe_type'] as $required)
		{
			$this->assertContains($required, $fields, "We no longer post $required to the settings page");
		}
		$post = array_fill_keys($fields, 'x');
		$post['iframe_type'] = 'user';

		[$status, $type, $body] = $this->ask(Bo::settings($this->server)['iframe'], $post);

		$this->assertEquals(200, $status, 'The settings page did not take our form');
		$this->assertStringContainsString('text/html', $type);
		$this->assertMatchesRegularExpression('#<script[^>]+src="[^"]*admin-bundle\.js"#', $body,
			'The settings page does not load admin-bundle.js, so the types of setting it writes can not be read');
	}

	/**
	 * Get the script of the settings page
	 */
	protected function settingsBundle() : string
	{
		$iframe = Bo::settings($this->server)['iframe'];
		[, , $page] = $this->ask($iframe, array_fill_keys($this->postedFields(), 'x'));
		$this->assertEquals(1, preg_match('#<script[^>]+src="([^"]*admin-bundle\.js)"#', $page, $src), 'No admin-bundle.js on the settings page');
		$url = parse_url($iframe, PHP_URL_SCHEME) . '://' . parse_url($iframe, PHP_URL_HOST) .
			(($port = parse_url($iframe, PHP_URL_PORT)) ? ':' . $port : '') . $src[1];
		[$status, , $js] = $this->ask($url);
		$this->assertEquals(200, $status, "Could not get $url");
		return $js;
	}

	/**
	 * Every type of setting file Collabora writes has to be one we accept.  A type we do not know is
	 * refused with an error in Collabora's settings page, so when Collabora adds one, this tells us which.
	 */
	public function testEverySettingTypeCollaboraWritesIsAccepted()
	{
		$js = $this->settingsBundle();

		preg_match_all('#settingConfigBasePath\(\)\s*\+\s*"/([A-Za-z]+)/?"#', $js, $matches);
		$types = array_values(array_unique($matches[1]));
		sort($types);

		$this->assertContains('wordbook', $types,
			'Found no wordbook upload in admin-bundle.js, it looks different now, so look at what it writes: ' . json_encode($types));
		$this->assertEquals([], array_values(array_diff($types, Settings::SETTING_DIRECTORIES)),
			'Collabora writes types of setting we do not accept (Settings::SETTING_DIRECTORIES)');
	}

	/**
	 * The lists Collabora shows are filled from the keys of what we answer: they are the names of the directories
	 */
	public function testCollaboraListsSettingsUnderTheNameOfTheirDirectory()
	{
		$js = $this->settingsBundle();

		preg_match_all('#populateList\("(\w+)",\s*\w+\.(\w+),\s*"/(\w+)"#', $js, $matches, PREG_SET_ORDER);
		$this->assertNotEmpty($matches, 'Found no populateList() in admin-bundle.js, it looks different now');
		foreach($matches as [, $list, $key, $directory])
		{
			$this->assertEquals($directory, $key, "$list is filled from .$key but written to /$directory: our answer has one key per directory");
			$this->assertContains($directory, Settings::SETTING_DIRECTORIES);
		}
	}

	/**
	 * The ids we get ("/settings/<type>/<setting>/<file>") and the two types of config, as Settings::delete()
	 * and upload() split them
	 */
	public function testSettingIdsAndConfigTypesAreWhatWeParse()
	{
		$js = $this->settingsBundle();

		$this->assertStringContainsString('"/settings/"+this.getConfigType()', str_replace(' ', '', $js),
			'The id of a setting is not "/settings/<config type>/..." any more');
		foreach(['userconfig', 'systemconfig'] as $type)
		{
			$this->assertStringContainsString('"' . $type . '"', $js, "Collabora does not use the config type $type any more");
		}
	}
}
