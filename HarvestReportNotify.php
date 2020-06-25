#!/usr/bin/env php
<?php
define('DEBUG_START', microtime(true));

/**
 * use
 * Class HarvestClient
 * Make api token https://id.getharvest.com/developers
 * @version 1.0.0
 */
class HarvestReportNotify {

	public const DATE_FORMAT = 'Y-m-d';

	private const HARVEST_API_BASE_URI = 'https://api.harvestapp.com/v2/';
	private const HARVEST_ENDPOINT = 'time_entries';
	private const HARVEST_ACCOUNT_ID = '';
	private const HARVEST_ACCESS_TOKEN = '';

	private const TELEGRAM_API_BASE_URI = 'https://api.telegram.org';
	private const TELEGRAM_ENDPOINT = 'sendMessage';
    private const TELEGRAM_BOT_TOKEN = '';
    private const TELEGRAM_CHAT_ID = '';

	public static function instance(){
		return new static;
	}

	public function getTimeEntries($from = null, $to = null)
	{
		$params = [
			'access_token' => self::HARVEST_ACCESS_TOKEN,
			'account_id'   => self::HARVEST_ACCOUNT_ID,
			'from'         => $from ?? date(self::DATE_FORMAT),
			'to'           => $to ?? date(self::DATE_FORMAT),
		];
		$api_url = self::HARVEST_API_BASE_URI.self::HARVEST_ENDPOINT.'?'.http_build_query($params);

		return self::sendRequest($api_url);
	}

	/**
	 * @param null $from
	 * @param null $to
	 * https://api.harvestapp.com/v2/time_entries
	 */
	public function sendTimeEntriesToTelegram($from = null, $to = null)
	{
		$time_entries = $this->getTimeEntries($from, $to);
		$time_entries_data = \json_decode($time_entries['data'], true);
		$time_entries_items = $time_entries_data['time_entries'];

		$text = '#TimeReport from ' . $from . ' to ' . $to . PHP_EOL.PHP_EOL;
		$total_hours = 0;
		$total_rounded_hours = 0;
		foreach ($time_entries_items as $item) {
			$external_reference_link = !empty($item['external_reference']) ?
				' | [' . $item['external_reference']['service'] . '](' . $item['external_reference']['permalink'] . ')'
				: null;
			/**
			 * spent_date
			 */
			$total_hours += $item['hours'];
			$total_rounded_hours += $item['rounded_hours'];
			$text .= '+ ' . $item['notes'] . $external_reference_link . ' — ' . $item['hours'] . PHP_EOL;
		}

		$text .= PHP_EOL;
		$text .= '*Total hours:* ' . $total_hours . 'h' . PHP_EOL;
		$text .= '*Total rounded hours:* ' . $total_rounded_hours . 'h' . PHP_EOL;

		self::sendToTelegram($text);
	}

	private static function sendToTelegram($text = '', $chat_id = null)
	{
		$params = [
			'chat_id'                  => $chat_id ??self::TELEGRAM_CHAT_ID,
			'text'                     => $text,
			'parse_mode'               => 'Markdown',
			'disable_web_page_preview' => true,
		];

		$apiUrl = sprintf('%s/bot%s/%s', self::TELEGRAM_API_BASE_URI, self::TELEGRAM_BOT_TOKEN, self::TELEGRAM_ENDPOINT);
		$headers = [
			'Content-type' => 'application/json'
		];

		self::sendRequest($apiUrl, 'POST', $params, $headers);
	}

	/**
	 * @param $url
	 * @param string $method
	 * @param array $params
	 * @param array $headers
	 * @param int $pause_time
	 * @param int $retry
	 * @param $referer_url
	 * @return mixed
	 */
	private static function sendRequest($url, $method = 'GET', array $params = [], array $headers = [], $pause_time = 3, $retry = 1, $referer_url = '') {
		try {

			/**
			 * $url - адрес страницы-источника
			 * $referer_url - адрес страницы для поля REFERER
			 * $pause_time - пауза между попытками парсинга
			 * $retry - 0 - не повторять запрос, 1 - повторить запрос при неудаче
			 */
			$error_page = array();
			$ch = \curl_init();

			$method = strtoupper($method);
			switch ($method) {
				case 'POST':
					$headers[] = 'Content-Type: application/json';
					\curl_setopt($ch, CURLOPT_POST, true);
					\curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
					break;
			}

			\curl_setopt($ch, CURLOPT_USERAGENT, "Harvest API Example");
			\curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Автоматом идём по редиректам
			\curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Не проверять SSL сертификат
			\curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Не проверять Host SSL сертификата
			\curl_setopt($ch, CURLOPT_URL, $url);             // Куда отправляем
			\curl_setopt($ch, CURLOPT_REFERER, $referer_url); // Откуда пришли
			\curl_setopt($ch, CURLOPT_HEADER, $headers);      // headers
			\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  // Возвращаем, но не выводим на экран результат
			$info = \curl_getinfo($ch);
			if ($info['http_code'] != 200 && $info['http_code'] != 404) {
				$error_page[] = array(1, $url, $info['http_code']);
				if ($retry) {
					sleep($pause_time);
					$response['data'] = \curl_exec($ch);
					$info = \curl_getinfo($ch);
					if ($info['http_code'] != 200 && $info['http_code'] != 404)
						$error_page[] = array(2, $url, $info['http_code']);
				}
			} else {
				$response['data'] = \curl_exec($ch);
//            $response['data'] = \substr(\curl_exec($ch), \curl_getinfo($ch, CURLINFO_HEADER_SIZE)); // crop body
			}

			$response['code'] = $info['http_code'];
			$response['errors'] = $error_page;

			\curl_close($ch);
			return $response;
		} catch (\Throwable $exception)
		{
			throw new \RuntimeException($exception->getMessage(), $exception->getCode());
		}
	}

	/**
	 * @param $seconds
	 *
	 * @return string
	 */
	public static function formatDuration($seconds)
	{
		if ($seconds < 0.001) {
			return round($seconds * 1000000).'μs';
		} elseif ($seconds < 1) {
			return round($seconds * 1000, 2).'ms';
		}

		return round($seconds, 2).'s';
	}

}

$shortopts  = "";
$shortopts .= "f::";
$shortopts .= "t::";
$cli_options = getopt($shortopts);

$from = $cli_options['f'] ?? null;
$to = $cli_options['t'] ?? null;

HarvestReportNotify::instance()->sendTimeEntriesToTelegram($from, $to);

$duration = HarvestReportNotify::formatDuration(microtime(true) - DEBUG_START);

var_dump($duration);

exit();
