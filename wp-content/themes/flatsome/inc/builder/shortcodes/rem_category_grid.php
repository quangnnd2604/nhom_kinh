<?php

/**
 * UX Builder Element: Rem Category Grid
 * Displays a parent product category with selected child categories in a premium grid layout.
 * Uses conditional child selects that show only children of the selected parent.
 */

// Build parent categories options (only top-level)
function rem_cat_grid_parent_options() {
    $terms = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'parent'     => 0,
        'exclude'    => get_option('default_product_cat', 0),
    ]);
    $options = [];
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $options[$term->slug] = $term->name;
        }
    }
    return $options;
}

// Build child options for a specific parent slug
function rem_cat_grid_children_of($parent_slug) {
    $parent = get_term_by('slug', $parent_slug, 'product_cat');
    if (!$parent) return [];
    $children = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'parent'     => $parent->term_id,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    $options = [];
    if (!is_wp_error($children)) {
        foreach ($children as $child) {
            $options[$child->term_id] = $child->name;
        }
    }
    return $options;
}

// Dynamically build child select fields with conditions per parent
function rem_cat_grid_build_child_options() {
    $parents = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'parent'     => 0,
        'exclude'    => get_option('default_product_cat', 0),
    ]);

    $child_fields = [];
    if (!is_wp_error($parents)) {
        foreach ($parents as $parent) {
            $children_opts = rem_cat_grid_children_of($parent->slug);
            if (!empty($children_opts)) {
                $field_key = 'children_' . str_replace('-', '_', $parent->slug);
                $child_fields[$field_key] = array(
                    'type'       => 'select',
                    'heading'    => __('Danh mục con của ' . $parent->name),
                    'default'    => '',
                    'conditions' => 'parent == "' . $parent->slug . '"',
                    'config'     => array(
                        'multiple'    => true,
                        'placeholder' => 'Để trống = lấy tất cả...',
                    ),
                    'options' => $children_opts,
                );
            }
        }
    }
    return $child_fields;
}

// Build the full options array
$child_option_fields = rem_cat_grid_build_child_options();

$general_options = array(
    'parent' => array(
        'type'    => 'select',
        'heading' => __('Danh mục cha'),
        'default' => 'rem-cua',
        'options' => rem_cat_grid_parent_options(),
    ),
);

// Merge child fields into general options
$general_options = array_merge($general_options, $child_option_fields);

$general_options['number'] = array(
    'type'    => 'slider',
    'heading' => __('Số lượng hiển thị'),
    'default' => '6',
    'min'     => '1',
    'max'     => '12',
    'step'    => '1',
);

add_ux_builder_shortcode('rem_category_grid', array(
    'name'      => __('Rem Category Grid'),
    'category'  => __('Shop'),
    'priority'  => 4,
    'thumbnail' => flatsome_ux_builder_thumbnail('categories'),
    'info'      => '{{ parent }}',
    'wrap'      => false,

    'presets' => array(
        array(
            'name'    => __('Rèm cửa'),
            'content' => '[rem_category_grid parent="rem-cua" cols="3" number="6"]',
        ),
        array(
            'name'    => __('Rèm chuyên dụng'),
            'content' => '[rem_category_grid parent="rem-chuyen-dung" cols="3" number="6"]',
        ),
    ),

    'options' => array(
        'general' => array(
            'type'    => 'group',
            'heading' => __('Cài đặt chung'),
            'options' => $general_options,
        ),
        'layout' => array(
            'type'    => 'group',
            'heading' => __('Bố cục'),
            'options' => array(
                'cols' => array(
                    'type'    => 'radio-buttons',
                    'heading' => __('Số cột'),
                    'default' => '3',
                    'options' => array(
                        '2' => array('title' => '2'),
                        '3' => array('title' => '3'),
                        '4' => array('title' => '4'),
                    ),
                ),
            ),
        ),
    ),
));
