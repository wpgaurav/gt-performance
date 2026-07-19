# GT Performance asset manifest

These files are distribution and marketing assets. They are intentionally excluded from the installable plugin ZIP.

## WordPress directory

| File | Size | Use |
|---|---:|---|
| `wordpress-org/icon-128x128.png` | 128 × 128 | Standard directory icon |
| `wordpress-org/icon-256x256.png` | 256 × 256 | Retina directory icon |
| `wordpress-org/banner-772x250.png` | 772 × 250 | Standard plugin banner |
| `wordpress-org/banner-1544x500.png` | 1544 × 500 | Retina plugin banner |
| `wordpress-org/screenshot-1.png` | Browser capture | Performance dashboard |
| `wordpress-org/screenshot-2.png` | Browser capture | Cache presets and lifetime controls |
| `wordpress-org/screenshot-3.png` | Browser capture | Server-side CSS delivery controls |
| `wordpress-org/screenshot-4.png` | Browser capture | Live unused-CSS reports |
| `wordpress-org/screenshot-5.png` | Browser capture | FluentCart license and protected updates |

The screenshot captions live in `readme.txt` under `== Screenshots ==`.

## FluentCart and marketing

| File | Size | Use |
|---|---:|---|
| `fluentcart/gt-performance-product-cover-1800x1200.png` | 1800 × 1200 | FluentCart featured image and product hero |
| `marketing/gt-performance-social-1200x630.png` | 1200 × 630 | Open Graph and social sharing |

## Brand

- Primary blue: `#2271b1`
- Deep blue: `#0a4b78`
- Light blue: `#72aee6`
- Interface neutral: `#f6f7f7`
- Success: `#3a8f28`

The Magnific-generated master mark and brand field are in `magnific/`. The final banner, product cover, and social card are deterministic HTML/CSS compositions in `source/marketing-assets.html` and `source/marketing-assets.css`. Those compositions embed the real WordPress Studio screenshots, so interface labels and product state are not AI-generated.

The GT monogram is original artwork and does not use the WordPress or Cloudflare logo. The PNG directory icons are the source of truth; an unrelated vector fallback is intentionally not shipped.

Run `build-product-page.php` with the public dashboard image, CSS-report image, and verified FluentCart direct-checkout URLs to create the WordPress-ready `marketers-delight/inline-page-block` stored in `fluentcart/product-page.wordpress.html`. The builder prepends the canonical GTDS token layer from the `gt-design` skill; use `--tokens-path` when that skill lives elsewhere.
