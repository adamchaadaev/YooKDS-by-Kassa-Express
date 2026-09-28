# YooKDS — development contract

This is a WooCommerce WordPress plugin for Kassa Express. Read `START-HERE.md`
and `handoff/TASK.md` before editing. The customer reports that the alpha.2 build
does not show a newly placed WooCommerce order and that QR link generation is not
working reliably. These reports are unresolved, regardless of old mock-test results.

## Preserve these product requirements

- One real open WooCommerce order corresponds to one KDS card. Query WooCommerce
  through its order APIs. Do not invent/demo/seed orders in runtime code or show
  test fixtures as fallback. Preserve full order IDs as keys for all actions.
- Keep Förbereds, Klara and separate Historik. A ready action changes kitchen state
  only; Utlämnad must persist `completed` on the SAME WooCommerce order. Do not report
  success until the server confirms it. Do not mix payment capture with handover.
- Do not show completed, cancelled, fully refunded, failed, trashed or draft orders
  as active. Show other supported registered open statuses, with unpaid status clear.
- Four-digit order numbers are DISPLAY ONLY: 27080 -> 7080 and 7 -> 0007. Keep the
  WooCommerce order-number filter and the existing NutsExpress receipt selectors.
  Never use truncated display numbers as identifiers or merge distinct orders.
- Preserve NX (`nx_channel`, `nx_station`, `nx_table`, `nx_mode`) capture and saved
  `_nx_` order metadata in classic checkout and Checkout Block. A station here means
  the originating register, not a Grill/Bar kitchen department.
- QR generator: live URL updates, optional values, preserving unrelated query/fragment,
  safe encoding, actionable error messages, copy and QR export. Validate exported
  code by decoding it and compare the decoded URL, then test checkout attribution.
  The entire QR -> visit -> checkout -> order metadata -> KDS path is the feature.
- Preserve saved WAPF choices, visible order-line metadata, mapped custom fields,
  customer instructions and shipping information. Do not reconstruct old selections
  from current product defaults. Do not leak private payment/authentication metadata.
- Keep the existing design; no wholesale visual rewrite or unrelated feature expansion.

## Safety and verification

- Do not install, change statuses, edit settings, run integration fixtures or trigger
  emails/payments/printing against the customer's production store without a new
  explicit authorization for the specific action. Use an isolated test installation.
- Do not commit credentials, wp-config.php, databases, real customer/order exports,
  sessions, API responses, production screenshots or environment files to GitHub.
- Server-side authentication, authorization and CSRF protection remain required.
  Do not solve visibility issues by exposing customer orders publicly.
- Do not silently discard an order that cannot be read. Surface a clear incomplete
  board/per-order error. One bad or locked order must not silently hide other orders.
  Preserve stale-state/action safety while improving availability.
- Do not add raw Throwable messages or metadata to public API responses. Diagnostics
  must be staff-only and avoid customer data; `tools/diagnose-woo.php` is WP-CLI only.
- Tests with WC/WP doubles are not live integration proof. The actual acceptance gate
  requires WordPress + WooCommerce + database + real HTTP/browser requests with
  authentication, tested in both HPOS and legacy stores. Record exact versions.
- Use separate commits for source import, a reproduced failing test, and each fix.
  Work on `fix/woo-sync-qr-alpha2` or an available similarly named branch; never force
  push, overwrite unrelated work, merge to main or deploy automatically.
- GitHub publish is requested, but credentials must use the user's normal GitHub
  authorization. On denial, report the exact denial; do not search for credentials
  or try to bypass access restrictions. Publishing source is not deployment.

## Layout

- `yookds-for-woocommerce/`: installable plugin source (alpha.2 baseline).
- `tests/`: explicitly isolated doubles and the opt-in real-integration smoke script.
- `tools/build.py`: packages ONLY the plugin directory.
- `tools/diagnose-woo.php`: operational diagnosis, no order/option writes.
- `handoff/`: current task, evidence and a reproduced failure path.

## Existing checks

`php tests/compat.php` and `node tests/client.test.cjs` can run without WordPress.
`php handoff/reproduce-board-abort.php` demonstrates the current all-or-nothing
read failure with doubles; it is not a production-store diagnosis.
`python3 tools/build.py` builds a plugin ZIP but does not certify it.
Read `tests/README.md` and the test-plan warnings before running integration scripts.
