# Paymos für FOSSBilling

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles FOSSBilling-Modul samt Zahlungsgateway-Adapter (`modules/Paymos/` + `library/Payment/Adapter/Paymos/`) für [Paymos](https://paymos.io/de). Ermöglicht es Ihren Kunden, FOSSBilling-Rechnungen mit vier Stablecoins (**USDT**, **USDC**, **USD1**, **DAI**) und goldgedecktem **XAUT** über 13 Mainnet-Netzwerke zu bezahlen, und bucht bestätigte Zahlungen nativ über die FOSSBilling-Abrechnungspipeline (`addFunds` + `payInvoiceWithCredits`) ein — sowohl unter FOSSBilling 0.7 (RedBean) als auch unter FOSSBilling 0.8+ / 2.0 (Doctrine).

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- FOSSBilling `0.7.x` oder `0.8+ / 2.0+` (`>=0.7.0 <1.0 || ^2.0`)
- PHP 8.1+ mit den Erweiterungen `pdo`, `curl`, `hash`, `json` und `openssl`
- Eine **HTTPS**-FOSSBilling-URL (`url` in `config.php`) — Verbindungen über unverschlüsseltes HTTP werden abgelehnt
- Ein nicht-leerer `salt`-Wert in der FOSSBilling-Datei `config.php`
- Ein Paymos-Konto mit ausgewähltem Zielprojekt im Paymos-Dashboard

Bei der Installation ist kein Composer-Schritt nötig: Das Paymos PHP SDK (`paymos/php-sdk >= 1.11.0`) ist bereits unter `modules/Paymos/vendor/` enthalten. Admin-Oberfläche und Checkout-Meldungen werden in sechs Sprachen ausgeliefert: Englisch, Russisch, Deutsch, Spanisch, Türkisch und Chinesisch (vereinfacht) (`zh-Hans`).

## Installation und Verbindung

1. Laden Sie das aktuelle Release-Archiv von [GitHub Releases](https://github.com/Paymos-labs/fossbilling/releases/latest) herunter.
2. Entpacken Sie das Archiv in das Stammverzeichnis Ihrer FOSSBilling-Installation — dadurch werden `modules/Paymos/` und `library/Payment/Adapter/Paymos/` an ihren kanonischen Pfaden abgelegt.
3. Öffnen Sie im FOSSBilling-Adminbereich **Extensions → Overview** und aktivieren Sie das Modul **Paymos**.
4. Öffnen Sie **System → Payment Gateways → New Payment Gateway**, installieren Sie **Paymos** und aktivieren Sie es.
5. Öffnen Sie das Zielprojekt im Paymos-Dashboard und navigieren Sie anschließend im FOSSBilling-Adminbereich zu **Extensions → Paymos**.
6. Klicken Sie auf **Paymos verbinden** (Connect Paymos) und bestätigen Sie die Verknüpfung im Paymos-Tab.

Jede Installation lädt dasselbe Archiv ohne API-Schlüssel, API-Secrets, Projekt-IDs, Webhook-Secrets oder Gerätecodes herunter. Eine einzige Bestätigung richtet **Sandbox** und **Live** atomar ein und registriert die feste Guest-API-Callback-Route:

```text
https://your-billing.example/index.php?_url=/api/guest/paymos/webhook
```

Nach dem Verbinden stehen genau zwei nicht-geheime Einstellungen zur Verfügung: **Modus** (`Sandbox` oder `Live`) und **Buttontext**. In der gesamten Oberfläche gibt es keine manuellen Felder für Zugangsdaten oder Webhook-Secrets.

## Speicherung von Geheimnissen

Eingerichtete Sandbox- und Live-Zugangsdaten sowie temporärer RFC-8628-Device-Flow-Status werden in authentifizierten AES-256-GCM-Umschlägen (`Paymos\Plugin\AesGcmEnvelope`) versiegelt, deren Schlüssel per HKDF-SHA256 aus dem `salt` in FOSSBillings `config.php` abgeleitet wird. Gespeicherte Geheimnisse werden niemals in HTML/Twig-Templates, API-Antworten, Fehlermeldungen oder Logs ausgegeben. Verbinden Sie die Installation über **Paymos neu verbinden** (Reconnect Paymos) erneut, nachdem Sie die Installations-URL geändert, einen Payment-Schlüssel widerrufen oder ein Webhook-Secret rotiert haben.

## Checkout und Rechnungslebenszyklus

- **Absturzsichere Erstellung (`STATUS_CREATING`):** Vor dem Aufruf von `POST /v1/invoices` speichert der Adapter eine ausstehende Absicht in `paymos_invoice_snapshots`. Wird der Netzwerkaufruf oder Prozess vor dem Speichern der Antwort unterbrochen, wiederholt der nächste Aufruf der Rechnung exakt dieselben Werte für `external_order_id`, `project_id`, `amount`, `currency` und `environment` und stellt die bestehende Rechnung ohne Duplikat wieder her.
- **Rechnungspositionen (`products[]`):** Gültige Rechnungszeilen (1–50 Positionen mit positivem Preis aus `$invoice['lines']`) werden über `Paymos\Plugin\InvoicePresentation` normalisiert und in `products[]` übertragen; bei ungültigen oder leeren Zeilen fällt der Checkout sauber auf reine Betragsabrechnung zurück.
- **Fail-closed-Ersetzung (`InvoiceReplacement`):** Ändern sich Rechnungssumme, Währung, Modus oder Projekt — oder läuft eine unbezahlte Paymos-Rechnung ab (`InvoiceRenewal`) — schließt der Adapter zuerst die alte Rechnung bei Paymos. Ist die alte Rechnung bereits bezahlt, noch bezahlbar, nicht erreichbar oder liefert sie 404, wird die Ersetzung blockiert (`InvoiceReplacementBlockedException`) und zur manuellen Prüfung zurückgehalten, damit der Käufer nicht zwei offene Rechnungen für denselben Vorgang bezahlen kann. Rechnungen mit der Markierung `paid_late_noted` blockieren eine Ersetzung ebenfalls bis zur Prüfung.
- **Keine automatischen Abonnements:** Paymos verarbeitet push-basierte Blockchain-Transaktionen und zieht keine wiederkehrenden Zahlungen automatisch ein; Verlängerungsrechnungen von FOSSBilling werden vom Kunden jeweils einzeln bezahlt.

## Webhooks und Cron-Abgleich

Eingehende Webhooks erreichen `/index.php?_url=/api/guest/paymos/webhook` (`Box\Mod\Paymos\Api\Guest::webhook()`). Der Handler liest den unveränderten Request-Body aus `php://input`, prüft `X-Webhook-Signature` ohne Berücksichtigung der Groß-/Kleinschreibung über Sandbox und Live hinweg via `MultiEnvironmentWebhookVerifier` (Dual-`v1=`-Rotationsunterstützung, 300-Sekunden-Replay-Fenster), dedupliziert atomar über `PdoEventStore` (`200` für bereits verbuchte Duplikate, `409 Conflict` für parallele laufende Zustellungen, `401` für ungültige oder abgelaufene Signaturen), führt für Abschlussereignisse eine Rückwärtsprüfung per `GET /v1/invoices/{id}` (`InvoiceReverseVerifier`) durch, erzwingt triangulierte Dezimalprüfungen (`AmountGuard`) und schützt bezahlte oder abgeschlossene Rechnungen vor Rollbacks. Nicht-2xx-Statuscodes (`401`/`409`/`400`/`500`) werden direkt ausgegeben, sodass `Api_Handler` Webhook-Fehler niemals zu HTTP `200` umschreibt.

`invoice.paid_late` erzeugt ausschließlich eine Notiz: Es setzt `paid_late_noted` und protokolliert einen Hinweis zur manuellen Prüfung, ohne den Zahlungsstatus der Rechnung zu verändern.

Verpasste Webhooks fängt der FOSSBilling-Cron-Listener (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`) auf: Er gleicht (gedrosselt auf höchstens einmal alle 10 Minuten) bis zu 50 unbezahlte Paymos-Rechnungen der letzten 24 Stunden über dieselben Prüfmechanismen ab und synchronisiert außerdem laufende sowie im Dashboard ausgelöste Erstattungen.

## Erstattungen und Überzahlungen

Das Modul bildet den vollständigen Paymos-Erstattungslebenszyklus ab (`RefundCoordinator`, `RefundIntentStoreInterface`, `RefundCandidateStoreInterface`, `NativeRefundProjectorInterface` und `RefundDiscovery`) und unterstützt beide serverseitig berechneten Modi:

- **`full`:** Vollständige Erstattung einer bezahlten Rechnung zurück an die von Paymos aufgezeichnete, verifizierte `first_sender`-Adresse.
- **`overpayment`:** Erstattung des zu viel gezahlten Betrags einer `paid_over`-Rechnung, während die FOSSBilling-Rechnung als bezahlt bestehen bleibt.

Administratoren mit Erstattungsberechtigung und gültigem CSRF-Token verwalten Erstattungen unter **Invoices → Paymos Refunds** (`/admin/paymos/refunds`) oder über die nativen Adapter-Hooks `refundPayment()` / `listRefundsForTransaction()` in FOSSBilling 2.0. Frei gewählte Teilbeträge sowie manuell angegebene Empfängeradressen oder Netzwerke werden vor jeder API-Anfrage abgelehnt.

- **Keine vorzeitige Buchungsbelastung:** Erstattungen im Status pending, processing oder unknown erzeugen niemals eine vorzeitige Buchung in FOSSBilling.
- **Verifizierte Abschlussprojektion:** Sobald `GET /v1/refunds/{withdrawal_id}` und `GET /v1/invoices/{invoice_id}` den Status `completed` bestätigen (über `withdrawal.completed`-Webhook oder Cron), werden `full`-Erstattungen einmalig über `Invoice\Service::refundInvoice($invoice, $note, null, ['gateway_returned' => true, 'gateway_refund' => false, 'external_reversal' => [...]])` in FOSSBilling 2.0 projiziert (mit sicherem Fallback für FOSSBilling 0.7); `overpayment`-Erstattungen hinterlegen eine Abschlussnotiz und lassen die Rechnung bezahlt.
- **Erkennung von Dashboard-Erstattungen:** Direkt im Paymos-Dashboard oder per API gestartete Erstattungen werden über `RefundDiscovery` erkannt, durch signierte `GET`-Abfragen verifiziert und ohne doppelten `POST /v1/refunds`-Aufruf projiziert.

## Fehlerbehebung

- **Verbindung startet nicht:** Prüfen Sie, ob `url` in `config.php` mit `https://` beginnt und `salt` gesetzt ist.
- **Rechnung bleibt nach der Zahlung unbezahlt:** Prüfen Sie die FOSSBilling-Protokolle auf `Manual review` (Betragsabweichung, Konflikt bei der Rückwärtsprüfung oder blockierte Ersetzung) und stellen Sie sicher, dass die feste Webhook-URL `/index.php?_url=/api/guest/paymos/webhook` öffentlich ohne HTTP-Authentifizierung oder Weiterleitungen erreichbar ist.
- **Erstattungsschaltfläche meldet erforderliche Neuverbindung:** Verbinden Sie die Installation über **Extensions → Paymos → Reconnect Paymos** neu, damit die Berechtigung `invoice_refunds_v1` im verschlüsselten Zugangsdaten-Umschlag hinterlegt wird.

## Links

- [Dokumentation](https://paymos.io/de/docs/cms-fossbilling)
- [Quellcode](https://github.com/Paymos-labs/fossbilling)
- [Änderungsprotokoll](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
- [Lizenz](LICENSE) — MIT License, Copyright (c) 2026 Paymos Labs
