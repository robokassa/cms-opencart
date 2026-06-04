<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;

class Result extends \Opencart\System\Engine\Controller {
    public function index()
    {

        if ($this->config->get('payment_robokassa_test')) {
            $password_2 = $this->config->get('payment_robokassa_test_password_2');
        } else {
            $password_2 = $this->config->get('payment_robokassa_password_2');
        }

        $request_data = $this->request->post ?: $this->request->get;

        $out_summ = (string)($request_data['OutSum'] ?? '');
        $order_id = (int)($request_data['InvId'] ?? 0);
        $crc = (string)($request_data['SignatureValue'] ?? '');

        $crc = strtoupper($crc);

        if ($order_id > 0 && $crc !== '' && $this->isValidResultSignature($out_summ, $order_id, $password_2, $request_data, $crc)) {
            $this->load->model('checkout/order');

            $order_info = $this->model_checkout_order->getOrder($order_id);
            $new_order_status_id = $this->config->get('payment_robokassa_order_status_id');

            if (!$order_info) {
                return false;
            }

            if ($order_info['order_status_id'] == 0) {
                $this->model_checkout_order->addHistory($order_id, $new_order_status_id);
            } elseif ($order_info['order_status_id'] != $new_order_status_id) {
                $this->model_checkout_order->addHistory($order_id, $new_order_status_id);

                if ($this->config->get('payment_robokassa_test')) {
                    $this->log->write('ROBOKASSA в заказе: ' . $order_id . '. Статус заказа успешно изменен');
                }

            }

            $this->clearPersistentCart((int)($order_info['customer_id'] ?? 0));
            $this->response->setOutput('OK' . $order_id);

            return true;

        } else {

            if ($this->config->get('payment_robokassa_test')) {
                $this->log->write('ROBOKASSA ошибка в заказе: ' . $order_id . '. Контрольные суммы не совпадают');
            }

        }

    }

    private function normalizeShpSignatureKey($key): string
    {
        $lower_key = strtolower((string)$key);
        $known_map = [
            'shp_item' => 'Shp_item',
            'shp_label' => 'shp_label',
            'shp_merchant_id' => 'Shp_merchant_id',
            'shp_order_id' => 'Shp_order_id',
            'shp_result_url' => 'Shp_result_url'
        ];

        return $known_map[$lower_key] ?? (string)$key;
    }

    private function buildResultSignature($out_summ, int $order_id, string $password, array $request_data): string
    {
        $shp = [];

        foreach ($request_data as $key => $value) {
            if (stripos((string)$key, 'Shp_') === 0 || stripos((string)$key, 'shp_') === 0) {
                $shp[(string)$key] = (string)$value;
            }
        }

        if ($shp) {
            uksort($shp, 'strcasecmp');
        }

        $parts = [$out_summ, $order_id, $password];

        foreach ($shp as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return strtoupper(md5(implode(':', $parts)));
    }

    private function isValidResultSignature($out_summ, int $order_id, string $password, array $request_data, string $signature): bool
    {
        $signature = strtoupper($signature);
        $variants = [
            $this->buildResultSignature($out_summ, $order_id, $password, $request_data)
        ];

        $normalized_request_data = $request_data;

        foreach ($request_data as $key => $value) {
            if (stripos((string)$key, 'shp_') !== 0) {
                continue;
            }

            $normalized_key = $this->normalizeShpSignatureKey($key);

            if ($normalized_key !== (string)$key) {
                unset($normalized_request_data[$key]);
                $normalized_request_data[$normalized_key] = $value;
            }
        }

        $variants[] = $this->buildResultSignature($out_summ, $order_id, $password, $normalized_request_data);

        foreach (array_unique($variants) as $candidate) {
            if ($candidate === $signature) {
                return true;
            }
        }

        return false;
    }

    private function clearPersistentCart(int $order_customer_id = 0): void
    {
        if ($order_customer_id > 0) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE `customer_id` = '" . (int)$order_customer_id . "'");
        }
    }
}
