# Developer-only tests

Do not put this directory in WordPress plugins or the public webroot.
`compat.php` includes `service.php` and `run.php`: the total is 139, not three
independent totals added again. WP/WC/database implementations in these files are
explicit test doubles. `browser.py` uses the real plugin JS/CSS/template and a fake
REST boundary; its confirmation decision is stubbed. It is not a live WooCommerce test.
`qr.test.py` compares matrices to python-qrcode 8.2, not a physical camera.

Commands and limits are described in `../yookds-for-woocommerce/docs/TESTPLAN.md`.
`integration.php` is syntax-checked but not run. It needs explicit opt-in and an empty,
disposable real WC installation. Do not run it on a customer/production store.

The `results-*-alpha2.txt` files record this development environment, 2026-09-28.
