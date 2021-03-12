<?php declare(strict_types=1);

define('DEBUG_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

/**
 * use
 * Class HarvestClient
 * Make api token https://id.getharvest.com/developers
 *
 * @version 1.0.0
 */
class HarvestReportNotify
{
    public const DATE_FORMAT = 'Y-m-d';

    private const HARVEST_API_BASE_URI = 'https://api.harvestapp.com/v2/';
    private const HARVEST_ENDPOINT     = 'time_entries';

    private const TELEGRAM_API_BASE_URI = 'https://api.telegram.org';
    private const TELEGRAM_ENDPOINT     = 'sendMessage';

    private int $harvest_account_id;
    private string $harvest_access_token;
    private string $telegram_bot_token;
    private string $telegram_chat_id;

    public function __construct(array $config)
    {
        $this->harvest_account_id   = $config['harvest_account_id'];
        $this->harvest_access_token = $config['harvest_access_token'];
        $this->telegram_bot_token   = $config['telegram_bot_token'];
        $this->telegram_chat_id     = $config['telegram_chat_id'];
    }

    public function getTimeEntries($from, $to)
    {
        $params  = [
            'access_token' => $this->harvest_access_token,
            'account_id'   => $this->harvest_account_id,
            'from'         => $from,
            'to'           => $to,
        ];
        $api_url = self::HARVEST_API_BASE_URI . self::HARVEST_ENDPOINT . '?' . http_build_query($params);

        return self::sendRequest($api_url);
    }

    /**
     * @param null $from
     * @param null $to
     * https://api.harvestapp.com/v2/time_entries
     */
    public function execute($from = null, $to = null)
    {
        $date_from = $from ?: date(self::DATE_FORMAT);
        $date_to = $to ?: date(self::DATE_FORMAT);

        if($date_from == $date_to) {
            $text = '#TimeReport for ' . $date_from . ' (' . date_create_from_format(self::DATE_FORMAT, $date_from)->format('l') . ')' . PHP_EOL . PHP_EOL;
        }else{
            $text = '#TimeReport from ' . $date_from . ' to ' . $date_to . PHP_EOL . PHP_EOL;
        }

        $time_entries       = $this->getTimeEntries($date_from, $date_to);
        $time_entries_data  = \json_decode($time_entries['data'], true);
        $time_entries_items = $time_entries_data['time_entries'];

        $total_hours         = 0;
        $total_rounded_hours = 0;
        foreach($time_entries_items as $item){
            $external_reference_link = !empty($item['external_reference']) ? ' | <a href="' . $item['external_reference']['permalink'] . '">' . $item['external_reference']['service'] . '</a>' : null;
            /**
             * spent_date
             */
            $total_hours         += $item['hours'];
            $total_rounded_hours += $item['rounded_hours'];
            $text                .= '+ ' . $item['notes'] . $external_reference_link . ' — ' . $item['hours'] . PHP_EOL;
        }

        $text .= PHP_EOL;
        $text .= '<b>Total hours:</b> ' . $total_hours . 'h' . PHP_EOL;
        $text .= '<b>Total rounded hours:</b> ' . $total_rounded_hours . 'h' . PHP_EOL;

        print_r($text);
        $this->sendToTelegram($text);
    }

    private function sendToTelegram(string $text = '')
    {
        $params = [
            'chat_id'                  => $this->telegram_chat_id,
            'text'                     => $text,
            'parse_mode'               => 'html',
            'disable_web_page_preview' => true,
        ];

        $apiUrl  = sprintf('%s/bot%s/%s', self::TELEGRAM_API_BASE_URI, $this->telegram_bot_token, self::TELEGRAM_ENDPOINT);
        $headers = [
            'Content-type' => 'application/json'
        ];

        self::sendRequest($apiUrl, 'POST', $params, $headers);
    }

    /**
     * @param        $url
     * @param string $method
     * @param array  $params
     * @param array  $headers
     * @param int    $pause_time
     * @param int    $retry
     * @param        $referer_url
     *
     * @return mixed
     */
    private static function sendRequest($url, $method = 'GET', array $params = [], array $headers = [], $pause_time = 3, $retry = 1, $referer_url = '')
    {
        try{
            /**
             * $url - адрес страницы-источника
             * $referer_url - адрес страницы для поля REFERER
             * $pause_time - пауза между попытками парсинга
             * $retry - 0 - не повторять запрос, 1 - повторить запрос при неудаче
             */
            $error_page = [];
            $ch         = \curl_init();

            $method = strtoupper($method);
            switch($method){
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
            if($info['http_code'] != 200 && $info['http_code'] != 404) {
                $error_page[] = [
                    1,
                    $url,
                    $info['http_code']
                ];
                if($retry) {
                    sleep($pause_time);
                    $response['data'] = \curl_exec($ch);
                    $info             = \curl_getinfo($ch);
                    if($info['http_code'] != 200 && $info['http_code'] != 404)
                        $error_page[] = [
                            2,
                            $url,
                            $info['http_code']
                        ];
                }
            }else{
                $response['data'] = \curl_exec($ch);
            }

            $response['code']   = $info['http_code'];
            $response['errors'] = $error_page;

            \curl_close($ch);
            return $response;
        } catch(\Throwable $exception){
            throw new \RuntimeException($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * @param $seconds
     *
     * @return string
     */
    public static function formatElapsed($seconds) : string
    {
        if($seconds < 0.001) {
            return round($seconds * 1000000) . 'μs';
        }elseif($seconds < 1){
            return round($seconds * 1000, 2) . 'ms';
        }

        return round($seconds, 2) . 's';
    }

}
date_default_timezone_set($_ENV['TIMEZONE']);

$shortopts   = "";
$shortopts   .= "f::";
$shortopts   .= "t::";
$cli_options = getopt($shortopts);

$from = $cli_options['f'] ?? date(HarvestReportNotify::DATE_FORMAT);
$to   = $cli_options['t'] ?? date(HarvestReportNotify::DATE_FORMAT);

$config = [
    'harvest_account_id'   => (int) $_ENV['HARVEST_ACCOUNT_ID'],
    'harvest_access_token' => $_ENV['HARVEST_ACCESS_TOKEN'],
    'telegram_bot_token'   => $_ENV['TELEGRAM_BOT_TOKEN'],
    'telegram_chat_id'     => $_ENV['TELEGRAM_CHAT_ID'],
];

(new HarvestReportNotify($config))->execute($from, $to);
$duration = HarvestReportNotify::formatElapsed(microtime(true) - DEBUG_START);
print_r("duration: " . $duration . PHP_EOL);
