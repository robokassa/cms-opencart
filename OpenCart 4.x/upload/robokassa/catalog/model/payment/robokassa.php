<?php
namespace Opencart\Catalog\Model\Extension\Robokassa\Payment;

class Robokassa extends \Opencart\System\Engine\Model {

    public function getMethod($address)
    {
        $this->load->language('extension/robokassa/payment/robokassa');

        return [
            'code'       => 'robokassa',
            'title'      => $this->language->get('text_title'),
            'name'       => $this->language->get('text_title'),
            'sort_order' => $this->config->get('payment_robokassa_sort_order'),
        ];
    }

    public function getMethods($address)
    {
        $this->load->language('extension/robokassa/payment/robokassa');

        $option_data['robokassa'] = [
            'code' => 'robokassa.robokassa',
            'name' => $this->language->get('text_title')
        ];

        $method_data = array(
            'code'          => 'robokassa',
            'option'        => $option_data,
            'name'          => $this->language->get('text_title'),
            'sort_order'    => $this->config->get('payment_robokassa_sort_order'),
        );

        return $method_data;
    }

    protected function getExtraMethod(array $address, string $code, string $alias, string $title, float $min, float $max, string $status_key, string $sort_order_key): array
    {
        if (!$this->config->get('payment_robokassa_status') || $this->config->get('payment_robokassa_country') !== 'RUB') {
            return [];
        }

        if (!$this->config->get($status_key) || !$this->hasSyncedAlias($alias)) {
            return [];
        }

        $total = $this->getCartTotal();

        if ($total < $min || $total > $max || !$this->matchesGeoZone($address)) {
            return [];
        }

        $method = [
            'code'       => $code,
            'title'      => $title,
            'name'       => $title,
            'sort_order' => $this->config->get($sort_order_key)
        ];

        if (defined('VERSION')
            && version_compare(VERSION, '4.0.2.0', '<')
            && !empty($this->session->data['robokassa_widget_payment_code'])
            && (string)$this->session->data['robokassa_widget_payment_code'] === $code) {
            $this->session->data['payment_method'] = $code;
            unset($this->session->data['robokassa_widget_payment_code']);
        }

        return $method;
    }

    protected function getExtraMethods(array $address, string $code, string $alias, string $title, float $min, float $max, string $status_key, string $sort_order_key): array
    {
        $method = $this->getExtraMethod($address, $code, $alias, $title, $min, $max, $status_key, $sort_order_key);

        if (!$method) {
            return [];
        }

        if (!empty($this->session->data['robokassa_widget_payment_code']) && (string)$this->session->data['robokassa_widget_payment_code'] === $code) {
            $this->session->data['payment_method'] = [
                'code' => $code . '.' . $code,
                'name' => $method['name']
            ];

            unset($this->session->data['robokassa_widget_payment_code']);
        }

        return [
            'code'       => $code,
            'option'     => [
                $code => [
                    'code' => $code . '.' . $code,
                    'name' => $method['name']
                ]
            ],
            'name'       => $method['name'],
            'sort_order' => $method['sort_order']
        ];
    }

    protected function getGraphPaymentMethod(string $code): string
    {
        $map = [
            'robokassa_mokka' => 'Mokka',
            'robokassa_podeli' => 'Podeli',
            'robokassa_yandex_split' => 'YandexPaySplit'
        ];

        return $map[$code] ?? '';
    }

    protected function hasSyncedAlias(string $alias): bool
    {
        $aliases = $this->config->get('payment_robokassa_methods_aliases');

        if (!is_array($aliases)) {
            return false;
        }

        return in_array(strtolower($alias), array_map('strtolower', $aliases), true);
    }

    protected function getCartTotal(): float
    {
        if (method_exists($this->cart, 'getTotal')) {
            return (float)$this->cart->getTotal();
        }

        return (float)$this->cart->getSubTotal();
    }

    protected function matchesGeoZone(array $address): bool
    {
        $geo_zone_id = (int)$this->config->get('payment_robokassa_geo_zone_id');

        if (!$geo_zone_id) {
            return true;
        }

        if (empty($address['country_id'])) {
            return false;
        }

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE geo_zone_id = '" . $geo_zone_id . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)($address['zone_id'] ?? 0) . "' OR zone_id = '0')");

        return (bool)$query->num_rows;
    }
}
