<?php
namespace Opencart\Catalog\Model\Extension\Robokassa\Payment;
class Robokassa extends \Opencart\System\Engine\Model {
	public function getMethod($address) {
		$this->load->language('extension/robokassa/payment/robokassa');
		
		if ($this->config->get('payment_robokassa_status')) {
			  $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_robokassa_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
			
			if (!$this->config->get('payment_robokassa_geo_zone_id')) {
				$status = TRUE;
			} elseif ($query->num_rows) {
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
				'code'       => 'robokassa',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('payment_robokassa_sort_order')
			);
		}
		return $method_data;
	}
	
	// Подготовка строки перед кодированием в base64
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

	// Подготовка строки после кодирования в base64
    protected static function formatSignFinish($string)
    {
    	return \preg_replace('/^(.*?)(=*)$/', '$1', $string);
    }
	
	// Товары заказа
	public function getOrderProducts($order_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$order_id . "'");

		return $query->rows;
	}
	
	// UPC
	public function getUPCProduct($product_id) {
		$query = $this->db->query("SELECT upc FROM " . DB_PREFIX . "product WHERE product_id = '" . (int)$product_id . "'");

		return $query->row['upc'];
	}
	
	// shipping
	public function getTotalShipping($order_id, $code = 'shipping') {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$order_id . "' AND code = '" . $code . "'");

		return $query->row;
	}
	
	// Отправка второго чека
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
					'payment_method' => 'full_prepayment',
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
			    'payment_method' => 'full_prepayment',
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