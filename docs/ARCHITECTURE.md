# Marketplace architecture

## Product scope
- Multi-creator marketplace for individual photo and video sales.
- Free items are allowed. No bundles, subscriptions or physical goods in the first release.
- Creators must pass administrator verification before publishing any public offer.
- No editorial pre-moderation of each upload in the first release. Rights declarations, reporting, takedown and legal response remain mandatory.
- Platform commission: 10% initially, recorded as a rate snapshot per sale.
- PayPal is the only initial buyer payment provider. Stripe is excluded.
- Funds attributable to creators are held for a configurable period and become payable only after hold release, dispute checks and a configurable minimum.

## Technology
- Laravel 13 / PHP 8.3+ application.
- PostgreSQL as transactional database.
- Redis for queues, cache and locks.
- S3-compatible private object storage for originals.
- PayPal Checkout for buyer payments.
- First-party domain modules for creators, digital media, entitlements, finance and payouts.
- No reliance on Bagisto's separate marketplace extension or Spree's Stripe Connect payout implementation.

## Domain modules and ownership
1. Identity: users, account state, roles and permissions.
2. Creator onboarding: creator_profiles and creator_verification_cases; approval is an explicit administrator action.
3. Digital media: digital_media and media_assets; originals are private, previews are separate derivatives.
4. Commerce: order and order-item records (to be implemented); each line snapshots creator, media, currency and price.
5. Entitlements: media_access_grants; only active grants authorize access to the original file.
6. Payments: payment_transactions and paypal_webhook_events; PayPal webhook events are persisted and deduplicated before processing.
7. Finance: creator_ledger_accounts, append-only creator_ledger_entries, fund_holds and commission snapshots.
8. Payouts: payout requests/batches and reconciliation (to be implemented).
9. Audit: immutable security and administrative event trail (to be implemented).

## Financial invariants
- Store money as integer minor units; never use floating-point amounts.
- Commission and creator net must sum exactly to gross for each sale.
- Commission calculation is deterministic and uses basis points; default 1000 bps = 10%.
- Every capture, webhook, entitlement, commission and ledger posting must be idempotent.
- Ledger corrections use compensating entries; existing ledger rows are not updated or deleted.
- Creator net remains pending while a hold is active.
- A dispute freezes the affected amount; no payout may exceed reconciled available balance.
- A payout is not marked paid until provider confirmation or a documented, audited manual reconciliation.
- PayPal Payouts is a separate API product from Checkout. Automation remains disabled until account eligibility, regional availability, KYC and limits are confirmed.

## Access and storage
- Original media and verification documents must never be stored in a public webroot.
- Every media access request checks ownership or an active entitlement server-side.
- Downloads use short-lived signed URLs issued only after authorization.
- Validate file MIME by content, size, checksum and permitted media type.
- Enforce per-resource authorization in backend policies; never trust client-supplied creator IDs.
- Log sensitive administrative and financial actions; minimize IP/device data and apply retention rules.

## Core flows
### Creator verification
Account registration -> creator application -> pending review -> administrator approval/rejection -> approved creator permissions. Pending or rejected creators cannot publish, including through direct API calls.

### Media publication
Approved creator uploads original to private storage -> backend validates file and creates preview -> creator confirms rights/authorization declaration -> publishes as free or paid. No editorial approval queue is introduced in this phase.

### Paid purchase
Create pending order -> create PayPal order -> server-side capture confirmation -> persist and deduplicate webhook -> create entitlement -> split gross into 10% platform commission and creator net -> post pending ledger entries -> create hold -> after hold and dispute checks release to available -> payout when minimum reached.

### Free access
Create a free entitlement without PayPal capture or financial ledger entry.

### Refund/dispute
Persist provider event -> freeze affected pending/available amount -> reconcile provider outcome -> post compensating entries -> revoke entitlement only when required by the final decision/policy.

### Creator payout
Calculate reconciled available balance -> enforce minimum and account checks -> create idempotent payout request -> administrator approval if required -> use PayPal Payouts only if account is eligible, otherwise audited manual payout -> reconcile and post paid entry.

## Creator verification API

- Account registration and login issue Sanctum personal access tokens.
- Authenticated users can submit one creator application; rejected applications may be resubmitted.
- A submitted application is always pending and does not grant publishing privileges.
- Identity evidence is uploaded separately and stored on the private storage disk; only an administrator route can download it.
- Only users with the server-side `is_admin` flag can review verification cases.
- Approval is blocked until an identity document is present. Rejections require an administrator reason.
- The review decision and creator profile status are updated in one database transaction.
- The public registration endpoint cannot set administrative privileges.

## Creator media draft API

- Approved creators can upload individual photo or video originals into private object storage.
- Draft metadata supports free items or paid items with positive integer prices in minor currency units.
- Upload metadata records MIME type, byte size and SHA-256 checksum.
- Creators can list, inspect and update only their own drafts.
- Pending, rejected or suspended creators cannot upload or manage sellable media.
- New uploads remain drafts. Publication requires a separately uploaded preview image and an accepted rights declaration (owner, licensed, or authorized). The public catalogue exposes only published metadata and preview images; original files remain private and are not exposed by the catalogue.

## Implemented in current bootstrap
- Laravel application skeleton and configuration.
- User, creator profile and verification case schema.
- Digital media and private asset metadata schema.
- Payment transaction, access grant, ledger account/entry, fund hold and PayPal webhook event schema.
- Creator eligibility rule for paid media.
- Commission calculator in integer minor units with half-up rounding and invariant tests.

## Not implemented yet
Checkout/order orchestration, PayPal API client and webhook signature verification, creator approval workflow endpoints, upload pipeline, download authorization controller, ledger posting service, hold release worker, refunds/disputes, payout workflows, admin UI, and production hardening.
