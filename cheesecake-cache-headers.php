<?php
/*
Plugin Name:  Cache Optimization
Description:  Enables post and page content caching in browsers using Cache-Control and ETag headers.
Version:      0.3
Author:       Peter Marshall
Author URI:   https://petermarshall.ca
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
*/

add_action('wp', function() {
    if (is_admin()) {
        return;
    }

    header('Cache-Control: max-age=60');
    header('Vary: Cookie, Accept-Encoding');

    if (!is_singular()) {
        return;
    }

    $hash = cheesecake_get_current_state_hash();

    // Set weak flag since content may not be byte-for-byte identical
    $current_etag   = 'W/"' . $hash . '"';
    $requested_etag = trim($_SERVER['HTTP_IF_NONE_MATCH']??'');

    if ($requested_etag === $current_etag) {
        status_header(304);
        exit;
    }

    header('ETag: ' . $current_etag);
});

function cheesecake_get_current_state_hash() {
    $state = [
        'content'      => (int)get_post_modified_time(),
        'last_comment' => cheesecake_get_date_of_last_comment(),
        'theme_state'  => cheesecake_get_active_theme_state(),
        'plugin_state' => cheesecake_get_cached_plugin_hash(),
        'menu_state'   => cheesecake_get_menu_state(),
        'core_version' => $GLOBALS['wp_version'],
    ];

    return md5( serialize( $state ) );
}

function cheesecake_get_date_of_last_comment() {
    $args = array(
        'number'  => 1,
        'post_id' => get_the_ID() ?: 0,
        'orderby' => 'comment_date_gmt',
        'status'  => 'approve',
    );

    $comment_query = new WP_Comment_Query( $args );

    $last_comment = $comment_query->comments;

    return $last_comment->comment_date_gmt??0;
}

function cheesecake_get_active_plugins_version_hash() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins    = get_plugins();
    $active_plugins = get_option( 'active_plugins' );
    $versions = [];

    foreach ( $active_plugins as $plugin_path ) {
        if ( isset( $all_plugins[ $plugin_path ] ) ) {
            $versions[$plugin_path] = $all_plugins[ $plugin_path ]['Version'];
        }
    }

    return md5( serialize ( $versions ) );
}

function cheesecake_get_cached_plugin_hash() {
    $hash = get_transient( 'cheesecake_plugin_hash' );

    if ( false === $hash ) {
        $hash = cheesecake_get_active_plugins_version_hash();

        set_transient( 'cheesecake_plugin_hash' , $hash, 12 * HOUR_IN_SECONDS );
    }

    return $hash;
}

function cheesecake_clear_plugin_hash_cache() {
    delete_transient( 'cheesecake_plugin_hash' );
}

function cheesecake_get_cpts_fingerprint_data($cpt_types) {
    $data = [];

    foreach ( $cpt_types as $type) {
        $posts = get_posts( [
            'post_type'      => $type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'suppress_filters' => true,
        ] );

        foreach ( $posts as $post ) {
            $data[ $post->ID ] = [
                'type' => $type,
                'modified' => $post->post_modified_gmt,
                'id'  => $post->ID,
            ];
        }
    }

    return $data;
}

function cheesecake_get_files_mtime($files) {
    $files_mtime = [];

    foreach ( $files as $file ) {
        $files_mtime[$file] = file_exists( $file ) ? filemtime( $file ) : 0;
    }

    return $files_mtime;
}

function cheesecake_get_active_theme_state() {
    $settings = wp_get_global_settings();
    $styles = wp_get_global_styles();
    $custom_css = wp_get_custom_css();

    $theme = wp_get_theme();

    $theme_files = [
        $theme->get_stylesheet_directory() . '/theme.json',
        $theme->get_stylesheet_directory() . '/style.css',
    ];

    if ( $theme->parent() ) {
        $theme_files[] = $theme->get_template_directory() . '/theme.json';
        $theme_files[] = $theme->get_template_directory() . '/style.css';
    }

    $theme_files_mtime = cheesecake_get_files_mtime( $theme_files );

    $user_style_post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( $theme );
    $user_customizations = ! empty( $user_style_post ) ? $user_style_post['post_content'] : '';

    $theme_cpts = cheesecake_get_cpts_fingerprint_data( [
        'wp_block',
        'wp_template_part',
        'wp_template',
        'wp_font_face',
        'wp_font_family',
    ] );

    $state = [
        'settings'          => $settings,
        'styles'            => $styles,
        'custom_css'        => $custom_css,
        'user_customizer'   => $user_customizations,
        'active_stylesheet' => get_stylesheet(),
        'theme_cpts'        => $theme_cpts,
        'theme_files'       => $theme_files_mtime
    ];

    return $state;
}

function cheesecake_get_menu_state() {
    $classic_menus = wp_get_nav_menus();

    $block_menus   = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish' ) );

    $block_menu_items = [];

    foreach ( (array) $block_menus as $nav ) {
        $block_menu_items[ $nav->ID ] = $nav->post_modified_gmt;
    }

    return [
        'classic' => $classic_menus,
        'block' => $block_menu_items,
    ];
}

add_action( 'update_option_active_plugins', 'cheesecake_clear_plugin_hash_cache' );

add_action( 'upgrader_process_complete', function( $upgrader_object, $options ) {
    if ( $options['action'] == 'update' ) {
        if ( $options['type'] == 'plugin' ) {
            cheesecake_clear_plugin_hash_cache();
        }
    }
}, 10, 2 );

