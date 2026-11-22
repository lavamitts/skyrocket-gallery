<?php
/**
 * Plugin Name: Skyrocket Gallery
 * Description: Displays WordPress media galleries dynamically using FileBird folders.
 * Version: 1.0.0
 * Author: Skyrocket Digital Development
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Provides the `[skyrocket_gallery]` shortcode for displaying images stored in
 * FileBird folders, with masonry or justified layouts and a front-end lightbox.
 *
 * Gallery images are discovered through FileBird's folder and attachment
 * relationship tables, then loaded as WordPress attachment posts. The
 * shortcode can display all folders beneath the top-level `Galleries` folder,
 * or a single named gallery (including its descendant folders).
 *
 * Shortcode examples:
 *
 *     [skyrocket_gallery]
 *     [skyrocket_gallery folder="Summer Events"]
 *     [skyrocket_gallery folder="Summer Events" layout="justified"]
 *     [skyrocket_gallery folder="Summer Events" layout="justified" row_height="240"]
 *
 * Shortcode attributes:
 *
 * - `folder`     Gallery folder name; defaults to `all`.
 * - `layout`     `masonry` or `justified`; defaults to `masonry`.
 * - `row_height` Target row height in pixels for the justified layout;
 *                defaults to 200 and is clamped to a minimum of 50.
 *
 * The plugin expects FileBird to be installed and its standard database tables
 * to be available. If the required folder or relationship table is missing,
 * the gallery will contain no images (or the requested folder will be reported
 * as not found).
 *
 * @since 1.0.0
 */
class skyrocket_gallery
{

    /**
     * Register the gallery shortcode and front-end asset enqueue callback.
     *
     * @return void
     */
    public function __construct()
    {
        add_shortcode('skyrocket_gallery', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Register and enqueue the plugin's inline front-end CSS and JavaScript.
     *
     * The assets are registered with no external file source; their contents
     * are added using `wp_add_inline_style()` and `wp_add_inline_script()`.
     *
     * @return void
     */
    public function enqueue_assets()
    {
        wp_register_style('skyrocket-gallery-css', false);
        wp_enqueue_style('skyrocket-gallery-css');
        wp_add_inline_style('skyrocket-gallery-css', $this->get_inline_css());

        wp_register_script('skyrocket-gallery-js', false, array(), '1.4.0', true);
        wp_enqueue_script('skyrocket-gallery-js');
        wp_add_inline_script('skyrocket-gallery-js', $this->get_inline_js());
    }

    /**
     * Find a FileBird folder by its name, without regard to letter case.
     *
     * Looks in the FileBird folder table (`{$wpdb->prefix}fbv`). This method
     * searches by folder name, not by slug.
     *
     * @param string $folder_name Folder name to find.
     * @return int|false Folder ID, or false if the table/folder is unavailable.
     */
    private function get_filebird_folder_id($folder_name)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fbv';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return false;
        }

        $folder_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE LOWER(name) = LOWER(%s)",
                trim($folder_name)
            )
        );

        return $folder_id ? (int) $folder_id : false;
    }

    /**
     * Return a folder ID and the IDs of all its descendants.
     *
     * The supplied folder is included in the returned array. Descendants are
     * discovered recursively using the FileBird `parent` relationship.
     *
     * @param int $parent_id FileBird folder ID whose tree should be traversed.
     * @return int[] Folder IDs, including the supplied parent ID.
     */
    private function get_child_folder_ids($parent_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fbv';

        $ids = array((int) $parent_id);
        $children = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE parent = %d",
                $parent_id
            )
        );

        foreach ($children as $child_id) {
            $ids = array_merge($ids, $this->get_child_folder_ids($child_id));
        }

        return $ids;
    }

    /**
     * Fetch unique WordPress attachment IDs assigned to the given folders.
     *
     * Uses FileBird's attachment relationship table
     * (`{$wpdb->prefix}fbv_attachment_folder`). Folder IDs are normalised to
     * integers before being used in a prepared SQL query.
     *
     * @param int[] $folder_ids FileBird folder IDs to search.
     * @return int[] Unique attachment IDs; an empty array if none are found
     *               or the relationship table is unavailable.
     */
    private function get_attachment_ids_by_folder_ids($folder_ids)
    {
        global $wpdb;
        $rel_table = $wpdb->prefix . 'fbv_attachment_folder';

        if (empty($folder_ids) || $wpdb->get_var("SHOW TABLES LIKE '{$rel_table}'") !== $rel_table) {
            return array();
        }

        $folder_ids = array_map('intval', (array) $folder_ids);
        $placeholders = implode(',', array_fill(0, count($folder_ids), '%d'));

        $attachment_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT attachment_id FROM {$rel_table} WHERE folder_id IN ({$placeholders})",
                ...$folder_ids
            )
        );

        return array_map('intval', $attachment_ids);
    }

    /**
     * Render the `[skyrocket_gallery]` shortcode.
     *
     * Resolves the requested FileBird folder scope, queries its image
     * attachments in newest-first order, and returns the gallery markup.
     *
     * When `folder` is `all`, images are collected from every descendant of
     * the top-level `Galleries` folder. For a named folder, the method first
     * looks for a direct child of `Galleries`, then falls back to a
     * case-insensitive folder-name lookup anywhere in FileBird. In either
     * case, the selected folder and all its descendants are included.
     *
     * Supported attributes:
     *
     * - `folder`     Folder name or `all` (default: `all`).
     * - `layout`     `masonry` or `justified` (default: `masonry`).
     * - `row_height` Target justified-row height in pixels (default: `200`,
     *                minimum: `50`).
     *
     * @param array|string $atts Shortcode attributes supplied by WordPress.
     * @return string Rendered gallery HTML or an explanatory message.
     */
    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(
            array(
                'folder' => 'all',
                'layout' => 'masonry', // Options: masonry, justified
                'row_height' => '200',     // Target row height in px for justified layout
            ),
            $atts,
            'skyrocket_gallery'
        );

        $folder_attr = strtolower(trim($atts['folder']));
        $layout = strtolower(trim($atts['layout']));
        $row_height = max(50, (int) $atts['row_height']); // Ensure a sensible minimum height.
        $folder_ids = array();

        if ($folder_attr !== 'all') {
            $parent_folder_id = $this->get_filebird_folder_id('galleries');
            $folder_id = false;

            if ($parent_folder_id) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'fbv';
                $folder_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table_name} WHERE LOWER(name) = LOWER(%s) AND parent = %d",
                        $folder_attr,
                        $parent_folder_id
                    )
                );
            }

            if (!$folder_id) {
                $folder_id = $this->get_filebird_folder_id($folder_attr);
            }

            if ($folder_id) {
                $folder_ids = $this->get_child_folder_ids($folder_id);
            } else {
                return '<p class="skyrocket-gallery-error">Gallery folder not found.</p>';
            }
        } else {
            $galleries_id = $this->get_filebird_folder_id('galleries');
            if ($galleries_id) {
                $folder_ids = $this->get_child_folder_ids($galleries_id);
            }
        }

        $attachment_ids = $this->get_attachment_ids_by_folder_ids($folder_ids);

        if (empty($attachment_ids)) {
            return '<p class="skyrocket-gallery-empty">No images found in this gallery.</p>';
        }

        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'post__in' => $attachment_ids,
        );

        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            return '<p class="skyrocket-gallery-empty">No images found in this gallery.</p>';
        }

        ob_start();
        ?>
        <div class="skyrocket-gallery skyrocket-layout-<?php echo esc_attr($layout); ?>"
            style="--row-height: <?php echo esc_attr($row_height); ?>px;">
            <?php
            while ($query->have_posts()):
                $query->the_post();
                $image_id = get_the_ID();
                $thumb_src = wp_get_attachment_image_url($image_id, 'medium_large');
                $full_src = wp_get_attachment_image_url($image_id, 'full');
                $title = get_the_title($image_id);
                $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: $title;
                $meta = wp_get_attachment_metadata($image_id);
                $width = isset($meta['width']) ? $meta['width'] : 1;
                $height = isset($meta['height']) ? $meta['height'] : 1;
                $aspect = $width / $height;
                ?>
                <div class="skyrocket-gallery-item" style="--aspect-ratio: <?php echo esc_attr($aspect); ?>;">
                    <a href="<?php echo esc_url($full_src); ?>" class="skyrocket-lightbox-trigger"
                        data-title="<?php echo esc_attr($title); ?>">
                        <img src="<?php echo esc_url($thumb_src); ?>" alt="<?php echo esc_attr($alt); ?>" loading="lazy" />
                        <span class="skyrocket-gallery-caption"><?php echo esc_html($title); ?></span>
                    </a>
                </div>
            <?php endwhile;
            wp_reset_postdata(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Build the inline CSS used by the gallery and lightbox.
     *
     * Defines the responsive masonry and justified layouts, image captions,
     * thumbnail presentation, and the full-screen lightbox interface.
     *
     * @return string CSS stylesheet contents.
     */
    private function get_inline_css()
    {
        return "
        .skyrocket-gallery {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 20px 0;
        }

        .skyrocket-gallery-item a {
            position: relative;
            display: block;
            overflow: hidden;
            border-radius: 4px;
            width: 100%;
            height: 100%;
        }

        /* Hover caption overlay */
        .skyrocket-gallery-caption {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            color: #ffffff;
            padding: 8px 12px;
            font-size: 13px;
            line-height: 1.3;
            text-align: center;
            opacity: 0;
            transform: translateY(100%);
            transition: opacity 0.25s ease, transform 0.25s ease;
            pointer-events: none;
            box-sizing: border-box;
        }

        .skyrocket-gallery-item a:hover .skyrocket-gallery-caption,
        .skyrocket-gallery-item a:focus .skyrocket-gallery-caption {
            opacity: 1;
            transform: translateY(0);
        }

        /* Masonry layout */
        .skyrocket-layout-masonry {
            display: block;
            column-count: 3;
            column-gap: 12px;
        }
        @media (max-width: 768px) {
            .skyrocket-layout-masonry { column-count: 2; }
        }
        @media (max-width: 480px) {
            .skyrocket-layout-masonry { column-count: 1; }
        }
        .skyrocket-layout-masonry .skyrocket-gallery-item {
            break-inside: avoid;
            margin-bottom: 12px;
        }
        .skyrocket-layout-masonry img {
            width: 100%;
            height: auto;
            display: block;
            border-radius: 4px;
        }

        /* Justified flexbox layout */
        .skyrocket-layout-justified {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .skyrocket-layout-justified .skyrocket-gallery-item {
            flex-grow: calc(var(--aspect-ratio) * 100);
            flex-basis: calc(var(--row-height, 200px) * var(--aspect-ratio));
            height: var(--row-height, 200px);
            overflow: hidden;
            border-radius: 4px;
        }
        .skyrocket-layout-justified img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Prevent last row stretch when unfulfilled */
        .skyrocket-layout-justified::after {
            content: '';
            flex-grow: 999999999;
        }

        /* Lightbox styling */
        .skyrocket-lightbox {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.75);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .skyrocket-lightbox.is-active {
            opacity: 1;
            visibility: visible;
        }
        .skyrocket-lightbox-content {
            position: relative;
            max-width: 90vw;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .skyrocket-lightbox-content img {
            max-width: 100%;
            max-height: 80vh;
            object-fit: contain;
            border-radius: 4px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        }
        .skyrocket-lightbox-download {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 40px;
            height: 40px;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, transform 0.2s ease;
            z-index: 10;
        }
        .skyrocket-lightbox-download:hover {
            background: rgba(0, 0, 0, 0.8);
            transform: scale(1.05);
        }
        .skyrocket-lightbox-download svg {
            width: 22px;
            height: 22px;
            fill: none;
            stroke: #ffffff;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .skyrocket-lightbox-caption {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.65);
            color: #ffffff;
            padding: 10px 16px;
            text-align: center;
            font-size: 14px;
            border-bottom-left-radius: 4px;
            border-bottom-right-radius: 4px;
        }
        .skyrocket-lightbox-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            border: none;
            font-size: 28px;
            padding: 12px 16px;
            cursor: pointer;
            border-radius: 50%;
            line-height: 1;
            transition: background 0.2s;
            user-select: none;
        }
        .skyrocket-lightbox-btn:hover {
            background: rgba(255, 255, 255, 0.4);
        }
        .skyrocket-lightbox-prev { left: -60px; }
        .skyrocket-lightbox-next { right: -60px; }

        @media (max-width: 600px) {
            .skyrocket-lightbox-prev { left: 10px; }
            .skyrocket-lightbox-next { right: 10px; }
        }
        ";
    }

    /**
     * Build the inline JavaScript that controls the gallery lightbox.
     *
     * The script creates one lightbox element on DOM ready and uses delegated
     * click handling so that every rendered gallery can open it. Navigation
     * wraps around within the currently opened gallery. Escape closes the
     * lightbox; the left and right arrow keys navigate between images.
     *
     * @return string JavaScript source.
     */
    private function get_inline_js()
    {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            let lightbox = document.createElement('div');
            lightbox.className = 'skyrocket-lightbox';
            lightbox.innerHTML = `
                <div class=\"skyrocket-lightbox-content\">
                    <a class=\"skyrocket-lightbox-download\" href=\"#\" download target=\"_blank\" title=\"Download full size image\">
                        <svg viewBox=\"0 0 24 24\">
                            <path d=\"M12 3v12m0 0l-5-5m5 5l5-5\"></path>
                            <path d=\"M20 16.5c1.5 0 2.5 1.2 2.5 2.7 0 1.5-1.2 2.8-2.7 2.8H4.2C2.7 22 1.5 20.7 1.5 19.2c0-1.5 1-2.7 2.5-2.7\"></path>
                        </svg>
                    </a>
                    <button class=\"skyrocket-lightbox-btn skyrocket-lightbox-prev\">&lsaquo;</button>
                    <img src=\"\" alt=\"\" />
                    <div class=\"skyrocket-lightbox-caption\"></div>
                    <button class=\"skyrocket-lightbox-btn skyrocket-lightbox-next\">&rsaquo;</button>
                </div>
            `;
            document.body.appendChild(lightbox);

            const imgTag = lightbox.querySelector('img');
            const captionTag = lightbox.querySelector('.skyrocket-lightbox-caption');
            const downloadBtn = lightbox.querySelector('.skyrocket-lightbox-download');
            const prevBtn = lightbox.querySelector('.skyrocket-lightbox-prev');
            const nextBtn = lightbox.querySelector('.skyrocket-lightbox-next');

            let currentGallery = [];
            let currentIndex = 0;

            document.addEventListener('click', function(e) {
                const trigger = e.target.closest('.skyrocket-lightbox-trigger');
                if (trigger) {
                    e.preventDefault();
                    const galleryContainer = trigger.closest('.skyrocket-gallery');
                    currentGallery = Array.from(galleryContainer.querySelectorAll('.skyrocket-lightbox-trigger'));
                    currentIndex = currentGallery.indexOf(trigger);

                    updateLightbox();
                    lightbox.classList.add('is-active');
                }
            });

            lightbox.addEventListener('click', function(e) {
                if (e.target === lightbox || e.target.classList.contains('skyrocket-lightbox-content')) {
                    lightbox.classList.remove('is-active');
                }
            });

            function updateLightbox() {
                if (!currentGallery.length) return;
                const item = currentGallery[currentIndex];
                const fullUrl = item.href;
                imgTag.src = fullUrl;
                downloadBtn.href = fullUrl;
                captionTag.textContent = item.getAttribute('data-title') || '';
            }

            prevBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                currentIndex = (currentIndex - 1 + currentGallery.length) % currentGallery.length;
                updateLightbox();
            });

            nextBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                currentIndex = (currentIndex + 1) % currentGallery.length;
                updateLightbox();
            });

            document.addEventListener('keydown', function(e) {
                if (!lightbox.classList.contains('is-active')) return;
                if (e.key === 'Escape') lightbox.classList.remove('is-active');
                if (e.key === 'ArrowLeft') prevBtn.click();
                if (e.key === 'ArrowRight') nextBtn.click();
            });
        });
        ";
    }
}

/**
 * Instantiate the plugin and register its WordPress hooks.
 *
 * @var skyrocket_gallery
 */
new skyrocket_gallery();