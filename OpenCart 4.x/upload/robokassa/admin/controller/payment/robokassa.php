<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;
class Robokassa extends \Opencart\System\Engine\Controller
{
	private array $error = [];

	public function index(): void
	{
		$this->load->language('extension/robokassa/payment/robokassa');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');
		$this->load->model('localisation/language');

		if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_robokassa', $this->request->post);

			$this->registerEvents();

			$this->load->model('user/user_group');
			$route = 'extension/robokassa/event/robokassa';
			$this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', $route);
			$this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', $route);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}

		$data['error_warning'] = $this->error['warning'] ?? '';
		$data['error_merch_login'] = $this->error['merch_login'] ?? '';
		$data['error_password1'] = $this->error['e_password1'] ?? '';
		$data['error_password2'] = $this->error['e_password2'] ?? '';

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_payment'),
			'href' => $this->url->link('marketplace/opencart/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/robokassa/payment/robokassa', 'user_token=' . $this->session->data['user_token'])
		];

		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

		$data['text_edit'] = $this->language->get('text_edit');
		$data['text_enabled'] = $this->language->get('text_enabled');
		$data['text_disabled'] = $this->language->get('text_disabled');
		$data['text_all_zones'] = $this->language->get('text_all_zones');
		$data['entry_tax'] = $this->language->get('entry_tax');
		$data['entry_tax_type'] = $this->language->get('entry_tax_type');
		$data['entry_payment_method'] = $this->language->get('entry_payment_method');
		$data['entry_payment_object'] = $this->language->get('entry_payment_object');
		$data['entry_fiscal'] = $this->language->get('entry_fiscal');
		$data['text_yes'] = $this->language->get('text_yes');
		$data['text_no'] = $this->language->get('text_no');
		$data['text_kz'] = $this->language->get('text_kz');
		$data['text_ru'] = $this->language->get('text_ru');

		$data['entry_login'] = $this->language->get('entry_login');
		$data['entry_password1'] = $this->language->get('entry_password1');
		$data['entry_password2'] = $this->language->get('entry_password2');
		$data['entry_test_password1'] = $this->language->get('entry_test_password1');
		$data['entry_test_password2'] = $this->language->get('entry_test_password2');
		$data['entry_result_url'] = $this->language->get('entry_result_url');
		$data['entry_success_url'] = $this->language->get('entry_success_url');
		$data['entry_fail_url'] = $this->language->get('entry_fail_url');
		$data['entry_test'] = $this->language->get('entry_test');
		$data['entry_order_status'] = $this->language->get('entry_order_status');
		$data['entry_geo_zone'] = $this->language->get('entry_geo_zone');
		$data['entry_status'] = $this->language->get('entry_status');
		$data['entry_sort_order'] = $this->language->get('entry_sort_order');
		$data['entry_country'] = $this->language->get('entry_country');
		$data['entry_iframe'] = $this->language->get('entry_iframe');
		$data['entry_hold'] = $this->language->get('entry_hold');

		$data['help_fiscal'] = $this->language->get('help_fiscal');
		$data['help_iframe'] = $this->language->get('help_iframe');
		$data['help_hold'] = $this->language->get('help_hold');

		$data['action'] = $this->url->link('extension/robokassa/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

		$data['opencart_languages'] = $this->model_localisation_language->getLanguages();

		$data['payment_robokassa_login'] = $this->request->post['payment_robokassa_login'] ?? $this->config->get('payment_robokassa_login');
		$data['payment_robokassa_password_1'] = $this->request->post['payment_robokassa_password_1'] ?? $this->config->get('payment_robokassa_password_1');
		$data['payment_robokassa_password_2'] = $this->request->post['payment_robokassa_password_2'] ?? $this->config->get('payment_robokassa_password_2');
		$data['payment_robokassa_test_password_1'] = $this->request->post['payment_robokassa_test_password_1'] ?? $this->config->get('payment_robokassa_test_password_1');
		$data['payment_robokassa_test_password_2'] = $this->request->post['payment_robokassa_test_password_2'] ?? $this->config->get('payment_robokassa_test_password_2');

		if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
			$data['payment_robokassa_result_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/robokassa/payment/result';
			$data['payment_robokassa_success_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/robokassa/payment/success';
			$data['payment_robokassa_fail_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/robokassa/payment/fail';
		} else {
			$data['payment_robokassa_result_url'] = HTTP_CATALOG . 'index.php?route=extension/robokassa/payment/result';
			$data['payment_robokassa_success_url'] = HTTP_CATALOG . 'index.php?route=extension/robokassa/payment/success';
			$data['payment_robokassa_fail_url'] = HTTP_CATALOG . 'index.php?route=extension/robokassa/payment/fail';
		}

		$data['payment_robokassa_test'] = $this->request->post['payment_robokassa_test'] ?? $this->config->get('payment_robokassa_test');

		if (isset($this->request->post['payment_robokassa_country'])) {
			$data['payment_robokassa_country'] = $this->request->post['payment_robokassa_country'];
		} elseif ($this->config->get('payment_robokassa_country')) {
			$data['payment_robokassa_country'] = $this->config->get('payment_robokassa_country');
		} else {
			$data['payment_robokassa_country'] = 'RUB';
		}

		$data['payment_robokassa_languages_map'] = $this->request->post['payment_robokassa_languages_map'] ?? $this->config->get('payment_robokassa_languages_map');

		$data['robokassa_available_languages'] = ['en', 'ru'];

		$data['robokassa_tax_type_list'] = [
			'osn' => 'общая СН',
			'usn_income' => 'упрощенная СН (доходы)',
			'usn_income_outcome' => 'упрощенная СН (доходы минус расходы)',
			'envd' => 'единый налог на вмененный доход',
			'esn' => 'единый сельскохозяйственный налог',
			'patent' => 'патентная СН',
		];

		$data['robokassa_tax_list'] = [
			'none' => 'без НДС',
			'vat0' => 'НДС по ставке 0%',
			'vat5' => 'НДС по ставке 5%',
			'vat7' => 'НДС по ставке 7%',
			'vat10' => 'НДС чека по ставке 10%',
			'vat20' => 'НДС чека по ставке 20%',
			'vat22' => 'НДС чека по ставке 22%',
			'vat105' => 'НДС чека по расчетной ставке 5/105',
			'vat107' => 'НДС чека по расчетной ставке 7/107',
			'vat110' => 'НДС чека по расчетной ставке 10/110',
			'vat120' => 'НДС чека по расчетной ставке 20/120',
			'vat122' => 'НДС чека по расчетной ставке 22/122'
		];

		$data['robokassa_tax_list_kz'] = [
			'none' => 'без НДС',
			'vat0' => 'НДС чека по ставке 8%',
			'vat12' => 'НДС чека по ставке 12%',
		];

		$data['robokassa_payment_method_list'] = [
			'full_prepayment' => 'предоплата 100% (по умолчанию)',
			'prepayment' => 'предоплата',
			'advance' => 'аванс',
			'full_payment' => 'полный расчет',
			'partial_payment' => 'частичный расчет и кредит',
			'credit' => 'передача в кредит',
			'credit_payment' => 'оплата кредита',
		];

		$data['robokassa_payment_object_list'] = [
			'commodity' => 'товар (по умолчанию)',
			'excise' => 'подакцизный товар',
			'job' => 'работа',
			'service' => 'услуга',
			'gambling_bet' => 'ставка азартной игры',
			'gambling_prize' => 'выигрыш азартной игры',
			'lottery' => 'лотерейный билет',
			'lottery_prize' => 'выигрыш лотереи',
			'intellectual_activity' => 'предоставление результатов интеллектуальной деятельности',
			'payment' => 'платеж',
			'agent_commission' => 'агентское вознаграждение',
			'composite' => 'составной предмет расчета',
			'another' => 'иной предмет расчета',
		];

		$data['payment_robokassa_tax_type'] = $this->request->post['payment_robokassa_tax_type'] ?? $this->config->get('payment_robokassa_tax_type');
		$data['payment_robokassa_tax'] = $this->request->post['payment_robokassa_tax'] ?? $this->config->get('payment_robokassa_tax');
		$data['payment_robokassa_fiscal'] = $this->request->post['payment_robokassa_fiscal'] ?? $this->config->get('payment_robokassa_fiscal');
		$data['payment_robokassa_payment_method'] = $this->request->post['payment_robokassa_payment_method'] ?? $this->config->get('payment_robokassa_payment_method');
		$data['payment_robokassa_payment_object'] = $this->request->post['payment_robokassa_payment_object'] ?? $this->config->get('payment_robokassa_payment_object');

		$data['payment_robokassa_order_status_id'] = $this->request->post['payment_robokassa_order_status_id'] ?? $this->config->get('payment_robokassa_order_status_id');
		$data['payment_robokassa_order_status_id_2check'] = $this->request->post['payment_robokassa_order_status_id_2check'] ?? $this->config->get('payment_robokassa_order_status_id_2check');

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['payment_robokassa_geo_zone_id'] = $this->request->post['payment_robokassa_geo_zone_id'] ?? $this->config->get('payment_robokassa_geo_zone_id');

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['payment_robokassa_status'] = $this->request->post['payment_robokassa_status'] ?? $this->config->get('payment_robokassa_status');
		$data['payment_robokassa_status_iframe'] = $this->request->post['payment_robokassa_status_iframe'] ?? $this->config->get('payment_robokassa_status_iframe');
		$data['payment_robokassa_status_hold'] = $this->request->post['payment_robokassa_status_hold'] ?? $this->config->get('payment_robokassa_status_hold');
		$data['payment_robokassa_sort_order'] = $this->request->post['payment_robokassa_sort_order'] ?? $this->config->get('payment_robokassa_sort_order');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/robokassa/payment/robokassa', $data));
	}

	private function validate(): bool
	{
		if (!$this->user->hasPermission('modify', 'extension/robokassa/payment/robokassa')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (empty($this->request->post['payment_robokassa_login'])) {
			$this->error['merch_login'] = $this->language->get('error_merch_login');
		}

		if (empty($this->request->post['payment_robokassa_password_1'])) {
			$this->error['e_password1'] = $this->language->get('error_password1');
		}

		if (empty($this->request->post['payment_robokassa_password_2'])) {
			$this->error['e_password2'] = $this->language->get('error_password2');
		}

		return !$this->error;
	}

	private function getModuleVersion(): string
	{
		$version = 'unknown';
		$file = DIR_EXTENSION . 'robokassa/install.json';

		if (is_file($file)) {
			$json = file_get_contents($file);
			if ($json) {
				$data = json_decode($json, true);
				if (isset($data['version']) && $data['version']) {
					$version = (string)$data['version'];
				}
			}
		}

		return $version;
	}

	private function sendPulseStatusChange(string $status): void
	{
		$apiUrl = 'https://pulse.robokassa.com/api/module-status';
		$apiKey = 'robokassa-plugin-stat-key-3953';

		$merchantId = (string)$this->config->get('payment_robokassa_login');
		if ($merchantId === '') {
			$merchantId = 'unknown';
		}

		$siteUrl = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG;

		$payload = [
			'cms'         => 'opencart4',
			'merchant_id' => $merchantId,
			'site_id'     => $siteUrl,
			'status'      => $status,
			'reported_at' => date('Y-m-d H:i:s'),
			'version'     => $this->getModuleVersion(),
		];

		$ch = curl_init($apiUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'X-API-KEY: ' . $apiKey,
		]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_exec($ch);
		curl_close($ch);
	}

	public function install(): void
	{
		$this->load->model('user/user_group');
		$route = 'extension/robokassa/event/robokassa';
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', $route);
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', $route);

		$this->registerEvents();
		$this->sendPulseStatusChange('enabled');
	}

	public function uninstall(): void
	{
		$this->unregisterEvents();
		$this->sendPulseStatusChange('disabled');
	}

	private function registerEvents(): void
	{
		$this->load->model('setting/event');

		$codes = [
			'robokassa_hold_call_before_dot',
			'robokassa_hold_call_before_pipe',
			'robokassa_hold_addhistory_before_dot',
			'robokassa_hold_addhistory_before_pipe',
		];

		foreach ($codes as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}

		$events = [
			[
				'code'        => 'robokassa_hold_call_before_dot',
				'description' => 'Robokassa hold cancel (sale/order.call before, dot)',
				'trigger'     => 'admin/controller/sale/order.call/before',
				'action'      => 'extension/robokassa/event/robokassa.onOrderCall',
				'status'      => 1,
				'sort_order'  => 0
			],
			[
				'code'        => 'robokassa_hold_call_before_pipe',
				'description' => 'Robokassa hold cancel (sale/order|call before, pipe)',
				'trigger'     => 'admin/controller/sale/order|call/before',
				'action'      => 'extension/robokassa/event/robokassa|onOrderCall',
				'status'      => 1,
				'sort_order'  => 1
			],
			[
				'code'        => 'robokassa_hold_addhistory_before_dot',
				'description' => 'Robokassa hold cancel (sale/order.addHistory before, dot)',
				'trigger'     => 'admin/model/sale/order.addHistory/before',
				'action'      => 'extension/robokassa/event/robokassa.onOrderAddHistory',
				'status'      => 1,
				'sort_order'  => 2
			],
			[
				'code'        => 'robokassa_hold_addhistory_before_pipe',
				'description' => 'Robokassa hold cancel (sale/order|addHistory before, pipe)',
				'trigger'     => 'admin/model/sale/order|addHistory/before',
				'action'      => 'extension/robokassa/event/robokassa|onOrderAddHistory',
				'status'      => 1,
				'sort_order'  => 3
			],
		];

		foreach ($events as $event) {
			$this->model_setting_event->addEvent($event);
		}
	}

	private function unregisterEvents(): void
	{
		$this->load->model('setting/event');

		$codes = [
			'robokassa_hold_call_before_dot',
			'robokassa_hold_call_before_pipe',
			'robokassa_hold_addhistory_before_dot',
			'robokassa_hold_addhistory_before_pipe',
		];

		foreach ($codes as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}
	}
}
