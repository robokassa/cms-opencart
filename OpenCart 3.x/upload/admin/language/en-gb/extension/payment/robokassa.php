<?php
// Heading
$_['heading_title']   	 	= 'Робокасса';

// Text 
$_['text_payment']      	= 'Оплата';
$_['text_success']       	= 'Настройки модуля оплаты Робокасса обновлены!';
$_['text_robokassa'] 		= '<a target="_blank" href="https://robokassa.ru/"><img src="/admin/view/image/payment/robokassa.png" alt="robokassa" style="max-width:140px" /></a>';
$_['text_edit']      	    = 'Редактирование модуля "Робокасса"';
$_['text_enabled']          = 'Включено';
$_['text_disabled']         = 'Отключено';
$_['text_yes']           	= 'Да';
$_['text_no']            	= 'Нет';
$_['text_kz']           	= 'Казахстан';
$_['text_ru']            	= 'Россия';
$_['text_marking_saved']    = 'Marking codes have been saved.';
$_['text_marking_empty']    = 'Not filled';
$_['text_marking_partial']  = 'Partially filled';
$_['text_marking_filled']   = 'Filled';
$_['text_marking_not_required'] = 'Not required';

// Entry
$_['entry_merch_login']     = 'Идентификатор магазина';
$_['entry_password1']    	= 'Пароль 1';
$_['entry_password2']    	= 'Пароль 2';
$_['entry_test_password1']  = 'Тестовый пароль 1';
$_['entry_test_password2']  = 'Тестовый пароль 2';
$_['entry_password2']    	= 'Пароль 2';
$_['entry_test']         	= 'Тестовый режим';
$_['entry_tax_type']        = 'Система налогообложения';
$_['entry_tax']         	= 'Налоговая ставка';
$_['entry_payment_method']  = 'Признак способа расчёта';
$_['entry_payment_object']  = 'Признак предмета расчёта';
$_['entry_fiscal']         	= '<a href="https://fiscal.robokassa.ru/" target="_blank">Фискализация</a>';
$_['entry_result_url']     	= 'Result URL';
$_['entry_success_url']     = 'Success URL';
$_['entry_fail_url']      	= 'Fail URL';
$_['entry_order_status'] 	= 'Статус заказа после оплаты';
$_['entry_geo_zone']     	= 'Географическая зона';
$_['entry_status']       	= 'Статус';
$_['entry_sort_order']   	= 'Порядок сортировки';
$_['entry_country']   		= 'Страна магазина';
$_['entry_iframe']   		= 'Включить iframe';
$_['entry_product_options'] = 'Передавать опции товара';
$_['entry_languages_map']   = 'Язык интерфейса платёжной страницы';
$_['entry_marking']          = 'Send product marking';
$_['entry_marking_required'] = 'Marked product';
$_['tab_robokassa_marking']  = 'Robokassa marking';
$_['column_marking']         = 'Marking';
$_['button_marking_save']    = 'Save';
$_['marking_unit']           = 'Product unit';

// Help
$_['help_iframe']         	= 'При включённом iframe, способов оплаты меньше, чем в обычной платежной странице - только карты, Apple и Samsung pay, Qiwi. IncCurrLabel работает, но ограничено.';
$_['help_fiscal']         	= 'Режим работы для решений - Облачное. Кассовое. Робочеки.';
$_['help_test']         	= 'Режим для отладки модуля. Информацию можно посмотреть в файле storage/logs/robo.log';
$_['help_marking']          = 'Adds a marked-product flag and lets an administrator scan one DataMatrix code per product unit before the second receipt is sent.';
$_['help_marking_required'] = 'A separate DataMatrix code will be required for every unit before the second receipt is sent.';

// Error
$_['error_permission']   	= 'Внимание: У Вас недостаточно прав для управления модулем оплаты Робокасса!';
$_['error_merch_login']     = 'Требуется указать логин!';
$_['error_password1']    	= 'Требуется ввести пароль 1!';
$_['error_password2']    	= 'Требуется ввести пароль 2!';
$_['error_marking_permission'] = 'You do not have permission to change order marking.';
$_['error_marking_product'] = 'The product was not found or is not marked as requiring marking codes.';
$_['error_marking_format'] = 'The code for unit #%d has an invalid format. Use an English keyboard layout and preserve the GS separator.';
$_['error_marking_duplicate'] = 'Duplicate marking codes are not allowed within one order item.';
$_['error_marking_load'] = 'Unable to load marking codes.';
$_['error_marking_save'] = 'Unable to save marking codes.';
$_['warning_marking_incomplete'] = 'Some marked products have missing codes. The second receipt cannot be sent.';
?>
