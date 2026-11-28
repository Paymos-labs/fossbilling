# Changelog

All notable changes to the Paymos for FOSSBilling module and payment gateway are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.0.5] - 2026-11-28

- fix(fossbilling): CSRF token, add-funds invoices, credit paid once

### Removed
- `Mode` and `Button Text` from the gateway form: both are set only on **Extensions → Paymos**. Values saved in the gateway form by an earlier build still apply, and that page shows them, until it is saved once.

### Fixed
- Connect, settings, refunds and manual reconciliation no longer fail with `CSRF token invalid`: the module reads the token FOSSBilling keeps under `csrf_token` and the pages send the core `CSRFToken`.
- A Paymos payment is credited to the client balance once: webhook, `ipn.php` and the cron reconciler settle an invoice under one lock, and a retry after a failed credit payment only pays the invoice from the balance. An interrupted credit is checked against `client_balance`: found, it is finished without crediting again; absent, it is credited; unreadable, it goes to manual review.
- Manual review notes are written once per case instead of on every webhook retry and cron pass.
- A paid "Add funds" invoice is marked paid, an unapproved invoice is approved before it is paid from credit, and an invoice FOSSBilling did not mark paid stays open for review instead of being recorded as settled.
- An invoice paid another way no longer hides a later Paymos payment: non-final events record their real status, and the Paymos payment is recorded with its transaction and a manual-review note.
- A definitive `4xx` on invoice creation ends the pending attempt; the next payment creates a new invoice for the current amount. A new attempt answered with an already final invoice is not bound to the FOSSBilling invoice.
- A passed local `expires_at` reads the invoice back from Paymos before replacing it, so a buyer who already picked a network keeps the same payment link.
- FOSSBilling 0.8.x full refunds run the native `refundInvoice()` (credit note or negative invoice per `invoice_refund_logic`).
- The cron reconciler moves past invoices it skipped instead of re-reading the same 50, and the cron refund sync runs at most every 10 minutes.

## [1.0.4] - 2026-11-27

- chore: rebuild canonical CMS package

### Added
- Canonical FOSSBilling module + adapter layout (`modules/Paymos/` and `library/Payment/Adapter/Paymos/`) with manifest v1 metadata (`schemas.fossbilling.net/manifest/v1.json`, `type: mod`, `id: paymos`, PHP 8.1+).
- One-Click **Connect Paymos** RFC 8628 device bootstrap (`ConnectHandler` + `DeviceConnectClient`) provisioning Sandbox and Live credentials atomically into AES-256-GCM storage (`CredentialStore`) keyed from FOSSBilling `config.php` `salt`.
- Fixed Guest API webhook endpoint (`/index.php?_url=/api/guest/paymos/webhook`) emitting exact HTTP status codes (`200`/`401`/`409`/`400`/`500`) directly via `Box\Mod\Paymos\Api\Guest::webhook()`, with `MultiEnvironmentWebhookVerifier`, `InvoiceReverseVerifier`, `AmountGuard`, rollback guard, and Doctrine 2.0 + RedBean 0.7 settlement support.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, and `products[]` line-item normalization.
- Cron reconciliation listener (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`) running 24h unpaid invoice sweeps (10-minute throttle) and refund reconciliation.
- Full server-calculated refunds (`full` and `overpayment`) via `RefundCoordinator`, `RefundIntentStore` (`RefundIntentStoreInterface` + `RefundCandidateStoreInterface`), `RefundDiscovery`, `ExternalRefundObservation`, FOSSBilling 2.0 `Invoice\Service::refundInvoice(..., external_reversal)` projection (with 0.7 fallback), admin Twig UI (`mod_paymos_refunds.html.twig`), and `Payment_Adapter_Paymos::refundPayment()` / `listRefundsForTransaction()`.
- Six native localization catalogs (`en`, `ru`, `de`, `es`, `tr`, `zh-Hans`) with full key parity.
- Canonical release archive builder (`scripts/build-fossbilling-plugin.ps1`) using `Bundle-PaymosPhpSdk.ps1` and logged test runner (`scripts/test-plugin-fossbilling.ps1`).

### Removed
- Manual API Key, API Secret, Project ID, Webhook Signing Secret, and API Base URL fields from gateway settings.
- Legacy root-only MVP adapter files and Python packager.

## [1.0.3] - 2026-11-26

- fix(release,fossbilling): allow translated README.*.md in Odoo root bundle and add Tabler badge text contrast
- docs(plugins): add 6-language READMEs (EN/zh-CN/ru/es/de/tr) and YouTube walkthrough previews across all 25 Paymos-labs repos
- chore: bundle Paymos PHP SDK v1.13.0

### Added
- Canonical FOSSBilling module + adapter layout (`modules/Paymos/` and `library/Payment/Adapter/Paymos/`) with manifest v1 metadata (`schemas.fossbilling.net/manifest/v1.json`, `type: mod`, `id: paymos`, PHP 8.1+).
- One-Click **Connect Paymos** RFC 8628 device bootstrap (`ConnectHandler` + `DeviceConnectClient`) provisioning Sandbox and Live credentials atomically into AES-256-GCM storage (`CredentialStore`) keyed from FOSSBilling `config.php` `salt`.
- Fixed Guest API webhook endpoint (`/index.php?_url=/api/guest/paymos/webhook`) emitting exact HTTP status codes (`200`/`401`/`409`/`400`/`500`) directly via `Box\Mod\Paymos\Api\Guest::webhook()`, with `MultiEnvironmentWebhookVerifier`, `InvoiceReverseVerifier`, `AmountGuard`, rollback guard, and Doctrine 2.0 + RedBean 0.7 settlement support.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, and `products[]` line-item normalization.
- Cron reconciliation listener (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`) running 24h unpaid invoice sweeps (10-minute throttle) and refund reconciliation.
- Full server-calculated refunds (`full` and `overpayment`) via `RefundCoordinator`, `RefundIntentStore` (`RefundIntentStoreInterface` + `RefundCandidateStoreInterface`), `RefundDiscovery`, `ExternalRefundObservation`, FOSSBilling 2.0 `Invoice\Service::refundInvoice(..., external_reversal)` projection (with 0.7 fallback), admin Twig UI (`mod_paymos_refunds.html.twig`), and `Payment_Adapter_Paymos::refundPayment()` / `listRefundsForTransaction()`.
- Six native localization catalogs (`en`, `ru`, `de`, `es`, `tr`, `zh-Hans`) with full key parity.
- Canonical release archive builder (`scripts/build-fossbilling-plugin.ps1`) using `Bundle-PaymosPhpSdk.ps1` and logged test runner (`scripts/test-plugin-fossbilling.ps1`).

### Removed
- Manual API Key, API Secret, Project ID, Webhook Signing Secret, and API Base URL fields from gateway settings.
- Legacy root-only MVP adapter files and Python packager.

## [1.0.2] - 2026-11-26

- fix(release,fossbilling): allow translated README.*.md in Odoo root bundle and add Tabler badge text contrast
- docs(plugins): add 6-language READMEs (EN/zh-CN/ru/es/de/tr) and YouTube walkthrough previews across all 25 Paymos-labs repos
- chore: bundle Paymos PHP SDK v1.13.0

### Added
- Canonical FOSSBilling module + adapter layout (`modules/Paymos/` and `library/Payment/Adapter/Paymos/`) with manifest v1 metadata (`schemas.fossbilling.net/manifest/v1.json`, `type: mod`, `id: paymos`, PHP 8.1+).
- One-Click **Connect Paymos** RFC 8628 device bootstrap (`ConnectHandler` + `DeviceConnectClient`) provisioning Sandbox and Live credentials atomically into AES-256-GCM storage (`CredentialStore`) keyed from FOSSBilling `config.php` `salt`.
- Fixed Guest API webhook endpoint (`/index.php?_url=/api/guest/paymos/webhook`) emitting exact HTTP status codes (`200`/`401`/`409`/`400`/`500`) directly via `Box\Mod\Paymos\Api\Guest::webhook()`, with `MultiEnvironmentWebhookVerifier`, `InvoiceReverseVerifier`, `AmountGuard`, rollback guard, and Doctrine 2.0 + RedBean 0.7 settlement support.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, and `products[]` line-item normalization.
- Cron reconciliation listener (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`) running 24h unpaid invoice sweeps (10-minute throttle) and refund reconciliation.
- Full server-calculated refunds (`full` and `overpayment`) via `RefundCoordinator`, `RefundIntentStore` (`RefundIntentStoreInterface` + `RefundCandidateStoreInterface`), `RefundDiscovery`, `ExternalRefundObservation`, FOSSBilling 2.0 `Invoice\Service::refundInvoice(..., external_reversal)` projection (with 0.7 fallback), admin Twig UI (`mod_paymos_refunds.html.twig`), and `Payment_Adapter_Paymos::refundPayment()` / `listRefundsForTransaction()`.
- Six native localization catalogs (`en`, `ru`, `de`, `es`, `tr`, `zh-Hans`) with full key parity.
- Canonical release archive builder (`scripts/build-fossbilling-plugin.ps1`) using `Bundle-PaymosPhpSdk.ps1` and logged test runner (`scripts/test-plugin-fossbilling.ps1`).

### Removed
- Manual API Key, API Secret, Project ID, Webhook Signing Secret, and API Base URL fields from gateway settings.
- Legacy root-only MVP adapter files and Python packager.

## [1.0.1] - 2026-11-25

- fix(plugins): accept stamped SemVer and avoid PHP 8.5 setAccessible deprecation in plugin tests
- fix(plugins): resolve 13 live CMS runtime issues in FOSSBilling 0.8.9, Paymenter v1.5.9, and Blesta 6.0.5
- chore: rebuild canonical CMS package

### Added
- Canonical FOSSBilling module + adapter layout (`modules/Paymos/` and `library/Payment/Adapter/Paymos/`) with manifest v1 metadata (`schemas.fossbilling.net/manifest/v1.json`, `type: mod`, `id: paymos`, PHP 8.1+).
- One-Click **Connect Paymos** RFC 8628 device bootstrap (`ConnectHandler` + `DeviceConnectClient`) provisioning Sandbox and Live credentials atomically into AES-256-GCM storage (`CredentialStore`) keyed from FOSSBilling `config.php` `salt`.
- Fixed Guest API webhook endpoint (`/index.php?_url=/api/guest/paymos/webhook`) emitting exact HTTP status codes (`200`/`401`/`409`/`400`/`500`) directly via `Box\Mod\Paymos\Api\Guest::webhook()`, with `MultiEnvironmentWebhookVerifier`, `InvoiceReverseVerifier`, `AmountGuard`, rollback guard, and Doctrine 2.0 + RedBean 0.7 settlement support.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, and `products[]` line-item normalization.
- Cron reconciliation listener (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`) running 24h unpaid invoice sweeps (10-minute throttle) and refund reconciliation.
- Full server-calculated refunds (`full` and `overpayment`) via `RefundCoordinator`, `RefundIntentStore` (`RefundIntentStoreInterface` + `RefundCandidateStoreInterface`), `RefundDiscovery`, `ExternalRefundObservation`, FOSSBilling 2.0 `Invoice\Service::refundInvoice(..., external_reversal)` projection (with 0.7 fallback), admin Twig UI (`mod_paymos_refunds.html.twig`), and `Payment_Adapter_Paymos::refundPayment()` / `listRefundsForTransaction()`.
- Six native localization catalogs (`en`, `ru`, `de`, `es`, `tr`, `zh-Hans`) with full key parity.
- Canonical release archive builder (`scripts/build-fossbilling-plugin.ps1`) using `Bundle-PaymosPhpSdk.ps1` and logged test runner (`scripts/test-plugin-fossbilling.ps1`).

### Removed
- Manual API Key, API Secret, Project ID, Webhook Signing Secret, and API Base URL fields from gateway settings.
- Legacy root-only MVP adapter files and Python packager.

## [1.0.0] - 2026-11-25

- feat(cms): rebuild Blesta, FOSSBilling, and Paymenter to full Paymos CMS lifecycle standard [skip ecosystem release]
- feat(plugins,distribution): add Package B hosting/billing gateways (Blesta, FOSSBilling, Paymenter) and archive Forge change [skip ecosystem release]
- chore: bundle Paymos PHP SDK v1.12.0
- chore: rebuild canonical CMS package

### Added
- Canonical FOSSBilling module + adapter layout (`modules/Paymos/` and `library/Payment/Adapter/Paymos/`) with manifest v1 metadata (`schemas.fossbilling.net/manifest/v1.json`, `type: mod`, `id: paymos`, PHP 8.1+).
- One-Click **Connect Paymos** RFC 8628 device bootstrap (`ConnectHandler` + `DeviceConnectClient`) provisioning Sandbox and Live credentials atomically into AES-256-GCM storage (`CredentialStore`) keyed from FOSSBilling `config.php` `salt`.
- Fixed Guest API webhook endpoint (`/index.php?_url=/api/guest/paymos/webhook`) emitting exact HTTP status codes (`200`/`401`/`409`/`400`/`500`) directly via `Box\Mod\Paymos\Api\Guest::webhook()`, with `MultiEnvironmentWebhookVerifier`, `InvoiceReverseVerifier`, `AmountGuard`, rollback guard, and Doctrine 2.0 + RedBean 0.7 settlement support.
- Crash-safe checkout (`CheckoutService`) with pre-POST `STATUS_CREATING` intent recovery, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, and `products[]` line-item normalization.
- Cron reconciliation listener (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`) running 24h unpaid invoice sweeps (10-minute throttle) and refund reconciliation.
- Full server-calculated refunds (`full` and `overpayment`) via `RefundCoordinator`, `RefundIntentStore` (`RefundIntentStoreInterface` + `RefundCandidateStoreInterface`), `RefundDiscovery`, `ExternalRefundObservation`, FOSSBilling 2.0 `Invoice\Service::refundInvoice(..., external_reversal)` projection (with 0.7 fallback), admin Twig UI (`mod_paymos_refunds.html.twig`), and `Payment_Adapter_Paymos::refundPayment()` / `listRefundsForTransaction()`.
- Six native localization catalogs (`en`, `ru`, `de`, `es`, `tr`, `zh-Hans`) with full key parity.
- Canonical release archive builder (`scripts/build-fossbilling-plugin.ps1`) using `Bundle-PaymosPhpSdk.ps1` and logged test runner (`scripts/test-plugin-fossbilling.ps1`).

### Removed
- Manual API Key, API Secret, Project ID, Webhook Signing Secret, and API Base URL fields from gateway settings.
- Legacy root-only MVP adapter files and Python packager.

