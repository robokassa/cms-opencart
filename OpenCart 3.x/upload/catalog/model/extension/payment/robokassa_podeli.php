<?php
class ModelExtensionPaymentRobokassaPodeli extends Model {
    public function checkCurrency($alias) {
        $url = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/GetCurrencies?MerchantLogin=' . $this->config->get('payment_robokassa_login') . '&Language=ru';
        $xml = file_get_contents($url);

        $xmlObj = simplexml_load_string($xml);

        if ($xmlObj !== false && isset($xmlObj->Groups->Group->Items->Currency)) {
            $namespaces = $xmlObj->getNamespaces(true);

            foreach ($xmlObj->children($namespaces[''])->Groups->Group as $group) {
                if (isset($group->Items->Currency)) {
                    foreach ($group->Items->Currency as $currency) {
                        if (isset($currency->attributes()->Alias)) {
                            $result = (string) $currency->attributes()->Alias;

                            if ($result == $alias) {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    public function getMethod($address, $total) {
        $this->load->language('extension/payment/robokassa_podeli');

        if ($this->config->get('payment_robokassa_status')) {
            $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_robokassa_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

            if (!$this->config->get('payment_robokassa_geo_zone_id') && $total >= 300 && $total <= 30000 && $this->checkCurrency('Podeli')) {
                $status = TRUE;
            } elseif ($query->num_rows && $total >= 300 && $total <= 30000 && $this->checkCurrency('Podeli')) {
                $status = TRUE;
            } else {
                $status = FALSE;
            }
        } else {
            $status = FALSE;
        }

        $method_data = array();

        if ($status) {
            $method_data = array(
                'code'       => 'robokassa_podeli',
                'title'      => $this->language->get('text_title'),
                'terms'      => '',
                'sort_order' => $this->config->get('payment_robokassa_podeli_sort_order')
            );
        }
        return $method_data;
    }

}