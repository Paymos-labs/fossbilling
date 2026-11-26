# Paymos para FOSSBilling

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Módulo oficial de FOSSBilling y adaptador de pasarela de pago (`modules/Paymos/` + `library/Payment/Adapter/Paymos/`) para [Paymos](https://paymos.io/es). Permite a sus clientes pagar facturas de FOSSBilling con cuatro stablecoins (**USDT**, **USDC**, **USD1**, **DAI**) y **XAUT** respaldado por oro en 13 redes principales, y registra los pagos confirmados a través del flujo de facturación nativo de FOSSBilling (`addFunds` + `payInvoiceWithCredits`) tanto en FOSSBilling 0.7 (RedBean) como en FOSSBilling 0.8+ / 2.0 (Doctrine).

[![Ver vídeo: 12 plugins oficiales de CMS para pagos cripto — Conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Demostración en vídeo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- FOSSBilling `0.7.x` o `0.8+ / 2.0+` (`>=0.7.0 <1.0 || ^2.0`)
- PHP 8.1+ con las extensiones `pdo`, `curl`, `hash`, `json` y `openssl`
- Una URL de FOSSBilling con **HTTPS** (`url` en `config.php`) — se rechaza la conexión sobre HTTP sin cifrar
- Un valor `salt` no vacío en el `config.php` de FOSSBilling
- Una cuenta de Paymos con el proyecto destino seleccionado en el panel de Paymos

No requiere Composer durante la instalación: el SDK de PHP de Paymos (`paymos/php-sdk >= 1.11.0`) viene empaquetado en `modules/Paymos/vendor/`. La interfaz de administración y los mensajes de pago incluyen seis idiomas: inglés, ruso, alemán, español, turco y chino simplificado (`zh-Hans`).

## Instalación y conexión

1. Descargue el archivo de lanzamiento más reciente desde [GitHub Releases](https://github.com/Paymos-labs/fossbilling/releases/latest).
2. Extraiga el archivo en el directorio raíz de FOSSBilling: situará `modules/Paymos/` y `library/Payment/Adapter/Paymos/` en sus rutas canónicas.
3. Abra **Extensions → Overview** en el panel de administración de FOSSBilling y active el módulo **Paymos**.
4. Abra **System → Payment Gateways → New Payment Gateway**, instale **Paymos** y actívelo.
5. Abra el proyecto destino en el panel de Paymos y después acceda a **Extensions → Paymos** en la administración de FOSSBilling.
6. Haga clic en **Conectar Paymos** (Connect Paymos) y apruebe la vinculación en la pestaña de Paymos.

Todas las instalaciones descargan el mismo paquete con cero claves de API, secretos de API, IDs de proyecto, secretos de webhook o códigos de dispositivo. Una sola aprobación aprovisiona de forma atómica los entornos **Sandbox** y **Producción** (Live) y registra la ruta fija de callback de la Guest API:

```text
https://your-billing.example/index.php?_url=/api/guest/paymos/webhook
```

Una vez conectado, solo existen dos preferencias no secretas: **Modo** (`Sandbox` o `Live`) y **Texto del botón**. No hay campos manuales de credenciales ni de secreto de webhook en ninguna parte de la interfaz.

## Almacenamiento de secretos

Las credenciales aprovisionadas de Sandbox y Producción, junto con el estado temporal del flujo de dispositivo RFC 8628, se sellan en sobres autenticados AES-256-GCM (`Paymos\Plugin\AesGcmEnvelope`) cuya clave se deriva del `salt` de `config.php` de FOSSBilling mediante HKDF-SHA256. Los secretos almacenados nunca se muestran en plantillas HTML/Twig, respuestas de API, mensajes de error ni registros. Vuelva a conectar mediante **Reconectar Paymos** (Reconnect Paymos) si cambia la URL de la instalación, revoca una clave de pago o rota un secreto de webhook.

## Checkout y ciclo de vida de la factura

- **Creación resistente a fallos (`STATUS_CREATING`):** antes de llamar a `POST /v1/invoices`, el adaptador guarda una intención pendiente en `paymos_invoice_snapshots`. Si la llamada de red o el proceso se interrumpen antes de registrar la respuesta, la siguiente carga de la factura repite exactamente el mismo `external_order_id`, `project_id`, `amount`, `currency` y `environment` para recuperar la factura existente sin crear duplicados.
- **Líneas de factura (`products[]`):** las líneas válidas de la factura (entre 1 y 50 conceptos con precio positivo de `$invoice['lines']`) se normalizan mediante `Paymos\Plugin\InvoicePresentation` y se envían en `products[]`; si faltan líneas o no son válidas, el checkout pasa limpiamente a modo solo por importe.
- **Sustitución fail-closed (`InvoiceReplacement`):** cuando cambia el total de una factura, la moneda, el modo o el proyecto — o cuando caduca una factura impagada de Paymos (`InvoiceRenewal`) — el adaptador cierra primero la factura anterior en Paymos. Si la factura anterior está pagada, sigue abierta para pago, no es accesible o devuelve 404, se bloquea la sustitución (`InvoiceReplacementBlockedException`) y se retiene para revisión manual, evitando que el comprador pague dos facturas abiertas por el mismo cobro. Las facturas marcadas como `paid_late_noted` también bloquean la sustitución hasta su revisión.
- **Sin suscripciones automáticas:** Paymos procesa transferencias push en blockchain y no realiza cargos recurrentes automáticos; cada factura de renovación generada por FOSSBilling la paga el cliente individualmente.

## Webhooks y conciliación mediante cron

Los webhooks entrantes llegan a `/index.php?_url=/api/guest/paymos/webhook` (`Box\Mod\Paymos\Api\Guest::webhook()`), que lee el cuerpo sin procesar `php://input`, verifica `X-Webhook-Signature` sin distinguir mayúsculas entre Sandbox y Producción con `MultiEnvironmentWebhookVerifier` (soporte para rotación dual `v1=`, ventana antirrepetición de 300 segundos), deduplica atómicamente mediante `PdoEventStore` (`200` para duplicados ya confirmados, `409 Conflict` para entregas concurrentes en curso, `401` para firmas inválidas o vencidas), realiza verificación inversa de eventos terminales con `GET /v1/invoices/{id}` (`InvoiceReverseVerifier`), aplica comprobaciones decimales trianguladas (`AmountGuard`) y protege las facturas pagadas o finalizadas frente a retrocesos. Los códigos de estado distintos de 2xx (`401`/`409`/`400`/`500`) se emiten directamente para que `Api_Handler` nunca convierta errores de webhook en HTTP `200`.

`invoice.paid_late` es estrictamente solo informativo: registra `paid_late_noted` y añade una nota de revisión manual sin modificar el estado de pago de la factura.

Los webhooks perdidos se recuperan mediante el listener cron de FOSSBilling (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`), que concilia hasta 50 facturas impagadas de Paymos de las últimas 24 horas (limitado a una vez cada 10 minutos) aplicando las mismas protecciones de verificación, y concilia además los reembolsos pendientes y los iniciados desde el panel.

## Reembolsos y sobrepagos

El módulo implementa el ciclo completo de reembolsos de Paymos (`RefundCoordinator`, `RefundIntentStoreInterface`, `RefundCandidateStoreInterface`, `NativeRefundProjectorInterface` y `RefundDiscovery`) con los dos modos calculados en servidor:

- **`full`:** reembolso completo de una factura pagada hacia la dirección verificada `first_sender` registrada por Paymos.
- **`overpayment`:** reembolso del importe excedente en una factura `paid_over`, manteniendo la factura de FOSSBilling como pagada.

Los administradores con permisos de reembolso y un token CSRF válido gestionan los reembolsos desde **Invoices → Paymos Refunds** (`/admin/paymos/refunds`) o mediante los hooks nativos `refundPayment()` / `listRefundsForTransaction()` del adaptador en FOSSBilling 2.0. Los importes parciales arbitrarios y las direcciones o redes de destino introducidas a mano se rechazan antes de enviar cualquier petición a la API.

- **Sin cargo contable prematuro:** los reembolsos en estado pending, processing o unknown nunca registran un cargo contable anticipado en FOSSBilling.
- **Proyección tras finalización verificada:** una vez que `GET /v1/refunds/{withdrawal_id}` y `GET /v1/invoices/{invoice_id}` confirman `completed` (por webhook `withdrawal.completed` o cron), los reembolsos `full` se proyectan una sola vez mediante `Invoice\Service::refundInvoice($invoice, $note, null, ['gateway_returned' => true, 'gateway_refund' => false, 'external_reversal' => [...]])` en FOSSBilling 2.0 (con reserva segura para FOSSBilling 0.7), mientras que los reembolsos `overpayment` guardan una nota de finalización y dejan la factura como pagada.
- **Detección de reembolsos del panel:** los reembolsos iniciados directamente desde el panel de Paymos o la API se detectan con `RefundDiscovery`, se verifican mediante lecturas `GET` firmadas y se proyectan sin emitir un `POST /v1/refunds` duplicado.

## Solución de problemas

- **La conexión no se inicia:** compruebe que `url` en `config.php` empieza por `https://` y que `salt` está configurado.
- **La factura sigue sin pagarse tras el pago:** revise los registros de FOSSBilling en busca de `Manual review` (discrepancia de importe, conflicto de verificación inversa o sustitución bloqueada) o verifique que la URL fija del webhook `/index.php?_url=/api/guest/paymos/webhook` sea accesible públicamente sin autenticación HTTP ni redirecciones.
- **El botón de reembolso indica que hay que reconectar:** vuelva a conectar la instalación en **Extensions → Paymos → Reconnect Paymos** para que la capacidad `invoice_refunds_v1` se almacene en el sobre cifrado de credenciales.

## Enlaces

- [Documentación](https://paymos.io/es/docs/cms-fossbilling)
- [Código fuente](https://github.com/Paymos-labs/fossbilling)
- [Historial de cambios](CHANGELOG.md)
- [Soporte](mailto:support@paymos.io)
- [Licencia](LICENSE) — MIT License, Copyright (c) 2026 Paymos Labs
