<?php
class ModelExtensionPaymentRobokassaSbp extends Model {
    private function getCurrencyData($alias) {
        $url = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/GetCurrencies?MerchantLogin=' . $this->config->get('payment_robokassa_login') . '&Language=ru';
        $xml = @file_get_contents($url);

        if ($xml === false) {
            return array();
        }

        $xmlObj = simplexml_load_string($xml);

        if ($xmlObj === false || !isset($xmlObj->Groups->Group->Items->Currency)) {
            return array();
        }

        $namespaces = $xmlObj->getNamespaces(true);
        $root = isset($namespaces['']) ? $xmlObj->children($namespaces['']) : $xmlObj;

        foreach ($root->Groups->Group as $group) {
            if (!isset($group->Items->Currency)) {
                continue;
            }

            foreach ($group->Items->Currency as $currency) {
                if (!isset($currency->attributes()->Alias)) {
                    continue;
                }

                if ((string)$currency->attributes()->Alias !== $alias) {
                    continue;
                }

                return array(
                    'alias' => $alias,
                    'min' => isset($currency->attributes()->MinValue) ? (float)str_replace(',', '.', (string)$currency->attributes()->MinValue) : 0.0,
                    'max' => isset($currency->attributes()->MaxValue) ? (float)str_replace(',', '.', (string)$currency->attributes()->MaxValue) : 0.0
                );
            }
        }

        return array();
    }

    private function isAmountAllowed(array $currency_data, $total) {
        $total = (float)$total;

        if (!$currency_data || $total <= 0) {
            return false;
        }

        if (!empty($currency_data['min']) && $total < (float)$currency_data['min']) {
            return false;
        }

        if (!empty($currency_data['max']) && $total > (float)$currency_data['max']) {
            return false;
        }

        return true;
    }

    public function getMethod($address, $total) {
        $this->load->language('extension/payment/robokassa_sbp');

        if (!$this->config->get('payment_robokassa_status')
            || !$this->config->get('payment_robokassa_sbp_status')
            || $this->config->get('payment_robokassa_country') != 'RUB') {
            return array();
        }

        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_robokassa_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

        $currency_data = $this->getCurrencyData('SBP');

        if (!$this->config->get('payment_robokassa_geo_zone_id') && $this->isAmountAllowed($currency_data, $total)) {
            $status = true;
        } elseif ($query->num_rows && $this->isAmountAllowed($currency_data, $total)) {
            $status = true;
        } else {
            $status = false;
        }

        if (!$status) {
            return array();
        }

        $sort_order = $this->config->get('payment_robokassa_sbp_sort_order');

        if ($sort_order === null || $sort_order === '') {
            $sort_order = $this->config->get('payment_robokassa_sort_order');
        }

        return array(
            'code'       => 'robokassa_sbp',
            'title'      => $this->language->get('text_title'),
            'terms'      => '',
            'sort_order' => $sort_order
        );
    }
}
