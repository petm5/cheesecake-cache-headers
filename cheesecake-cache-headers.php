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

    $requested_etag = $_SERVER['HTTP_IF_NONE_MATCH']??'';
    $current_etag =   cheesecake_get_current_etag();

    if ($requested_etag === $current_etag) {
        status_header(304);
        exit;
    }

    header('ETag: W/"' . $current_etag . '"');
});

function cheesecake_get_current_etag() {
    $content_mod_date  = (int)get_post_time();
    $last_comment_date = cheesecake_get_date_of_last_comment();

    $theme_hash = 

    $plugin_hash = cheesecake_get_cached_plugin_hash();

    return md5( "{$content_mod_date}_{$last_comment_date}_{$theme_version}_{$theme_settings_hash}_{$plugin_hash}" );
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

$cheesecake_plugin_hash_transient = 'cheesecake_plugin_hash';

function cheesecake_get_cached_plugin_hash() {
    $hash = get_transient( $cheesecake_plugin_hash_transient );

    if ( false === $hash ) {
        $hash = cheesecake_get_active_plugins_version_hash();

        set_transient( $cheesecake_plugin_hash_transient, $hash, 12 * HOUR_IN_SECONDS );
    }

    return $hash;
}

function cheesecake_clear_plugin_hash_cache() {
    delete_transient( $cheesecake_plugin_hash_transient );
}

function cheesecake_get_active_theme_state_hash() {
    $theme = wp_get_theme();
    $state = $theme->get( 'Name' ) . ':' . $theme->get( 'Version' );

    $merged_data = WP_Theme_JSON_Resolver::get_merged_data();
    $raw_data    = $merged_data->get_data();

    wp_recursive_ksort( $raw_data );

    $theme_json_string = wp_json_encode( $raw_data );
    return md5( $theme_json_string . ':' . $state );
}

$cheesecake_theme_hash_transient = 'cheesecake_theme_hash';

function cheesecake_get_cached_theme_hash() {
    $hash = get_transient( $cheesecake_theme_hash_transient );

    if ( false === $hash ) {
        $hash = cheesecake_get_active_theme_state_hash();

        set_transient( $cheesecake_theme_hash_transient, $hash, 12 * HOUR_IN_SECONDS );
    }

    return $hash;
}

function cheesecake_clear_theme_hash_cache() {
    delete_transient( $cheesecake_theme_hash_transient );
}

add_action( 'update_option_active_plugins', 'cheesecake_clear_plugin_hash_cache' );

add_action( 'save_post_wp_global_styles', 'cheesecake_clear_theme_hash_cache' );

add_action( 'customize_save_after', 'cheesecake_clear_theme_hash_cache' );

add_action( 'upgrader_process_complete', function( $upgrader_object, $options ) {
    if ( $options['action'] == 'update' ) {
        if ( $options['type'] == 'plugin' ) {
            cheesecake_clear_plugin_hash_cache();
        }
        if ( $options['type'] == 'theme' ) {
            cheesecake_clear_theme_hash_cache();
        }
    }
}, 10, 2 );

