# .NET MAUI API Extensions

A WordPress plugin that exposes term metadata and creates WooCommerce orders through REST API endpoints for a .NET MAUI app.

## Requirements

- WordPress with the REST API enabled
- WooCommerce for the Loyverse order endpoint
- A user authenticated with the `edit_shop_orders` capability to use either POST endpoint

## Installation

1. Download this repository as a ZIP.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin** and upload the ZIP, or copy this directory to `wp-content/plugins/maui-api-extensions/`.
3. Activate **.NET MAUI API Extensions** in the WordPress admin.

## Endpoints

The API namespace is `my-app/v1`.

### Read term metadata

`GET /wp-json/my-app/v1/term-meta/{id}`

Returns the term ID, name, and all metadata stored for that term. A nonexistent term returns 404. When no metadata exists, `meta` is returned as an empty object.

**Security:** This endpoint is public and returns every metadata key/value for the requested term. Do not store sensitive data in term metadata exposed this way. Before using the plugin on a site with non-public term metadata, restrict this route with an appropriate permission callback.

### Create or update term metadata

`POST /wp-json/my-app/v1/term-meta/{id}`

Requires the current user to have the `edit_shop_orders` capability. Send JSON such as:

```json
{
  "meta": {
    "mobile_label": "Featured",
    "mobile_options": ["one", "two"]
  }
}
```

The term must exist. Metadata keys are sanitized with WordPress `sanitize_key()` and values with `sanitize_text_field()`.

### Create a WooCommerce order

`POST /wp-json/my-app/v1/loyverse-order`

Requires the current user to have the `edit_shop_orders` capability and WooCommerce to be active. The JSON body may contain `status`, `currency`, `total`, `billing`, `shipping`, `line_items`, `fee_lines`, `meta_data`, and `receipt_date_utc`. A line item can contain `product_id`, `quantity`, `subtotal`, `total`, and `meta_data`.

The optional `receipt_date_utc` accepts an ISO-8601 UTC timestamp, for example `2025-11-30T12:42:04Z`, and is used to set the order dates.

## Authentication

The plugin does not implement authentication itself. Configure WordPress REST API authentication and send authenticated requests for both POST endpoints. Authorization is based on the `edit_shop_orders` capability.

## License

GPL-2.0-or-later.
