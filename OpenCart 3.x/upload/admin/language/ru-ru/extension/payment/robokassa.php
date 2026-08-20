<?php
// Heading
$_['heading_title']   	 	= 'Робокасса';
$_['heading_refund']       = 'Возврат Robokassa — заказ #%d';

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
$_['text_marking_saved']    = 'Коды маркировки сохранены.';
$_['text_marking_empty']    = 'Не заполнено';
$_['text_marking_partial']  = 'Заполнено частично';
$_['text_marking_filled']   = 'Заполнено';
$_['text_marking_not_required'] = 'Не требуется';
$_['text_order']            = 'Заказы';
$_['text_refund_submitting'] = 'Отправляется';
$_['text_refund_unknown']    = 'Статус неизвестен';
$_['text_refund_processing'] = 'В обработке';
$_['text_refund_finished']   = 'Завершён';
$_['text_refund_canceled']   = 'Отменён';
$_['text_refund_failed']     = 'Ошибка';
$_['text_refund_without_receipt'] = '(без формирования чека возврата)';

// Entry
$_['entry_merch_login']     = 'Идентификатор магазина';
$_['entry_password1']    	= 'Пароль 1';
$_['entry_password2']    	= 'Пароль 2';
$_['entry_password3']      = 'Пароль 3';
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
$_['entry_podeli']   		= 'Оплата через Podeli';
$_['entry_hold']   	        = 'Отложенные платежи';
$_['entry_marking']          = 'Передавать маркировку';
$_['entry_marking_required'] = 'Маркируемый товар';
$_['tab_robokassa_marking']  = 'Маркировка Robokassa';
$_['column_marking']         = 'Маркировка';
$_['button_marking_save']    = 'Сохранить';
$_['button_robokassa_refund'] = 'Возврат Robokassa';
$_['marking_unit']           = 'Экземпляр товара';

// Help
$_['help_iframe']         	= 'При включённом iframe, способов оплаты меньше, чем в обычной платежной странице - только карты, Apple и Samsung pay, Qiwi. IncCurrLabel работает, но ограничено.';
$_['help_fiscal']         	= 'Режим работы для решений - Облачное. Кассовое. Робочеки.';
$_['help_test']         	= 'Режим для отладки модуля. Информацию можно посмотреть в файле storage/logs/robo.log';
$_['help_podeli']           = 'Включает способ оплаты RobokassaXPodeli. Позволяет разбить сумму заказа на части и оплатить её частями. Доступно для заказов на сумму от 300 до 3000';
$_['help_hold']             = 'Данная услуга доступна только по предварительному согласованию. Функционал доступен только при использовании банковских карт.';
$_['help_marking']          = 'Добавляет в карточку товара признак маркируемой продукции и позволяет сканировать DataMatrix для каждого экземпляра в заказе перед отправкой второго чека.';
$_['help_marking_required'] = 'Для каждой единицы такого товара потребуется отдельный код DataMatrix перед отправкой второго чека.';
$_['help_password3']        = 'Используется только для возвратов. Сгенерируйте Password3 в личном кабинете Robokassa.';

// Error
$_['error_permission']   	= 'Внимание: У Вас недостаточно прав для управления модулем оплаты Робокасса!';
$_['error_merch_login']     = 'Требуется указать логин!';
$_['error_password1']    	= 'Требуется ввести пароль 1!';
$_['error_password2']    	= 'Требуется ввести пароль 2!';
$_['error_marking_permission'] = 'Недостаточно прав для изменения маркировки заказа.';
$_['error_marking_product'] = 'Товар не найден или не отмечен как маркируемый.';
$_['error_marking_format'] = 'Код экземпляра #%d имеет неверный формат. Используйте английскую раскладку и не удаляйте разделитель GS.';
$_['error_marking_duplicate'] = 'В одной позиции нельзя использовать одинаковые коды маркировки.';
$_['error_marking_load'] = 'Не удалось загрузить коды маркировки.';
$_['error_marking_save'] = 'Не удалось сохранить коды маркировки.';
$_['warning_marking_incomplete'] = 'Не для всех маркируемых товаров заполнены коды. Второй чек нельзя будет отправить.';
$_['error_refund_permission'] = 'Недостаточно прав для выполнения возврата Robokassa.';
$_['error_refund_order'] = 'Заказ для возврата не найден.';
$_['error_refund_not_found'] = 'Заявка на возврат не найдена.';
$_['error_refund_payment'] = 'Заказ оплачен не через Robokassa.';
$_['error_refund_country'] = 'API возвратов доступно только для Robokassa Россия.';
$_['error_refund_currency'] = 'API возвратов поддерживает только заказы в рублях.';
$_['error_refund_test'] = 'API возвратов не поддерживает тестовые платежи.';
$_['error_refund_password3'] = 'Укажите Пароль #3 в настройках Robokassa.';
$_['error_refund_amount'] = 'Укажите корректную сумму возврата.';
$_['error_refund_amount_available'] = 'Сумма возврата должна быть больше нуля и не превышать доступные %s.';
$_['error_refund_state'] = 'Robokassa вернула неизвестный статус возврата.';
$_['error_refund_rejected'] = 'Robokassa отклонила заявку на возврат.';
$_['error_refund_unknown_retry'] = 'Не повторяйте запрос: сначала проверьте возврат в личном кабинете Robokassa.';
$_['success_refund_created'] = 'Заявка на возврат создана. ID: %s';
$_['note_refund_created'] = 'Robokassa: заявка на возврат %s принята. ID: %s%s';
$_['note_refund_finished'] = 'Robokassa: возврат %s успешно завершён. ID: %s';
$_['note_refund_canceled'] = 'Robokassa: возврат отменён. ID: %s';
$_['note_refund_unknown'] = 'Robokassa: результат отправки возврата неизвестен. Перед повтором проверьте статус возврата в личном кабинете Robokassa.';
?>
