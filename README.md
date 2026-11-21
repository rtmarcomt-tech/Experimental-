# Digital Creator Marketplace

Laravel application for a multi-creator digital marketplace. This repository is the application source; the existing Spree repository is not used as its runtime base.

## Confirmed product rules

- Individual sales of photographs and videos; free content is also supported.
- No bundles, subscriptions, physical products, stock or shipping in the initial release.
- A creator must be approved by an administrator before any paid or free content can be published.
- Platform commission starts at 10%.
- PayPal is the buyer payment provider. Do not add Stripe.
- Funds owed to creators are held for a configurable period and paid later, subject to a configurable minimum and PayPal account/API eligibility.
- No editorial pre-moderation of uploads in the initial release; seller verification, rights declarations, abuse reporting and legal takedown remain required.

## Stack

- PHP 8.3+
- Laravel 13
- PostgreSQL
- Redis for queues/cache
- Private S3-compatible object storage
- PayPal Checkout; creator payouts are a separate capability and must be verified against the platform account before enabling automation.

## Local setup

1. Install PHP 8.3+, Composer, PostgreSQL and the required PHP extensions.
2. Copy .env.example to .env and configure database and storage credentials.
3. Run composer install.
4. Run php artisan key:generate.
5. Run php artisan migrate.
6. Run php artisan test.

This is an early development foundation, not production-ready software. PayPal, payout, entitlement and immutable-ledger modules are not yet implemented.

## Current foundation

- User accounts and creator profile schema.
- Creator verification case schema.
- Digital media and private asset metadata schema.
- Domain guard requiring an approved, active creator before paid media is purchasable.
- Unit tests for the initial creator eligibility rules.
