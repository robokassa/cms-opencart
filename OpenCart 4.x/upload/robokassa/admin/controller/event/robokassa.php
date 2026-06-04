<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Event;

class Robokassa extends \Opencart\System\Engine\Controller
{
    private static array $done = [];

    private function getRobokassaInstallmentAliases(): array
    {
        $robokassa_methods_initialized = (int)$this->config->get('payment_robokassa_methods_initialized') === 1;
        $robokassa_current_login = trim((string)$this->config->get('payment_robokassa_login'));
        $robokassa_saved_login = trim((string)$this->config->get('payment_robokassa_methods_login'));
        $robokassa_aliases = $this->config->get('payment_robokassa_methods_aliases');

        if (!$robokassa_methods_initialized || $robokassa_current_login === '' || $robokassa_saved_login !== $robokassa_current_login || !is_array($robokassa_aliases)) {
            return [];
        }

        return array_values(array_unique(array_map(static function ($robokassa_alias): string {
            return strtolower((string)$robokassa_alias);
        }, $robokassa_aliases)));
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

    private function shouldRunHold(int $order_id, int $new_status_id): bool
    {
        if ($order_id <= 0) return false;
        if ($new_status_id !== 7 && $new_status_id !== 2) return false;
        if (!(int)$this->config->get('payment_robokassa_status_hold')) return false;

        $order = $this->getOrderRow($order_id);

        if (!$order) return false;

        $old_status_id = (int)$order['order_status_id'];

        if ($old_status_id !== 1) return false;
        if (!$this->isRobokassaOrder($order)) return false;

        return true;
    }

    private function shouldSendSecondCheck(int $order_id, int $new_status_id): bool
    {
        $second_check_status_id = (int)$this->config->get('payment_robokassa_order_status_id_2check');

        if ($order_id <= 0 || $second_check_status_id <= 0 || $new_status_id !== $second_check_status_id) {
            return false;
        }

        if (!(int)$this->config->get('payment_robokassa_fiscal')) {
            return false;
        }

        if (trim((string)$this->config->get('payment_robokassa_payment_method')) === 'full_payment') {
            return false;
        }

        $order = $this->getOrderRow($order_id);

        if (!$order) {
            return false;
        }

        return (int)$order['order_status_id'] !== $new_status_id
            && $this->isRobokassaOrder($order);
    }

    private function getOrderRow(int $order_id): array
    {
        $query = $this->db->query(
            "SELECT *
             FROM `" . DB_PREFIX . "order`
             WHERE order_id = '" . (int)$order_id . "'
             LIMIT 1"
        );

        return $query->num_rows ? $query->row : [];
    }

    private function isRobokassaOrder(array $order): bool
    {
        $payment_code = (string)($order['payment_code'] ?? '');

        if ($payment_code !== '' && strpos($payment_code, 'robokassa') === 0) {
            return true;
        }

        return stripos((string)($order['payment_method'] ?? ''), 'robokassa') !== false;
    }

    private function runOnce(int $order_id, int $new_status_id): void
    {
        $key = $order_id . ':' . $new_status_id;
        if (isset(self::$done[$key])) return;
        self::$done[$key] = true;

        $send_second_check = $this->shouldSendSecondCheck($order_id, $new_status_id);
        $run_hold = $this->shouldRunHold($order_id, $new_status_id);

        if (!$send_second_check && !$run_hold) return;

        $this->load->model('extension/robokassa/payment/robokassa');

        if ($send_second_check) {
            $this->model_extension_robokassa_payment_robokassa->sendSecondCheck($order_id);
        }

        if (!$run_hold) return;

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
        if (!in_array($action, ['sale/order.addHistory', 'sale/order|addHistory'], true)) return;

        $order_id = (int)($this->request->get['order_id'] ?? ($this->request->post['order_id'] ?? 0));

        $new_status_id = 0;
        if (isset($this->request->post['order_status_id'])) {
            $new_status_id = (int)$this->request->post['order_status_id'];
        } elseif (isset($this->request->post['order_status'])) {
            $new_status_id = (int)$this->request->post['order_status'];
        }

        $this->runOnce($order_id, $new_status_id);

        if ($this->shouldRunHold($order_id, $new_status_id)) {
            $cur = (string)($this->request->post['comment'] ?? '');
            $this->request->post['comment'] = $this->injectMessage($new_status_id, $cur);
        }
    }

    public function onOrderAddHistory(&$route, &$args, &$output = null): void
    {
        $order_id = (int)($args[0] ?? 0);
        $new_status_id = (int)($args[1] ?? 0);

        $this->runOnce($order_id, $new_status_id);

        if (!$this->shouldRunHold($order_id, $new_status_id)) return;

        $cur = isset($args[2]) ? (string)$args[2] : '';
        $args[2] = $this->injectMessage($new_status_id, $cur);
    }
}
