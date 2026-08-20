<?php
class ModelExtensionPaymentRobokassa extends Model {
    public function getMethod($address, $total) {
        $this->load->language('extension/payment/robokassa');

        if ($this->config->get('payment_robokassa_status')) {
            $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_robokassa_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

            if (!$this->config->get('payment_robokassa_geo_zone_id')) {
                $status = TRUE;
            } elseif ($query->num_rows) {
                $status = TRUE;
            } else {
                $status = FALSE;
            }
        } else {
            $status = FALSE;
        }

        $method_data = array();

        if ($status) {
            $method_data = array(
                'code'       => 'robokassa',
                'title'      => $this->language->get('text_title'),
                'terms'      => '',
                'sort_order' => $this->config->get('payment_robokassa_sort_order')
            );
        }
        return $method_data;
    }

    // Подготовка строки после кодирования в base64
    protected static function formatSignFinish($string)
    {
        return \preg_replace('/^(.*?)(=*)$/', '$1', $string);
    }

    // Товары заказа
    public function getOrderProducts($order_id) {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$order_id . "'");

        return $query->rows;
    }

    // shipping
    public function getTotalShipping($order_id, $code = 'shipping') {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$order_id . "' AND code = '" . $code . "'");

        return $query->row;
    }

    private function ensureMarkingTables()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "robokassa_product_marking` (
            `product_id` INT(11) NOT NULL,
            `marking_required` TINYINT(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "robokassa_order_product_marking` (
            `order_product_id` INT(11) NOT NULL,
            `unit_index` INT(11) NOT NULL,
            `nomenclature_code` VARBINARY(255) NOT NULL,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`order_product_id`, `unit_index`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "robokassa_second_receipt` (
            `receipt_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT(11) NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'new',
            `response` TEXT,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`receipt_id`),
            UNIQUE KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1000000000000");
    }

    private function isProductMarkingRequired($product_id)
    {
        if (!$this->config->get('payment_robokassa_marking') || $this->config->get('payment_robokassa_country') !== 'RUB') {
            return false;
        }

        $query = $this->db->query("SELECT `marking_required` FROM `" . DB_PREFIX . "robokassa_product_marking` WHERE `product_id` = '" . (int)$product_id . "'");

        return $query->num_rows && (bool)$query->row['marking_required'];
    }

    private function getOrderProductMarkingCodes($order_product_id)
    {
        $query = $this->db->query("SELECT `unit_index`, `nomenclature_code` FROM `" . DB_PREFIX . "robokassa_order_product_marking` WHERE `order_product_id` = '" . (int)$order_product_id . "' ORDER BY `unit_index`");
        $codes = array();

        foreach ($query->rows as $row) {
            $codes[(int)$row['unit_index']] = $row['nomenclature_code'];
        }

        return $codes;
    }

    private function validateNomenclatureCode($code)
    {
        return $code !== ''
            && strlen($code) <= 255
            && !preg_match('/[^\x1D\x20-\x7E]/', $code)
            && trim(str_replace(chr(29), '', $code)) !== '';
    }

    private function buildSecondReceiptProductItems(array $product)
    {
        $quantity = (int)$product['quantity'];
        $total = (float)$this->currency->format($product['price'] * $quantity, 'RUB', false, false);
        $base_item = array(
            'name' => utf8_substr(trim(htmlspecialchars($product['name'])), 0, 63),
            'tax' => $this->config->get('payment_robokassa_tax'),
            'payment_method' => 'full_payment',
            'payment_object' => $this->config->get('payment_robokassa_payment_object')
        );

        if (!$this->isProductMarkingRequired($product['product_id'])) {
            return array(array_merge($base_item, array('quantity' => $quantity, 'sum' => $total)));
        }

        $codes = $this->getOrderProductMarkingCodes($product['order_product_id']);
        if (count($codes) !== $quantity) {
            throw new Exception(sprintf('Не заполнены коды маркировки для товара «%s»: требуется %d.', $product['name'], $quantity));
        }

        $items = array();
        $allocated = 0.0;
        $unit_sum = round($total / $quantity, 2);
        $seen = array();

        for ($unit_index = 1; $unit_index <= $quantity; $unit_index++) {
            $code = isset($codes[$unit_index]) ? $codes[$unit_index] : '';
            $fingerprint = hash('sha256', $code);

            if (!$this->validateNomenclatureCode($code)) {
                throw new Exception(sprintf('Код маркировки #%d товара «%s» имеет неверный формат.', $unit_index, $product['name']));
            }

            if (isset($seen[$fingerprint])) {
                throw new Exception(sprintf('Для товара «%s» сохранены одинаковые коды маркировки.', $product['name']));
            }

            $seen[$fingerprint] = true;
            $item_sum = ($unit_index === $quantity) ? round($total - $allocated, 2) : $unit_sum;
            $allocated += $item_sum;

            $items[] = array_merge($base_item, array(
                'quantity' => 1,
                'sum' => $item_sum,
                'payment_object' => 'tovar_mark',
                'nomenclature_code' => $code
            ));
        }

        return $items;
    }

    private function getSecondReceipt($order_id)
    {
        $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "robokassa_second_receipt` SET `order_id` = '" . (int)$order_id . "', `status` = 'new', `date_added` = NOW(), `date_modified` = NOW()");
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "robokassa_second_receipt` WHERE `order_id` = '" . (int)$order_id . "'");

        return $query->row;
    }

    private function updateSecondReceipt($order_id, $status, $response)
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "robokassa_second_receipt` SET `status` = '" . $this->db->escape($status) . "', `response` = '" . $this->db->escape($response) . "', `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
    }

    // Отправка второго чека
    public function sendSecondCheck($order_id)
    {
        $this->ensureMarkingTables();
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($order_id);

        if (!$order) {
            throw new Exception('Заказ для второго чека не найден.');
        }

        $receipt = $this->getSecondReceipt($order_id);
        if ($receipt['status'] === 'succeeded') {
            return true;
        }

        if ($receipt['status'] === 'pending' || $receipt['status'] === 'unknown') {
            throw new Exception('Статус предыдущей отправки второго чека неизвестен. Проверьте чек в личном кабинете Robokassa.');
        }

        $fields = array(
            'merchantId' => $this->config->get('payment_robokassa_login'),
            'id' => (string)$receipt['receipt_id'],
            'originId' => (int)$order_id,
            'operation' => 'sell',
            'sno' => $this->config->get('payment_robokassa_tax_type'),
            'url' => $this->config->get('config_ssl') ?: $this->config->get('config_url'),
            'total' => (float)$order['total'],
            'items' => array(),
            'client' => array('email' => $order['email'], 'phone' => $order['telephone']),
            'payments' => array(array('type' => 2, 'sum' => (float)$order['total']))
        );

        foreach ($this->getOrderProducts($order_id) as $product) {
            $fields['items'] = array_merge($fields['items'], $this->buildSecondReceiptProductItems($product));
        }

        $seen_codes = array();
        foreach ($fields['items'] as $item) {
            if (!isset($item['nomenclature_code'])) {
                continue;
            }

            $fingerprint = hash('sha256', $item['nomenclature_code']);
            if (isset($seen_codes[$fingerprint])) {
                throw new Exception('В заказе сохранены одинаковые коды маркировки.');
            }
            $seen_codes[$fingerprint] = true;
        }

        $shipping = $this->getTotalShipping($order_id);
        if (!empty($shipping) && (float)$shipping['value'] > 0) {
            $fields['items'][] = array(
                'name' => utf8_substr(trim(htmlspecialchars($shipping['title'])), 0, 63),
                'quantity' => 1,
                'sum' => (float)$this->currency->format($shipping['value'], 'RUB', false, false),
                'tax' => $this->config->get('payment_robokassa_tax'),
                'payment_method' => 'full_payment',
                'payment_object' => $this->config->get('payment_robokassa_payment_object')
            );
        }

        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception('Не удалось сформировать данные второго чека.');
        }

        $startupHash = $this->formatSignFinish(base64_encode($json));
        $password = $this->config->get('payment_robokassa_test') == 1
            ? $this->config->get('payment_robokassa_test_password_1')
            : $this->config->get('payment_robokassa_password_1');
        $sign = $this->formatSignFinish(base64_encode(md5($startupHash . $password)));
        $body = $startupHash . '.' . $sign;

        $this->updateSecondReceipt($order_id, 'pending', '');

        $curl = curl_init('https://ws.roboxchange.com/RoboFiscal/Receipt/Attach');
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($body)));
        $result = curl_exec($curl);
        $curl_error = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($result === false || $http_code < 200 || $http_code >= 300) {
            $this->updateSecondReceipt($order_id, 'unknown', $curl_error ?: ('HTTP ' . $http_code));
            throw new Exception('Robokassa не подтвердила получение второго чека. Проверьте его статус в личном кабинете.');
        }

        $response = json_decode($result, true);
        if (!is_array($response) || !isset($response['ResultCode']) || (string)$response['ResultCode'] !== '0') {
            $description = is_array($response) && !empty($response['ResultDescription']) ? strip_tags($response['ResultDescription']) : 'Некорректный ответ сервиса';
            $this->updateSecondReceipt($order_id, 'failed', $result);
            throw new Exception('Robokassa отклонила второй чек: ' . $description);
        }

        $this->updateSecondReceipt($order_id, 'succeeded', $result);

        return true;
    }

    function robokassa_hold_confirm($order_id)
    {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($order_id);

        if (!$order) {
            return false;
        }

        $products = $this->getOrderProducts($order_id);

        $receipt_items = array();
        foreach ($products as $product) {
            $price = $this->currency->format(($product['price']) * $product['quantity'], 'RUB', false, false);
            $receipt_items[] = array(
                'name' => utf8_substr(trim(htmlspecialchars($product['name'])), 0, 63),
                'quantity' => $product['quantity'],
                'sum' => $price,
                'tax' => $this->config->get('payment_robokassa_tax'),
                'payment_method' => 'full_prepayment',
                'payment_object' => $this->config->get('payment_robokassa_payment_object'),
            );
        }

        $shipping = $this->getTotalShipping($order_id);
        // Проверяем, есть ли доставка
        if (is_array($shipping) && isset($shipping['title'], $shipping['value'])) {
            $shipping_name = $shipping['title'];
            $shipping_price = $shipping['value'];

            // Добавляем данные о доставке в чек, если цена доставки больше 0
            if ($shipping_price > 0) {
                $receipt_items[] = array(
                    'name' => utf8_substr(trim(htmlspecialchars($shipping_name)), 0, 63),
                    'quantity' => 1,
                    'sum' => $this->currency->format($shipping_price, 'RUB', false, false),
                    'tax' => $this->config->get('payment_robokassa_tax'),
                    'payment_method' => 'full_prepayment',
                    'payment_object' => $this->config->get('payment_robokassa_payment_object'),
                );
            }
        } else {
            // Если доставка отсутствует, устанавливаем значения по умолчанию
            $shipping_name = '';
            $shipping_price = 0;
        }

        $request_data = array(
            'MerchantLogin' => $this->config->get('payment_robokassa_login'),
            'InvoiceID' => $order_id,
            'OutSum' => $order['total'],
            'Receipt' => json_encode(array('items' => $receipt_items)),
        );

        $merchant_login = (string)$this->config->get('payment_robokassa_login');
        $password1 = $this->config->get('payment_robokassa_test')
            ? (string)$this->config->get('payment_robokassa_test_password_1')
            : (string)$this->config->get('payment_robokassa_password_1');

        if ($merchant_login === '' || $password1 === '') {
            return false;
        }

        $signature_value = md5("{$merchant_login}:{$request_data['OutSum']}:{$request_data['InvoiceID']}:{$request_data['Receipt']}:{$password1}");
        $request_data['SignatureValue'] = $signature_value;


        $url = 'https://auth.robokassa.ru/Merchant/Payment/Confirm';
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($request_data),
        ));
        $response = curl_exec($curl);
        $curl_error = curl_errno($curl);
        $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return !$curl_error && is_string($response) && $http_code >= 200 && $http_code < 400;
    }

    function robokassa_hold_cancel($order_id)
    {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($order_id);

        if (!$order) {
            return false;
        }

        $request_data = array(
            'MerchantLogin' => $this->config->get('payment_robokassa_login'),
            'InvoiceID' => $order_id,
            'OutSum' => $order['total'],
        );

        $merchant_login = (string)$this->config->get('payment_robokassa_login');
        $password1 = $this->config->get('payment_robokassa_test')
            ? (string)$this->config->get('payment_robokassa_test_password_1')
            : (string)$this->config->get('payment_robokassa_password_1');

        if ($merchant_login === '' || $password1 === '') {
            return false;
        }

        $signature_value = md5("{$merchant_login}::{$request_data['InvoiceID']}:{$password1}");
        $request_data['SignatureValue'] = $signature_value;

        $url = 'https://auth.robokassa.ru/Merchant/Payment/Cancel';
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($request_data),
        ));
        $response = curl_exec($curl);
        $curl_error = curl_errno($curl);
        $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return !$curl_error && is_string($response) && $http_code >= 200 && $http_code < 400;
    }

}
