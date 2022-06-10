<?php

namespace EGroupware\Collabora;

use EGroupware\Api\Exception\NotFound;
use EGroupware\Api\Json\Response;
use EGroupware\Api\MimeMagic;
use EGroupware\Api\Vfs;

class Conversion
{

	const CONVERT_ENDPOINT = '/convert-to/';

	public $public_functions = array(
		'ajax_convert' => true
	);

	/**
	 * Convert the specified file to a different file format.
	 *
	 * Uses the Collabora conversion API to convert the file to a different format
	 * @see https://sdk.collaboraonline.com/docs/conversion_api.html
	 * We use the same filename in the same location
	 *
	 * @param $file
	 * @param string $to
	 *
	 * @return boolean Success
	 */
	public function ajax_convert($file = '', $to_format = 'pdf')
	{
		$destination = '';
		$error_message = '';
		$result = $this->convert($file, $destination, $to_format, $error_message);
		$data = array(
			'success'        => $result,
			'error_message'  => $error_message,
			'original_path'  => $file,
			'converted_path' => $destination
		);
		Response::get()->data($data);
	}

	/**
	 * @param string $file
	 * @param string& $destination
	 * @param string $to_format extension, eg. 'pdf'
	 * @param string& $error_message
	 * @param bool $use_vfs true: use EGroupware VFS (eg. add Vfs::PREFIX), false: use regular filesystem eg. /tmp
	 * @return bool
	 * @throws NotFound
	 * @throws Vfs\Exception\ProtectedDirectory
	 * @throws \EGroupware\Api\Exception\AssertionFailed
	 * @throws \EGroupware\Api\Exception\WrongParameter
	 */
	public function convert($file, &$destination, $to_format, &$error_message, $use_vfs=true)
	{
		if ($use_vfs && (!Vfs::file_exists($file) || !Vfs::is_readable($file)) ||
			!$use_vfs && (!file_exists($file) || !is_readable($file)))
		{
			throw new NotFound("Entry not found: $file");
		}

		$destination_path = Vfs::dirname($file);
		$filename = Vfs::basename($file);
		$parts = explode('.', $filename);
		array_pop($parts);
		$destination = Vfs::make_unique($destination_path . '/' . implode('.', $parts) . '.' . $to_format);
		$server = Bo::get_server();
		$discovery = Bo::discover($server);
		// Coolabora 21.11+ uses /cool instead of /lool
		$cool = (strpos(current($discovery)['urlsrc'], '/loleaflet/') !== false ? '/lool' : '/cool');
		$url = $server . $cool . self::CONVERT_ENDPOINT . $to_format;

		/* Stream context options */
		/* Not working
		$options = array('http' =>
							 array(
								 'method'  => 'POST',
								 'header'  => [
									 'Content-Type: multipart/form-data; boundary=ConvertBoundary',
									 'Content-Disposition: form-data; name="data"; filename="' . $filename . '"',
									 'Content-Type: ' . Vfs::mime_content_type(Vfs::PREFIX . $file) . "\r\n\r\n"
								 ],
								 'content' => "--ConvertBoundary\r\n" . file_get_contents(Vfs::PREFIX . $file) . "\r\n--boundary--\r\n"
							 ));
		$context = stream_context_create($options);
		$result = file_put_contents(Vfs::PREFIX . $destination, fopen($url, 'rb', true, $context));
		*/

		$curl_session = curl_init();
		$curl_data = ['data' => new \CURLFile(($use_vfs ? Vfs::PREFIX : '') . $file,
			$use_vfs ? Vfs::mime_content_type($file) : MimeMagic::filename2mime($file), $filename)];

		curl_setopt($curl_session, CURLOPT_URL, $url);
		curl_setopt($curl_session, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl_session, CURLOPT_POST, 1);
		curl_setopt($curl_session, CURLOPT_POSTFIELDS, $curl_data);
		curl_setopt($curl_session, CURLOPT_HEADER, false);
		$result = file_put_contents(($use_vfs ? Vfs::PREFIX : '') . $destination, curl_exec($curl_session));
		if(curl_errno($curl_session))
		{
			$error_message = curl_error($curl_session);
		}
		curl_close($curl_session);

		if(!$result)
		{
			$use_vfs ? Vfs::remove($destination) : unlink($destination);
		}
		return $result > 0;
	}
}