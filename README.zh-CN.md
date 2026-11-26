# Paymos FOSSBilling 官方稳定币支付模块

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

适用于 [Paymos](https://paymos.io/zh-hans) 的 FOSSBilling 官方模块与支付网关适配器（`modules/Paymos/` + `library/Payment/Adapter/Paymos/`）。支持客户在 13 条主网使用四种稳定币（**USDT**、**USDC**、**USD1**、**DAI**）以及黄金锚定代币 **XAUT** 支付 FOSSBilling 账单，并通过 FOSSBilling 原生计费管道（`addFunds` + `payInvoiceWithCredits`）自动入账，同时兼容 FOSSBilling 0.7（RedBean）与 FOSSBilling 0.8+ / 2.0（Doctrine）。

[![观看演示：12 款官方 CMS 稳定币支付插件 — 一键连接 (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，支持 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- FOSSBilling `0.7.x` 或 `0.8+ / 2.0+`（`>=0.7.0 <1.0 || ^2.0`）
- PHP 8.1+，启用 `pdo`、`curl`、`hash`、`json` 和 `openssl` 扩展
- 使用 **HTTPS** 的 FOSSBilling 站点地址（`config.php` 中的 `url`）——拒绝通过明文 HTTP 连接
- `config.php` 中已配置非空的 FOSSBilling 安装 `salt`
- 已注册 Paymos 账户，并在 Paymos 控制台中选中目标项目

安装时无需运行 Composer：Paymos PHP SDK（`paymos/php-sdk >= 1.11.0`）已内置在 `modules/Paymos/vendor/` 目录中。后台管理界面与收银台提示内置六种语言：英文、俄文、德文、西班牙文、土耳其文和简体中文（`zh-Hans`）。

## 安装与一键连接

1. 前往 [GitHub Releases](https://github.com/Paymos-labs/fossbilling/releases/latest) 下载最新发行压缩包。
2. 将压缩包解压到 FOSSBilling 根目录——它会将 `modules/Paymos/` 与 `library/Payment/Adapter/Paymos/` 放置到标准路径。
3. 在 FOSSBilling 后台打开 **Extensions → Overview**，激活 **Paymos** 模块。
4. 打开 **System → Payment Gateways → New Payment Gateway**，安装并启用 **Paymos** 支付网关。
5. 在 Paymos 控制台中打开目标项目，然后进入 FOSSBilling 后台的 **Extensions → Paymos**。
6. 点击**连接 Paymos**（Connect Paymos），并在弹出的 Paymos 标签页中批准绑定。

每个站点下载的压缩包完全一致，不包含任何 API 密钥、API Secret、项目 ID、Webhook 密钥或设备码。一次批准即可原子化完成**沙盒**（Sandbox）与**正式模式**（Live）两套环境的配置，并自动注册固定的 Guest API 回调路由：

```text
https://your-billing.example/index.php?_url=/api/guest/paymos/webhook
```

连接完成后，界面仅保留两项非敏感偏好设置：**模式**（`Sandbox` 或 `Live`）与**按钮文字**（Button Text）。管理界面任何位置都不存在手动填写凭证或 Webhook 密钥的表单字段。

## 密钥安全存储

配置好的沙盒与正式环境凭证，以及临时的 RFC 8628 设备授权流程状态，均通过认证加密的 AES-256-GCM 封装（`Paymos\Plugin\AesGcmEnvelope`）保护，加密密钥由 FOSSBilling `config.php` 中的 `salt` 经 HKDF-SHA256 派生。已存储的密钥绝不会渲染到 HTML/Twig 模板、API 响应、错误提示或日志中。更改站点地址、吊销 Payment 密钥或轮换 Webhook 密钥后，点击**重新连接 Paymos**（Reconnect Paymos）即可。

## 结账与账单生命周期

- **防崩溃创建（`STATUS_CREATING`）：** 调用 `POST /v1/invoices` 前，适配器会在 `paymos_invoice_snapshots` 中持久化一条待处理意图。如果网络或进程在记录响应前中断，下次加载账单时会以完全相同的 `external_order_id`、`project_id`、`amount`、`currency` 和 `environment` 重放请求，恢复原有账单而不产生重复账单。
- **明细项（`products[]`）：** 来自 `$invoice['lines']` 的有效账单行（1–50 条正数单价商品）经 `Paymos\Plugin\InvoicePresentation` 规范化后通过 `products[]` 提交；若明细无效或为空，则平滑回退为纯金额结账。
- **安全阻断式替换（`InvoiceReplacement`）：** 当账单总额、币种、模式或项目变更，或未付的 Paymos 账单过期（`InvoiceRenewal`）时，适配器会先在 Paymos 侧关闭旧账单。如果旧账单已付款、仍可支付、无法访问或返回 404，替换会被阻断（`InvoiceReplacementBlockedException`）并转入人工审核，确保买家无法对同一账单重复支付两张开放账单。标记为 `paid_late_noted` 的账单在审核前同样会阻断替换。
- **不支持自动订阅扣款：** Paymos 处理的是客户主动推送的链上转账，不会自动发起周期扣款；FOSSBilling 生成的续费账单由客户逐笔主动支付。

## Webhook 与定时任务对账

入站 Webhook 发送至 `/index.php?_url=/api/guest/paymos/webhook`（`Box\Mod\Paymos\Api\Guest::webhook()`），直接读取原始 `php://input` 请求体，通过 `MultiEnvironmentWebhookVerifier` 跨沙盒与正式环境大小写不敏感地校验 `X-Webhook-Signature`（支持双 `v1=` 密钥轮换宽限、300 秒重放时间窗），借助 `PdoEventStore` 原子去重（已提交重复事件返回 `200`，并发处理中返回 `409 Conflict`，无效或过期签名返回 `401`），对终态事件调用 `GET /v1/invoices/{id}` 执行反向核验（`InvoiceReverseVerifier`），通过 `AmountGuard` 执行三角高精度金额校验，并防止已付或已终结账单被状态回滚。非 2xx 状态码（`401`/`409`/`400`/`500`）均直接输出，确保 `Api_Handler` 绝不会把 Webhook 错误改写为 HTTP `200`。

`invoice.paid_late` 严格仅记备注：记录 `paid_late_noted` 并在日志中写入人工审核说明，不会更改账单的支付状态。

遗漏的 Webhook 由 FOSSBilling 定时任务监听器（`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`）兜底补齐：每 10 分钟最多运行一次，对过去 24 小时内最多 50 笔未付 Paymos 账单执行与 Webhook 相同的全部校验防护，同时对账处理中的退款以及从 Paymos 控制台发起的退款。

## 退款与多付处理

模块实现了完整的 Paymos 退款生命周期（`RefundCoordinator`、`RefundIntentStoreInterface`、`RefundCandidateStoreInterface`、`NativeRefundProjectorInterface` 与 `RefundDiscovery`），支持两种服务端计算模式：

- **`full`：** 对已付账单全额退款，资金退回 Paymos 记录并校验的 `first_sender` 地址。
- **`overpayment`：** 退还 `paid_over` 账单的多付金额，同时保持 FOSSBilling 账单的已付款状态。

具备退款权限且携带有效 CSRF 令牌的管理员，可在 **Invoices → Paymos Refunds**（`/admin/paymos/refunds`）或通过 FOSSBilling 2.0 原生的 `refundPayment()` / `listRefundsForTransaction()` 适配器钩子管理退款。任何自定义部分金额、手动填写的收款地址或网络均会在发送 API 请求前被直接拒绝。

- **杜绝提前记账扣减：** 处于 pending、processing 或 unknown 状态的退款绝不会在 FOSSBilling 中提前记入账务扣减。
- **核验完成后精准回写：** 当 `GET /v1/refunds/{withdrawal_id}` 与 `GET /v1/invoices/{invoice_id}` 确认状态为 `completed`（经 `withdrawal.completed` Webhook 或定时任务触发）后，`full` 退款会通过 FOSSBilling 2.0 的 `Invoice\Service::refundInvoice($invoice, $note, null, ['gateway_returned' => true, 'gateway_refund' => false, 'external_reversal' => [...]])` 精确回写一次（并提供安全的 FOSSBilling 0.7 兼容回退）；`overpayment` 退款则记录完成备注并保持账单为已付款状态。
- **控制台退款自动发现：** 直接在 Paymos 控制台或 API 发起的退款会由 `RefundDiscovery` 自动识别，经签名 `GET` 核验后直接回写，不会重复发起 `POST /v1/refunds`。

## 故障排查

- **点击连接后无法启动：** 请确认 `config.php` 中的 `url` 以 `https://` 开头，且已配置非空的 `salt`。
- **付款后账单仍显示未支付：** 请检查 FOSSBilling 日志中是否出现 `Manual review`（金额不符、反向校验冲突或账单替换受阻），并确认固定 Webhook 地址 `/index.php?_url=/api/guest/paymos/webhook` 可从公网直接访问，且无 HTTP 认证或重定向。
- **退款按钮提示需要重新连接：** 请前往 **Extensions → Paymos → Reconnect Paymos** 重新连接安装，将 `invoice_refunds_v1` 退款权限写入加密凭证封装中。

## 相关链接

- [开发文档](https://paymos.io/zh-hans/docs/cms-fossbilling)
- [源码仓库](https://github.com/Paymos-labs/fossbilling)
- [更新日志](CHANGELOG.md)
- [技术支持](mailto:support@paymos.io)
- [许可证](LICENSE) — MIT License, Copyright (c) 2026 Paymos Labs
