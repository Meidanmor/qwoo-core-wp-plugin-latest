<?php
/**
 * Security & Cookie Handling
 * CORS is managed via .htaccess by Qwoo_Technical_Settings.
 * This file handles cookie fixes that cannot be done from .htaccess.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ── 1. WooCommerce Cookie SameSite / Secure flags ──
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
add_filter( 'woocommerce_cookie_samesite', function() { return 'None'; } );
add_filter( 'woocommerce_cookie_secure',   '__return_true' );

// ── 2. Global Cookie SameSite fix for all Set-Cookie headers ──
add_action( 'send_headers', function() {
    $headers = headers_list();
    foreach ( $headers as $header ) {
        if ( stripos( $header, 'Set-Cookie:' ) === 0 && stripos( $header, 'SameSite=' ) === false ) {
            $new_header = preg_replace( '/;(\s*)path=/i', '; SameSite=None; Secure; path=', $header );
            header_remove( 'Set-Cookie' );
            header( $new_header, false );
        }
    }
}, 100 );