# Changelog

All notable changes to the Paymos for FOSSBilling module and payment gateway are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

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

