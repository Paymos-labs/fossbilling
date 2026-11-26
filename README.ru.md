# Paymos для FOSSBilling

[English](README.md) | [简体中文](README.zh-CN.md) | **Русский** | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Официальный модуль FOSSBilling и адаптер платёжного шлюза (`modules/Paymos/` + `library/Payment/Adapter/Paymos/`) для [Paymos](https://paymos.io/ru). Позволяет клиентам оплачивать счета FOSSBilling четырьмя стейблкоинами (**USDT**, **USDC**, **USD1**, **DAI**) и обеспеченным золотом **XAUT** в 13 основных сетях, а подтверждённые платежи проводит через штатный биллинг FOSSBilling (`addFunds` + `payInvoiceWithCredits`) как в FOSSBilling 0.7 (RedBean), так и в FOSSBilling 0.8+ / 2.0 (Doctrine).

[![Видео: 12 официальных CMS-плагинов Paymos — подключение в один клик](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Видеообзор (1:12, субтитры на 6 языках):** [Смотреть на YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Требования

- FOSSBilling `0.7.x` или `0.8+ / 2.0+` (`>=0.7.0 <1.0 || ^2.0`)
- PHP 8.1+ с расширениями `pdo`, `curl`, `hash`, `json` и `openssl`
- URL установки FOSSBilling по **HTTPS** (`url` в `config.php`) — подключение по HTTP отклоняется
- Непустое значение `salt` в `config.php`
- Аккаунт Paymos с выбранным целевым проектом в кабинете Paymos

При установке Composer не требуется: Paymos PHP SDK (`paymos/php-sdk >= 1.11.0`) уже упакован в `modules/Paymos/vendor/`. Интерфейс администратора и сообщения на странице оплаты переведены на шесть языков: английский, русский, немецкий, испанский, турецкий и упрощённый китайский (`zh-Hans`).

## Установка и подключение

1. Скачайте актуальный релизный архив со страницы [GitHub Releases](https://github.com/Paymos-labs/fossbilling/releases/latest).
2. Распакуйте архив в корневой каталог FOSSBilling — файлы разложатся в штатные директории `modules/Paymos/` и `library/Payment/Adapter/Paymos/`.
3. В админке FOSSBilling откройте **Extensions → Overview** и активируйте модуль **Paymos**.
4. Откройте **System → Payment Gateways → New Payment Gateway**, установите **Paymos** и включите шлюз.
5. Откройте нужный проект в кабинете Paymos, затем перейдите в **Extensions → Paymos** в админке FOSSBilling.
6. Нажмите **Подключить Paymos** (Connect Paymos) и подтвердите привязку во вкладке Paymos.

Каждая установка скачивает один и тот же архив без API-ключей, секретов, ID проектов, секретов вебхуков или кодов устройства. Одно подтверждение атомарно настраивает **Песочницу** (Sandbox) и **Рабочий режим** (Live) и регистрирует фиксированный маршрут Guest API для вебхуков:

```text
https://your-billing.example/index.php?_url=/api/guest/paymos/webhook
```

После подключения в настройках остаются только два несекретных параметра: **Режим** (`Sandbox` или `Live`) и **Текст кнопки**. Ручных полей для ключей или секрета вебхука в интерфейсе нет.

## Хранение секретов

Ключи для Песочницы и Рабочего режима вместе с временным состоянием авторизации по RFC 8628 шифруются в аутентифицированных конвертах AES-256-GCM (`Paymos\Plugin\AesGcmEnvelope`) с ключом, выведенным через HKDF-SHA256 из `salt` в `config.php`. Сохранённые секреты никогда не попадают в HTML/Twig-шаблоны, ответы API, тексты ошибок или логи. При смене URL установки, отзыве Payment-ключа или ротации секрета вебхука переподключите модуль кнопкой **Переподключить Paymos** (Reconnect Paymos).

## Оплата и жизненный цикл счёта

- **Отказоустойчивое создание (`STATUS_CREATING`):** перед запросом `POST /v1/invoices` адаптер записывает намерение в `paymos_invoice_snapshots`. Если сеть или процесс оборвутся до сохранения ответа, следующая загрузка счёта повторит запрос с теми же `external_order_id`, `project_id`, `amount`, `currency` и `environment`, восстановив созданный инвойс без дублей.
- **Позиции счёта (`products[]`):** корректные строки счёта (1–50 позиций с положительной ценой из `$invoice['lines']`) нормализуются через `Paymos\Plugin\InvoicePresentation` и передаются в `products[]`; при пустом или некорректном списке оплата переходит в режим передачи одной суммы.
- **Безопасная замена счёта (`InvoiceReplacement`):** при изменении суммы, валюты, режима или проекта — либо при истечении неоплаченного инвойса Paymos (`InvoiceRenewal`) — адаптер сначала закрывает старый инвойс на стороне Paymos. Если старый счёт оплачен, всё ещё открыт для оплаты, недоступен или вернул 404, замена блокируется (`InvoiceReplacementBlockedException`) и переводится на ручную проверку, чтобы клиент не оплатил два открытых счёта за один заказ. Счета с отметкой `paid_late_noted` также блокируют замену до проверки.
- **Без автосписаний по подпискам:** Paymos обрабатывает блокчейн-переводы, которые отправляет сам клиент, и не списывает регулярные платежи автоматически; счета на продление от FOSSBilling клиент оплачивает отдельно.

## Вебхуки и сверка по cron

Входящие вебхуки приходят на `/index.php?_url=/api/guest/paymos/webhook` (`Box\Mod\Paymos\Api\Guest::webhook()`): обработчик читает сырое тело `php://input`, проверяет заголовок `X-Webhook-Signature` без учёта регистра сразу для Песочницы и Рабочего режима через `MultiEnvironmentWebhookVerifier` (поддержка двойного `v1=` при ротации и окно защиты от повторов 300 секунд), атомарно дедуплицирует события в `PdoEventStore` (`200` для обработанных дублей, `409 Conflict` для параллельной доставки в работе, `401` при неверной или просроченной подписи), перепроверяет финальные события через `GET /v1/invoices/{id}` (`InvoiceReverseVerifier`), сверяет десятичные суммы через `AmountGuard` и защищает оплаченные или закрытые счета от отката. Ошибочные коды (`401`/`409`/`400`/`500`) отдаются напрямую, чтобы `Api_Handler` никогда не подменял ошибку вебхука ответом HTTP `200`.

Событие `invoice.paid_late` фиксируется строго как заметка: ставит флаг `paid_late_noted` и добавляет запись о ручной проверке, не меняя статус оплаты счёта.

Пропущенные вебхуки подбирает обработчик cron FOSSBilling (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`): не чаще раза в 10 минут он сверяет до 50 неоплаченных счетов Paymos за последние 24 часа через те же защитные проверки, а также синхронизирует ожидающие возвраты и возвраты из кабинета Paymos.

## Возвраты и переплаты

Модуль реализует полный жизненный цикл возвратов Paymos (`RefundCoordinator`, `RefundIntentStoreInterface`, `RefundCandidateStoreInterface`, `NativeRefundProjectorInterface` и `RefundDiscovery`) с двумя серверными режимами:

- **`full`:** полный возврат оплаченного счёта на проверенный адрес `first_sender`, зафиксированный Paymos при оплате.
- **`overpayment`:** возврат переплаты по счёту `paid_over` с сохранением статуса оплаты счёта в FOSSBilling.

Администраторы с правами на возвраты и действующим CSRF-токеном управляют возвратами в разделе **Invoices → Paymos Refunds** (`/admin/paymos/refunds`) либо через штатные методы адаптера FOSSBilling 2.0 `refundPayment()` / `listRefundsForTransaction()`. Произвольные частичные суммы, а также ручной ввод адреса получателя или сети отклоняются до отправки запроса в API.

- **Без преждевременного списания в учёте:** возвраты в статусе pending, processing или unknown никогда не создают преждевременную проводку возврата в FOSSBilling.
- **Проекция после подтверждения:** когда `GET /v1/refunds/{withdrawal_id}` и `GET /v1/invoices/{invoice_id}` подтверждают статус `completed` (по вебхуку `withdrawal.completed` или через cron), возвраты `full` один раз проводятся через метод FOSSBilling 2.0 `Invoice\Service::refundInvoice($invoice, $note, null, ['gateway_returned' => true, 'gateway_refund' => false, 'external_reversal' => [...]])` (с безопасным откатом на механизм FOSSBilling 0.7), а возвраты `overpayment` добавляют заметку о завершении и оставляют счёт оплаченным.
- **Обнаружение возвратов из кабинета:** возвраты, запущенные напрямую в кабинете Paymos или через API, находятся через `RefundDiscovery`, перепроверяются подписанными `GET`-запросами и отражаются в системе без повторного вызова `POST /v1/refunds`.

## Решение проблем

- **Подключение не запускается:** проверьте, что `url` в `config.php` начинается с `https://`, а параметр `salt` задан.
- **Счёт остаётся неоплаченным после перевода:** проверьте логи FOSSBilling на наличие записей `Manual review` (расхождение суммы, конфликт обратной проверки или заблокированная замена счёта) и убедитесь, что фиксированный URL вебхука `/index.php?_url=/api/guest/paymos/webhook` доступен из интернета без HTTP-авторизации и редиректов.
- **Кнопка возврата просит переподключить модуль:** выполните повторную привязку в **Extensions → Paymos → Reconnect Paymos**, чтобы в зашифрованный конверт ключей добавилась возможность `invoice_refunds_v1`.

## Ссылки

- [Документация](https://paymos.io/ru/docs/cms-fossbilling)
- [Исходный код](https://github.com/Paymos-labs/fossbilling)
- [История изменений](CHANGELOG.md)
- [Поддержка](mailto:support@paymos.io)
- [Лицензия](LICENSE) — MIT License, Copyright (c) 2026 Paymos Labs
