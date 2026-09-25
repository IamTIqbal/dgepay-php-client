# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-09-25

### Security

- **Callback verification examples could mark unpaid orders as paid.** The
  callback DGePay sends after payment reaches your server through the
  customer's browser, so its contents can't prove a payment happened. The
  shipped examples could still record an order as paid when DGePay's
  server-to-server verification had not confirmed the payment, or had
  confirmed it at a different amount. If you copied `examples/laravel-controller.php`,
  `examples/handle-callback.php`, or the README "Handle Callback" snippet into
  your application, **review your callback handler and apply the same checks**
  (#1).

### Changed

- `examples/laravel-controller.php`: only marks a payment `completed` after
  `getTransactionStatus()` returns `success === true`, `DgePay::isSuccessStatus()`
  holds for the verified `status_code`, and the verified `unique_txn_id` and
  `amount` match the stored order. Otherwise it marks the payment `failed` or
  `pending_review`, logs the reason, and asks the customer to contact support
  with their order ID. Records only verified data (`txn_number`,
  `payment_method`, `amount`).
- `examples/laravel-controller.php`: an unverified "cancelled"/"failed"
  callback no longer closes an open order. Orders are only marked `cancelled`
  when DGePay confirms it, and orders held in `pending_review` can still be
  completed by a later fully verified callback.
- `examples/handle-callback.php` and README: same verification requirements,
  including the amount check and use of verified data only. The example now
  escapes values it echoes and logs only selected verification fields.
- `DgePay::parseCallbackResult()`: added a docblock warning that its result
  must never be trusted on its own.

No public method signatures changed.

## [1.0.0]

- Initial release.

[1.0.1]: https://github.com/IamTIqbal/dgepay-php-client/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/IamTIqbal/dgepay-php-client/releases/tag/v1.0.0
