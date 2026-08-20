<?php

class RobokassaRefundApi
{
    const CREATE_URL = 'https://services.robokassa.ru/RefundService/Refund/Create';
    const STATE_URL = 'https://services.robokassa.ru/RefundService/Refund/GetState';
    const OP_STATE_URL = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/OpStateExt';

    private $merchant_login;
    private $password_2;
    private $password_3;

    public function __construct($merchant_login, $password_2, $password_3)
    {
        $this->merchant_login = (string)$merchant_login;
        $this->password_2 = (string)$password_2;
        $this->password_3 = (string)$password_3;
    }

    public function create($operation_key, $amount = null, array $invoice_items = array())
    {
        if ($this->password_3 === '') {
            return $this->error('Не указан Пароль #3 для API возвратов Robokassa.', false, 0);
        }

        $payload = array('OpKey' => (string)$operation_key);

        if ($amount !== null) {
            $payload['RefundSum'] = (float)$amount;
        }

        if ($invoice_items) {
            $payload['InvoiceItems'] = array_values($invoice_items);
        }

        $token = $this->buildToken($payload);

        if ($token === false) {
            return $this->error('Не удалось сформировать JWT возврата Robokassa.', false, 0);
        }

        $response = $this->request('POST', self::CREATE_URL, json_encode($token), array(
            'Accept: application/json',
            'Content-Type: application/json'
        ), 30);

        return $this->decodeJsonResponse($response, true);
    }

    public function getState($request_id)
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$request_id)) {
            return $this->error('Некорректный ID заявки на возврат.', false, 0);
        }

        $response = $this->request('GET', self::STATE_URL . '?id=' . rawurlencode((string)$request_id), null, array(
            'Accept: application/json'
        ), 15);

        return $this->decodeJsonResponse($response, false);
    }

    public function getOperationKey($invoice_id)
    {
        if ($this->merchant_login === '' || $this->password_2 === '') {
            return $this->error('Не заданы логин магазина или Пароль #2 Robokassa.', false, 0);
        }

        $signature = strtoupper(md5($this->merchant_login . ':' . (int)$invoice_id . ':' . $this->password_2));
        $url = self::OP_STATE_URL . '?' . http_build_query(array(
            'MerchantLogin' => $this->merchant_login,
            'InvoiceID' => (int)$invoice_id,
            'Signature' => $signature
        ));
        $response = $this->request('GET', $url, null, array('Accept: text/xml'), 15);

        if (!$response['transport_success'] || $response['http_code'] < 200 || $response['http_code'] >= 300 || trim($response['body']) === '') {
            return $this->error('Robokassa не вернула данные оплаченной операции.', true, $response['http_code'], $response['error']);
        }

        if (!function_exists('simplexml_load_string')) {
            return $this->error('Расширение SimpleXML недоступно.', false, $response['http_code']);
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response['body'], 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return $this->error('Robokassa вернула некорректный ответ OpStateExt.', false, $response['http_code']);
        }

        $result_codes = $xml->xpath('//*[local-name()="Result"]/*[local-name()="Code"]');

        if (!empty($result_codes) && (string)$result_codes[0] !== '0') {
            $descriptions = $xml->xpath('//*[local-name()="Result"]/*[local-name()="Description"]');
            return $this->error(!empty($descriptions) ? (string)$descriptions[0] : 'Операция Robokassa не найдена.', false, $response['http_code']);
        }

        $state_codes = $xml->xpath('//*[local-name()="State"]/*[local-name()="Code"]');
        $state_code = !empty($state_codes) ? (int)$state_codes[0] : 0;

        if ($state_code !== 100) {
            return $this->error('Возврат доступен только для завершённой операции Robokassa. Текущий код состояния: ' . $state_code . '.', false, $response['http_code']);
        }

        $operation_keys = $xml->xpath('//*[local-name()="OpKey"]');
        $operation_key = !empty($operation_keys) ? trim((string)$operation_keys[0]) : '';

        if ($operation_key === '') {
            return $this->error('Robokassa не вернула OpKey оплаченной операции.', false, $response['http_code']);
        }

        return array('success' => true, 'operation_key' => $operation_key, 'http_code' => $response['http_code']);
    }

    public function buildToken(array $payload)
    {
        $header_json = json_encode(array('alg' => 'HS256', 'typ' => 'JWT'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload_json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($header_json === false || $payload_json === false) {
            return false;
        }

        $segments = array($this->base64UrlEncode($header_json), $this->base64UrlEncode($payload_json));
        $signing_input = implode('.', $segments);
        $segments[] = $this->base64UrlEncode(hash_hmac('sha256', $signing_input, $this->password_3, true));

        return implode('.', $segments);
    }

    protected function request($method, $url, $body = null, array $headers = array(), $timeout = 15)
    {
        if (!function_exists('curl_init')) {
            return array('transport_success' => false, 'http_code' => 0, 'body' => '', 'error' => 'Расширение cURL недоступно.');
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, (int)$timeout);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $request_host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $robokassa_root_r46 = __DIR__ . '/certs/globalsign-root-r46.pem';

        if ($request_host === 'services.robokassa.ru' && is_readable($robokassa_root_r46)) {
            curl_setopt($curl, CURLOPT_CAINFO, $robokassa_root_r46);

            if (is_dir('/etc/ssl/certs')) {
                curl_setopt($curl, CURLOPT_CAPATH, '/etc/ssl/certs');
            }
        } elseif (trim((string)ini_get('curl.cainfo')) === '') {
            foreach (array(
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/pki/tls/certs/ca-bundle.crt',
                '/etc/ssl/ca-bundle.pem'
            ) as $ca_bundle) {
                if (is_readable($ca_bundle)) {
                    curl_setopt($curl, CURLOPT_CAINFO, $ca_bundle);
                    break;
                }
            }
        }

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response_body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return array(
            'transport_success' => $response_body !== false,
            'http_code' => $http_code,
            'body' => $response_body === false ? '' : (string)$response_body,
            'error' => $error,
            'errno' => $errno
        );
    }

    private function decodeJsonResponse(array $response, $submission)
    {
        if (!$response['transport_success']) {
            $errno = isset($response['errno']) ? (int)$response['errno'] : 0;
            $definitely_not_sent = in_array($errno, array(
                defined('CURLE_UNSUPPORTED_PROTOCOL') ? CURLE_UNSUPPORTED_PROTOCOL : 1,
                defined('CURLE_URL_MALFORMAT') ? CURLE_URL_MALFORMAT : 3,
                defined('CURLE_COULDNT_RESOLVE_HOST') ? CURLE_COULDNT_RESOLVE_HOST : 6,
                defined('CURLE_COULDNT_CONNECT') ? CURLE_COULDNT_CONNECT : 7,
                defined('CURLE_SSL_CONNECT_ERROR') ? CURLE_SSL_CONNECT_ERROR : 35,
                defined('CURLE_SSL_CERTPROBLEM') ? CURLE_SSL_CERTPROBLEM : 58,
                defined('CURLE_PEER_FAILED_VERIFICATION') ? CURLE_PEER_FAILED_VERIFICATION : 60
            ), true);
            $uncertain = (bool)$submission && !$definitely_not_sent;

            return $this->error($response['error'] ?: 'Сетевая ошибка при обращении к Robokassa.', $uncertain, 0, $response['error']);
        }

        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            $uncertain = $submission && ($response['http_code'] === 0 || $response['http_code'] >= 500 || ($response['http_code'] >= 200 && $response['http_code'] < 300));
            return $this->error('Robokassa вернула некорректный JSON-ответ.', $uncertain, $response['http_code'], $response['body']);
        }

        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            $message = !empty($data['message']) ? (string)$data['message'] : 'HTTP ' . $response['http_code'];
            return $this->error('Ошибка API возвратов Robokassa: ' . $message, $submission && $response['http_code'] >= 500, $response['http_code'], $response['body']);
        }

        return array('success' => true, 'data' => $data, 'http_code' => $response['http_code'], 'raw' => $response['body']);
    }

    private function error($message, $uncertain, $http_code, $raw = '')
    {
        return array(
            'success' => false,
            'error' => (string)$message,
            'uncertain' => (bool)$uncertain,
            'http_code' => (int)$http_code,
            'raw' => (string)$raw
        );
    }

    private function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
    }
}
