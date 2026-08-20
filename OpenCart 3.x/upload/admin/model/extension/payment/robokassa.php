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
}
