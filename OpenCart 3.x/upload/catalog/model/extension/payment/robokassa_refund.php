<?php

class ModelExtensionPaymentRobokassaRefund extends Model
{
    public function getPendingRefunds($limit = 50)
    {
        $limit = max(1, min(100, (int)$limit));
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "robokassa_refund`
            WHERE `status` IN ('processing', 'unknown') AND `request_id` IS NOT NULL AND `request_id` <> ''
            ORDER BY `date_modified` ASC
            LIMIT " . $limit);

        return $query->rows;
    }

    public function updateState($refund_id, $status, $response, $error = '')
    {
        $allowed = array('processing', 'finished', 'canceled', 'unknown');
        $status = in_array($status, $allowed, true) ? $status : 'processing';
        $this->db->query("UPDATE `" . DB_PREFIX . "robokassa_refund` SET
            `status` = '" . $status . "',
            `response` = '" . $this->db->escape($response) . "',
            `last_error` = '" . $this->db->escape($error) . "',
            `attempts` = `attempts` + 1,
            `date_modified` = NOW()
            WHERE `refund_id` = '" . (int)$refund_id . "'
              AND `status` IN ('processing', 'unknown')");

        return $this->db->countAffected() > 0;
    }

    public function recordFinished($order_id, $comment)
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

        $this->addOrderHistory($order_id, $order_status_id, $comment);

        return $is_full;
    }

    public function recordCanceled($order_id, $comment)
    {
        $query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "'");

        if ($query->num_rows) {
            $this->addOrderHistory($order_id, (int)$query->row['order_status_id'], $comment);
        }
    }

    public function acquireLock()
    {
        $lock_name = 'robokassa_refund_' . substr(sha1(DB_DATABASE . ':' . DB_PREFIX), 0, 24);
        $query = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($lock_name) . "', 0) AS acquired");

        return $query->num_rows && (int)$query->row['acquired'] === 1;
    }

    public function releaseLock()
    {
        $lock_name = 'robokassa_refund_' . substr(sha1(DB_DATABASE . ':' . DB_PREFIX), 0, 24);
        $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lock_name) . "')");
    }

    private function addOrderHistory($order_id, $order_status_id, $comment)
    {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET
            `order_id` = '" . (int)$order_id . "',
            `order_status_id` = '" . (int)$order_status_id . "',
            `notify` = '0',
            `comment` = '" . $this->db->escape($comment) . "',
            `date_added` = NOW()");
    }
}
