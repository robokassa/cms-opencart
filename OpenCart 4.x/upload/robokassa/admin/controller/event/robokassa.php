<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Event;

class Robokassa extends \Opencart\System\Engine\Controller
{
    private static array $done = [];
    private static array $savedProducts = [];

    private function filterRobokassaPaymentList(string $output): string
    {
        foreach (['robokassa_credit', 'robokassa_mokka', 'robokassa_podeli', 'robokassa_sbp', 'robokassa_split', 'robokassa_widget', 'robokassa_yandex_split'] as $robokassa_code) {
            $output = $this->removeRobokassaPaymentRow($output, $robokassa_code);
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

    public function onAdminMenuBefore(string &$route, array &$data): void
    {
        if (!$this->user->hasPermission('access', 'extension/robokassa/payment/robokassa')
            || empty($data['menus'])
            || !is_array($data['menus'])) {
            return;
        }

        foreach ($data['menus'] as $menu) {
            if (($menu['id'] ?? '') === 'menu-robokassa') {
                return;
            }
        }

        $robokassa_menu = [
            'id' => 'menu-robokassa',
            'icon' => 'fas fa-credit-card',
            'name' => 'Robokassa',
            'href' => $this->url->link(
                'extension/robokassa/payment/robokassa',
                'user_token=' . $this->session->data['user_token']
            ),
            'children' => []
        ];

        array_splice($data['menus'], 1, 0, [$robokassa_menu]);
    }

    public function onPaymentExtensionViewAfter(&$route, &$args, &$output = null): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }

        $output = $this->filterRobokassaPaymentList($output);
    }

    public function onProductFormViewAfter(string &$route, array &$data, mixed &$output): void
    {
        if (!$this->config->get('payment_robokassa_marking')
            || $this->config->get('payment_robokassa_country') !== 'RUB'
            || !is_string($output)
            || strpos($output, 'input-robokassa-marking-required') !== false) {
            return;
        }

        $this->load->language('extension/robokassa/payment/robokassa');
        $this->load->model('extension/robokassa/payment/robokassa');
        $this->model_extension_robokassa_payment_robokassa->installMarkingTables();

        $product_id = (int)($data['product_id'] ?? ($this->request->get['product_id'] ?? 0));
        $field = $this->load->view('extension/robokassa/payment/robokassa_product_marking', [
            'entry_marking_required' => $this->language->get('entry_marking_required'),
            'help_marking_required' => $this->language->get('help_marking_required'),
            'text_yes' => $this->language->get('text_yes'),
            'text_no' => $this->language->get('text_no'),
            'robokassa_marking_required' => $product_id > 0
                && $this->model_extension_robokassa_payment_robokassa->isProductMarkingRequired($product_id)
        ]);

        $output = (string)preg_replace(
            '~(<div class="row mb-3">\s*<label for="input-ean")~',
            $field . '$1',
            $output,
            1
        );
    }

    public function onProductAddAfter(string &$route, array &$args, mixed &$output): void
    {
        $this->saveProductMarking((int)$output);
    }

    public function onProductEditAfter(string &$route, array &$args, mixed &$output): void
    {
        $product_id = str_ends_with($route, '/editVariant')
            ? (int)($args[1] ?? 0)
            : (int)($args[0] ?? 0);

        $this->saveProductMarking($product_id);
    }

    private function saveProductMarking(int $product_id): void
    {
        if ($product_id <= 0
            || isset(self::$savedProducts[$product_id])
            || !isset($this->request->post['robokassa_marking_required'])) {
            return;
        }

        self::$savedProducts[$product_id] = true;
        $this->load->model('extension/robokassa/payment/robokassa');
        $this->model_extension_robokassa_payment_robokassa->installMarkingTables();
        $this->model_extension_robokassa_payment_robokassa->saveProductMarkingRequired(
            $product_id,
            (bool)$this->request->post['robokassa_marking_required']
        );
    }

    public function onOrderInfoViewAfter(string &$route, array &$data, mixed &$output): void
    {
        if (!is_string($output)) {
            return;
        }

        $order_id = (int)($data['order_id'] ?? ($this->request->get['order_id'] ?? 0));
        $order = $this->getOrderRow($order_id);

        if (!$order || !$this->isRobokassaOrder($order)) {
            return;
        }

        $this->load->language('extension/robokassa/payment/robokassa');
        $refund_url = $this->url->link('extension/robokassa/payment/robokassa|refund', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id, true);
        $refund_button = '<a href="' . $refund_url . '" class="btn btn-danger me-1" title="' . $this->language->get('button_robokassa_refund') . '"><i class="fas fa-undo"></i> ' . $this->language->get('button_robokassa_refund') . '</a>';

        if (strpos($output, $refund_url) === false && preg_match('~<div class="float-end">~', $output)) {
            $output = (string)preg_replace('~(<div class="float-end">)~', '$1' . $refund_button, $output, 1);
        }

        if (!$this->config->get('payment_robokassa_marking')
            || $this->config->get('payment_robokassa_country') !== 'RUB'
            || strpos($output, 'robokassa-marking-card') !== false) {
            return;
        }

        $this->load->model('extension/robokassa/payment/robokassa');
        $this->model_extension_robokassa_payment_robokassa->installMarkingTables();
        $products = $this->model_extension_robokassa_payment_robokassa->getOrderProductsForMarking($order_id);
        $incomplete = false;

        foreach ($products as $product) {
            if ((int)$product['marking_required'] && $product['marking_status'] !== 'filled') {
                $incomplete = true;
                break;
            }
        }

        $panel = $this->load->view('extension/robokassa/payment/robokassa_order_marking', [
            'products' => $products,
            'incomplete' => $incomplete,
            'heading_marking' => $this->language->get('heading_marking'),
            'column_product' => $this->language->get('column_product'),
            'column_quantity' => $this->language->get('column_quantity'),
            'column_marking' => $this->language->get('column_marking'),
            'text_marking_empty' => $this->language->get('text_marking_empty'),
            'text_marking_partial' => $this->language->get('text_marking_partial'),
            'text_marking_filled' => $this->language->get('text_marking_filled'),
            'text_marking_not_required' => $this->language->get('text_marking_not_required'),
            'warning_marking_incomplete' => $this->language->get('warning_marking_incomplete'),
            'button_marking_save' => $this->language->get('button_marking_save'),
            'marking_unit' => $this->language->get('marking_unit'),
            'error_marking_load' => $this->language->get('error_marking_load'),
            'error_marking_save' => $this->language->get('error_marking_save'),
            'marking_get_url' => $this->url->link('extension/robokassa/payment/robokassa|getOrderProductMarking', 'user_token=' . $this->session->data['user_token'], true),
            'marking_save_url' => $this->url->link('extension/robokassa/payment/robokassa|saveOrderProductMarking', 'user_token=' . $this->session->data['user_token'], true)
        ]);

        $history_marker = '~(<div class="card mb-3">\s*<div class="card-header"><i class="fas fa-comment"></i>)~';

        if (preg_match($history_marker, $output)) {
            $output = (string)preg_replace($history_marker, $panel . '$1', $output, 1);
        } elseif (strpos($output, '<form id="form-history"') !== false) {
            $output = str_replace('<form id="form-history"', $panel . '<form id="form-history"', $output);
        } else {
            $output .= $panel;
        }
    }

    private function shouldRunHold(int $order_id, int $new_status_id): bool
    {
        if ($order_id <= 0) return false;

        $confirm_status_id = (int)($this->config->get('payment_robokassa_hold_confirm_status_id') ?: 2);
        $cancel_status_id = (int)($this->config->get('payment_robokassa_hold_cancel_status_id') ?: 7);

        if ($new_status_id !== $confirm_status_id && $new_status_id !== $cancel_status_id) return false;
        if (!(int)$this->config->get('payment_robokassa_status_hold')) return false;

        $order = $this->getOrderRow($order_id);

        if (!$order) return false;

        $old_status_id = (int)$order['order_status_id'];
        $pending_status_id = (int)($this->config->get('payment_robokassa_hold_pending_status_id') ?: 1);

        if ($old_status_id !== $pending_status_id) return false;
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

        if ($run_hold) {
            $cancel_status_id = (int)($this->config->get('payment_robokassa_hold_cancel_status_id') ?: 7);
            $confirm_status_id = (int)($this->config->get('payment_robokassa_hold_confirm_status_id') ?: 2);

            if ($new_status_id === $cancel_status_id) {
                if (!$this->model_extension_robokassa_payment_robokassa->holdCancel($order_id)) {
                    throw new \RuntimeException('Robokassa не отменила захолдированный платеж. Статус заказа не изменен.');
                }

                return;
            }

            if ($new_status_id === $confirm_status_id
                && !$this->model_extension_robokassa_payment_robokassa->holdConfirm($order_id)) {
                throw new \RuntimeException('Robokassa не подтвердила списание захолдированного платежа. Статус заказа не изменен.');
            }
        }

        if ($send_second_check) {
            $this->model_extension_robokassa_payment_robokassa->sendSecondCheck($order_id);
        }
    }

    private function injectMessage(int $new_status_id, string $current): string
    {
        $msg = '';
        $cancel_status_id = (int)($this->config->get('payment_robokassa_hold_cancel_status_id') ?: 7);
        $confirm_status_id = (int)($this->config->get('payment_robokassa_hold_confirm_status_id') ?: 2);

        if ($new_status_id === $cancel_status_id) $msg = 'Robokassa: Платеж успешно отменен.';
        if ($new_status_id === $confirm_status_id) $msg = 'Robokassa: Платеж успешно подтвержден.';
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
