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
    $content_mod_date  = (int)get_post_modified_time();
    $last_comment_date = cheesecake_get_date_of_last_comment();

    $theme_hash = cheesecake_get_active_theme_state_hash();

    $plugin_hash = cheesecake_get_cached_plugin_hash();

    $menu_hash = cheesecake_get_menu_state_hash();

    $state_string = "{$content_mod_date}_{$last_comment_date}_{$theme_hash}_{$plugin_hash}_{$menu_hash}";

    // header('X-Etag-Debug: '. $state_string);

    return md5( $state_string );
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
    $version_string = '';

    foreach ( $active_plugins as $plugin_path ) {
        if ( isset( $all_plugins[ $plugin_path ] ) ) {
            $version_string .= $plugin_path . ':' . $all_plugins[ $plugin_path ]['Version'] . '|';
        }
    }

    return md5( $version_string );
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

function cheesecake_get_active_theme_state_hash() {
    $theme = wp_get_theme();
    $state = $theme->get( 'Name' ) . ':' . $theme->get( 'Version' );

    $global_settings = wp_get_global_settings();
    $state .= wp_json_encode( $global_settings );

    return md5( $state );
}

function cheesecake_get_menu_state_hash() {
    $classic_menus = wp_get_nav_menus();
    $block_menus   = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish' ) );

    $menu_string = '';

    foreach ( $classic_menus as $menu ) {
        $menu_string .= $menu->term_id . ':' . $menu->count . '|';
    }

    foreach ( (array) $block_menus as $nav ) {
        $menu_string .= 'block' . $nav->ID . $nav->post_modified_gmt;
    }

    return md5( $menu_string );
}

add_action( 'update_option_active_plugins', 'cheesecake_clear_plugin_hash_cache' );

add_action( 'upgrader_process_complete', function( $upgrader_object, $options ) {
    if ( $options['action'] == 'update' ) {
        if ( $options['type'] == 'plugin' ) {
            cheesecake_clear_plugin_hash_cache();
        }
    }
}, 10, 2 );

