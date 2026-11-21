# Decision log

## 2026-11-21 — Initial implementation direction
- Use Laravel as the application framework and build marketplace domain modules in first-party code.
- Bagisto was evaluated as a Laravel commerce candidate. The inspected Bagisto 2.5 core tree contains downloadable products and a PayPal module, but the multi-vendor seller feature is a separate extension. Rather than make the initial build depend on that extension, the first-party app will own creator onboarding, digital media, entitlements and finance.
- Spree is available in the account as a Ruby on Rails codebase. Its published marketplace payout path is based on Stripe Connect and therefore does not meet the PayPal-only constraint.
- Do not add Stripe unless the owner explicitly changes the decision.
- PayPal Checkout and PayPal Payouts are distinct capabilities. Confirm the actual platform account's eligibility and regional support before enabling automated creator payouts.
- Use PostgreSQL, Redis and private S3-compatible storage.
- No physical goods, shipping, inventory, bundles or subscriptions in the first release.
- No editorial pre-moderation of creator uploads in the first release; creator verification remains mandatory.

## Open gates
1. Confirm PayPal Checkout credentials and webhook configuration for sandbox.
2. Confirm PayPal Payouts eligibility and country/account requirements.
3. Define seller identity/age verification and rights declaration requirements with legal review for target markets.
4. Complete order/order-item domain and connect it to payment capture.
5. Implement and test idempotent ledger posting, holds, refunds, disputes and payout reconciliation.
6. Add admin UI and operational audit tooling before production.
