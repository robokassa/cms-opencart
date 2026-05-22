<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Event;

class Robokassa extends \Opencart\System\Engine\Controller
{
    private static array $done = [];

    private function getRobokassaInstallmentAliases(): array
    {
        $robokassa_installment_aliases = [];
        $robokassa_methods_initialized = (int)$this->config->get('payment_robokassa_methods_initialized') === 1;
        $robokassa_current_login = trim((string)$this->config->get('payment_robokassa_login'));
        $robokassa_saved_login = trim((string)$this->config->get('payment_robokassa_methods_login'));
        $robokassa_merchant_login = ($robokassa_methods_initialized && $robokassa_current_login !== '' && $robokassa_saved_login === $robokassa_current_login) ? $robokassa_saved_login : '';

        if ($robokassa_merchant_login !== '') {
            $robokassa_currency_url = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/GetCurrencies?MerchantLogin=' . rawurlencode($robokassa_merchant_login) . '&Language=ru';
            $robokassa_currency_xml = false;

            if (function_exists('curl_init')) {
                $robokassa_ch = curl_init($robokassa_currency_url);
                curl_setopt($robokassa_ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($robokassa_ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($robokassa_ch, CURLOPT_TIMEOUT, 3);
                $robokassa_currency_xml = curl_exec($robokassa_ch);
                curl_close($robokassa_ch);
            }

            if ($robokassa_currency_xml === false && ini_get('allow_url_fopen')) {
                $robokassa_currency_context = stream_context_create([
                    'http' => [
                        'timeout' => 3
                    ]
                ]);
                $robokassa_currency_xml = @file_get_contents($robokassa_currency_url, false, $robokassa_currency_context);
            }

            if ($robokassa_currency_xml !== false && strpos($robokassa_currency_xml, '<Code>0</Code>') !== false && preg_match_all('/\bAlias="([^"]+)"/i', $robokassa_currency_xml, $robokassa_aliases_match)) {
                foreach ($robokassa_aliases_match[1] as $robokassa_alias) {
                    $robokassa_installment_aliases[] = strtolower((string)$robokassa_alias);
                }

                $robokassa_installment_aliases = array_unique($robokassa_installment_aliases);
            }
        }

        return $robokassa_installment_aliases;
    }

    private function filterRobokassaPaymentList(string $output): string
    {
        $robokassa_installment_map = [
            'robokassa_podeli' => 'podeli',
            'robokassa_credit' => 'otp',
            'robokassa_mokka' => 'mokka',
            'robokassa_sbp' => 'sbp',
            'robokassa_yandex_split' => 'yandexpaysplit',
            'robokassa_split' => 'yandexpaysplit'
        ];
        $robokassa_bnpl_aliases = ['podeli', 'otp', 'mokka', 'yandexpaysplit'];
        $robokassa_installment_aliases = $this->getRobokassaInstallmentAliases();

        foreach ($robokassa_installment_map as $robokassa_code => $robokassa_alias) {
            if (in_array($robokassa_alias, $robokassa_installment_aliases, true)) {
                continue;
            }

            $output = $this->removeRobokassaPaymentRow($output, $robokassa_code);
        }

        if (!array_intersect($robokassa_bnpl_aliases, $robokassa_installment_aliases)) {
            $output = $this->removeRobokassaPaymentRow($output, 'robokassa_widget');
        }

        return $output;
    }

    private function removeRobokassaPaymentRow(string $output, string $code): string
    {
        return (string)preg_replace_callback('~<tr\b[\s\S]*?</tr>~i', static function (array $matches) use ($code): string {
            $row = $matches[0];

            if (strpos($row, 'extension/robokassa/payment/' . $code) !== false) {
                return '';
            }

            if (strpos($row, 'code=' . $code) !== false) {
                return '';
            }

            if (preg_match('~\b' . preg_quote($code, '~') . '\b~', $row)) {
                return '';
            }

            return $row;
        }, $output);
    }

    public function onPaymentExtensionViewAfter(&$route, &$args, &$output = null): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }

        $output = $this->filterRobokassaPaymentList($output);
    }

    private function shouldRun(int $order_id, int $new_status_id): bool
    {
        if ($order_id <= 0) return false;
        if ($new_status_id !== 7 && $new_status_id !== 2) return false;
        if (!(int)$this->config->get('payment_robokassa_status_hold')) return false;

        $q = $this->db->query(
            "SELECT order_status_id, payment_method
             FROM `" . DB_PREFIX . "order`
             WHERE order_id = '" . (int)$order_id . "'
             LIMIT 1"
        );

        if (!$q->num_rows) return false;

        $old_status_id  = (int)$q->row['order_status_id'];
        $payment_method = (string)$q->row['payment_method'];

        if ($old_status_id !== 1) return false;
        if (stripos($payment_method, 'robokassa') === false) return false;

        return true;
    }

    private function runOnce(int $order_id, int $new_status_id): void
    {
        $key = $order_id . ':' . $new_status_id;
        if (isset(self::$done[$key])) return;
        self::$done[$key] = true;

        if (!$this->shouldRun($order_id, $new_status_id)) return;

        $this->load->model('extension/robokassa/payment/robokassa');

        if ($new_status_id === 7) {
            $this->model_extension_robokassa_payment_robokassa->holdCancel($order_id);
            return;
        }

        if ($new_status_id === 2) {
            $this->model_extension_robokassa_payment_robokassa->holdConfirm($order_id);
            return;
        }
    }

    private function injectMessage(int $new_status_id, string $current): string
    {
        $msg = '';
        if ($new_status_id === 7) $msg = 'Robokassa: Платеж успешно отменен.';
        if ($new_status_id === 2) $msg = 'Robokassa: Платеж успешно подтвержден.';
        if ($msg === '') return $current;

        $current = trim($current);
        if ($current !== '' && mb_strpos($current, $msg) !== false) return $current;

        return $current === '' ? $msg : ($current . "\n" . $msg);
    }

    public function onOrderCall(&$route, &$args, &$output = null): void
    {
        $action = (string)($this->request->get['action'] ?? ($this->request->post['action'] ?? ''));
        if ($action !== 'sale/order.addHistory') return;

        $order_id = (int)($this->request->get['order_id'] ?? ($this->request->post['order_id'] ?? 0));

        $new_status_id = 0;
        if (isset($this->request->post['order_status_id'])) {
            $new_status_id = (int)$this->request->post['order_status_id'];
        } elseif (isset($this->request->post['order_status'])) {
            $new_status_id = (int)$this->request->post['order_status'];
        }

        $this->runOnce($order_id, $new_status_id);

        if ($this->shouldRun($order_id, $new_status_id)) {
            $cur = (string)($this->request->post['comment'] ?? '');
            $this->request->post['comment'] = $this->injectMessage($new_status_id, $cur);
        }
    }

    public function onOrderAddHistory(&$route, &$args, &$output = null): void
    {
        $order_id = (int)($args[0] ?? 0);
        $new_status_id = (int)($args[1] ?? 0);

        $this->runOnce($order_id, $new_status_id);

        if (!$this->shouldRun($order_id, $new_status_id)) return;

        $cur = isset($args[2]) ? (string)$args[2] : '';
        $args[2] = $this->injectMessage($new_status_id, $cur);
    }
}
