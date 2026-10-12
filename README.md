# AfPromotionFeedback

Shopware 6 plugin that explains **why** a promo code does not work. When a customer
enters a voucher that is expired, deactivated, not yet active, only valid in another
sales channel, or already redeemed, they receive a concrete and understandable error
message instead of the generic *"Promo code could not be found."*

## Features

Replace the generic "promotion not found" cart error with a reason-specific flash message:

| Reason | Message (DE) | Message (EN) |
|---|---|---|
| Campaign ended (`expired`) | Dieser Gutscheincode ist nicht mehr gültig. Die Aktion ist inzwischen beendet. | This promo code is no longer valid. The campaign has ended. |
| Promotion inactive (`inactive`) | Dieser Gutscheincode ist zurzeit nicht aktiv. | This promo code is currently not active. |
| Not started yet (`not-started`) | Dieser Gutscheincode wird erst zu einem späteren Zeitpunkt gültig. | This promo code is not valid yet. |
| Wrong sales channel (`sales-channel`) | Dieser Gutscheincode ist in diesem Shop nicht gültig. | This promo code is not valid in this shop. |
| Already redeemed (`already-redeemed`) | Dieser Gutscheincode wurde bereits eingelöst. | This promo code has already been redeemed. |
| Code unknown (default) | Der Gutscheincode „%code%“ wurde nicht erkannt. Bitte überprüfen Sie die Schreibweise. | The promo code "%code%" was not recognized. Please check the spelling. |

All messages can be customised via Shopware snippet management.

## Requirements

- Shopware 6.7 (tested on `>= 6.7.13.0`)

## Installation

Copy the plugin into `custom/plugins/AfPromotionFeedback` and run:

```bash
bin/console plugin:refresh
composer dump-autoload
bin/console plugin:install --activate AfPromotionFeedback
bin/console cache:clear
```

## How it works

The core `PromotionCollector` (priority 4900) looks up codes with *strict* filters
(`active = true`, sales channel assignment, active date range) and raises a single
generic `PromotionNotFoundError` when anything fails.

This plugin registers an additional cart collector
(`Af\PromotionFeedback\Cart\PromotionReasonCollector`, priority 0 – it runs **after** the
core promotion collector). Whenever a `PromotionNotFoundError` is present, the collector
runs a *permissive* lookup
(`Af\PromotionFeedback\Service\PromotionCodeDiagnosticService`) that ignores those filters
and determines the concrete reason, then swaps the generic error for a reason-specific
`PromotionInvalidError` (`promotion-invalid-<reason>`).

Codes that do not exist at all keep the default error, which is translated using the
friendlier `checkout.promotion-not-found` snippet.

## Demo data (for testing)

No demo data is installed by the plugin itself. Use the included console command to
create or remove test promotions (each with a 10% cart discount):

```bash
# create AF-EXPIRED, AF-INACTIVE, AF-FUTURE, AF-VALID
bin/console af:promotion-demo install

# remove them again
bin/console af:promotion-demo remove
```

By default the promotions are assigned to the first active sales channel; pass
`--sales-channel-id=<32-char-hex>` to target a specific one. `install` is idempotent
(existing codes are skipped).

| Code | Active | Valid from | Valid until |
|---|---|---|---|
| `AF-EXPIRED` | yes | 10 days ago | 1 day ago |
| `AF-INACTIVE` | no | – | – |
| `AF-FUTURE` | yes | in 2 days | – |
| `AF-VALID` | yes | 10 days ago | in 10 days |

## Customising messages

The messages live in `src/Resources/snippet/{de_DE,en_GB}/storefront.{de-DE,en-GB}.json`.
They can be overridden per storefront snippet set in the Shopware administration, or by
editing the files (followed by `bin/console cache:clear`).

## Changelog

### 0.1.0

- Initial release: reason-specific promo code error messages.