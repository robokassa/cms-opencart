<?php

class ModelExtensionPaymentRobokassa extends Model
{
    public function installMarkingTables()
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    public function isProductMarkingRequired($product_id)
    {
        $query = $this->db->query("SELECT `marking_required` FROM `" . DB_PREFIX . "robokassa_product_marking` WHERE `product_id` = '" . (int)$product_id . "'");

        return $query->num_rows && (bool)$query->row['marking_required'];
    }

    public function saveProductMarkingRequired($product_id, $required)
    {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "robokassa_product_marking` SET `product_id` = '" . (int)$product_id . "', `marking_required` = '" . (int)(bool)$required . "'");
    }

    public function getOrderProduct($order_product_id)
    {
        $query = $this->db->query("SELECT op.*, COALESCE(pm.marking_required, 0) AS marking_required
            FROM `" . DB_PREFIX . "order_product` op
            LEFT JOIN `" . DB_PREFIX . "robokassa_product_marking` pm ON (pm.product_id = op.product_id)
            WHERE op.order_product_id = '" . (int)$order_product_id . "'");

        return $query->row;
    }

    public function getOrderProductCodes($order_product_id)
    {
        $query = $this->db->query("SELECT `unit_index`, `nomenclature_code` FROM `" . DB_PREFIX . "robokassa_order_product_marking` WHERE `order_product_id` = '" . (int)$order_product_id . "' ORDER BY `unit_index`");
        $codes = array();

        foreach ($query->rows as $row) {
            $codes[(int)$row['unit_index']] = $row['nomenclature_code'];
        }

        return $codes;
    }

    public function saveOrderProductCodes($order_product_id, array $codes)
    {
        $this->db->query("START TRANSACTION");

        try {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "robokassa_order_product_marking` WHERE `order_product_id` = '" . (int)$order_product_id . "'");

            foreach ($codes as $unit_index => $code) {
                if ($code === '') {
                    continue;
                }

                $this->db->query("INSERT INTO `" . DB_PREFIX . "robokassa_order_product_marking` SET
                    `order_product_id` = '" . (int)$order_product_id . "',
                    `unit_index` = '" . (int)$unit_index . "',
                    `nomenclature_code` = '" . $this->db->escape($code) . "',
                    `date_added` = NOW(),
                    `date_modified` = NOW()");
            }

            $this->db->query("COMMIT");
        } catch (Exception $e) {
            $this->db->query("ROLLBACK");
            throw $e;
        }
    }

    public function isCodeUsedInOrder($order_product_id, $code)
    {
        $query = $this->db->query("SELECT m.order_product_id
            FROM `" . DB_PREFIX . "robokassa_order_product_marking` m
            INNER JOIN `" . DB_PREFIX . "order_product` other_product ON (other_product.order_product_id = m.order_product_id)
            INNER JOIN `" . DB_PREFIX . "order_product` current_product ON (current_product.order_product_id = '" . (int)$order_product_id . "')
            WHERE other_product.order_id = current_product.order_id
              AND m.order_product_id != current_product.order_product_id
              AND m.nomenclature_code = '" . $this->db->escape($code) . "'
            LIMIT 1");

        return (bool)$query->num_rows;
    }

    public function getMarkingStatus($order_product_id, $quantity)
    {
        $filled = count($this->getOrderProductCodes($order_product_id));

        if (!$filled) {
            return 'empty';
        }

        return $filled >= (int)$quantity ? 'filled' : 'partial';
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
                throw new Exception('Некорректное количество возврата для товара «' . $product['name'] . '».');
            }

            $quantity = (float)$quantity_value;

            if ($quantity <= 0) {
                continue;
            }

            if (floor($quantity) != $quantity || $quantity > (float)$product['available_quantity']) {
                throw new Exception('Некорректное количество возврата для товара «' . $product['name'] . '».');
            }

            $name = utf8_substr(trim(strip_tags(html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8'))), 0, 128);
            $unit_cost = round((float)$product['unit_cost'], 2);

            if ($product['marking_required']) {
                $codes = $this->getAvailableMarkingCodes($order_product_id);

                if (count($codes) < $quantity) {
                    throw new Exception('Для товара «' . $product['name'] . '» недостаточно свободных кодов маркировки.');
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
                throw new Exception('Доставка уже включена в другой возврат или отсутствует в заказе.');
            }

            $shipping_amount = round((float)$refundable['shipping']['amount'], 2);
            $invoice_items[] = array(
                'Name' => utf8_substr(trim(strip_tags(html_entity_decode($refundable['shipping']['title'], ENT_QUOTES, 'UTF-8'))), 0, 128),
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
            throw new Exception('Выберите хотя бы одну позицию для чека возврата.');
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
        } catch (Exception $e) {
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
