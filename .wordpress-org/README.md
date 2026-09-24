# WordPress.org Plugin Directory Assets

This directory holds the images for the WordPress.org plugin page. They are
prepared and ready, but **the plugin is not published on WordPress.org**, so
nothing here is live.

## File naming conventions

- `banner-772x250.png`   — Plugin page header banner (normal resolution)
- `banner-1544x500.png`  — Plugin page header banner (high-DPI / retina)
- `icon-128x128.png`     — Plugin icon (normal resolution)
- `icon-256x256.png`     — Plugin icon (high-DPI / retina)
- `screenshot-1.png`     — Screenshot #1 (referenced in readme.txt)
- `screenshot-2.png`     — Screenshot #2
- ...and so on

> **Do not delete or rename files here** unless you also update the
> `== Screenshots ==` section in `readme.txt`.

These files are ready for the WordPress.org plugin page assets directory if the
plugin is ever published there. They are not live: the plugin is currently
distributed from GitHub, and there is no automated deploy workflow.

## Regenerating

The banner and icon are generated from `assets/logo.svg` in the CHIP house
style — the white mark on brand indigo, matching the sibling CHIP plugins.

```bash
python3 scripts/make-assets.py
```

Requires `rsvg-convert` (librsvg) and Pillow. Screenshots, if added, are real
captures from a local WordPress running Sprout Invoices with this plugin
active, resized with:

```bash
convert capture.png -resize 1280x -strip -quality 92 screenshot-1.png
```

WordPress.org requires screenshots to be at least 4:3 (or wider). Keep the
banner at the exact dimensions above; other sizes are rejected.
