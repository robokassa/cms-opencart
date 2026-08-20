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

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "robokassa_refund` (
            `refund_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT(11) NOT NULL,
            `request_id` VARCHAR(36) DEFAULT NULL,
            `operation_key` VARCHAR(128) NOT NULL,
            `fingerprint` CHAR(64) NOT NULL,
            `amount` DECIMAL(15,4) NOT NULL,
            `is_full` TINYINT(1) NOT NULL DEFAULT '0',
            `status` VARCHAR(16) NOT NULL DEFAULT 'submitting',
            `reason` VARCHAR(255) NOT NULL DEFAULT '',
            `invoice_items` MEDIUMTEXT,
            `response` MEDIUMTEXT,
            `last_error` TEXT,
            `attempts` INT(11) NOT NULL DEFAULT '0',
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`refund_id`),
            UNIQUE KEY `request_id` (`request_id`),
            UNIQUE KEY `fingerprint` (`fingerprint`),
            KEY `order_status` (`order_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "robokassa_refund_item` (
            `refund_item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `refund_id` BIGINT UNSIGNED NOT NULL,
            `order_product_id` INT(11) NOT NULL DEFAULT '0',
            `unit_index` INT(11) NOT NULL DEFAULT '0',
            `quantity` DECIMAL(15,4) NOT NULL DEFAULT '0',
            `amount` DECIMAL(15,4) NOT NULL DEFAULT '0',
            `nomenclature_code` VARBINARY(255) DEFAULT NULL,
            PRIMARY KEY (`refund_item_id`),
            KEY `refund_id` (`refund_id`),
            KEY `order_product_id` (`order_product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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

        $this->post('https://auth.robokassa.ru/Merchant/Payment/Cancel', [
            'MerchantLogin'  => $merchant,
            'OutSum'         => $out_sum,
            'InvoiceID'      => $order_id,
            'SignatureValue' => $signature,
        ]);

        return true;
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
        $this->post('https://auth.robokassa.ru/Merchant/Payment/Confirm', [
            'MerchantLogin'  => $merchant,
            'OutSum'         => $out_sum,
            'InvoiceID'      => $order_id,
            'Receipt'        => $receipt,
            'SignatureValue' => $signature,
        ]);

        return true;
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
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($error);
        }

        if ($http_code < 200 || $http_code >= 400) {
            curl_close($ch);
            throw new \RuntimeException('Robokassa returned HTTP ' . $http_code);
        }

        curl_close($ch);

        return (string)$result;
    }

    public function getRefunds($order_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "robokassa_refund` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY `refund_id` DESC");

        return $query->rows;
    }

    public function getRefund($refund_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "robokassa_refund` WHERE `refund_id` = '" . (int)$refund_id . "'");

        return $query->row;
    }

    public function getReservedRefundTotal($order_id)
    {
        $query = $this->db->query("SELECT COALESCE(SUM(`amount`), 0) AS total FROM `" . DB_PREFIX . "robokassa_refund` WHERE `order_id` = '" . (int)$order_id . "' AND `status` IN ('submitting', 'unknown', 'processing', 'finished')");

        return (float)$query->row['total'];
    }

    public function getRefundableData($order_id)
    {
        $products_query = $this->db->query("SELECT op.*, COALESCE(pm.marking_required, 0) AS marking_required
            FROM `" . DB_PREFIX . "order_product` op
            LEFT JOIN `" . DB_PREFIX . "robokassa_product_marking` pm ON (pm.product_id = op.product_id)
            WHERE op.order_id = '" . (int)$order_id . "'
            ORDER BY op.order_product_id");
        $totals_query = $this->db->query("SELECT `code`, `title`, `value` FROM `" . DB_PREFIX . "order_total` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY `sort_order`, `order_total_id`");
        $reserved_query = $this->db->query("SELECT ri.order_product_id, SUM(ri.quantity) AS quantity
            FROM `" . DB_PREFIX . "robokassa_refund_item` ri
            INNER JOIN `" . DB_PREFIX . "robokassa_refund` r ON (r.refund_id = ri.refund_id)
            WHERE r.order_id = '" . (int)$order_id . "'
              AND r.status IN ('submitting', 'unknown', 'processing', 'finished')
              AND ri.order_product_id > 0
            GROUP BY ri.order_product_id");
        $reserved_quantities = array();

        foreach ($reserved_query->rows as $row) {
            $reserved_quantities[(int)$row['order_product_id']] = (float)$row['quantity'];
        }

        $discount = 0.0;
        $shipping = array();

        foreach ($totals_query->rows as $total) {
            if ((float)$total['value'] < 0) {
                $discount = abs((float)$total['value']);
            }

            if ($total['code'] === 'shipping' && (float)$total['value'] > 0) {
                $shipping = array(
                    'title' => $total['title'],
                    'amount' => round((float)$total['value'], 2)
                );
            }
        }

        $product_total = 0.0;

        foreach ($products_query->rows as $product) {
            $product_total += (float)$product['price'] * (float)$product['quantity'];
        }

        $discount_percent = $product_total > 0 ? $discount / $product_total : 0;
        $products = array();
        $marking_enabled = (bool)$this->config->get('payment_robokassa_marking')
            && $this->config->get('payment_robokassa_country') === 'RUB';

        foreach ($products_query->rows as $product) {
            $unit_cost = round((float)$product['price'] - round((float)$product['price'] * $discount_percent, 2), 2);
            $reserved = isset($reserved_quantities[(int)$product['order_product_id']]) ? $reserved_quantities[(int)$product['order_product_id']] : 0;
            $product['unit_cost'] = max(0, $unit_cost);
            $product['refunded_quantity'] = $reserved;
            $product['available_quantity'] = max(0, (float)$product['quantity'] - $reserved);
            $product['marking_codes_available'] = 0;
            $product['marking_required'] = $marking_enabled && (bool)$product['marking_required'];

            if ($product['marking_required']) {
                $product['marking_codes_available'] = count($this->getAvailableMarkingCodes($product['order_product_id']));
            }

            $products[] = $product;
        }

        if ($shipping) {
            $shipping_query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "robokassa_refund_item` ri
                INNER JOIN `" . DB_PREFIX . "robokassa_refund` r ON (r.refund_id = ri.refund_id)
                WHERE r.order_id = '" . (int)$order_id . "'
                  AND r.status IN ('submitting', 'unknown', 'processing', 'finished')
                  AND ri.order_product_id = '0'");
            $shipping['available'] = !(int)$shipping_query->row['total'];
        }

        return array('products' => $products, 'shipping' => $shipping);
    }

    public function buildRefundInvoiceItems($order_id, array $product_quantities, $include_shipping)
    {
        $refundable = $this->getRefundableData($order_id);
        $invoice_items = array();
        $allocations = array();
        $amount = 0.0;
        $tax = $this->config->get('payment_robokassa_tax') ?: 'none';
        $payment_object = $this->config->get('payment_robokassa_payment_object') ?: 'commodity';

        foreach ($refundable['products'] as $product) {
            $order_product_id = (int)$product['order_product_id'];
            $quantity_value = isset($product_quantities[$order_product_id]) && is_scalar($product_quantities[$order_product_id])
                ? str_replace(',', '.', trim((string)$product_quantities[$order_product_id]))
                : '0';

            if (!is_numeric($quantity_value)) {
                throw new \Exception('Некорректное количество возврата для товара «' . $product['name'] . '».');
            }

            $quantity = (float)$quantity_value;

            if ($quantity <= 0) {
                continue;
            }

            if (floor($quantity) != $quantity || $quantity > (float)$product['available_quantity']) {
                throw new \Exception('Некорректное количество возврата для товара «' . $product['name'] . '».');
            }

            $name = mb_substr(trim(strip_tags(html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8'))), 0, 128, 'UTF-8');
            $unit_cost = round((float)$product['unit_cost'], 2);

            if ($product['marking_required']) {
                $codes = $this->getAvailableMarkingCodes($order_product_id);

                if (count($codes) < $quantity) {
                    throw new \Exception('Для товара «' . $product['name'] . '» недостаточно свободных кодов маркировки.');
                }

                $selected_codes = array_slice($codes, 0, (int)$quantity, true);

                foreach ($selected_codes as $unit_index => $code) {
                    $invoice_items[] = array(
                        'Name' => $name,
                        'Quantity' => 1,
                        'Cost' => $unit_cost,
                        'Tax' => $tax,
                        'PaymentMethod' => 'full_payment',
                        'PaymentObject' => 'tovar_mark',
                        'NomenclatureCode' => $code
                    );
                    $allocations[] = array(
                        'order_product_id' => $order_product_id,
                        'unit_index' => (int)$unit_index,
                        'quantity' => 1,
                        'amount' => $unit_cost,
                        'nomenclature_code' => $code
                    );
                    $amount += $unit_cost;
                }
            } else {
                $line_amount = round($unit_cost * $quantity, 2);
                $invoice_items[] = array(
                    'Name' => $name,
                    'Quantity' => $quantity,
                    'Cost' => $unit_cost,
                    'Tax' => $tax,
                    'PaymentMethod' => 'full_payment',
                    'PaymentObject' => $payment_object
                );
                $allocations[] = array(
                    'order_product_id' => $order_product_id,
                    'unit_index' => 0,
                    'quantity' => $quantity,
                    'amount' => $line_amount,
                    'nomenclature_code' => ''
                );
                $amount += $line_amount;
            }
        }

        if ($include_shipping) {
            if (!$refundable['shipping'] || empty($refundable['shipping']['available'])) {
                throw new \Exception('Доставка уже включена в другой возврат или отсутствует в заказе.');
            }

            $shipping_amount = round((float)$refundable['shipping']['amount'], 2);
            $invoice_items[] = array(
                'Name' => mb_substr(trim(strip_tags(html_entity_decode($refundable['shipping']['title'], ENT_QUOTES, 'UTF-8'))), 0, 128, 'UTF-8'),
                'Quantity' => 1,
                'Cost' => $shipping_amount,
                'Tax' => $tax,
                'PaymentMethod' => 'full_payment',
                'PaymentObject' => $payment_object
            );
            $allocations[] = array(
                'order_product_id' => 0,
                'unit_index' => 0,
                'quantity' => 1,
                'amount' => $shipping_amount,
                'nomenclature_code' => ''
            );
            $amount += $shipping_amount;
        }

        if (!$invoice_items) {
            throw new \Exception('Выберите хотя бы одну позицию для чека возврата.');
        }

        return array('amount' => round($amount, 2), 'invoice_items' => $invoice_items, 'allocations' => $allocations);
    }

    public function reserveRefund($order_id, $operation_key, $fingerprint, $amount, $is_full, $reason, array $invoice_items, array $allocations, $order_total)
    {
        $this->db->query('START TRANSACTION');

        try {
            $order_query = $this->db->query("SELECT `order_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' FOR UPDATE");

            if (!$order_query->num_rows) {
                $this->db->query('ROLLBACK');
                return array('success' => false, 'error' => 'Заказ для возврата не найден.');
            }

            $reserved_query = $this->db->query("SELECT COALESCE(SUM(`amount`), 0) AS total FROM `" . DB_PREFIX . "robokassa_refund` WHERE `order_id` = '" . (int)$order_id . "' AND `status` IN ('submitting', 'unknown', 'processing', 'finished')");
            $remaining = max(0, round((float)$order_total - (float)$reserved_query->row['total'], 2));

            if ((float)$amount <= 0 || (float)$amount > $remaining + 0.005) {
                $this->db->query('ROLLBACK');
                return array('success' => false, 'error' => 'Доступная сумма возврата изменилась. Обновите страницу и повторите проверку.');
            }

            if (!$this->validateRefundAllocations($order_id, $allocations)) {
                $this->db->query('ROLLBACK');
                return array('success' => false, 'error' => 'Доступные позиции или коды маркировки изменились. Обновите страницу и сформируйте возврат заново.');
            }

            $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "robokassa_refund` WHERE `fingerprint` = '" . $this->db->escape($fingerprint) . "' FOR UPDATE");

            if ($query->num_rows) {
                $refund = $query->row;

                if (!in_array($refund['status'], array('failed', 'canceled'), true)) {
                    $this->db->query('ROLLBACK');
                    return array('success' => false, 'error' => 'Такой возврат уже отправлен или сейчас обрабатывается.', 'refund' => $refund);
                }

                $refund_id = (int)$refund['refund_id'];
                $this->db->query("UPDATE `" . DB_PREFIX . "robokassa_refund` SET
                    `request_id` = NULL,
                    `operation_key` = '" . $this->db->escape($operation_key) . "',
                    `amount` = '" . (float)$amount . "',
                    `is_full` = '" . (int)(bool)$is_full . "',
                    `status` = 'submitting',
                    `reason` = '" . $this->db->escape($reason) . "',
                    `invoice_items` = '" . $this->db->escape(json_encode($invoice_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "',
                    `response` = NULL,
                    `last_error` = NULL,
                    `attempts` = '0',
                    `date_modified` = NOW()
                    WHERE `refund_id` = '" . $refund_id . "'");
                $this->db->query("DELETE FROM `" . DB_PREFIX . "robokassa_refund_item` WHERE `refund_id` = '" . $refund_id . "'");
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "robokassa_refund` SET
                    `order_id` = '" . (int)$order_id . "',
                    `operation_key` = '" . $this->db->escape($operation_key) . "',
                    `fingerprint` = '" . $this->db->escape($fingerprint) . "',
                    `amount` = '" . (float)$amount . "',
                    `is_full` = '" . (int)(bool)$is_full . "',
                    `status` = 'submitting',
                    `reason` = '" . $this->db->escape($reason) . "',
                    `invoice_items` = '" . $this->db->escape(json_encode($invoice_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "',
                    `date_added` = NOW(),
                    `date_modified` = NOW()");
                $refund_id = (int)$this->db->getLastId();
            }

            foreach ($allocations as $allocation) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "robokassa_refund_item` SET
                    `refund_id` = '" . $refund_id . "',
                    `order_product_id` = '" . (int)$allocation['order_product_id'] . "',
                    `unit_index` = '" . (int)$allocation['unit_index'] . "',
                    `quantity` = '" . (float)$allocation['quantity'] . "',
                    `amount` = '" . (float)$allocation['amount'] . "',
                    `nomenclature_code` = " . ($allocation['nomenclature_code'] === '' ? 'NULL' : "'" . $this->db->escape($allocation['nomenclature_code']) . "'") . "");
            }

            $this->db->query('COMMIT');
            return array('success' => true, 'refund_id' => $refund_id);
        } catch (\Exception $e) {
            $this->db->query('ROLLBACK');
            return array('success' => false, 'error' => 'Не удалось заблокировать повторную отправку возврата.');
        }
    }

    public function completeRefundSubmission($refund_id, $request_id, $response)
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "robokassa_refund` SET
            `request_id` = '" . $this->db->escape($request_id) . "',
            `status` = 'processing',
            `response` = '" . $this->db->escape($response) . "',
            `last_error` = NULL,
            `date_modified` = NOW()
            WHERE `refund_id` = '" . (int)$refund_id . "'");
    }

    public function failRefundSubmission($refund_id, $status, $error, $response = '')
    {
        $allowed = array('failed', 'unknown');
        $status = in_array($status, $allowed, true) ? $status : 'failed';
        $this->db->query("UPDATE `" . DB_PREFIX . "robokassa_refund` SET
            `status` = '" . $status . "',
            `response` = '" . $this->db->escape($response) . "',
            `last_error` = '" . $this->db->escape($error) . "',
            `date_modified` = NOW()
            WHERE `refund_id` = '" . (int)$refund_id . "'");
    }

    public function updateRefundState($refund_id, $status, $response, $error = '')
    {
        $allowed = array('processing', 'finished', 'canceled', 'unknown');
        $status = in_array($status, $allowed, true) ? $status : 'unknown';
        $this->db->query("UPDATE `" . DB_PREFIX . "robokassa_refund` SET
            `status` = '" . $status . "',
            `response` = '" . $this->db->escape($response) . "',
            `last_error` = '" . $this->db->escape($error) . "',
            `attempts` = `attempts` + 1,
            `date_modified` = NOW()
            WHERE `refund_id` = '" . (int)$refund_id . "'
              AND `status` NOT IN ('finished', 'canceled')");

        return $this->db->countAffected() > 0;
    }

    public function addOrderNote($order_id, $comment)
    {
        $query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "'");

        if (!$query->num_rows) {
            return;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET
            `order_id` = '" . (int)$order_id . "',
            `order_status_id` = '" . (int)$query->row['order_status_id'] . "',
            `notify` = '0',
            `comment` = '" . $this->db->escape($comment) . "',
            `date_added` = NOW()");
    }

    public function recordFinishedRefund($order_id, $comment)
    {
        $order_query = $this->db->query("SELECT `total`, `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "'");

        if (!$order_query->num_rows) {
            return false;
        }

        $refunded_query = $this->db->query("SELECT COALESCE(SUM(`amount`), 0) AS total FROM `" . DB_PREFIX . "robokassa_refund` WHERE `order_id` = '" . (int)$order_id . "' AND `status` = 'finished'");
        $is_full = (float)$refunded_query->row['total'] + 0.005 >= (float)$order_query->row['total'];
        $refund_status_id = (int)$this->config->get('payment_robokassa_refund_status_id');

        if (!$refund_status_id) {
            $refund_status_id = 11;
        }

        $status_query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE `order_status_id` = '" . $refund_status_id . "' LIMIT 1");

        if ($is_full && $status_query->num_rows && (int)$order_query->row['order_status_id'] !== $refund_status_id) {
            $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `order_status_id` = '" . $refund_status_id . "', `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
            $order_status_id = $refund_status_id;
        } else {
            $order_status_id = (int)$order_query->row['order_status_id'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET
            `order_id` = '" . (int)$order_id . "',
            `order_status_id` = '" . $order_status_id . "',
            `notify` = '0',
            `comment` = '" . $this->db->escape($comment) . "',
            `date_added` = NOW()");

        return $is_full;
    }

    private function getAvailableMarkingCodes($order_product_id)
    {
        $codes_query = $this->db->query("SELECT `unit_index`, `nomenclature_code` FROM `" . DB_PREFIX . "robokassa_order_product_marking` WHERE `order_product_id` = '" . (int)$order_product_id . "' ORDER BY `unit_index`");
        $used_query = $this->db->query("SELECT ri.unit_index FROM `" . DB_PREFIX . "robokassa_refund_item` ri
            INNER JOIN `" . DB_PREFIX . "robokassa_refund` r ON (r.refund_id = ri.refund_id)
            WHERE ri.order_product_id = '" . (int)$order_product_id . "'
              AND r.status IN ('submitting', 'unknown', 'processing', 'finished')
              AND ri.unit_index > 0");
        $used = array();

        foreach ($used_query->rows as $row) {
            $used[(int)$row['unit_index']] = true;
        }

        $codes = array();

        foreach ($codes_query->rows as $row) {
            $unit_index = (int)$row['unit_index'];

            if (!isset($used[$unit_index])) {
                $codes[$unit_index] = $row['nomenclature_code'];
            }
        }

        return $codes;
    }

    private function validateRefundAllocations($order_id, array $allocations)
    {
        $requested_quantities = array();
        $requested_codes = array();
        $shipping_requested = false;

        foreach ($allocations as $allocation) {
            $order_product_id = (int)$allocation['order_product_id'];

            if ($order_product_id === 0) {
                $shipping_requested = true;
                continue;
            }

            if (!isset($requested_quantities[$order_product_id])) {
                $requested_quantities[$order_product_id] = 0;
            }

            $requested_quantities[$order_product_id] += (float)$allocation['quantity'];

            if ((int)$allocation['unit_index'] > 0) {
                $requested_codes[] = array(
                    'order_product_id' => $order_product_id,
                    'unit_index' => (int)$allocation['unit_index']
                );
            }
        }

        foreach ($requested_quantities as $order_product_id => $requested_quantity) {
            $product_query = $this->db->query("SELECT `quantity` FROM `" . DB_PREFIX . "order_product` WHERE `order_id` = '" . (int)$order_id . "' AND `order_product_id` = '" . (int)$order_product_id . "'");

            if (!$product_query->num_rows) {
                return false;
            }

            $used_query = $this->db->query("SELECT COALESCE(SUM(ri.quantity), 0) AS quantity
                FROM `" . DB_PREFIX . "robokassa_refund_item` ri
                INNER JOIN `" . DB_PREFIX . "robokassa_refund` r ON (r.refund_id = ri.refund_id)
                WHERE r.order_id = '" . (int)$order_id . "'
                  AND r.status IN ('submitting', 'unknown', 'processing', 'finished')
                  AND ri.order_product_id = '" . (int)$order_product_id . "'");

            if ((float)$used_query->row['quantity'] + $requested_quantity > (float)$product_query->row['quantity'] + 0.0001) {
                return false;
            }
        }

        foreach ($requested_codes as $requested_code) {
            $code_query = $this->db->query("SELECT COUNT(*) AS total
                FROM `" . DB_PREFIX . "robokassa_refund_item` ri
                INNER JOIN `" . DB_PREFIX . "robokassa_refund` r ON (r.refund_id = ri.refund_id)
                WHERE r.status IN ('submitting', 'unknown', 'processing', 'finished')
                  AND ri.order_product_id = '" . (int)$requested_code['order_product_id'] . "'
                  AND ri.unit_index = '" . (int)$requested_code['unit_index'] . "'");

            if ((int)$code_query->row['total'] > 0) {
                return false;
            }
        }

        if ($shipping_requested) {
            $shipping_query = $this->db->query("SELECT COUNT(*) AS total
                FROM `" . DB_PREFIX . "robokassa_refund_item` ri
                INNER JOIN `" . DB_PREFIX . "robokassa_refund` r ON (r.refund_id = ri.refund_id)
                WHERE r.order_id = '" . (int)$order_id . "'
                  AND r.status IN ('submitting', 'unknown', 'processing', 'finished')
                  AND ri.order_product_id = '0'");

            if ((int)$shipping_query->row['total'] > 0) {
                return false;
            }
        }

        return true;
    }
}
