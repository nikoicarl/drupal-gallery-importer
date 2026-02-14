/**
 * Plugin Name: Drupal Gallery Importer
 * Description: Import Drupal galleries from JSON into a custom post type, with background processing, batched imports, live progress, and multiple front-end displays including tiles with lightbox. Handles large gallery deletions without timeouts.
 * Version: 2.4.2
 * Author: Carl N.
 * License: GPL v2 or later
 * Text Domain: drupal-gallery-importer
 */

if (!defined('ABSPATH')) exit;

/**
 * =========================
 * Constants
 * =========================
 */
define('DGI_META_NID', 'dgi_drupal_nid');
define('DGI_META_DRUPAL_LINK', 'dgi_drupal_link');
define('DGI_META_GALLERY_IMAGES', '_gallery_images');

define('DGI_BATCH_SIZE', 5); // Reduced for stability
define('DGI_IMAGE_BATCH_SIZE', 3); // Process images in smaller batches
define('DGI_MAX_SECONDS', 15); // Reduced for safety
define('DGI_CRON_HOOK', 'dgi_run_batch');
define('DGI_UPLOAD_DIR', 'dgi-imports');
define('DGI_FILE_SIZE_FALLBACK', 3 * 1024 * 1024); // 3MB threshold
define('DGI_DEFAULT_COLUMNS', 3);
define('DGI_DOWNLOAD_TIMEOUT', 45); // Image download timeout

add_action(DGI_CRON_HOOK, 'dgi_run_batch_handler', 10, 1);

/**
 * =========================
 * CPT & Taxonomy Registration
 * =========================
 */
class DGI_Post_Types {
    private $post_type = 'gallery';
    private $taxonomy = 'gallery_type';

    public function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomy'));
    }

    public function register_post_type() {
        $labels = array(
            'name'               => __('Galleries', 'drupal-gallery-importer'),
            'singular_name'      => __('Gallery', 'drupal-gallery-importer'),
            'add_new'            => __('Add New', 'drupal-gallery-importer'),
            'add_new_item'       => __('Add New Gallery', 'drupal-gallery-importer'),
            'edit_item'          => __('Edit Gallery', 'drupal-gallery-importer'),
            'new_item'           => __('New Gallery', 'drupal-gallery-importer'),
            'view_item'          => __('View Gallery', 'drupal-gallery-importer'),
            'search_items'       => __('Search Galleries', 'drupal-gallery-importer'),
            'not_found'          => __('No galleries found', 'drupal-gallery-importer'),
            'menu_name'          => __('Galleries', 'drupal-gallery-importer'),
        );

        $args = array(
            'labels'         => $labels,
            'public'         => true,
            'show_ui'        => true,
            'show_in_menu'   => true,
            'query_var'      => true,
            'rewrite'        => array('slug' => 'galleries'),
            'capability_type'=> 'post',
            'has_archive'    => true,
            'hierarchical'   => false,
            'menu_position'  => 5,
            'menu_icon'      => 'dashicons-format-gallery',
            'supports'       => array('title', 'editor', 'thumbnail', 'excerpt'),
            'show_in_rest'   => true,
        );

        register_post_type($this->post_type, $args);
    }

    public function register_taxonomy() {
        $labels = array(
            'name'              => __('Gallery Types', 'drupal-gallery-importer'),
            'singular_name'     => __('Gallery Type', 'drupal-gallery-importer'),
            'search_items'      => __('Search Gallery Types', 'drupal-gallery-importer'),
            'all_items'         => __('All Gallery Types', 'drupal-gallery-importer'),
            'menu_name'         => __('Gallery Types', 'drupal-gallery-importer'),
        );

        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array('slug' => 'gallery-type'),
            'show_in_rest'      => true,
        );

        register_taxonomy($this->taxonomy, array($this->post_type), $args);
    }
}
new DGI_Post_Types();

/**
 * =========================
 * Asset Loading Helper
 * =========================
 */
function dgi_should_enqueue_gallery_assets() {
    $force = apply_filters('dgi_force_assets', false);
    if ($force) return true;

    if (is_singular('gallery')) return true;

    global $post;
    if ($post instanceof WP_Post) {
        return has_shortcode($post->post_content, 'gallery_display')
            || has_shortcode($post->post_content, 'gallery_index_lightbox')
            || has_shortcode($post->post_content, 'gallery_archive')
            || has_shortcode($post->post_content, 'gallery_index');
    }
    return false;
}

/**
 * =========================
 * Gallery Display Shortcode (single gallery by ID)
 * =========================
 */
add_shortcode('gallery_display', 'dgi_gallery_display_shortcode');
function dgi_gallery_display_shortcode($atts) {
    $atts = shortcode_atts(array(
        'id'       => get_the_ID(),
        'size'     => 'medium',
        'columns'  => DGI_DEFAULT_COLUMNS,
        'lightbox' => 'yes',
    ), $atts);

    $post_id = (int) $atts['id'];
    if (!$post_id) {
        return '<p class="nu-gallery-empty">No gallery images found.</p>';
    }

    $gallery_images = get_post_meta($post_id, DGI_META_GALLERY_IMAGES, true);
    if (empty($gallery_images) || !is_array($gallery_images)) {
        return '<p class="nu-gallery-empty">No gallery images found.</p>';
    }

    $use_lightbox = ($atts['lightbox'] === 'yes');
    $columns      = max(1, min(6, (int)$atts['columns']));
    $size         = sanitize_text_field($atts['size']);

    ob_start(); ?>
    <div class="nu-gallery-grid nu-gallery-cols-<?php echo esc_attr($columns); ?>">
        <?php foreach ($gallery_images as $attachment_id): 
            $attachment_id = (int) $attachment_id;
            if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') continue;
            
            $full_url   = wp_get_attachment_image_url($attachment_id, 'full');
            $image_html = wp_get_attachment_image($attachment_id, $size, false, array('loading' => 'lazy'));
            $caption    = wp_get_attachment_caption($attachment_id);
        ?>
            <div class="nu-gallery-item">
                <?php if ($use_lightbox && $full_url): ?>
                    <a href="<?php echo esc_url($full_url); ?>"
                       class="nu-gallery-link"
                       data-lightbox="gallery-<?php echo (int)$post_id; ?>"
                       <?php if ($caption): ?>data-title="<?php echo esc_attr($caption); ?>"<?php endif; ?>>
                        <?php echo $image_html; ?>
                    </a>
                <?php else: ?>
                    <?php echo $image_html; ?>
                <?php endif; ?>

                <?php if ($caption): ?>
                    <div class="nu-gallery-caption"><?php echo esc_html($caption); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * =========================
 * Auto-append Gallery to Content (single gallery page)
 * =========================
 */
add_filter('the_content', 'dgi_append_gallery_to_content');
function dgi_append_gallery_to_content($content) {
    if (!is_singular('gallery') || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    $post_id = get_the_ID();
    $gallery_images = get_post_meta($post_id, DGI_META_GALLERY_IMAGES, true);
    if (empty($gallery_images) || !is_array($gallery_images)) {
        return $content;
    }

    $columns = apply_filters('dgi_gallery_columns', 4);
    $columns = max(1, min(6, (int)$columns));

    $html = '<div class="nu-gallery-wrapper-single nu-gallery-cols-' . $columns . '">';

    foreach ($gallery_images as $attachment_id) {
        $attachment_id = (int) $attachment_id;
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') continue;

        $full_url  = wp_get_attachment_image_url($attachment_id, 'full');
        $img_html  = wp_get_attachment_image(
            $attachment_id,
            'medium',
            false,
            array(
                'loading' => 'lazy',
                'class'   => 'nu-gallery-image',
            )
        );
        $caption   = wp_get_attachment_caption($attachment_id);

        $html .= '<div class="nu-gallery-item-single">';

        if ($full_url) {
            $html .= '<a href="' . esc_url($full_url) . '" target="_blank" rel="noopener" class="nu-gallery-link-single">';
            $html .= $img_html;
            $html .= '</a>';
        } else {
            $html .= $img_html;
        }

        if ($caption) {
            $html .= '<div class="nu-gallery-caption-single">' . esc_html($caption) . '</div>';
        }

        $html .= '</div>';
    }

    $html .= '</div>';

    return $content . $html;
}

/**
 * =========================
 * Tiles Index → native page navigation: [gallery_index_lightbox]
 * =========================
 */
add_shortcode('gallery_index_lightbox', function($atts){
    $atts = shortcode_atts([
        'per_page'    => 24,
        'columns'     => 4,
        'size'        => 'large',
        'show_titles' => 'yes',
        'paged'       => 1,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'new_tab'     => 'no',
    ], $atts, 'gallery_index_lightbox');

    $paged = max(1, (int)$atts['paged']);
    if (get_query_var('paged')) {
        $paged = (int) get_query_var('paged');
    } elseif (get_query_var('page')) {
        $paged = (int) get_query_var('page');
    }

    $q = new WP_Query([
        'post_type'      => 'gallery',
        'post_status'    => 'publish',
        'posts_per_page' => (int)$atts['per_page'],
        'paged'          => $paged,
        'orderby'        => sanitize_key($atts['orderby']),
        'order'          => (strtoupper($atts['order']) === 'ASC') ? 'ASC' : 'DESC',
        'no_found_rows'  => false,
    ]);

    if (!$q->have_posts()) {
        return '<p class="nu-gallery-empty">No galleries found.</p>';
    }

    $cols        = max(1, min(6, (int)$atts['columns']));
    $cover_size  = sanitize_text_field($atts['size']);
    $show_titles = ($atts['show_titles'] === 'yes');
    $new_tab     = ($atts['new_tab'] === 'yes');

    ob_start();
    ?>
    <style>
      .nu-gallery-index { display:grid; gap:20px; grid-template-columns: repeat(<?php echo (int)$cols; ?>, 1fr); }
      @media (max-width: 1024px) { .nu-gallery-index { grid-template-columns: repeat(<?php echo max(2, (int)$cols - 1); ?>, 1fr); } }
      @media (max-width: 768px)  { .nu-gallery-index { grid-template-columns: repeat(2, 1fr); } }
      @media (max-width: 480px)  { .nu-gallery-index { grid-template-columns: 1fr; } }

      .nu-gallery-index-item {
        display:block; background:#f7f7f7; border-radius:none; overflow:hidden; text-decoration:none; color:inherit;
        box-shadow:0 2px 8px rgba(0,0,0,.08); transition:transform .2s, box-shadow .2s; position:relative;
      }
      .nu-gallery-index-item:hover { transform: translateY(-2px); box-shadow:0 4px 16px rgba(0,0,0,.12); }
      .nu-gallery-index-thumb img { width:100%; height:220px; object-fit:cover; display:block; }
      .nu-gallery-index-title { padding:10px; text-align:center; font-weight:600; background:#fff; }
    </style>

    <div class="nu-gallery-index">
    <?php
    while ($q->have_posts()) {
        $q->the_post();
        $post_id   = get_the_ID();
        $permalink = get_permalink($post_id);
        $target    = $new_tab ? ' target="_blank" rel="noopener"' : '';

        $cover_html = get_the_post_thumbnail($post_id, $cover_size, ['loading' => 'lazy']);
        if (!$cover_html) {
            $images = get_post_meta($post_id, DGI_META_GALLERY_IMAGES, true);
            if (is_array($images) && !empty($images)) {
                $first = (int) $images[0];
                if ($first && get_post_type($first) === 'attachment') {
                    $cover_html = wp_get_attachment_image($first, $cover_size, false, ['loading' => 'lazy']);
                }
            }
        }

        if (!$cover_html) continue;
        ?>
        <a class="nu-gallery-index-item" href="<?php echo esc_url($permalink); ?>"<?php echo $target; ?>>
            <div class="nu-gallery-index-thumb"><?php echo $cover_html; ?></div>
            <?php if ($show_titles): ?>
                <div class="nu-gallery-index-title"><?php echo esc_html(get_the_title()); ?></div>
            <?php endif; ?>
        </a>
        <?php
    }
    ?>
    </div>
    <?php

    $big = 999999999;
    $pagination = paginate_links([
        'base'      => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
        'format'    => '?paged=%#%',
        'current'   => max(1, $paged),
        'total'     => (int)$q->max_num_pages,
        'type'      => 'list',
    ]);
    if ($pagination) {
        echo '<nav class="nu-gallery-pagination">' . $pagination . '</nav>';
    }

    wp_reset_postdata();

    return ob_get_clean();
});

/**
 * =========================
 * Gallery Styles (front-end)
 * =========================
 */
add_action('wp_head', function () {
    if (!dgi_should_enqueue_gallery_assets()) return;
    ?>
    <style>
      .nu-gallery-wrapper {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
        margin: 30px 0;
      }
      .nu-gallery-item {
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: none;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0,0,0,.06);
        transition: transform .2s ease, box-shadow .2s ease;
      }
      .nu-gallery-item:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.12); }
      .nu-gallery-item a { display:block; line-height: 0; }
      .nu-gallery-item img {
        width: 100%;
        height: 220px;
        object-fit: cover;
        display: block;
        transition: transform .25s ease;
      }
      .nu-gallery-item:hover img { transform: scale(1.04); }
      .nu-gallery-caption {
        padding: 10px 12px;
        font-size: 13px;
        color: #444;
        background: #fafafa;
        border-top: 1px solid #eee;
        text-align: center;
      }

      .nu-gallery-wrapper-single {
        display: grid;
        gap: 20px;
        margin: 30px 0;
      }
      
      .nu-gallery-wrapper-single.nu-gallery-cols-1 { grid-template-columns: repeat(1, 1fr); }
      .nu-gallery-wrapper-single.nu-gallery-cols-2 { grid-template-columns: repeat(2, 1fr); }
      .nu-gallery-wrapper-single.nu-gallery-cols-3 { grid-template-columns: repeat(3, 1fr); }
      .nu-gallery-wrapper-single.nu-gallery-cols-4 { grid-template-columns: repeat(4, 1fr); }
      .nu-gallery-wrapper-single.nu-gallery-cols-5 { grid-template-columns: repeat(5, 1fr); }
      .nu-gallery-wrapper-single.nu-gallery-cols-6 { grid-template-columns: repeat(6, 1fr); }
      
      .nu-gallery-item-single {
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: none;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0,0,0,.06);
        transition: transform .2s ease, box-shadow .2s ease;
      }
      .nu-gallery-item-single:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0,0,0,.12);
      }
      .nu-gallery-link-single {
        display: block;
        line-height: 0;
        position: relative;
        overflow: hidden;
      }
      .nu-gallery-link-single::after {
        content: '↗';
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(0, 0, 0, 0.7);
        color: #fff;
        width: 32px;
        height: 32px;
        border-radius: none;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        opacity: 0;
        transition: opacity .2s ease;
      }
      .nu-gallery-item-single:hover .nu-gallery-link-single::after {
        opacity: 1;
      }
      .nu-gallery-image {
        width: 100%;
        height: 250px;
        object-fit: cover;
        display: block;
        transition: transform .3s ease;
      }
      .nu-gallery-item-single:hover .nu-gallery-image {
        transform: scale(1.05);
      }
      .nu-gallery-caption-single {
        padding: 12px 15px;
        font-size: 14px;
        color: #444;
        background: #fafafa;
        border-top: 1px solid #eee;
        text-align: center;
        line-height: 1.4;
      }

      @media (max-width: 1200px) {
        .nu-gallery-wrapper-single.nu-gallery-cols-6 { grid-template-columns: repeat(4, 1fr); }
        .nu-gallery-wrapper-single.nu-gallery-cols-5 { grid-template-columns: repeat(4, 1fr); }
      }
      
      @media (max-width: 900px) {
        .nu-gallery-wrapper-single.nu-gallery-cols-6,
        .nu-gallery-wrapper-single.nu-gallery-cols-5,
        .nu-gallery-wrapper-single.nu-gallery-cols-4 { grid-template-columns: repeat(3, 1fr); }
      }
      
      @media (max-width: 768px) {
        .nu-gallery-wrapper {
          grid-template-columns: repeat(2, 1fr);
          gap: 12px;
        }
        .nu-gallery-wrapper-single.nu-gallery-cols-6,
        .nu-gallery-wrapper-single.nu-gallery-cols-5,
        .nu-gallery-wrapper-single.nu-gallery-cols-4,
        .nu-gallery-wrapper-single.nu-gallery-cols-3 { 
          grid-template-columns: repeat(2, 1fr); 
        }
        .nu-gallery-item img,
        .nu-gallery-image {
          height: 180px;
        }
      }
      
      @media (max-width: 480px) {
        .nu-gallery-wrapper,
        .nu-gallery-wrapper-single { 
          grid-template-columns: 1fr; 
        }
        .nu-gallery-item img,
        .nu-gallery-image {
          height: 220px;
        }
      }
    </style>
    <?php
});

/**
 * =========================
 * Simple Lightbox Script (front-end)
 * =========================
 */
add_action('wp_footer', 'dgi_gallery_lightbox_script');
function dgi_gallery_lightbox_script() {
    if (!dgi_should_enqueue_gallery_assets()) return;
    ?>
    <script>
    (function(){
        var lightbox = document.createElement('div');
        lightbox.className = 'nu-gallery-lightbox';
        lightbox.setAttribute('role', 'dialog');
        lightbox.setAttribute('aria-modal', 'true');
        lightbox.innerHTML =
            '<button class="nu-gallery-lightbox-close" aria-label="Close">&times;</button>' +
            '<button class="nu-gallery-lightbox-prev" aria-label="Previous">&#10094;</button>' +
            '<div class="nu-gallery-lightbox-inner">' +
              '<img src="" alt="">' +
              '<div class="nu-gallery-lightbox-caption" aria-live="polite"></div>' +
            '</div>' +
            '<button class="nu-gallery-lightbox-next" aria-label="Next">&#10095;</button>';

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function(){ document.body.appendChild(lightbox); init(); });
        } else {
            document.body.appendChild(lightbox); init();
        }

        var imgEl, closeBtn, prevBtn, nextBtn, captionEl;
        var activeGroupId = null;
        var activeGroup = [];
        var activeIndex = 0;
        var activeTrigger = null;

        function init(){
            imgEl = lightbox.querySelector('img');
            closeBtn = lightbox.querySelector('.nu-gallery-lightbox-close');
            prevBtn = lightbox.querySelector('.nu-gallery-lightbox-prev');
            nextBtn = lightbox.querySelector('.nu-gallery-lightbox-next');
            captionEl = lightbox.querySelector('.nu-gallery-lightbox-caption');

            closeBtn.addEventListener('click', closeLb);
            prevBtn.addEventListener('click', function(e){ e.stopPropagation(); showIndex(activeIndex - 1); });
            nextBtn.addEventListener('click', function(e){ e.stopPropagation(); showIndex(activeIndex + 1); });

            lightbox.addEventListener('click', function(e){
                if (e.target === lightbox) closeLb();
            });

            document.addEventListener('keydown', function(e){
                if (!lightbox.classList.contains('active')) return;
                if (e.key === 'Escape') { closeLb(); }
                else if (e.key === 'ArrowLeft') { showIndex(activeIndex - 1); }
                else if (e.key === 'ArrowRight') { showIndex(activeIndex + 1); }
            });

            document.addEventListener('click', function(e){
                var link = e.target && e.target.closest('.nu-gallery-link[data-lightbox]');
                if (!link) return;

                e.preventDefault();
                var groupAttr = link.getAttribute('data-lightbox');
                var groupAnchors = document.querySelectorAll('.nu-gallery-link[data-lightbox="' + groupAttr + '"]');
                openGroupFromAnchors(groupAttr, groupAnchors, link);
            });
        }

        function collectFromAnchors(nodeList) {
            var items = [];
            nodeList.forEach(function(a){
                var href = a.getAttribute('href');
                if (!href) return;
                var img = a.querySelector('img');
                var alt = img ? img.getAttribute('alt') || '' : '';
                var title = a.getAttribute('data-title') || alt || '';
                items.push({ href: href, alt: alt, title: title });
            });
            return items;
        }

        function openGroupFromAnchors(groupId, anchors, triggerEl) {
            activeGroupId = groupId;
            activeGroup = collectFromAnchors(anchors);
            if (!activeGroup.length) return;

            var startIdx = 0;
            if (triggerEl) {
                var href = triggerEl.getAttribute('href');
                for (var i=0; i<activeGroup.length; i++){
                    if (activeGroup[i].href === href) { startIdx = i; break; }
                }
            }
            openLb(startIdx, triggerEl);
        }

        function openLb(startIndex, triggerEl) {
            activeTrigger = triggerEl || null;
            lightbox.classList.add('active');
            document.body.style.overflow = 'hidden';
            showIndex(startIndex);
            closeBtn.focus();
        }

        function closeLb() {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
            imgEl.removeAttribute('src');
            captionEl.textContent = '';
            if (activeTrigger && activeTrigger.focus) { try { activeTrigger.focus(); } catch(e){} }
            activeTrigger = null;
            activeGroupId = null;
            activeGroup = [];
            activeIndex = 0;
        }

        function showIndex(i) {
            if (!activeGroup.length) return;
            if (i < 0) i = activeGroup.length - 1;
            if (i >= activeGroup.length) i = 0;
            activeIndex = i;

            var item = activeGroup[activeIndex];
            imgEl.src = item.href;
            imgEl.alt = item.alt || '';
            captionEl.textContent = item.title || '';

            var multi = activeGroup.length > 1;
            prevBtn.style.display = multi ? 'block' : 'none';
            nextBtn.style.display = multi ? 'block' : 'none';
        }

        window.dgiOpenLightboxGroup = function(groupId, startIndex, triggerEl){
            var anchors = document.querySelectorAll('.nu-gallery-link[data-lightbox="' + groupId + '"]');
            if (!anchors.length) return;
            openGroupFromAnchors(groupId, anchors, triggerEl || null);

            if (typeof startIndex === 'number' && !isNaN(startIndex)) {
                showIndex(startIndex);
            }
        };
    })();
    </script>
    <?php
}

/**
 * =========================
 * Admin Interface Menus
 * =========================
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=gallery',
        __('Import Galleries', 'drupal-gallery-importer'),
        __('Import', 'drupal-gallery-importer'),
        'manage_options',
        'dgi-import',
        'dgi_admin_page'
    );
    add_submenu_page(
        'edit.php?post_type=gallery',
        __('Import Jobs', 'drupal-gallery-importer'),
        __('Import Jobs', 'drupal-gallery-importer'),
        'manage_options',
        'dgi-jobs',
        'dgi_jobs_page'
    );
});

/**
 * =========================
 * Completion Notice
 * =========================
 */
add_action('admin_notices', function () {
    if (!is_admin() || !current_user_can('manage_options')) return;
    
    $key = 'dgi_done_' . get_current_user_id();
    $msg = get_transient($key);
    if ($msg) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        delete_transient($key);
    }
    
    $delete_key = 'dgi_delete_notice_' . get_current_user_id();
    $delete_msg = get_transient($delete_key);
    if ($delete_msg) {
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($delete_msg) . '</p></div>';
        delete_transient($delete_key);
    }

    $error_key = 'dgi_error_' . get_current_user_id();
    $error_msg = get_transient($error_key);
    if ($error_msg) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_msg) . '</p></div>';
        delete_transient($error_key);
    }
});

/**
 * =========================
 * Jobs Registry
 * =========================
 */
function dgi_jobs_get() {
    $jobs = get_option('dgi_jobs', []);
    return is_array($jobs) ? $jobs : [];
}

function dgi_jobs_save(array $jobs) {
    update_option('dgi_jobs', $jobs, false);
}

function dgi_jobs_register($job) {
    $jobs = dgi_jobs_get();
    $jobs[$job['job_id']] = [
        'job_id'     => $job['job_id'],
        'file_path'  => $job['file_path'],
        'status'     => $job['status'],
        'queued_by'  => (int)($job['queued_by'] ?? 0),
        'created_at' => (int)$job['created_at'],
        'last_seen'  => time(),
    ];
    dgi_jobs_save($jobs);
}

function dgi_jobs_update_status($job_id, $status) {
    $jobs = dgi_jobs_get();
    if (isset($jobs[$job_id])) {
        $jobs[$job_id]['status'] = $status;
        $jobs[$job_id]['last_seen'] = time();
        dgi_jobs_save($jobs);
    }
}

function dgi_jobs_gc($keep_days = 7) {
    $ttl = time() - (int)$keep_days * DAY_IN_SECONDS;
    $jobs = dgi_jobs_get();
    $changed = false;
    foreach ($jobs as $id => $meta) {
        $last = (int)($meta['last_seen'] ?? 0);
        if ($last && $last < $ttl) {
            unset($jobs[$id]);
            $changed = true;
        }
    }
    if ($changed) dgi_jobs_save($jobs);
}

/**
 * =========================
 * Helper Functions
 * =========================
 */
function dgi_parse_ini_bytes($val) {
    $val = trim((string)$val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val)-1]);
    $num = (int)$val;
    switch ($last) {
        case 'g': return $num * 1024 * 1024 * 1024;
        case 'm': return $num * 1024 * 1024;
        case 'k': return $num * 1024;
        default:  return (int)$val;
    }
}

function dgi_memory_available_bytes() {
    $mem_limit = ini_get('memory_limit');
    $limit = dgi_parse_ini_bytes($mem_limit);
    if ($limit <= 0) return PHP_INT_MAX;
    $used = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
    $available = $limit - $used;
    return $available > 0 ? $available : 0;
}

function dgi_append_log($job, $lines) {
    if (!$job || empty($job['log_file'])) return;
    $s = is_array($lines) ? implode("\n", $lines) : (string)$lines;
    @file_put_contents($job['log_file'], '[' . date('Y-m-d H:i:s') . "]\n" . $s . "\n\n", FILE_APPEND);
}

function dgi_job_log_path($job_id) {
    $up = wp_upload_dir();
    if (!empty($up['error'])) return '';
    $dir = trailingslashit($up['basedir']) . DGI_UPLOAD_DIR;
    if (!file_exists($dir)) wp_mkdir_p($dir);
    return trailingslashit($dir) . $job_id . '.log';
}

function dgi_job_log_url($job_id) {
    $up = wp_upload_dir();
    if (!empty($up['error'])) return '';
    $url = trailingslashit($up['baseurl']) . DGI_UPLOAD_DIR;
    return trailingslashit($url) . $job_id . '.log';
}

/**
 * =========================
 * AJAX Handlers
 * =========================
 */
add_action('wp_ajax_dgi_control', 'dgi_ajax_control_handler');
function dgi_ajax_control_handler() {
    check_ajax_referer('dgi_status');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error' => 'forbidden'], 403);
    }

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    $action = sanitize_text_field($_POST['control_action'] ?? '');

    if (!$job_id || !$action) {
        wp_send_json_error(['error' => 'missing parameters'], 400);
    }

    $job = get_option('dgi_job_' . $job_id);
    if (!$job) {
        wp_send_json_error(['error' => 'job not found'], 404);
    }

    switch ($action) {
        case 'pause':
            if ($job['status'] === 'running' || $job['status'] === 'queued') {
                $job['status'] = 'paused';
                update_option('dgi_job_' . $job_id, $job, false);
                dgi_jobs_update_status($job_id, 'paused');
                dgi_append_log($job, 'Job paused by user.');
                wp_send_json_success(['status' => 'paused']);
            } else {
                wp_send_json_error(['error' => 'cannot pause'], 400);
            }
            break;

        case 'resume':
            if ($job['status'] === 'paused') {
                $job['status'] = 'queued';
                update_option('dgi_job_' . $job_id, $job, false);
                dgi_jobs_update_status($job_id, 'queued');
                dgi_append_log($job, 'Job resumed by user.');
                wp_schedule_single_event(time() + 1, DGI_CRON_HOOK, [$job_id]);
                wp_send_json_success(['status' => 'queued']);
            } else {
                wp_send_json_error(['error' => 'cannot resume'], 400);
            }
            break;

        case 'stop':
            if (in_array($job['status'], ['running', 'queued', 'paused'], true)) {
                $job['status'] = 'stopped';
                $job['done'] = true;
                update_option('dgi_job_' . $job_id, $job, false);
                dgi_jobs_update_status($job_id, 'stopped');
                dgi_append_log($job, 'Job stopped by user.');
                wp_send_json_success(['status' => 'stopped']);
            } else {
                wp_send_json_error(['error' => 'cannot stop'], 400);
            }
            break;

        default:
            wp_send_json_error(['error' => 'unknown action'], 400);
    }
}

add_action('wp_ajax_dgi_status', 'dgi_ajax_status_handler');
function dgi_ajax_status_handler() {
    check_ajax_referer('dgi_status');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error'=>'forbidden'], 403);
    }

    $job_id = sanitize_text_field($_GET['job_id'] ?? '');
    if (!$job_id) {
        wp_send_json_error(['error'=>'missing job_id'], 400);
    }

    $job = get_option('dgi_job_' . $job_id);
    if (!$job) {
        wp_send_json_error(['error'=>'not found'], 404);
    }

    $out = [
        'job_id'    => $job['job_id'],
        'status'    => $job['status'],
        'processed' => (int)$job['processed'],
        'total'     => $job['total'],
        'created'   => (int)$job['created'],
        'updated'   => (int)$job['updated'],
        'skipped'   => (int)$job['skipped'],
        'error'     => $job['error'],
        'log_file'  => dgi_job_log_url($job['job_id']),
    ];
    wp_send_json_success($out);
}

add_action('wp_ajax_dgi_poke', 'dgi_ajax_poke_handler');
function dgi_ajax_poke_handler() {
    check_ajax_referer('dgi_status');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error'=>'forbidden'], 403);
    }

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    if (!$job_id) {
        wp_send_json_error(['error'=>'missing job_id'], 400);
    }

    dgi_run_batch_handler($job_id);
    wp_send_json_success(['ok'=>true]);
}

/**
 * =========================
 * AJAX: Check if NID exists (diagnostic)
 * =========================
 */
add_action('wp_ajax_dgi_check_nid', 'dgi_ajax_check_nid_handler');
function dgi_ajax_check_nid_handler() {
    $nonce_action = 'dgi_check_nid';
    check_ajax_referer($nonce_action);

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error' => 'forbidden'], 403);
    }

    $nid = (int)($_POST['nid'] ?? 0);
    if (!$nid) {
        wp_send_json_error(['error' => 'missing NID'], 400);
    }

    $post_id = dgi_get_post_by_nid($nid);
    
    if ($post_id) {
        $post = get_post($post_id);
        wp_send_json_success([
            'exists' => true,
            'post_id' => $post_id,
            'title' => $post ? $post->post_title : '',
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit')
        ]);
    } else {
        wp_send_json_success([
            'exists' => false,
            'nid' => $nid
        ]);
    }
}

/**
 * =========================
 * Admin Pages
 * =========================
 */
function dgi_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');

    $notice = '';
    $job_qs = isset($_GET['dgi_job']) ? sanitize_text_field($_GET['dgi_job']) : '';

    if (!empty($_POST['dgi_action']) && $_POST['dgi_action'] === 'import' && check_admin_referer('dgi_import_nonce')) {
        if (function_exists('set_time_limit')) @set_time_limit(0);

        $uploaded_tmp  = $_FILES['dgi_json_file']['tmp_name'] ?? '';
        $uploaded_size = ($uploaded_tmp && is_readable($uploaded_tmp)) ? (@filesize($uploaded_tmp) ?: 0) : 0;

        if (!$uploaded_tmp || $uploaded_size === 0) {
            $notice = '<div class="notice notice-error"><p>Error: Please upload a JSON file.</p></div>';
        } else {
            $opts = [
                'drupal_url'      => esc_url_raw(trim($_POST['dgi_drupal_url'] ?? '')),
                'skip_existing'   => !empty($_POST['dgi_skip_existing']),
                'download_images' => !empty($_POST['dgi_download_images']),
            ];
            $run_background = !empty($_POST['dgi_run_background']);

            // Always use background for files > 3MB or insufficient memory
            $available      = dgi_memory_available_bytes();
            $estimated_need = $uploaded_size * 4 + 64 * 1024 * 1024;
            $auto_reason    = '';

            if ($uploaded_size >= DGI_FILE_SIZE_FALLBACK) {
                $run_background = true;
                $auto_reason = sprintf('Large file detected (%.2f MB).', $uploaded_size / 1048576);
            } elseif ($estimated_need > 0 && $available > 0 && $estimated_need > $available) {
                $run_background = true;
                $auto_reason = 'Insufficient memory for synchronous import.';
            }

            // ALWAYS use background processing if downloading images
            if ($opts['download_images']) {
                $run_background = true;
                if (!$auto_reason) {
                    $auto_reason = 'Background processing enabled for image downloads.';
                }
            }

            if ($run_background) {
                $file_path = dgi_save_source_file($_FILES['dgi_json_file']);
                if (is_wp_error($file_path)) {
                    $notice = '<div class="notice notice-error"><p>Error: ' . esc_html($file_path->get_error_message()) . '</p></div>';
                } else {
                    $job_id = dgi_queue_job($file_path, $opts);
                    if (is_wp_error($job_id)) {
                        $notice = '<div class="notice notice-error"><p>Error: ' . esc_html($job_id->get_error_message()) . '</p></div>';
                    } else {
                        $view_link = add_query_arg(['page'=>'dgi-import','dgi_job'=>$job_id], admin_url('edit.php?post_type=gallery'));
                        $jobs_link = admin_url('edit.php?post_type=gallery&page=dgi-jobs');
                        $msg = 'Import queued as job `' . esc_html($job_id) . '`.';
                        if ($auto_reason) $msg .= ' ' . esc_html($auto_reason);
                        $msg .= ' <a href="' . esc_url($view_link) . '">View progress</a>';
                        $msg .= ' | <a href="' . esc_url($jobs_link) . '">All jobs</a>';
                        $notice = '<div class="notice notice-success"><p>' . $msg . '</p></div>';
                        $job_qs = $job_id;
                    }
                }
            } else {
                // Synchronous (not recommended for image downloads)
                $raw_json = file_get_contents($uploaded_tmp);
                if (substr($raw_json, 0, 3) === "\xEF\xBB\xBF") $raw_json = substr($raw_json, 3);

                $result = dgi_import_json($raw_json, $opts);
                if (is_wp_error($result)) {
                    $notice = '<div class="notice notice-error"><p>Error: ' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    $notice = '<div class="notice notice-success"><p>Import complete: ' . intval($result['created']) . ' created, ' . intval($result['updated']) . ' updated, ' . intval($result['skipped']) . ' skipped.</p></div>';
                }
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>Import Drupal Galleries</h1>';
    echo $notice;

    echo '<form method="post" enctype="multipart/form-data" style="max-width: 800px;">';
    wp_nonce_field('dgi_import_nonce');
    echo '<input type="hidden" name="dgi_action" value="import">';

    echo '<div class="card" style="margin-bottom: 20px; padding: 15px;">';
    echo '<h2>🔍 Quick Diagnostic</h2>';
    echo '<p>Check if a gallery already exists by NID:</p>';
    echo '<input type="number" id="check_nid" placeholder="Enter NID (e.g., 8821)" style="width: 200px;">';
    echo '<button type="button" class="button" onclick="checkNID()">Check</button>';
    echo '<div id="nid_result" style="margin-top: 10px; padding: 10px; display: none;"></div>';
    echo '</div>';

    echo '<script>
    function checkNID() {
        var nid = document.getElementById("check_nid").value;
        if (!nid) { alert("Please enter a NID"); return; }
        
        var xhr = new XMLHttpRequest();
        xhr.open("POST", ajaxurl, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    var resultDiv = document.getElementById("nid_result");
                    resultDiv.style.display = "block";
                    if (res.success && res.data) {
                        if (res.data.exists) {
                            resultDiv.style.background = "#fffbcc";
                            resultDiv.style.border = "1px solid #e6db55";
                            resultDiv.innerHTML = "✓ Gallery EXISTS<br>Post ID: " + res.data.post_id + "<br>Title: " + res.data.title + "<br><a href=\"" + res.data.edit_url + "\" target=\"_blank\">Edit Gallery</a>";
                        } else {
                            resultDiv.style.background = "#e7f7e7";
                            resultDiv.style.border = "1px solid #4caf50";
                            resultDiv.innerHTML = "✓ Gallery does NOT exist (will be created on import)";
                        }
                    } else {
                        resultDiv.innerHTML = "Error: " + (res.data ? res.data.error : "unknown");
                    }
                } catch(e) {
                    alert("Error checking NID");
                }
            }
        };
        xhr.send("action=dgi_check_nid&nid=" + encodeURIComponent(nid) + "&_ajax_nonce=" + <?php echo json_encode(wp_create_nonce('dgi_check_nid')); ?>);
    }
    </script>';

    echo '<h2>Upload JSON File</h2>';
    echo '<p><input type="file" name="dgi_json_file" accept=".json" required></p>';

    echo '<h2>Import Options</h2>';
    echo '<p><label>Drupal Site URL<br><input type="url" name="dgi_drupal_url" class="regular-text" placeholder="https://example.com" required></label></p>';
    echo '<p><label><input type="checkbox" name="dgi_skip_existing" value="1" checked> Skip galleries that already exist (gallery types will still be created)</label></p>';
    echo '<p><label><input type="checkbox" name="dgi_download_images" value="1" checked> Download images to WordPress media library</label></p>';
    echo '<p><label><input type="checkbox" name="dgi_run_background" value="1" checked> Run in background (recommended)</label></p>';
    echo '<p class="description"><strong>Note:</strong> Background processing is automatically enabled for large files and when downloading images.</p>';
    submit_button('Import Galleries');

    $ajax_nonce = wp_create_nonce('dgi_status');
    $job_for_js = $job_qs ? $job_qs : '';

    echo '<div id="dgi-progress-wrap" style="display:none; margin:20px 0; padding:15px; border:1px solid #ccc; background:#f9f9f9;">';
    echo '<h2>Import Progress</h2>';
    echo '<div id="dgi-progress-inner"></div>';
    echo '<div id="dgi-controls" style="margin-top:15px;"></div>';
    echo '</div>';
    ?>

    <script>
    (function(){
        var currentJob   = <?php echo json_encode($job_for_js); ?>;
        var nonce        = <?php echo json_encode($ajax_nonce); ?>;
        var progressWrap = document.getElementById("dgi-progress-wrap");
        var progressInner= document.getElementById("dgi-progress-inner");
        var controlsDiv  = document.getElementById("dgi-controls");
        var pollTimer, pokeTimer;

        function updateProgress() {
            if (!currentJob) return;
            var xhr = new XMLHttpRequest();
            xhr.open("GET", ajaxurl + "?action=dgi_status&_ajax_nonce=" + encodeURIComponent(nonce) + "&job_id=" + encodeURIComponent(currentJob), true);
            xhr.timeout = 10000;
            xhr.ontimeout = function() { console.log('Status request timeout'); };
            xhr.onerror = function() { console.log('Status request error'); };
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data) renderProgress(res.data);
                    } catch(e) { console.log('Parse error:', e); }
                }
            };
            xhr.send();
        }

        function renderProgress(data) {
            progressWrap.style.display = "block";
            var total   = data.total || "?";
            var percent = (total !== "?" && total > 0) ? Math.round((data.processed / total) * 100) : 0;

            var html = "<p><strong>Job:</strong> " + data.job_id + "</p>";
            html += "<p><strong>Status:</strong> " + data.status + "</p>";
            html += "<p><strong>Processed:</strong> " + data.processed + " / " + total;
            if (total !== "?") html += " (" + percent + "%)";
            html += "</p>";
            html += "<p><strong>Created:</strong> " + data.created + " | <strong>Updated:</strong> " + data.updated + " | <strong>Skipped:</strong> " + data.skipped + "</p>";
            if (data.error) html += "<p style=\"color:red;\"><strong>Error:</strong> " + data.error + "</p>";
            if (data.log_file) html += "<p><a href=\"" + data.log_file + "\" target=\"_blank\">View Log</a></p>";

            progressInner.innerHTML = html;
            renderControls(data.status);

            if (data.status === "complete" || data.status === "failed" || data.status === "stopped") {
                clearInterval(pollTimer);
                clearTimeout(pokeTimer);
            }
        }

        function renderControls(status) {
            var html = "";
            if (status === "running" || status === "queued") {
                html += "<button type=\"button\" class=\"button\" onclick=\"dgiControlJob('pause')\">⏸ Pause</button> ";
                html += "<button type=\"button\" class=\"button\" onclick=\"dgiControlJob('stop')\">⏹ Stop</button>";
            } else if (status === "paused") {
                html += "<button type=\"button\" class=\"button button-primary\" onclick=\"dgiControlJob('resume')\">▶ Resume</button> ";
                html += "<button type=\"button\" class=\"button\" onclick=\"dgiControlJob('stop')\">⏹ Stop</button>";
            }
            controlsDiv.innerHTML = html;
        }

        window.dgiControlJob = function(action) {
            if (!currentJob) return;
            var xhr = new XMLHttpRequest();
            xhr.open("POST", ajaxurl, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.timeout = 10000;
            xhr.ontimeout = function() { alert("Request timeout"); };
            xhr.onerror = function() { alert("Request failed"); };
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) updateProgress();
                        else alert("Control failed: " + (res.data ? res.data.error : "unknown"));
                    } catch(e) { alert("Control failed."); }
                }
            };
            var params = "action=dgi_control&_ajax_nonce=" + encodeURIComponent(nonce) + "&job_id=" + encodeURIComponent(currentJob) + "&control_action=" + encodeURIComponent(action);
            xhr.send(params);
        };

        function pokeBatch() {
            if (!currentJob) return;
            var xhr = new XMLHttpRequest();
            xhr.open("POST", ajaxurl, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.timeout = 30000;
            xhr.ontimeout = function() { 
                console.log('Poke timeout');
                pokeTimer = setTimeout(pokeBatch, 5000);
            };
            xhr.onerror = function() { 
                console.log('Poke error');
                pokeTimer = setTimeout(pokeBatch, 5000);
            };
            xhr.onload = function() {
                updateProgress();
                pokeTimer = setTimeout(pokeBatch, 3000);
            };
            var params = "action=dgi_poke&_ajax_nonce=" + encodeURIComponent(nonce) + "&job_id=" + encodeURIComponent(currentJob);
            xhr.send(params);
        }

        if (currentJob) {
            updateProgress();
            pollTimer = setInterval(updateProgress, 5000);
            pokeTimer = setTimeout(pokeBatch, 1000);
        }
    })();
    </script>

    <?php
    echo '</form></div>';
}

function dgi_jobs_page() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');

    dgi_jobs_gc(7);
    $jobs = dgi_jobs_get();
    uasort($jobs, function($a,$b){
        return ((int)($b['created_at'] ?? 0)) - ((int)($a['created_at'] ?? 0));
    });

    echo '<div class="wrap">';
    echo '<h1>Gallery Import Jobs</h1>';

    if (empty($jobs)) {
        echo '<p>No jobs found.</p></div>';
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Job ID</th><th>Status</th><th>Started</th><th>Actions</th></tr></thead><tbody>';
    foreach ($jobs as $j) {
        $job_id   = esc_html($j['job_id']);
        $status   = esc_html($j['status'] ?? 'unknown');
        $date_label = !empty($j['created_at'])
            ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int)$j['created_at']))
            : '—';
        $view_url = add_query_arg(['page'=>'dgi-import','dgi_job'=>$j['job_id']], admin_url('edit.php?post_type=gallery'));
        $log_url  = dgi_job_log_url($j['job_id']);

        echo '<tr>';
        echo '<td><code>' . $job_id . '</code></td>';
        echo '<td>' . $status . '</td>';
        echo '<td>' . $date_label . '</td>';
        echo '<td>';
        echo '<a href="' . esc_url($view_url) . '" class="button button-small">View</a> ';
        if ($log_url) echo '<a href="' . esc_url($log_url) . '" target="_blank" class="button button-small">Log</a>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * =========================
 * File Management
 * =========================
 */
function dgi_save_source_file($file_field) {
    $up = wp_upload_dir();
    if (!empty($up['error'])) return new WP_Error('upload_dir', $up['error']);

    $dir = trailingslashit($up['basedir']) . DGI_UPLOAD_DIR;
    if (!file_exists($dir)) wp_mkdir_p($dir);
    if (!is_writable($dir)) return new WP_Error('perm', 'Directory not writable');

    if (!empty($file_field['tmp_name'])) {
        $name = 'dgi-' . date('Ymd-His') . '-' . wp_generate_password(8, false) . '.json';
        $dest = trailingslashit($dir) . $name;
        if (!@move_uploaded_file($file_field['tmp_name'], $dest)) {
            return new WP_Error('move_failed', 'Upload failed');
        }
        return $dest;
    }
    return new WP_Error('no_source', 'No file uploaded');
}

/**
 * =========================
 * Queue Job
 * =========================
 */
function dgi_queue_job($file_path, array $opts) {
    $file_path = realpath($file_path);
    if (!$file_path || !file_exists($file_path)) return new WP_Error('nofile', 'File not found');

    $job_id = 'job_' . wp_generate_password(12, false, false);
    $job = [
        'job_id'     => $job_id,
        'created_at' => time(),
        'status'     => 'queued',
        'file_path'  => $file_path,
        'processed'  => 0,
        'total'      => null,
        'created'    => 0,
        'updated'    => 0,
        'skipped'    => 0,
        'error'      => '',
        'opts'       => $opts,
        'log_file'   => dgi_job_log_path($job_id),
        'done'       => false,
        'lock'       => 0,
        'queued_by'  => get_current_user_id() ?: 0,
    ];

    add_option('dgi_job_' . $job_id, $job, '', false);
    dgi_jobs_register($job);
    wp_schedule_single_event(time() + 1, DGI_CRON_HOOK, [$job_id]);

    return $job_id;
}

/**
 * =========================
 * Job Locking
 * =========================
 */
function dgi_job_try_lock(&$job, $ttl = 45) {
    $now = time();
    if (!empty($job['lock']) && ($now - (int)$job['lock']) < $ttl) return false;
    $job['lock'] = $now;
    update_option('dgi_job_' . $job['job_id'], $job, false);
    return true;
}

function dgi_job_unlock(&$job) {
    $job['lock'] = 0;
    update_option('dgi_job_' . $job['job_id'], $job, false);
}

/**
 * =========================
 * Batch Runner
 * =========================
 */
function dgi_run_batch_handler($job_id) {
    $opt_key = 'dgi_job_' . $job_id;
    $job = get_option($opt_key);
    
    if (!$job) return;
    if (in_array($job['status'], ['paused', 'stopped', 'complete', 'failed'], true)) return;
    if (!empty($job['done'])) return;

    if (!dgi_job_try_lock($job, 45)) {
        wp_schedule_single_event(time() + 10, DGI_CRON_HOOK, [$job_id]);
        return;
    }

    // Set time limit and memory
    if (function_exists('set_time_limit')) @set_time_limit(300);
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '512M');
    }

    $started = microtime(true);
    $job['status'] = 'running';
    dgi_jobs_update_status($job_id, 'running');
    update_option($opt_key, $job, false);

    $file = $job['file_path'];
    if (!file_exists($file)) {
        $job['status'] = 'failed';
        $job['error']  = 'Source file missing';
        $job['done'] = true;
        dgi_jobs_update_status($job_id, 'failed');
        dgi_append_log($job, 'Source file missing');
        dgi_job_unlock($job);
        update_option($opt_key, $job, false);
        return;
    }

    // Read JSON
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $job['status'] = 'failed';
        $job['error']  = 'Cannot read file';
        $job['done'] = true;
        dgi_append_log($job, 'Cannot read file');
        dgi_job_unlock($job);
        update_option($opt_key, $job, false);
        return;
    }

    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    $data = @json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        $job['status'] = 'failed';
        $job['error']  = 'Invalid JSON: ' . json_last_error_msg();
        $job['done'] = true;
        dgi_append_log($job, 'JSON decode failed: ' . json_last_error_msg());
        dgi_job_unlock($job);
        update_option($opt_key, $job, false);
        return;
    }

    $items = $data['items'] ?? $data;
    if (!is_array($items)) $items = [$items];

    if (empty($job['total'])) $job['total'] = count($items);

    // Process batch
    $start_idx = (int)$job['processed'];
    $batch = array_slice($items, $start_idx, DGI_BATCH_SIZE);

    if (!empty($batch)) {
        $result = dgi_import_galleries($batch, $job['opts']);
        if (is_wp_error($result)) {
            $job['error'] = $result->get_error_message();
            dgi_append_log($job, 'Import error: ' . $result->get_error_message());
        } else {
            $job['created']   += (int)($result['created'] ?? 0);
            $job['updated']   += (int)($result['updated'] ?? 0);
            $job['skipped']   += (int)($result['skipped'] ?? 0);
            $job['processed'] += count($batch);
            if (!empty($result['logs'])) {
                dgi_append_log($job, $result['logs']);
            }
        }
    }

    $done = $job['processed'] >= $job['total'];
    $elapsed = microtime(true) - $started;

    if ($done || !empty($job['error'])) {
        $job['status'] = !empty($job['error']) ? 'failed' : 'complete';
        $job['done']   = true;
        dgi_jobs_update_status($job_id, $job['status']);
        dgi_job_unlock($job);
        update_option($opt_key, $job, false);

        $queued_by = (int)($job['queued_by'] ?? 0);
        if ($queued_by > 0 && $job['status'] === 'complete') {
            set_transient(
                'dgi_done_' . $queued_by,
                sprintf(
                    'Gallery import complete (job %s): %d created, %d updated, %d skipped.',
                    $job['job_id'],
                    $job['created'],
                    $job['updated'],
                    $job['skipped']
                ),
                HOUR_IN_SECONDS
            );
        }
        return;
    } else {
        dgi_job_unlock($job);
        update_option($opt_key, $job, false);
        $delay = ($elapsed < DGI_MAX_SECONDS) ? 1 : 5;
        wp_schedule_single_event(time() + $delay, DGI_CRON_HOOK, [$job_id]);
    }
}

/**
 * =========================
 * Import Functions
 * =========================
 */
function dgi_import_json($raw_json, $opts) {
    $data = @json_decode($raw_json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_invalid', 'Invalid JSON: ' . json_last_error_msg());
    }
    $items = $data['items'] ?? $data;
    if (!is_array($items)) $items = [$items];
    return dgi_import_galleries($items, $opts);
}

function dgi_import_galleries(array $items, $opts) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $logs = [];

    $drupal_url      = $opts['drupal_url'] ?? '';
    $skip_existing   = $opts['skip_existing'] ?? false;
    $download_images = $opts['download_images'] ?? false;

    // Defer term counting
    wp_defer_term_counting(true);

    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            $skipped++;
            $logs[] = "Item {$idx}: skipped (invalid)";
            continue;
        }

        $nid   = (int)($item['nid'] ?? 0);
        $title = sanitize_text_field($item['title'] ?? '');
        
        // Enhanced logging
        $logs[] = "Processing item {$idx}: NID={$nid}, Title='{$title}'";
        
        if (!$title) {
            $skipped++;
            $logs[] = "Item {$idx}: skipped (no title)";
            continue;
        }

        // Process gallery types first
        $term_ids = [];
        if (!empty($item['gallery_types'])) {
            foreach ($item['gallery_types'] as $type) {
                $term_name = sanitize_text_field($type['name'] ?? '');
                if ($term_name) {
                    $term = term_exists($term_name, 'gallery_type');
                    if (!$term) {
                        $term = wp_insert_term($term_name, 'gallery_type');
                        if (!is_wp_error($term)) {
                            $logs[] = "Created gallery type: {$term_name}";
                        }
                    }
                    if (!is_wp_error($term) && isset($term['term_id'])) {
                        $term_ids[] = (int)$term['term_id'];
                    }
                }
            }
        }

        // Check if exists
        if ($skip_existing && $nid) {
            $existing = dgi_get_post_by_nid($nid);
            if ($existing) {
                $skipped++;
                $logs[] = "Item {$idx} (NID {$nid}): SKIPPED - already exists as post ID {$existing}, gallery types processed";
                continue;
            } else {
                $logs[] = "Item {$idx} (NID {$nid}): Not found in database, will create";
            }
        } else {
            $logs[] = "Item {$idx}: Skip-existing is " . ($skip_existing ? 'ON' : 'OFF') . ", NID={$nid}";
        }

        // Create post
        $post_data = [
            'post_title'   => $title,
            'post_content' => wp_kses_post($item['description'] ?? ''),
            'post_excerpt' => sanitize_text_field($item['summary'] ?? ''),
            'post_status'  => 'publish',
            'post_type'    => 'gallery',
            'post_date'    => !empty($item['publish_date']) ? $item['publish_date'] : current_time('mysql'),
        ];
        
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            $skipped++;
            $logs[] = "Item {$idx}: failed - " . $post_id->get_error_message();
            continue;
        }

        // Save meta
        if ($nid) update_post_meta($post_id, DGI_META_NID, $nid);
        if (!empty($item['link'])) update_post_meta($post_id, DGI_META_DRUPAL_LINK, $item['link']);

        // Assign terms
        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'gallery_type');
        }

        // Download images in batches
        $attachment_ids = [];
        $image_errors   = [];
        
        if ($download_images && !empty($item['images']) && is_array($item['images'])) {
            $image_count = count($item['images']);
            $logs[] = "Post {$post_id}: Starting download of {$image_count} images";
            
            // Process images in smaller batches
            $image_batches = array_chunk($item['images'], DGI_IMAGE_BATCH_SIZE);
            
            foreach ($image_batches as $batch_num => $image_batch) {
                foreach ($image_batch as $img_idx => $image_url) {
                    $full_url = rtrim($drupal_url, '/') . $image_url;
                    
                    $attachment_id = dgi_download_image($full_url, $post_id);
                    if ($attachment_id && !is_wp_error($attachment_id)) {
                        $attachment_ids[] = $attachment_id;
                    } else {
                        $error_msg = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Unknown error';
                        $image_errors[] = $image_url;
                        $logs[] = "Failed: {$image_url} - {$error_msg}";
                    }
                }
                
                // Clear caches between batches
                if ($batch_num < count($image_batches) - 1) {
                    wp_cache_flush();
                }
            }
        }

        if (!empty($attachment_ids)) {
            update_post_meta($post_id, DGI_META_GALLERY_IMAGES, $attachment_ids);
            set_post_thumbnail($post_id, $attachment_ids[0]);
            $logs[] = "Post {$post_id}: Saved " . count($attachment_ids) . " images (" . count($image_errors) . " failed)";
        }

        $created++;
        $logs[] = "Item {$idx}: created post {$post_id}";
        
        // Clear object cache periodically
        if ($created % 5 === 0) {
            wp_cache_flush();
        }
    }

    // Re-enable term counting
    wp_defer_term_counting(false);

    return compact('created', 'updated', 'skipped', 'logs');
}

/**
 * =========================
 * Helper: Find post by NID
 * =========================
 */
function dgi_get_post_by_nid($nid) {
    global $wpdb;
    $sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d LIMIT 1";
    $post_id = $wpdb->get_var($wpdb->prepare($sql, DGI_META_NID, $nid));
    return $post_id ? (int)$post_id : 0;
}

/**
 * =========================
 * Download Image (Optimized)
 * =========================
 */
function dgi_download_image($url, $post_id) {
    // Increase timeout for downloads
    $tmp = download_url($url, DGI_DOWNLOAD_TIMEOUT);
    
    if (is_wp_error($tmp)) {
        return $tmp;
    }

    // Safety check
    if (!file_exists($tmp)) {
        return new WP_Error('download_failed', 'Downloaded file not found');
    }

    $filename = wp_basename(parse_url($url, PHP_URL_PATH));
    if (!$filename) {
        $filename = 'image-' . time() . '.jpg';
    }

    $file_array = [
        'name'     => $filename,
        'tmp_name' => $tmp,
    ];

    // Import into media library
    $attachment_id = media_handle_sideload($file_array, $post_id);

    // Safe cleanup
    if (file_exists($tmp)) {
        @unlink($tmp);
    }

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }
    
    return $attachment_id;
}

/**
 * =========================
 * Gallery Meta Box
 * =========================
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'dgi_gallery_images',
        __('Gallery Images', 'drupal-gallery-importer'),
        'dgi_render_gallery_meta_box',
        'gallery',
        'normal',
        'high'
    );
});

function dgi_render_gallery_meta_box($post) {
    wp_nonce_field('dgi_save_meta', 'dgi_meta_nonce');

    $gallery_images = get_post_meta($post->ID, DGI_META_GALLERY_IMAGES, true);
    if (!is_array($gallery_images)) $gallery_images = [];

    $drupal_link = get_post_meta($post->ID, DGI_META_DRUPAL_LINK, true); 
    ?>
    <div id="dgi-gallery-images-container">
        <p>
            <button type="button" class="button" id="dgi-add-images"><?php _e('Add Images', 'drupal-gallery-importer'); ?></button>
            <?php if ($drupal_link): ?>
                <a href="<?php echo esc_url($drupal_link); ?>" target="_blank" class="button">
                    <?php _e('View Original Drupal Gallery', 'drupal-gallery-importer'); ?>
                </a>
            <?php endif; ?>
        </p>

        <ul id="dgi-gallery-images-list" class="dgi-sortable">
            <?php foreach ($gallery_images as $attachment_id): 
                $attachment_id = (int)$attachment_id;
                if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') continue;
            ?>
                <li data-attachment-id="<?php echo esc_attr($attachment_id); ?>">
                    <?php echo wp_get_attachment_image($attachment_id, 'thumbnail'); ?>
                    <input type="hidden" name="dgi_gallery_images[]" value="<?php echo esc_attr($attachment_id); ?>">
                    <button type="button" class="button dgi-remove-image" aria-label="<?php esc_attr_e('Remove image', 'drupal-gallery-importer'); ?>">&times;</button>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (empty($gallery_images)): ?>
            <p class="description">
                <?php _e('No images in this gallery yet. You can add images using the "Add Images" button above.', 'drupal-gallery-importer'); ?>
            </p>
        <?php endif; ?>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#dgi-gallery-images-list').sortable({ placeholder: 'dgi-sortable-placeholder' });

        $('#dgi-add-images').on('click', function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: '<?php echo esc_js(__('Select Gallery Images', 'drupal-gallery-importer')); ?>',
                button: { text: '<?php echo esc_js(__('Add to Gallery', 'drupal-gallery-importer')); ?>' },
                multiple: true
            });

            frame.on('select', function() {
                var attachments = frame.state().get('selection').toJSON();
                attachments.forEach(function(attachment) {
                    var thumb = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
                    var html  = '<li data-attachment-id="' + attachment.id + '">';
                    html     += '<img src="' + thumb + '" alt="">';
                    html     += '<input type="hidden" name="dgi_gallery_images[]" value="' + attachment.id + '">';
                    html     += '<button type="button" class="button dgi-remove-image" aria-label="<?php echo esc_js(__('Remove image', 'drupal-gallery-importer')); ?>">&times;</button>';
                    html     += '</li>';
                    $('#dgi-gallery-images-list').append(html);
                });
            });

            frame.open();
        });

        $(document).on('click', '.dgi-remove-image', function() {
            $(this).closest('li').remove();
        });
    });
    </script>

    <style>
        #dgi-gallery-images-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            list-style: none;
            margin: 10px 0;
            padding: 0;
        }
        #dgi-gallery-images-list li {
            position: relative;
            width: 150px;
            height: 150px;
            border: 2px solid #ddd;
            border-radius: none;
            background: #fff;
            cursor: move;
            overflow: hidden;
        }
        #dgi-gallery-images-list li img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        #dgi-gallery-images-list li .dgi-remove-image {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 24px;
            height: 24px;
            padding: 0;
            background: rgba(255, 0, 0, 0.85);
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            border-radius: none;
        }
        #dgi-gallery-images-list li .dgi-remove-image:hover {
            background: rgba(220, 0, 0, 0.95);
        }
        .dgi-sortable-placeholder {
            width: 150px;
            height: 150px;
            border: 2px dashed #aaa;
            background: #f0f0f0;
        }
    </style>
<?php
}

/**
 * =========================
 * Save Gallery Images Meta
 * =========================
 */
add_action('save_post_gallery', function($post_id) {
    if (!isset($_POST['dgi_meta_nonce']) || !wp_verify_nonce($_POST['dgi_meta_nonce'], 'dgi_save_meta')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['dgi_gallery_images']) && is_array($_POST['dgi_gallery_images'])) {
        $gallery_images = array_map('intval', $_POST['dgi_gallery_images']);
        $gallery_images = array_values(array_filter($gallery_images));
        update_post_meta($post_id, DGI_META_GALLERY_IMAGES, $gallery_images);
    } else {
        delete_post_meta($post_id, DGI_META_GALLERY_IMAGES);
    }
});

/**
 * =========================
 * Gallery Deletion (Optimized)
 * =========================
 */
add_action('delete_post', 'dgi_cleanup_gallery_on_delete', 10, 2);
function dgi_cleanup_gallery_on_delete($post_id, $post) {
    if (!$post || $post->post_type !== 'gallery') {
        return;
    }

    if (!current_user_can('delete_posts')) {
        return;
    }

    if (defined('DOING_CRON') && DOING_CRON) {
        return;
    }

    $delete_images = get_option('dgi_delete_images', true);
    $delete_terms = get_option('dgi_delete_terms', true);

    if ($delete_images) {
        $gallery_images = get_post_meta($post_id, DGI_META_GALLERY_IMAGES, true);
        
        if (is_array($gallery_images) && !empty($gallery_images)) {
            $total_images = count($gallery_images);
            
            // Always use background for galleries with 30+ images
            if ($total_images > 30) {
                dgi_schedule_background_image_deletion($post_id, $gallery_images);
            } else {
                // Immediate processing for small galleries
                wp_defer_term_counting(true);
                
                foreach ($gallery_images as $attachment_id) {
                    $attachment_id = (int) $attachment_id;
                    
                    if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
                        continue;
                    }
                    
                    if (dgi_is_image_orphan($attachment_id, $post_id)) {
                        wp_delete_attachment($attachment_id, true);
                    }
                }
                
                wp_defer_term_counting(false);
            }
        }
    }

    if ($delete_terms) {
        $terms = wp_get_object_terms($post_id, 'gallery_type', array('fields' => 'ids'));
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term_id) {
                $term_id = (int) $term_id;
                
                if (!$term_id) continue;
                
                $term = get_term($term_id, 'gallery_type');
                if (is_wp_error($term) || !$term) continue;
                
                if (dgi_count_term_usage($term_id, $post_id) === 0) {
                    wp_delete_term($term_id, 'gallery_type');
                }
            }
        }
    }
}

/**
 * Background deletion scheduler
 */
function dgi_schedule_background_image_deletion($post_id, $gallery_images) {
    $job_id = 'delete_' . $post_id . '_' . time();
    
    $job = array(
        'job_id' => $job_id,
        'post_id' => $post_id,
        'images' => $gallery_images,
        'processed' => 0,
        'total' => count($gallery_images),
        'created_at' => time(),
    );
    
    update_option('dgi_delete_job_' . $job_id, $job, false);
    
    add_action('shutdown', function() use ($job_id) {
        wp_schedule_single_event(time(), 'dgi_process_image_deletion', array($job_id));
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }, 999);
    
    $user_id = get_current_user_id();
    if ($user_id) {
        set_transient(
            'dgi_delete_notice_' . $user_id,
            sprintf(
                'Gallery deleted successfully. %d images are being removed in the background.',
                count($gallery_images)
            ),
            300
        );
    }
}

/**
 * Background deletion processor
 */
add_action('dgi_process_image_deletion', 'dgi_process_background_image_deletion');
function dgi_process_background_image_deletion($job_id) {
    $job = get_option('dgi_delete_job_' . $job_id);
    if (!$job) return;
    
    if (function_exists('set_time_limit')) @set_time_limit(300);
    
    $batch_size = 15;
    $start_idx = (int) $job['processed'];
    $batch = array_slice($job['images'], $start_idx, $batch_size);
    
    if (empty($batch)) {
        delete_option('dgi_delete_job_' . $job_id);
        return;
    }
    
    wp_defer_term_counting(true);
    
    foreach ($batch as $attachment_id) {
        $attachment_id = (int) $attachment_id;
        
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            continue;
        }
        
        if (dgi_is_image_orphan($attachment_id, $job['post_id'])) {
            wp_delete_attachment($attachment_id, true);
        }
    }
    
    wp_defer_term_counting(false);
    
    $job['processed'] += count($batch);
    update_option('dgi_delete_job_' . $job_id, $job, false);
    
    wp_cache_flush();
    
    if ($job['processed'] < $job['total']) {
        wp_schedule_single_event(time(), 'dgi_process_image_deletion', array($job_id));
        spawn_cron(time());
    } else {
        delete_option('dgi_delete_job_' . $job_id);
    }
}

/**
 * Check if image is orphaned (optimized with caching)
 */
function dgi_is_image_orphan($attachment_id, $exclude_post_id) {
    global $wpdb;
    
    $attachment_id = (int) $attachment_id;
    $exclude_post_id = (int) $exclude_post_id;
    
    if (!$attachment_id || !$exclude_post_id) {
        return false;
    }
    
    // Cache check
    $cache_key = 'dgi_orphan_' . $attachment_id . '_' . $exclude_post_id;
    $cached = wp_cache_get($cache_key, 'dgi_orphan_checks');
    if ($cached !== false) {
        return (bool) $cached;
    }
    
    // Verify attachment exists
    if (get_post_type($attachment_id) !== 'attachment') {
        return false;
    }
    
    // Check gallery usage
    $sql = $wpdb->prepare(
        "SELECT 1 FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key = %s
         AND p.post_type = 'gallery'
         AND p.ID != %d
         AND p.post_status NOT IN ('trash', 'auto-draft')
         AND pm.meta_value LIKE %s
         LIMIT 1",
        DGI_META_GALLERY_IMAGES,
        $exclude_post_id,
        '%' . $wpdb->esc_like('i:' . $attachment_id . ';') . '%'
    );
    
    if ($wpdb->get_var($sql)) {
        wp_cache_set($cache_key, 0, 'dgi_orphan_checks', 300);
        return false;
    }
    
    // Check featured image usage
    $featured_sql = $wpdb->prepare(
        "SELECT 1 FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key = '_thumbnail_id'
         AND pm.meta_value = %d
         AND p.ID != %d
         AND p.post_status NOT IN ('trash', 'auto-draft')
         LIMIT 1",
        $attachment_id,
        $exclude_post_id
    );
    
    if ($wpdb->get_var($featured_sql)) {
        wp_cache_set($cache_key, 0, 'dgi_orphan_checks', 300);
        return false;
    }
    
    wp_cache_set($cache_key, 1, 'dgi_orphan_checks', 300);
    return true;
}

/**
 * Count term usage (optimized)
 */
function dgi_count_term_usage($term_id, $exclude_post_id) {
    $term_id = (int) $term_id;
    $exclude_post_id = (int) $exclude_post_id;
    
    if (!$term_id || !$exclude_post_id) {
        return 999;
    }
    
    $term = get_term($term_id, 'gallery_type');
    if (is_wp_error($term) || !$term) {
        return 999;
    }
    
    $args = array(
        'post_type'      => 'gallery',
        'post_status'    => array('publish', 'pending', 'draft', 'future', 'private'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'post__not_in'   => array($exclude_post_id),
        'tax_query'      => array(
            array(
                'taxonomy' => 'gallery_type',
                'field'    => 'term_id',
                'terms'    => $term_id,
            ),
        ),
    );
    
    $query = new WP_Query($args);
    return $query->found_posts;
}

/**
 * =========================
 * Settings
 * =========================
 */
add_action('admin_init', 'dgi_register_settings');
function dgi_register_settings() {
    register_setting('dgi_settings', 'dgi_delete_images', array(
        'type'              => 'boolean',
        'default'           => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    
    register_setting('dgi_settings', 'dgi_delete_terms', array(
        'type'              => 'boolean',
        'default'           => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    
    add_settings_section(
        'dgi_deletion_settings',
        __('Gallery Deletion Settings', 'drupal-gallery-importer'),
        'dgi_deletion_settings_callback',
        'dgi_settings'
    );
    
    add_settings_field(
        'dgi_delete_images',
        __('Delete Images', 'drupal-gallery-importer'),
        'dgi_delete_images_callback',
        'dgi_settings',
        'dgi_deletion_settings'
    );
    
    add_settings_field(
        'dgi_delete_terms',
        __('Delete Orphaned Terms', 'drupal-gallery-importer'),
        'dgi_delete_terms_callback',
        'dgi_settings',
        'dgi_deletion_settings'
    );
}

function dgi_deletion_settings_callback() {
    echo '<p>' . esc_html__('Control what happens when a gallery is deleted.', 'drupal-gallery-importer') . '</p>';
}

function dgi_delete_images_callback() {
    $value = get_option('dgi_delete_images', true);
    ?>
    <label>
        <input type="checkbox" name="dgi_delete_images" value="1" <?php checked($value, true); ?>>
        <?php esc_html_e('Automatically delete gallery images when gallery is deleted (only if not used elsewhere)', 'drupal-gallery-importer'); ?>
    </label>
    <?php
}

function dgi_delete_terms_callback() {
    $value = get_option('dgi_delete_terms', true);
    ?>
    <label>
        <input type="checkbox" name="dgi_delete_terms" value="1" <?php checked($value, true); ?>>
        <?php esc_html_e('Automatically delete gallery type terms when they are no longer in use', 'drupal-gallery-importer'); ?>
    </label>
    <?php
}

add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=gallery',
        __('Gallery Settings', 'drupal-gallery-importer'),
        __('Settings', 'drupal-gallery-importer'),
        'manage_options',
        'dgi-settings',
        'dgi_settings_page'
    );
}, 20);

function dgi_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'drupal-gallery-importer'));
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('dgi_settings');
            do_settings_sections('dgi_settings');
            submit_button();
            ?>
        </form>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2><?php _e('How Gallery Deletion Works', 'drupal-gallery-importer'); ?></h2>
            <p><?php _e('When you delete a gallery:', 'drupal-gallery-importer'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><strong><?php _e('Images:', 'drupal-gallery-importer'); ?></strong> <?php _e('Only deleted if they are not used in any other gallery (orphaned images).', 'drupal-gallery-importer'); ?></li>
                <li><strong><?php _e('Gallery Types:', 'drupal-gallery-importer'); ?></strong> <?php _e('Only deleted if no other galleries are assigned to that type.', 'drupal-gallery-importer'); ?></li>
                <li><strong><?php _e('Large Galleries:', 'drupal-gallery-importer'); ?></strong> <?php _e('Galleries with 30+ images are processed in the background to prevent timeouts.', 'drupal-gallery-importer'); ?></li>
            </ul>
            <p><em><?php _e('This ensures shared resources are protected while keeping your media library clean.', 'drupal-gallery-importer'); ?></em></p>
        </div>
    </div>
    <?php
}

