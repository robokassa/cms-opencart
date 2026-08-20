<?php
namespace Opencart\Admin\Model\Extension\Robokassa\Payment;

class Robokassa extends \Opencart\System\Engine\Model
{
    public function installMarkingTables(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "robokassa_product_marking` (
            `product_id` INT(11) NOT NULL,
            `marking_required` TINYINT(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "robokassa_order_product_marking` (
            `order_product_id` INT(11) NOT NULL,
            `unit_index` INT(11) NOT NULL,
            `nomenclature_code` VARBINARY(255) NOT NULL,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`order_product_id`, `unit_index`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "robokassa_second_receipt` (
            `receipt_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT(11) NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'new',
            `response` TEXT,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`receipt_id`),
            UNIQUE KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000000000000");
    }

    public function isProductMarkingRequired(int $product_id): bool
    {
        $query = $this->db->query("SELECT `marking_required` FROM `" . DB_PREFIX . "robokassa_product_marking` WHERE `product_id` = '" . $product_id . "'");

        return $query->num_rows && (bool)$query->row['marking_required'];
    }

    public function saveProductMarkingRequired(int $product_id, bool $required): void
    {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "robokassa_product_marking` SET `product_id` = '" . $product_id . "', `marking_required` = '" . (int)$required . "'");
    }

    public function getOrderProduct(int $order_product_id): array
    {
        $query = $this->db->query("SELECT op.*, COALESCE(pm.marking_required, 0) AS marking_required
            FROM `" . DB_PREFIX . "order_product` op
            LEFT JOIN `" . DB_PREFIX . "robokassa_product_marking` pm ON (pm.product_id = op.product_id)
            WHERE op.order_product_id = '" . $order_product_id . "'");

        return $query->num_rows ? $query->row : [];
    }

    public function getOrderProductsForMarking(int $order_id): array
    {
        $query = $this->db->query("SELECT op.*, COALESCE(pm.marking_required, 0) AS marking_required
            FROM `" . DB_PREFIX . "order_product` op
            LEFT JOIN `" . DB_PREFIX . "robokassa_product_marking` pm ON (pm.product_id = op.product_id)
            WHERE op.order_id = '" . $order_id . "'
            ORDER BY op.order_product_id ASC");

        foreach ($query->rows as &$product) {
            $product['marking_status'] = (int)$product['marking_required']
                ? $this->getMarkingStatus((int)$product['order_product_id'], (int)$product['quantity'])
                : 'not_required';
        }
        unset($product);

        return $query->rows;
    }

    public function getOrderProductCodes(int $order_product_id): array
    {
        $query = $this->db->query("SELECT `unit_index`, `nomenclature_code` FROM `" . DB_PREFIX . "robokassa_order_product_marking` WHERE `order_product_id` = '" . $order_product_id . "' ORDER BY `unit_index`");
        $codes = [];

        foreach ($query->rows as $row) {
            $codes[(int)$row['unit_index']] = $row['nomenclature_code'];
        }

        return $codes;
    }

    public function saveOrderProductCodes(int $order_product_id, array $codes): void
    {
        $this->db->query('START TRANSACTION');

        try {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "robokassa_order_product_marking` WHERE `order_product_id` = '" . $order_product_id . "'");

            foreach ($codes as $unit_index => $code) {
                if ($code === '') {
                    continue;
                }

                $this->db->query("INSERT INTO `" . DB_PREFIX . "robokassa_order_product_marking` SET
                    `order_product_id` = '" . $order_product_id . "',
                    `unit_index` = '" . (int)$unit_index . "',
                    `nomenclature_code` = '" . $this->db->escape($code) . "',
                    `date_added` = NOW(),
                    `date_modified` = NOW()");
            }

            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }

    public function isCodeUsedInOrder(int $order_product_id, string $code): bool
    {
        $query = $this->db->query("SELECT m.order_product_id
            FROM `" . DB_PREFIX . "robokassa_order_product_marking` m
            INNER JOIN `" . DB_PREFIX . "order_product` other_product ON (other_product.order_product_id = m.order_product_id)
            INNER JOIN `" . DB_PREFIX . "order_product` current_product ON (current_product.order_product_id = '" . $order_product_id . "')
            WHERE other_product.order_id = current_product.order_id
              AND m.order_product_id != current_product.order_product_id
              AND m.nomenclature_code = '" . $this->db->escape($code) . "'
            LIMIT 1");

        return (bool)$query->num_rows;
    }

    public function getMarkingStatus(int $order_product_id, int $quantity): string
    {
        $filled = count($this->getOrderProductCodes($order_product_id));

        if (!$filled) {
            return 'empty';
        }

        return $filled === $quantity ? 'filled' : 'partial';
    }

    public function holdCancel(int $order_id): bool
    {
        $merchant = (string)$this->config->get('payment_robokassa_login');
        $password1 = (int)$this->config->get('payment_robokassa_test')
            ? (string)$this->config->get('payment_robokassa_test_password_1')
            : (string)$this->config->get('payment_robokassa_password_1');

        if ($merchant === '' || $password1 === '') {
            return false;
        }

        $out_sum = $this->getOutSum($order_id);
        $signature = md5($merchant . '::' . $order_id . ':' . $password1);

        $response = $this->post('https://auth.robokassa.ru/Merchant/Payment/Cancel', [
            'MerchantLogin'  => $merchant,
            'OutSum'         => $out_sum,
            'InvoiceID'      => $order_id,
            'SignatureValue' => $signature,
        ]);

        return stripos($response, 'OK') !== false;
    }

    public function holdConfirm(int $order_id): bool
    {
        $merchant = (string)$this->config->get('payment_robokassa_login');
        $password1 = (int)$this->config->get('payment_robokassa_test')
            ? (string)$this->config->get('payment_robokassa_test_password_1')
            : (string)$this->config->get('payment_robokassa_password_1');

        if ($merchant === '' || $password1 === '') {
            return false;
        }

        [$out_sum, $receipt] = $this->buildConfirmReceipt($order_id);

        if ($out_sum === '0.00' || $receipt === '') {
            return false;
        }

        $signature = md5($merchant . ':' . $out_sum . ':' . $order_id . ':' . $receipt . ':' . $password1);
        $response = $this->post('https://auth.robokassa.ru/Merchant/Payment/Confirm', [
            'MerchantLogin'  => $merchant,
            'OutSum'         => $out_sum,
            'InvoiceID'      => $order_id,
            'Receipt'        => $receipt,
            'SignatureValue' => $signature,
        ]);

        return stripos($response, 'OK') !== false;
    }

    public function sendSecondCheck(int $order_id): bool
    {
        if (trim((string)$this->config->get('payment_robokassa_payment_method')) === 'full_payment') {
            return false;
        }

        $this->installMarkingTables();

        $order_query = $this->db->query("SELECT `total`, `email`, `telephone`, `store_url`
            FROM `" . DB_PREFIX . "order`
            WHERE order_id = '" . $order_id . "'
            LIMIT 1");

        if (!$order_query->num_rows) {
            throw new \RuntimeException('Заказ для второго чека не найден.');
        }

        $receipt = $this->getSecondReceipt($order_id);

        if ($receipt['status'] === 'succeeded') {
            return true;
        }

        if ($receipt['status'] === 'pending' || $receipt['status'] === 'unknown') {
            throw new \RuntimeException('Статус предыдущей отправки второго чека неизвестен. Проверьте чек в личном кабинете Robokassa.');
        }

        $order = $order_query->row;
        $items = [];

        $products = $this->db->query("SELECT op.order_product_id, op.product_id, op.name, op.quantity, op.total
            FROM `" . DB_PREFIX . "order_product` op
            WHERE op.order_id = '" . $order_id . "'
            ORDER BY op.order_product_id ASC");

        foreach ($products->rows as $product) {
            $items = array_merge($items, $this->buildSecondReceiptProductItems($product));
        }

        $seen_codes = [];

        foreach ($items as $item) {
            if (!isset($item['nomenclature_code'])) {
                continue;
            }

            $fingerprint = hash('sha256', $item['nomenclature_code']);

            if (isset($seen_codes[$fingerprint])) {
                throw new \RuntimeException('В заказе сохранены одинаковые коды маркировки.');
            }

            $seen_codes[$fingerprint] = true;
        }

        $shipping = $this->db->query("SELECT `title`, `value`
            FROM `" . DB_PREFIX . "order_total`
            WHERE order_id = '" . $order_id . "' AND code = 'shipping'
            ORDER BY sort_order ASC
            LIMIT 1");

        if ($shipping->num_rows && (float)$shipping->row['value'] > 0) {
            $items[] = $this->buildSecondCheckItem(
                (string)$shipping->row['title'],
                (float)$shipping->row['value'],
                1,
                (string)$this->config->get('payment_robokassa_payment_object') ?: 'commodity'
            );
        }

        if (!$items) {
            throw new \RuntimeException('В заказе отсутствуют позиции для второго чека.');
        }

        $fields = [
            'merchantId' => (string)$this->config->get('payment_robokassa_login'),
            'id' => (string)$receipt['receipt_id'],
            'originId' => $order_id,
            'operation' => 'sell',
            'sno' => (string)$this->config->get('payment_robokassa_tax_type'),
            'url' => (string)$order['store_url'],
            'total' => (float)$order['total'],
            'items' => $items,
            'client' => [
                'email' => (string)$order['email'],
                'phone' => (string)$order['telephone']
            ],
            'payments' => [[
                'type' => 2,
                'sum' => (float)$order['total']
            ]],
            'vats' => []
        ];

        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Не удалось сформировать данные второго чека.');
        }

        $startup_hash = $this->encodeFiscalToken($json);
        $password1 = (int)$this->config->get('payment_robokassa_test')
            ? (string)$this->config->get('payment_robokassa_test_password_1')
            : (string)$this->config->get('payment_robokassa_password_1');
        $sign = $this->encodeFiscalToken(md5($startup_hash . $password1));
        $payload = $startup_hash . '.' . $sign;

        $this->updateSecondReceipt($order_id, 'pending', '');

        $ch = curl_init('https://ws.roboxchange.com/RoboFiscal/Receipt/Attach');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $result = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $http_status < 200 || $http_status >= 300) {
            $this->updateSecondReceipt($order_id, 'unknown', $curl_error ?: ('HTTP ' . $http_status));
            throw new \RuntimeException('Robokassa не подтвердила получение второго чека. Проверьте его статус в личном кабинете.');
        }

        $response = json_decode((string)$result, true);

        if (!is_array($response) || !isset($response['ResultCode']) || (string)$response['ResultCode'] !== '0') {
            $description = is_array($response) && !empty($response['ResultDescription'])
                ? strip_tags((string)$response['ResultDescription'])
                : 'Некорректный ответ сервиса';
            $this->updateSecondReceipt($order_id, 'failed', (string)$result);
            throw new \RuntimeException('Robokassa отклонила второй чек: ' . $description);
        }

        $this->updateSecondReceipt($order_id, 'succeeded', (string)$result);

        return true;
    }

    private function isMarkingEnabled(): bool
    {
        return (bool)$this->config->get('payment_robokassa_marking')
            && $this->config->get('payment_robokassa_country') === 'RUB';
    }

    private function validateNomenclatureCode(string $code): bool
    {
        return $code !== ''
            && strlen($code) <= 255
            && !preg_match('/[^\x1D\x20-\x7E]/', $code)
            && trim(str_replace(chr(29), '', $code)) !== '';
    }

    private function buildSecondReceiptProductItems(array $product): array
    {
        $quantity = (int)$product['quantity'];
        $total = round((float)$product['total'], 2);
        $payment_object = (string)$this->config->get('payment_robokassa_payment_object') ?: 'commodity';
        $base_item = $this->buildSecondCheckItem((string)$product['name'], $total, $quantity, $payment_object);

        if (!$this->isMarkingEnabled() || !$this->isProductMarkingRequired((int)$product['product_id'])) {
            return [$base_item];
        }

        if ($quantity <= 0) {
            throw new \RuntimeException('У маркируемого товара указано некорректное количество.');
        }

        $codes = $this->getOrderProductCodes((int)$product['order_product_id']);

        if (count($codes) !== $quantity) {
            throw new \RuntimeException(sprintf('Не заполнены коды маркировки для товара «%s»: требуется %d.', $product['name'], $quantity));
        }

        $items = [];
        $allocated = 0.0;
        $unit_sum = round($total / $quantity, 2);
        $seen = [];

        for ($unit_index = 1; $unit_index <= $quantity; $unit_index++) {
            $code = isset($codes[$unit_index]) ? (string)$codes[$unit_index] : '';
            $fingerprint = hash('sha256', $code);

            if (!$this->validateNomenclatureCode($code)) {
                throw new \RuntimeException(sprintf('Код маркировки #%d товара «%s» имеет неверный формат.', $unit_index, $product['name']));
            }

            if (isset($seen[$fingerprint])) {
                throw new \RuntimeException(sprintf('Для товара «%s» сохранены одинаковые коды маркировки.', $product['name']));
            }

            $seen[$fingerprint] = true;
            $item_sum = $unit_index === $quantity ? round($total - $allocated, 2) : $unit_sum;
            $allocated += $item_sum;

            $item = $base_item;
            $item['quantity'] = 1;
            $item['sum'] = $item_sum;
            $item['payment_object'] = 'tovar_mark';
            $item['nomenclature_code'] = $code;
            $items[] = $item;
        }

        return $items;
    }

    private function buildSecondCheckItem(string $name, float $sum, int $quantity, string $payment_object): array
    {
        return [
            'name' => mb_substr(trim(htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 0, 63, 'UTF-8'),
            'quantity' => $quantity,
            'sum' => (float)number_format($sum, 2, '.', ''),
            'tax' => (string)$this->config->get('payment_robokassa_tax') ?: 'none',
            'payment_method' => 'full_payment',
            'payment_object' => $payment_object
        ];
    }

    private function getSecondReceipt(int $order_id): array
    {
        $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "robokassa_second_receipt` SET `order_id` = '" . $order_id . "', `status` = 'new', `date_added` = NOW(), `date_modified` = NOW()");
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "robokassa_second_receipt` WHERE `order_id` = '" . $order_id . "'");

        return $query->row;
    }

    private function updateSecondReceipt(int $order_id, string $status, string $response): void
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "robokassa_second_receipt` SET `status` = '" . $this->db->escape($status) . "', `response` = '" . $this->db->escape($response) . "', `date_modified` = NOW() WHERE `order_id` = '" . $order_id . "'");
    }

    private function encodeFiscalToken(string $value): string
    {
        return rtrim(base64_encode($value), '=');
    }

    private function buildConfirmReceipt(int $order_id): array
    {
        $payment_method = (string)$this->config->get('payment_robokassa_payment_method') ?: 'full_payment';
        $payment_object = (string)$this->config->get('payment_robokassa_payment_object') ?: 'commodity';
        $tax = (string)$this->config->get('payment_robokassa_tax') ?: 'none';
        $items = [];
        $sum_items = 0.0;

        $products = $this->db->query("SELECT `name`, `quantity`, `total` FROM `" . DB_PREFIX . "order_product` WHERE order_id = '" . $order_id . "' ORDER BY order_product_id ASC");

        foreach ($products->rows as $row) {
            $quantity = (int)$row['quantity'];
            $line = round((float)$row['total'], 2);

            if ($quantity <= 0 || $line <= 0) {
                continue;
            }

            $items[] = [
                'name' => (string)$row['name'],
                'quantity' => $quantity,
                'sum' => (float)number_format($line, 2, '.', ''),
                'payment_method' => $payment_method,
                'payment_object' => $payment_object,
                'tax' => $tax,
            ];
            $sum_items += $line;
        }

        if (!$items || $sum_items <= 0) {
            return ['0.00', ''];
        }

        $receipt = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [number_format($sum_items, 2, '.', ''), (string)$receipt];
    }

    private function getOutSum(int $order_id): string
    {
        $sub_total = 0.0;
        $query = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . $order_id . "' AND code = 'sub_total' ORDER BY sort_order ASC LIMIT 1");

        if ($query->num_rows) {
            $sub_total = (float)$query->row['value'];
        }

        $total = 0.0;
        $query = $this->db->query("SELECT `total` FROM `" . DB_PREFIX . "order` WHERE order_id = '" . $order_id . "' LIMIT 1");

        if ($query->num_rows) {
            $total = (float)$query->row['total'];
        }

        return number_format($sub_total > 0 ? $sub_total : $total, 2, '.', '');
    }

    private function post(string $url, array $data): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $result = curl_exec($ch);

        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($error);
        }

        curl_close($ch);

        return (string)$result;
    }
}
