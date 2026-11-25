# PaySum plugins

| Plugin | Package | Use |
| --- | --- | --- |
| Invision Community 4.7 Commerce | `paysum-invision.tar` | Hosted checkout for one-time invoices |
| WooCommerce | `paysum-woocommerce.zip` | Hosted checkout for classic and block checkout; supports HPOS |
| WordPress | `paysum-wordpress.zip` | Buy button for an existing PaySum payment link |

Download the installable files from Releases. To build them from source, run `bash build.sh`. The packages in `dist/` include the shared checkout code. Run `for test in tests/*.php; do php "$test" || exit; done` to check the checkout contract and plugin flows with fixtures.

## Invision and WooCommerce

Use an HTTPS site and a PaySum store in the same currency. Create a store API key with the `checkout_api` permission and copy the store webhook signing secret. Install the package in the platform's admin panel, add or enable PaySum as a payment method, and enter both values in the gateway settings.

The gateway sets the callback URL when it creates a checkout session. Allow PaySum to send HTTPS POST requests to that URL without a browser challenge. An order is not marked paid on the browser return. The callback must have a valid signature and the PaySum API must confirm a completed session for the same order, amount, and currency.

These packages do not handle recurring billing or refunds. Issue refunds in PaySum and update the order in your platform. Invision requires Commerce and PHP with cURL. Test on a staging installation before enabling either gateway in production.

## WordPress

Upload and activate the package. Add this shortcode to a page, using your own hosted checkout link:

```text
[paysum_checkout url="YOUR_PAYSUM_PAYMENT_LINK" label="Buy now"]
```

No API key is needed. PaySum handles payment and fulfillment for the linked checkout. The plugin does not create orders in WordPress.

## License

GPL-2.0-or-later.
