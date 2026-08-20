<?php
// Heading
$_['heading_title']   	 	= 'Робокасса';
$_['heading_refund']       = 'Robokassa refund — order #%d';

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
$_['text_order']            = 'Orders';
$_['text_refund_submitting'] = 'Submitting';
$_['text_refund_unknown']    = 'Unknown';
$_['text_refund_processing'] = 'Processing';
$_['text_refund_finished']   = 'Finished';
$_['text_refund_canceled']   = 'Canceled';
$_['text_refund_failed']     = 'Failed';
$_['text_refund_without_receipt'] = '(without a fiscal refund receipt)';

// Entry
$_['entry_merch_login']     = 'Идентификатор магазина';
$_['entry_password1']    	= 'Пароль 1';
$_['entry_password2']    	= 'Пароль 2';
$_['entry_password3']      = 'Password 3';
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
$_['entry_hold']              = 'Deferred payments';
$_['entry_hold_pending_status'] = 'Status after authorization hold';
$_['entry_hold_confirm_status'] = 'Status to capture the hold';
$_['entry_hold_cancel_status'] = 'Status to cancel the hold';
$_['entry_product_options'] = 'Передавать опции товара';
$_['entry_languages_map']   = 'Язык интерфейса платёжной страницы';
$_['entry_marking']          = 'Send product marking';
$_['entry_marking_required'] = 'Marked product';
$_['tab_robokassa_marking']  = 'Robokassa marking';
$_['column_marking']         = 'Marking';
$_['button_marking_save']    = 'Save';
$_['button_robokassa_refund'] = 'Robokassa refund';
$_['marking_unit']           = 'Product unit';

// Help
$_['help_iframe']         	= 'При включённом iframe, способов оплаты меньше, чем в обычной платежной странице - только карты, Apple и Samsung pay, Qiwi. IncCurrLabel работает, но ограничено.';
$_['help_fiscal']         	= 'Режим работы для решений - Облачное. Кассовое. Робочеки.';
$_['help_test']         	= 'Режим для отладки модуля. Информацию можно посмотреть в файле storage/logs/robo.log';
$_['help_hold']             = 'This service requires prior activation and is available only for bank card payments.';
$_['help_hold_statuses']    = 'Moving an order from the hold status to the capture or cancel status performs the corresponding operation in Robokassa.';
$_['help_marking']          = 'Adds a marked-product flag and lets an administrator scan one DataMatrix code per product unit before the second receipt is sent.';
$_['help_marking_required'] = 'A separate DataMatrix code will be required for every unit before the second receipt is sent.';
$_['help_password3']        = 'Used only for refunds. Generate Password3 in your Robokassa account.';

// Error
$_['error_permission']   	= 'Внимание: У Вас недостаточно прав для управления модулем оплаты Робокасса!';
$_['error_merch_login']     = 'Требуется указать логин!';
$_['error_password1']    	= 'Требуется ввести пароль 1!';
$_['error_password2']    	= 'Требуется ввести пароль 2!';
$_['error_hold_statuses']   = 'Select three different existing order statuses for payment holds.';
$_['error_marking_permission'] = 'You do not have permission to change order marking.';
$_['error_marking_product'] = 'The product was not found or is not marked as requiring marking codes.';
$_['error_marking_format'] = 'The code for unit #%d has an invalid format. Use an English keyboard layout and preserve the GS separator.';
$_['error_marking_duplicate'] = 'Duplicate marking codes are not allowed within one order item.';
$_['error_marking_load'] = 'Unable to load marking codes.';
$_['error_marking_save'] = 'Unable to save marking codes.';
$_['warning_marking_incomplete'] = 'Some marked products have missing codes. The second receipt cannot be sent.';
$_['error_refund_permission'] = 'You do not have permission to issue a Robokassa refund.';
$_['error_refund_order'] = 'The refund order was not found.';
$_['error_refund_not_found'] = 'The refund request was not found.';
$_['error_refund_payment'] = 'This order was not paid through Robokassa.';
$_['error_refund_country'] = 'The Refund API is available only for Robokassa Russia.';
$_['error_refund_currency'] = 'The Refund API supports RUB orders only.';
$_['error_refund_test'] = 'The Refund API does not support test payments.';
$_['error_refund_password3'] = 'Enter Password3 in the Robokassa settings.';
$_['error_refund_amount'] = 'Enter a valid refund amount.';
$_['error_refund_amount_available'] = 'The refund amount must be greater than zero and not exceed %s.';
$_['error_refund_state'] = 'Robokassa returned an unknown refund status.';
$_['error_refund_rejected'] = 'Robokassa rejected the refund request.';
$_['error_refund_unknown_retry'] = 'Do not retry: check the refund in your Robokassa account first.';
$_['success_refund_created'] = 'Refund request created. ID: %s';
$_['note_refund_created'] = 'Robokassa: refund %s accepted. ID: %s%s';
$_['note_refund_finished'] = 'Robokassa: refund %s finished. ID: %s';
$_['note_refund_canceled'] = 'Robokassa: refund canceled. ID: %s';
$_['note_refund_unknown'] = 'Robokassa: refund submission result is unknown. Check the refund status in your Robokassa account before retrying.';
?>
