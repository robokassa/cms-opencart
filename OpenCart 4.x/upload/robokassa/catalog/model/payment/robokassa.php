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

        if (in_array($code, ['robokassa_mokka', 'robokassa_podeli', 'robokassa_yandex_split'], true)) {
            $graph_method = $this->getGraphPaymentMethod($code);
            $title .= '<div class="robokassa-checkout-graph" data-payment-method="' . $this->db->escape($graph_method) . '"><robokassa-graph merchantLogin="' . $this->db->escape((string)$this->config->get('payment_robokassa_login')) . '" outSum="' . number_format($total, 2, '.', '') . '" paymentMethod="' . $this->db->escape($graph_method) . '"></robokassa-graph></div>';
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
	
    protected static function formatSignReplace($string)
    {
    	return \strtr(
    		$string,
		    [
			    '+' => '-',
		        '/' => '_',
		    ]
	    );
    }


    protected static function formatSignFinish($string)
    {
    	return \preg_replace('/^(.*?)(=*)$/', '$1', $string);
    }
	

	public function getOrderProducts($order_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$order_id . "'");

		return $query->rows;
	}
	

	public function getUPCProduct($product_id) {
		$query = $this->db->query("SELECT upc FROM " . DB_PREFIX . "product WHERE product_id = '" . (int)$product_id . "'");

		return $query->row['upc'];
	}
	

	public function getTotalShipping($order_id, $code = 'shipping') {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$order_id . "' AND code = '" . $code . "'");

		return $query->row;
	}
	

	public function sendSecondCheck($order_id)
    {
		
		$this->load->model('checkout/order');
		
	    $order = $this->model_checkout_order->getOrder($order_id);

	    /** @var array $fields */
		$fields = [
		  	'merchantId' => $this->config->get('payment_robokassa_login'),
		    'id' => $order_id + 1,
		    'originId' => $order_id,
		    'operation' => 'sell',
		    'sno' => $this->config->get('payment_robokassa_tax_type'),
		    'url' => \urlencode('http://' . $_SERVER['HTTP_HOST']),
		    'total' => $order['total'],
		    'items' => [],
		    'client' => [
		    	'email' => $order['email'],
		    	'phone' => $order['telephone'],
		    ],
		    'payments' => [
			    [
			    	'type' => 2,
				    'sum' => $order['total']
			    ]
		    ],
		    'vats' => []
		];

		$products = $this->getOrderProducts($order_id);
		$shipping = $this->getTotalShipping($order_id);
		
		if (!empty($shipping)) {
			$shipping_name = $shipping['title'];
			$shipping_price = $shipping['value'];

			if ($shipping_price > 0) {

				$products_items = [	
					'name' => trim(htmlspecialchars($shipping_name)), 0, 63,
					'quantity' => 1,
					'sum' => $this->currency->format($shipping_price, 'RUB', false, false),
					'tax' => $this->config->get('payment_robokassa_tax'),
					'payment_method' => 'full_payment',
					'payment_object' => $this->config->get('payment_robokassa_payment_object'),
				];
				
				$fields['items'][] = $products_items;
				
				switch ($this->config->get('payment_robokassa_tax'))
				{
					case "vat0":
						 $fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => 0];
					case "none":
						$fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => 0];
					break;

					default:
						$fields['vats'][] = ['type' => 'novat', 'sum' => 0];
					break;

					case "vat10":
						$fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => ($price/100)*10];
					case "vat18":
						$fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => ($price/100)*18];
					case "vat20":
						$fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => ($price/100)*20];
					break;
				}

			}
		}
		
		foreach ($products as $product)
		{
			$price = $this->currency->format(($product['price']) * $product['quantity'], 'RUB', false, false);
			
		    $products_items = [
			    'name' => trim(htmlspecialchars($product['name'])), 0, 63,
			    'quantity' => $product['quantity'],
			    'sum' => $price,
			    'tax' => $this->config->get('payment_robokassa_tax'),
			    'payment_method' => 'full_payment',
			    'payment_object' => $this->config->get('payment_robokassa_payment_object'),
		    ];
			
			$UPC = $this->getUPCProduct($product['product_id']);
			
			if(!empty($UPC)){
				$products_items['nomenclature_code'] = mb_convert_encoding($UPC, 'UTF-8');
			}

		    $fields['items'][] = $products_items;
			
			switch ($this->config->get('payment_robokassa_tax'))
		    {
			    case "vat0":
					 $fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => 0];
			    case "none":
				    $fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => 0];
			    break;

			    default:
				    $fields['vats'][] = ['type' => 'novat', 'sum' => 0];
			    break;

			    case "vat10":
					$fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => ($price/100)*18];
			    case "vat18":
					$fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => ($price/100)*18];
			    case "vat20":
				    $fields['vats'][] = ['type' => $this->config->get('payment_robokassa_tax'), 'sum' => ($price/100)*20];
			    break;
		    }
	    }

	    /** @var string $startupHash */
	    $startupHash = $this->formatSignFinish(
	    	\base64_encode(
	    		$this->formatSignReplace(
				    json_encode($fields)
			    )
		    )
	    );

	    /** @var string $sign */
	    $sign = $this->formatSignFinish(
	    	\base64_encode(
	    	    \md5(
	    	    	$startupHash .
			        ($this->config->get('payment_robokassa_test') === 1 ? $this->config->get('payment_robokassa_test_password_1') : $this->config->get('payment_robokassa_password_1'))
		        )
		    )
	    );
		
		$curl = curl_init('https://ws.roboxchange.com/RoboFiscal/Receipt/Attach');
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($curl, CURLOPT_POSTFIELDS, $startupHash . '.' . $sign);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
		   'Content-Type: application/json',
		   'Content-Length: ' . strlen($startupHash . '.' . $sign))
		);
		$result = curl_exec($curl);
		curl_close($curl);
    }
}
