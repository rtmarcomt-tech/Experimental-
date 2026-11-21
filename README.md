# Digital Creator Marketplace

Laravel application for a multi-creator marketplace focused exclusively on individual digital-content sales. The Spree repository is not used as the runtime base.

## Confirmed product rules

- Individual sales of photographs and videos; free content is also supported.
- No bundles, subscriptions, physical products, stock or shipping in the initial release.
- Creator registration requires identity-document submission and administrator approval before publication or sales.
- Creators are responsible for the rights and permissions covering uploaded content; the initial release does not include editorial pre-moderation.
- Platform commission: configurable, default 10%.
- Buyer checkout: PayPal Checkout. Do not add Stripe.
- Creator proceeds are recorded in an append-only ledger and held for a configurable period.
- Creator payouts are requested by the creator, reserved in the ledger, submitted by an administrator through PayPal Payouts, and settled only after a verified PayPal webhook or administrator reconciliation.
- Minimum payout and currency are configurable.

## Stack

- PHP 8.3+
- Laravel 13
- PostgreSQL
- Sanctum for API authentication
- Private S3-compatible object storage for original content and verification documents
- PayPal Checkout and PayPal Payouts

## Implemented foundation

- Registration, login and authenticated buyer/creator areas.
- Creator applications, private identity-document upload, administrator review and approval/rejection.
- Public creator profiles with separate free and paid content.
- Private creator media library, rights declaration, offer configuration and explicit publication step.
- Storefront and content detail pages.
- Free-content claims, protected buyer library, purchase history and purchase detail.
- PayPal order creation/capture with idempotency, amount/currency verification and payment webhooks.
- Immutable creator ledger, configurable refund/dispute hold, scheduled release from pending to available.
- Admin-only full/partial refund workflow, refund webhooks, creator-ledger reversals and access revocation after full refund.
- Creator payout email configuration, full-available-balance payout requests, minimum threshold, ledger reservation, PayPal Payouts submission, verified success/failure webhooks and manual reconciliation.
- Creator sales/financial dashboard and administrator operational, transaction and payout dashboards.

## Configuration

Copy `.env.example` to `.env` and configure at least:

- Database and private object-storage credentials.
- `PAYPAL_MODE`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET` and `PAYPAL_WEBHOOK_ID`.
- `MARKETPLACE_COMMISSION_BPS` (default `1000` = 10%).
- `MARKETPLACE_HOLD_DAYS` (default `14`).
- `MARKETPLACE_PAYOUT_MINIMUM_MINOR` (default `5000` = €50.00).
- `MARKETPLACE_CURRENCY` (default `EUR`).

The PayPal REST application must be enabled for Payouts by PayPal before creator payments can be sent. Sandbox and live payout eligibility are controlled by PayPal and the platform account; passing automated tests does not confirm live-account approval.

## Local setup

1. Install PHP 8.3+, Composer, PostgreSQL and the required PHP extensions.
2. Copy `.env.example` to `.env` and configure database, storage and PayPal sandbox credentials.
3. Run `composer install`.
4. Run `php artisan key:generate`.
5. Run `php artisan migrate`.
6. Run `php artisan test`.
7. Run `php artisan schedule:work` locally to process matured creator fund holds.

In production, configure Laravel's scheduler (the `schedule:run` cron entry every minute) and a queue worker as required by the deployment configuration.

## Release status

This repository is still under development. CI validates Composer configuration, PostgreSQL migrations and automated unit/feature tests. The application has not been merged to the default branch or deployed. Live PayPal checkout and payout credentials, PayPal webhook registration, private object storage, production scheduling, security review, operational monitoring and end-to-end production acceptance remain deployment prerequisites.
