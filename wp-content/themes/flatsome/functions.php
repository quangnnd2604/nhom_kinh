<?php

/**
 * Flatsome functions and definitions
 *
 * @package flatsome
 */

require get_template_directory() . '/inc/init.php';

flatsome()->init();
update_option('flatsome_wup_purchase_code', '99dcbf02-cd62-41d2-bf60-bf0d62d95d62');
update_option('flatsome_wup_supported_until', '01.01.2050');
update_option('flatsome_wup_buyer', 'Licensed');
update_option('flatsome_wup_sold_at', time());
delete_option('flatsome_wup_errors', '');
delete_option('flatsome_wupdates', '');

function quangnnd_styles()
{
    wp_enqueue_style('quangnnd-css', get_template_directory_uri() . '/assets/css/quangnnd.css');
}
add_action('wp_enqueue_scripts', 'quangnnd_styles');

/**
 * UTMAvoBold - Đăng ký font vào Kirki Typography Customizer
 *
 * Kirki dùng AJAX endpoint wp_ajax_kirki_fonts_standard_all_get
 * để lấy danh sách Standard Fonts cho font picker dropdown.
 * Filter kirki_fonts_standard_fonts được gọi trong Kirki_Fonts::get_standard_fonts().
 */
function quangnnd_register_utm_standard_font( $fonts ) {
    $fonts['UTMAvoBold'] = array(
        'label' => 'UTM Avo Bold',
        'stack' => 'UTMAvoBold, sans-serif',
    );
    return $fonts;
}
add_filter( 'kirki_fonts_standard_fonts', 'quangnnd_register_utm_standard_font' );

// Cũng thêm vào kirki_fonts_all để Flatsome nhận ra font khi output CSS
function quangnnd_register_custom_fonts( $fonts ) {
    $fonts['UTMAvoBold'] = array(
        'label'    => 'UTM Avo Bold',
        'variants' => array( 'regular', '700' ),
        'category' => 'sans-serif',
    );
    return $fonts;
}
add_filter( 'kirki_fonts_all', 'quangnnd_register_custom_fonts' );

// Load font trong Customizer admin để preview chính xác
function quangnnd_customizer_font_preview() {
    wp_enqueue_style( 'utmavobold-font', get_template_directory_uri() . '/assets/css/quangnnd.css' );
}
add_action( 'customize_controls_enqueue_scripts', 'quangnnd_customizer_font_preview' );
add_action( 'customize_preview_init', 'quangnnd_customizer_font_preview' );


function bootstrap_styles()
{
    wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css
');
}
add_action('wp_enqueue_scripts', 'bootstrap_styles');

function bootstrap_script()
{
    wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js');
}
add_action('wp_enqueue_scripts', 'bootstrap_script');

function bootstrap_bundle()
{
    wp_enqueue_script('bootstrap-bundle', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js');
}
add_action('wp_enqueue_scripts', 'bootstrap_bundle');


/**
 * Custom Shortcode: [rem_category_grid]
 * Displays a parent category with child category cards in a grid
 * 
 * Usage:
 *   [rem_category_grid parent="rem-cua" cols="3" number="6"]
 *   [rem_category_grid parent="rem-cua" cols="3" children="29,31,32,34,35,37"]
 *   [rem_category_grid parent="rem-cua" cols="3" children="rem-vai,rem-sao-nhom"]
 */

/**
 * Translate a product_cat term_id to the current MML_LANG equivalent.
 * Uses a static cache to avoid duplicate DB hits within one request.
 * Returns the translated term_id, or the original if no translation exists.
 */
function mml_translate_term_id( int $term_id ): int {
    if ( ! defined( 'MML_LANG' ) || ! class_exists( 'MML_Translations' ) ) {
        return $term_id;
    }

    static $cache = [];
    $lang = MML_LANG;

    $cache_key = $term_id . ':' . $lang;
    if ( ! array_key_exists( $cache_key, $cache ) ) {
        $translated = MML_Translations::get_translated_id( $term_id, $lang, 'term' );
        $cache[ $cache_key ] = ( $translated !== null ) ? $translated : $term_id;
    }

    return $cache[ $cache_key ];
}

function rem_category_grid_shortcode($atts) {
    // Don't filter — accept all attributes including dynamic children_* params
    $raw_atts = $atts;

    $atts = shortcode_atts([
        'parent'   => 'rem-cua',
        'children' => '',
        'cols'     => '3',
        'number'   => '6',
    ], $atts);

    // ── Helper: fetch a term bypassing the MML language filter.
    // The filter blocks VI source terms when MML_LANG=non-default, but here
    // we need the SOURCE term in order to TRANSLATE it — not to display it.
    $get_term_unfiltered = function( $id_or_slug, string $taxonomy ): ?WP_Term {
        $has_filter = has_filter( 'terms_clauses', 'mml_terms_clauses_lang_filter' );
        if ( $has_filter ) {
            remove_filter( 'terms_clauses', 'mml_terms_clauses_lang_filter', 10 );
        }
        $term = is_numeric( $id_or_slug )
            ? get_term( (int) $id_or_slug, $taxonomy )
            : get_term_by( 'slug', $id_or_slug, $taxonomy );
        if ( $has_filter ) {
            add_filter( 'terms_clauses', 'mml_terms_clauses_lang_filter', 10, 3 );
        }
        return ( $term && ! is_wp_error( $term ) ) ? $term : null;
    };

    // Resolve parent term (can be slug or term_id) — bypass language filter
    $parent_term = $get_term_unfiltered( $atts['parent'], 'product_cat' );

    if ( ! $parent_term ) return '';

    // ── Save original (VI) parent slug BEFORE translation for children_* key lookup
    $original_parent_slug = $parent_term->slug;

    // ── MML: swap parent term to current-language equivalent ──────────────
    $translated_parent_id = mml_translate_term_id( $parent_term->term_id );
    if ( $translated_parent_id !== $parent_term->term_id ) {
        $translated_parent = get_term( $translated_parent_id, 'product_cat' );
        if ( $translated_parent && ! is_wp_error( $translated_parent ) ) {
            $parent_term = $translated_parent;
        }
    }

    // Check for dynamic children_* attribute matching the ORIGINAL (VI) parent slug.
    // Must use original slug — at this point $parent_term may already be the
    // translated term whose slug is different (e.g. "th-rem-cua" vs "rem-cua").
    $children_key = 'children_' . str_replace( '-', '_', $original_parent_slug );
    $children_value = '';
    if ( ! empty( $raw_atts[ $children_key ] ) ) {
        $children_value = $raw_atts[ $children_key ];
    } elseif ( ! empty( $atts['children'] ) ) {
        $children_value = $atts['children'];
    }

    $parent_link = get_term_link($parent_term);
    $number = max(1, (int)$atts['number']);

    // Resolve child terms — bypass language filter so VI source IDs/slugs are
    // always found regardless of the currently active language.
    $child_terms = [];
    if (!empty($children_value)) {
        $ids = array_map('trim', explode(',', $children_value));
        foreach ($ids as $id) {
            $term = $get_term_unfiltered( $id, 'product_cat' );
            if ($term) {
                $child_terms[] = $term;
            }
        }
    } else {
        // Auto-fetch children of translated parent (already correct-language term)
        $child_terms = get_terms([
            'taxonomy'   => 'product_cat',
            'parent'     => $parent_term->term_id,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        if (is_wp_error($child_terms)) $child_terms = [];
    }

    // ── MML: swap each child term to current-language equivalent ──────────
    $translated_child_terms = [];
    foreach ( $child_terms as $term ) {
        $translated_id = mml_translate_term_id( $term->term_id );
        if ( $translated_id !== $term->term_id ) {
            $translated_term = get_term( $translated_id, 'product_cat' );
            if ( $translated_term && ! is_wp_error( $translated_term ) ) {
                $translated_child_terms[] = $translated_term;
                continue;
            }
        }
        $translated_child_terms[] = $term;
    }
    $child_terms = $translated_child_terms;

    // Limit by number
    $child_terms = array_slice($child_terms, 0, $number);

    if (empty($child_terms)) return '';

    ob_start();
    ?>
    <div class="rem-cat-section">
        <div class="rem-cat-header">
            <h2 class="rem-cat-title"><?php echo esc_html($parent_term->name); ?></h2>
            <div class="rem-cat-title-line"></div>
        </div>
        <div class="rem-cat-grid rem-cat-cols-<?php echo esc_attr($atts['cols']); ?>">
            <?php foreach ($child_terms as $term):
                $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
                $image_url = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : '';
                $cat_link = get_term_link($term);
            ?>
            <a href="<?php echo esc_url($cat_link); ?>" class="rem-cat-card">
                <div class="rem-cat-card-bg" style="background-image: url('<?php echo esc_url($image_url); ?>')"></div>
                <div class="rem-cat-card-overlay"></div>
                <div class="rem-cat-card-content">
                    <h3 class="rem-cat-card-title"><?php echo esc_html($term->name); ?></h3>
                    <span class="rem-cat-card-btn"><?php echo esc_html( class_exists( 'MML_Strings' ) && defined( 'MML_LANG' ) ? MML_Strings::get_value( 'rem_cat_card_btn', MML_LANG ) : 'Xem thêm' ); ?></span>
                </div>
                <div class="rem-cat-card-corner rem-cat-corner-tl"></div>
                <div class="rem-cat-card-corner rem-cat-corner-br"></div>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="rem-cat-footer">
            <a href="<?php echo esc_url($parent_link); ?>" class="rem-cat-view-all">
                <?php echo esc_html( class_exists( 'MML_Strings' ) && defined( 'MML_LANG' ) ? MML_Strings::get_value( 'rem_cat_view_all', MML_LANG ) : 'Xem tất cả' ); ?> <?php echo esc_html($parent_term->name); ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('rem_category_grid', 'rem_category_grid_shortcode');


/**
 * =============================================
 * ADMIN SETTINGS: Hotline / Email / Zalo
 * =============================================
 */
function techzhome_hotline_menu() {
    add_options_page(
        'Cài đặt Hotline',
        'Hotline',
        'manage_options',
        'techzhome-hotline',
        'techzhome_hotline_page'
    );
}
add_action('admin_menu', 'techzhome_hotline_menu');

function techzhome_hotline_settings_init() {
    register_setting('techzhome_hotline_group', 'techzhome_hotline');
    register_setting('techzhome_hotline_group', 'techzhome_email');
    register_setting('techzhome_hotline_group', 'techzhome_zalo');

    add_settings_section('techzhome_hotline_section', 'Thông tin liên hệ', null, 'techzhome-hotline');

    add_settings_field('techzhome_hotline', 'Số Hotline', function() {
        $val = get_option('techzhome_hotline', '');
        echo '<input type="text" name="techzhome_hotline" value="' . esc_attr($val) . '" class="regular-text" placeholder="0909 123 456" />';
        echo '<p class="description">Số điện thoại hotline (dùng cho nút Liên hệ tư vấn)</p>';
    }, 'techzhome-hotline', 'techzhome_hotline_section');

    add_settings_field('techzhome_email', 'Email', function() {
        $val = get_option('techzhome_email', '');
        echo '<input type="email" name="techzhome_email" value="' . esc_attr($val) . '" class="regular-text" placeholder="info@techzhome.vn" />';
    }, 'techzhome-hotline', 'techzhome_hotline_section');

    add_settings_field('techzhome_zalo', 'Zalo', function() {
        $val = get_option('techzhome_zalo', '');
        echo '<input type="text" name="techzhome_zalo" value="' . esc_attr($val) . '" class="regular-text" placeholder="0909 123 456" />';
        echo '<p class="description">Số Zalo hoặc link Zalo OA</p>';
    }, 'techzhome-hotline', 'techzhome_hotline_section');
}
add_action('admin_init', 'techzhome_hotline_settings_init');

function techzhome_hotline_page() {
    ?>
    <div class="wrap">
        <h1>Cài đặt Hotline</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('techzhome_hotline_group');
            do_settings_sections('techzhome-hotline');
            submit_button('Lưu thay đổi');
            ?>
        </form>
    </div>
    <?php
}

/**
 * =============================================
 * PRODUCT PAGE: Hide cart, show description, add contact button
 * =============================================
 */

// Hide the add-to-cart form
add_action('wp', function() {
    if (is_product()) {
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    }
});

// Move short description up (before price)
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
add_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 25);

// Add contact button after short description
add_action('woocommerce_single_product_summary', 'techzhome_contact_button', 35);

function techzhome_contact_button() {
    $hotline = get_option('techzhome_hotline', '');
    $hotline_clean = preg_replace('/[^0-9+]/', '', $hotline);
    $hotline_display = $hotline ?: 'Chưa cấu hình';
    ?>
    <!-- Contact Button -->
    <div class="techzhome-contact-wrap">
        <!-- Desktop: single button → popup -->
        <button type="button" class="techzhome-contact-btn techzhome-desktop-only" onclick="document.getElementById('techzhome-popup').classList.add('active')">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
            </svg>
            <?php echo do_shortcode('[my_trans key="lien_he_tu_ask" original="Liên hệ tư vấn"]'); ?>
        </button>

        <!-- Mobile: main button toggles 2 actions -->
        <div class="techzhome-mobile-only techzhome-mobile-group">
            <button type="button" class="techzhome-contact-btn" onclick="this.parentElement.classList.toggle('open')">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
                <?php echo do_shortcode('[my_trans key="lien_he_tu_ask" original="Liên hệ tư vấn"]'); ?>
            </button>
            <div class="techzhome-mobile-actions">
                <a href="tel:<?php echo esc_attr($hotline_clean); ?>" class="techzhome-action-btn techzhome-action-call">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <?php echo do_shortcode('[my_trans key="goi_tu_van_93o" original="Gọi tư vấn"]'); ?>
                </a>
                <button type="button" class="techzhome-action-btn techzhome-action-form" onclick="document.getElementById('techzhome-popup').classList.add('active')">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php echo do_shortcode('[my_trans key="de_lai_thong_8uo" original="Để lại thông tin"]'); ?>
                </button>
            </div>
        </div>

        <p class="techzhome-hotline-text">
            Hotline: <a href="tel:<?php echo esc_attr($hotline_clean); ?>"><?php echo esc_html($hotline_display); ?></a>
        </p>
    </div>

    <!-- Popup Contact Form (Desktop) -->
    <div id="techzhome-popup" class="techzhome-popup-overlay" onclick="if(event.target===this)this.classList.remove('active')">
        <div class="techzhome-popup-box">
            <button type="button" class="techzhome-popup-close" onclick="document.getElementById('techzhome-popup').classList.remove('active')">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <h3 class="techzhome-popup-title"><?php echo do_shortcode('[my_trans key="lien_he_tu_ask" original="Liên hệ tư vấn"]'); ?></h3>
            <p class="techzhome-popup-subtitle"><?php echo do_shortcode('[my_trans key="de_lai_thong_b9u" original="Để lại thông tin, chúng tôi sẽ liên hệ bạn sớm nhất"]'); ?></p>
            <div class="techzhome-popup-form">
                <?php echo do_shortcode('[contact-form-7 id="3870070" title="Form liên hệ mua sản phẩm"]'); ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * =============================================
 * FLOATING HOTLINE BUTTONS (Bottom-Right)
 * Zalo | Phone | Email
 * =============================================
 */
function techzhome_floating_buttons() {
    $hotline = get_option('techzhome_hotline', '');
    $email   = get_option('techzhome_email', '');
    // Zalo: use dedicated Zalo number; fallback to hotline number if blank
    $zalo_raw = get_option('techzhome_zalo', '');
    $zalo     = !empty(trim($zalo_raw)) ? $zalo_raw : $hotline;

    // Clean phone number for tel: link (remove all non-numeric except +)
    $hotline_clean = preg_replace('/[^0-9+]/', '', $hotline);
    // Clean zalo number
    $zalo_clean    = preg_replace('/[^0-9+]/', '', $zalo);
    // Email: trim whitespace
    $email = trim($email);

    // Icon paths (uploaded to media)
    $uploads_url = content_url('uploads/2026/03');
    $icon_zalo   = $uploads_url . '/zalo-icon.png';
    $icon_phone  = $uploads_url . '/phone-icon.png';
    $icon_email  = $uploads_url . '/email-icon.png';
    ?>
    <!-- Floating Hotline Buttons -->
    <div class="tz-float-wrap" id="tz-float-wrap">
        <?php if (!empty($email)) : ?>
        <div class="tz-float-item tz-float-email" style="--delay:0s">
            <a href="mailto:<?php echo esc_attr($email); ?>" target="_blank" rel="noopener" title="Gửi email">
                <img src="<?php echo esc_url($icon_email); ?>" alt="Email" />
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($zalo_clean)) : ?>
        <div class="tz-float-item tz-float-zalo" style="--delay:0.15s">
            <a href="https://zalo.me/<?php echo esc_attr($zalo_clean); ?>" target="_blank" rel="noopener" title="Chat Zalo">
                <img src="<?php echo esc_url($icon_zalo); ?>" alt="Zalo" />
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($hotline_clean)) : ?>
        <div class="tz-float-item tz-float-phone" style="--delay:0.3s">
            <!-- Desktop: no tel link, just display -->
            <a href="tel:<?php echo esc_attr($hotline_clean); ?>" class="tz-phone-link" title="Gọi hotline">
                <img src="<?php echo esc_url($icon_phone); ?>" alt="Hotline" />
                <span class="tz-phone-text"><?php echo esc_html($hotline); ?></span>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <script>
    (function() {
        var phoneLink = document.querySelector('.tz-float-phone .tz-phone-link');
        if (phoneLink) {
            phoneLink.addEventListener('click', function(e) {
                // Only allow tel: call on mobile (≤ 768px)
                if (window.innerWidth > 768) {
                    e.preventDefault();
                }
            });
        }
    })();
    </script>
    <?php
}
add_action('wp_footer', 'techzhome_floating_buttons');

/**
 * It's not recommended to add any custom code here. Please use a child theme
 * so that your customizations aren't lost during updates.
 *
 * Learn more here: https://developer.wordpress.org/themes/advanced-topics/child-themes/
 */
