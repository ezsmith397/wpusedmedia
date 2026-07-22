# Used Media Pro

A more powerful media manager for WordPress. It adds a **Media → Used Media** screen that shows *where every library item is actually used*, and (as it grows) will find external images, download and re-attach them locally, and safely clean up unused media.

> Status: **early development.** Phase 0–1 is implemented (usage index + enhanced library view). External-image import and cleanup tools are on the roadmap below.

## Why

Native `upload.php` shows your media, but it can't tell you which post, page, or builder layout references a given file — so you can never safely delete anything. Used Media Pro builds a **usage index** across your content and answers that question.

## Architecture

Everything hangs off one extensible contract, `Source_Adapter` (`includes/interface-source-adapter.php`). Each place media can be referenced is a pluggable adapter:

- `scan_references()` — feeds the usage index (where is this attachment used?)
- `scan_external()` — finds off-site image URLs (Phase 4)
- `replace()` — swaps an external URL for a locally imported attachment, re-attaching the id (Phase 4)

The golden rule: **every read/write of a reference goes through an adapter** — never a global find/replace, which corrupts serialized/JSON builder data.

Third parties (and future modules) register adapters via the `ump_source_adapters` filter.

### Shipping adapters

| Adapter | Status | Covers |
| --- | --- | --- |
| `core_content` | ✅ Phase 1 | classic + block editor content, featured images, reusable blocks, FSE templates |
| `bricks` | ✅ Phase 2 | Bricks Builder layout data (`_bricks_page_content_*` postmeta) — image elements, backgrounds, logos, galleries |

## Roadmap

| Phase | Deliverable |
| --- | --- |
| 0 | Scaffold, menu, settings, adapter registry ✅ |
| 1 | Core adapter + usage index + enhanced list view with "Used in" ✅ |
| 2 | Bricks adapter feeding the same index ✅ |
| 3 | "No references found" cleanup + stage-and-restore delete + multi-select bulk delete |
| 4 | External image scan + download & **full re-attach** + undo |
| 5 | Background-job progress, incremental index on `save_post`, polish |

Design decisions locked in: **distributable** build with a trusted-domain allowlist, **stage-and-restore** deletes (nothing hard-deleted until you purge), and **full re-attach** on external-image replacement.

## Install (development)

Clone into `wp-content/plugins/` and activate:

```bash
git clone https://github.com/ezsmith397/wpusedmedia.git wp-content/plugins/used-media-pro
```

Then go to **Media → Used Media** and click **Build index**.

## Development

Coding standards are enforced with [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) via PHP_CodeSniffer:

```bash
composer install     # install dev tooling (PHPCS + WPCS)
composer lint        # check against the WordPress standard (phpcs.xml.dist)
composer lint:fix    # auto-fix what can be fixed (phpcbf)
```

The ruleset lives in `phpcs.xml.dist` (prefix `umedia` / `UsedMediaPro`, text domain `used-media-pro`, PHP 7.4+, WP 6.0+).

## Requirements

- WordPress 6.0+
- PHP 7.4+

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
