# Skyrocket Gallery

**Author:** Skyrocket Digital Development

Skyrocket Gallery is a lightweight WordPress plugin that dynamically builds media galleries from [FileBird](https://ninjateam.org/wordpress-media-library-folders-plugin/) folders using the `[skyrocket_gallery]` shortcode. It supports responsive masonry and justified layouts, hover captions, lazy-loaded thumbnails, and an interactive full-screen lightbox with keyboard navigation and full-size image downloads.

---

## Features

- **FileBird Folder Integration:** Queries FileBird's folder (`{$wpdb->prefix}fbv`) and attachment relationship (`{$wpdb->prefix}fbv_attachment_folder`) tables to resolve images automatically.
- **Recursive Subfolder Support:** Automatically includes images from a selected folder as well as all of its nested descendant folders.
- **Flexible Folder Resolution:**
  - Defaults to collecting images under a top-level `Galleries` folder when `folder="all"`.
  - When a specific `folder` name is supplied, checks for a direct child of `Galleries` first (case-insensitive) and falls back to matching a folder of that name anywhere in FileBird.
- **Two Responsive Layouts:**
  - **Masonry** (multi-column CSS layout that adapts across desktop, tablet, and mobile breakpoints).
  - **Justified** (aspect-ratio-aware flexbox rows with a configurable target row height).
- **Built-in Lightbox:** Full-screen image viewer with previous/next controls, wrap-around navigation, keyboard support (`Escape`, `ArrowLeft`, `ArrowRight`), and a full-size download button.
- **Zero External Asset Files:** Injects inline CSS and JavaScript via `wp_add_inline_style()` and `wp_add_inline_script()`.

---

## Requirements

- **WordPress** (with `WP_Query`, shortcodes, and attachment metadata support)
- **FileBird** (active/installed with standard `fbv` and `fbv_attachment_folder` database tables)

### Behavior When FileBird Is Unavailable or No Images Match

- If a named folder is not found or the FileBird folder table is missing, the shortcode returns:
  `<p class="skyrocket-gallery-error">Gallery folder not found.</p>`
- If no matching attachments are found (or `folder="all"` finds no images under `Galleries` / the relationship table is missing), the shortcode returns:
  `<p class="skyrocket-gallery-empty">No images found in this gallery.</p>`
- Front-end inline styles and scripts are enqueued on `wp_enqueue_scripts`.

---

## Installation

1. Upload `skyrocket-gallery.php` to a new folder inside `/wp-content/plugins/` (for example, `/wp-content/plugins/skyrocket-gallery/skyrocket-gallery.php`).
2. Activate **Skyrocket Gallery** from the **Plugins** menu in the WordPress admin dashboard.
3. Ensure **FileBird** is installed and organize your gallery images into folders (optionally under a top-level `Galleries` folder).

---

## Usage

Add the `[skyrocket_gallery]` shortcode to any post, page, or widget area that supports shortcodes.

### Basic Examples

Display all images from folders beneath the `Galleries` folder in a default masonry layout:

```text
[skyrocket_gallery]
```

Display images from a specific folder (and any subfolders inside it):

```text
[skyrocket_gallery folder="Summer Events"]
```

Display a folder using the justified layout:

```text
[skyrocket_gallery folder="Summer Events" layout="justified"]
```

Display a justified gallery with a custom target row height of `240px`:

```text
[skyrocket_gallery folder="Summer Events" layout="justified" row_height="240"]
```

---

## Shortcode Attributes

| Attribute | Default | Allowed / Expected Values | Description |
| :--- | :--- | :--- | :--- |
| `folder` | `all` | `all` or a FileBird folder name | When `all`, pulls images from all descendants of a folder named `Galleries` (case-insensitive). When given a name, looks first for a direct child of `Galleries`, then falls back to any matching folder name anywhere in FileBird. Includes descendant folders. |
| `layout` | `masonry` | `masonry`, `justified` | Controls the layout class (`skyrocket-layout-masonry` or `skyrocket-layout-justified`) applied to the gallery container. |
| `row_height` | `200` | Integer (clamped to a minimum of `50`) | Sets the `--row-height` CSS custom property (in pixels) used to size rows in the `justified` layout. |

---

## Ordering, Images, and Lightbox Controls

- **Query Order:** Matching attachments (`post_type => attachment`, `post_status => inherit`, `posts_per_page => -1`) are queried ordered by `date` descending (`DESC`).
- **Thumbnails & Full Images:** Grid thumbnails use the `medium_large` image size with `loading="lazy"`, while clicking an item opens the `full` size image in the lightbox.
- **Captions & Alt Text:** Each item uses the attachment title for hover captions and lightbox captions, falling back to the attachment title if `_wp_attachment_image_alt` is empty.
- **Lightbox Controls:**
  - Click any gallery item to open the lightbox.
  - Click the **Previous** (`‹`) or **Next** (`›`) buttons, or press **Left Arrow** / **Right Arrow** on your keyboard, to cycle through images in the active gallery.
  - Click the download icon in the lightbox to download the full-size image (`download` attribute).
  - Press **Escape** or click outside the image/content area to close the lightbox.

---

## CSS Classes and Custom Properties

You can target or override styles in your theme stylesheet using the following classes and variables:

- `.skyrocket-gallery` — Main gallery wrapper (`--row-height` inline variable).
- `.skyrocket-layout-masonry` — Masonry column container (`3` columns default, `2` columns at `<= 768px`, `1` column at `<= 480px`).
- `.skyrocket-layout-justified` — Justified flex container (`--aspect-ratio` inline variable on each `.skyrocket-gallery-item`).
- `.skyrocket-gallery-item` — Individual gallery item container.
- `.skyrocket-lightbox-trigger` — Link wrapping each thumbnail (`data-title` attribute).
- `.skyrocket-gallery-caption` — Hover/focus caption overlay on thumbnails.
- `.skyrocket-gallery-error` / `.skyrocket-gallery-empty` — Status message paragraphs.
- `.skyrocket-lightbox` (`.is-active` when open), `.skyrocket-lightbox-content`, `.skyrocket-lightbox-download`, `.skyrocket-lightbox-caption`, `.skyrocket-lightbox-btn`, `.skyrocket-lightbox-prev`, `.skyrocket-lightbox-next` — Lightbox elements.

## Development

New functionality should be added as independent modules.

Register each module in `includes/loader.php`.

Use the `swu_` prefix for global functions to avoid
conflicts with WordPress and other plugins.

## CSS build

Run this ...

`npm run watch:css`


## Building a release

The plugin uses [Skyrocket Plugin Updater](https://github.com/lavamitts/skyrocket-plugin-updater) as a Composer dependency.

The updater dependency also provides the distribution build script, which prepares the plugin files for release.

After installing the Composer dependencies, run the following command from the plugin root directory:

```bash
./vendor/lavamitts/skyrocket-plugin-updater/tools/build-distribution.sh
```

This builds the release candidate, preparing a distributable version of the plugin without requiring the development dependency structure to be included in the release.

The resulting distribution package is intended for installation through the standard WordPress plugin installation process.

## Versioning

Version numbers follow semantic versioning:

- MAJOR: Breaking changes
- MINOR: New functionality
- PATCH: Bug fixes

## Licence

Private project. Not intended for public distribution.
