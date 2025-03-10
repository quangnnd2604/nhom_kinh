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
 * It's not recommended to add any custom code here. Please use a child theme
 * so that your customizations aren't lost during updates.
 *
 * Learn more here: https://developer.wordpress.org/themes/advanced-topics/child-themes/
 */
