<?php

add_action('rest_api_init', function () {

    register_rest_route('qwoo/v1', '/export-products', [
            'methods'  => 'GET',
            'callback' => 'qwoo_export_products_json',
            'permission_callback' => '__return_true'
    ]);

});
function qwoo_export_products_json(WP_REST_Request $request)
{
    // Make sure no caching interferes
    nocache_headers();

    // If your function already returns JSON string:
    $json = aps_generate_products_json();

    // Safety: ensure valid JSON response
    $decoded = json_decode($json, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return rest_ensure_response($decoded);
    }

    // fallback: return raw string if generator already outputs JSON text
    return new WP_REST_Response($json, 200, [
            'Content-Type' => 'application/json'
    ]);
}

// Trigger sync when a product is created or updated
add_action('woocommerce_update_product', 'aps_sync_products_to_github', 10, 0);

// Trigger sync when a product is deleted
add_action('woocommerce_delete_product', 'aps_sync_products_to_github', 10, 0);

// Run every hour
add_action('auto_sync_products_to_github', 'aps_sync_products_to_github');
if (!wp_next_scheduled('auto_sync_products_to_github')) {
    wp_schedule_event(time(), 'hourly', 'auto_sync_products_to_github');
}

/**
 * Main function: fetch products → generate JSON → push to GitHub if changed.
 */
/**
 * Atomically sync the hero image + home.json to GitHub in ONE commit.
 *
 * Safety: this function only ever reads/deletes tree entries whose path
 * starts with $image_folder. It never inspects or modifies any other
 * part of the repository tree.
 *
 * @param int    $attachment_id   WP attachment ID of the hero image
 * @param array  $home_data       The 'home' settings array (without hero_image_id)
 * @param string $image_folder    e.g. 'public/homepage-hero'
 * @param string $json_path       e.g. 'public/config/home.json'
 * @return bool
 */
function aps_sync_hero_image_to_github( $attachment_id, $home_data, $image_folder = 'public/homepage-hero', $json_path = 'public/config/home.json' ) {

    $owner  = Qwoo_Technical_Settings::get_key('GITHUB_REPO_OWNER');
    $repo   = Qwoo_Technical_Settings::get_key('GITHUB_REPO_NAME');
    $token  = Qwoo_Technical_Settings::get_key('GITHUB_TOKEN');
    $branch = Qwoo_Technical_Settings::get_key('GITHUB_BRANCH') ?: 'main';

    if ( empty($owner) || empty($repo) || empty($token) ) {
        return false;
    }

    $attachment_path = get_attached_file( $attachment_id );
    if ( ! $attachment_path || ! file_exists( $attachment_path ) ) {
        return false;
    }

    $image_folder = trim( $image_folder, '/' );

    // Keep the ORIGINAL filename from the backend, just sanitized for safety
    // (no path traversal, no weird characters) — no more hero-{ID} renaming.
    $original_filename = sanitize_file_name( basename( $attachment_path ) );
    $new_image_path    = "{$image_folder}/{$original_filename}";

    // The value written into home.json — the real backend URL, used by the
    // frontend as: try local /{basename} first, fall back to this URL if
    // the local file isn't there yet (e.g. deploy still in progress).
    $backend_image_url = wp_get_attachment_url( $attachment_id );

    // Read the image once and compute its Git blob sha locally, so we can
    // compare against the tree without ever re-downloading the file from GitHub.
    $image_data = file_get_contents( $attachment_path );
    if ( $image_data === false ) return false;
    $local_image_sha = sha1( "blob " . strlen( $image_data ) . "\0" . $image_data );

    $github_api = function( $method, $endpoint, $body = null ) use ( $owner, $repo, $token ) {
        $args = [
                'method'  => $method,
                'headers' => [
                        'Authorization' => "token {$token}",
                        'User-Agent'    => 'WordPress-GitHub-Client',
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/vnd.github+json',
                ],
                'timeout' => 30,
        ];
        if ( $body !== null ) {
            $args['body'] = json_encode( $body );
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}{$endpoint}";
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            return false;
        }

        return $decoded;
    };

    // 1. Latest commit on branch
    $ref = $github_api( 'GET', "/git/ref/heads/{$branch}" );
    if ( ! $ref ) return false;
    $base_commit_sha = $ref['object']['sha'];

    $commit = $github_api( 'GET', "/git/commits/{$base_commit_sha}" );
    if ( ! $commit ) return false;
    $base_tree_sha = $commit['tree']['sha'];

    // 2. Full recursive tree — read-only, used to find stale image files AND
    // to grab the current home.json blob sha (so we can diff it with zero extra requests).
    $tree = $github_api( 'GET', "/git/trees/{$base_tree_sha}?recursive=1" );
    if ( ! $tree || empty( $tree['tree'] ) ) return false;

    // 3. SAFETY-SCOPED: only collect blob paths inside $image_folder.
    // Anything in that folder that ISN'T the new file is stale and gets deleted.
    $stale_files = [];
    $image_already_current = false;
    $existing_json_sha = null;

    foreach ( $tree['tree'] as $entry ) {
        if ( $entry['type'] !== 'blob' ) continue;

        if ( $entry['path'] === $json_path ) {
            $existing_json_sha = $entry['sha'];
            continue;
        }

        if ( strpos( $entry['path'], $image_folder . '/' ) !== 0 ) continue;

        if ( $entry['path'] === $new_image_path && $entry['sha'] === $local_image_sha ) {
            $image_already_current = true;
        } else {
            $stale_files[] = $entry['path'];
        }
    }

    $tree_updates = [];

    foreach ( $stale_files as $old_path ) {
        $tree_updates[] = [ 'path' => $old_path, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
    }

    if ( ! $image_already_current ) {
        $image_blob = $github_api( 'POST', '/git/blobs', [
                'content'  => base64_encode( $image_data ),
                'encoding' => 'base64',
        ] );
        if ( ! $image_blob ) return false;

        $tree_updates[] = [ 'path' => $new_image_path, 'mode' => '100644', 'type' => 'blob', 'sha' => $image_blob['sha'] ];
    }

    // 4. JSON — key renamed to hero_image, value is the backend URL (not a local path)
    $home_data['hero_image'] = $backend_image_url;
    $json_content = json_encode( $home_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

    // Compare the local content hash against the sha already captured from the tree scan —
    // no extra GET request needed, and no chance of hitting the Contents API's 1MB inline limit.
    $local_json_sha = sha1( "blob " . strlen( $json_content ) . "\0" . $json_content );
    $json_changed   = ( $existing_json_sha !== $local_json_sha );

    if ( $json_changed ) {
        $json_blob = $github_api( 'POST', '/git/blobs', [
                'content'  => base64_encode( $json_content ),
                'encoding' => 'base64',
        ] );
        if ( ! $json_blob ) return false;

        $tree_updates[] = [ 'path' => $json_path, 'mode' => '100644', 'type' => 'blob', 'sha' => $json_blob['sha'] ];
    }

    if ( empty( $tree_updates ) ) {
        return 'no_changes';
    }

    // 5. New tree, commit, move ref — unchanged
    $new_tree = $github_api( 'POST', '/git/trees', [
            'base_tree' => $base_tree_sha,
            'tree'      => $tree_updates,
    ] );
    if ( ! $new_tree ) return false;

    $new_commit = $github_api( 'POST', '/git/commits', [
            'message' => 'Update hero image and home config',
            'tree'    => $new_tree['sha'],
            'parents' => [ $base_commit_sha ],
    ] );
    if ( ! $new_commit ) return false;

    $updated_ref = $github_api( 'PATCH', "/git/refs/heads/{$branch}", [
            'sha'   => $new_commit['sha'],
            'force' => false,
    ] );
    if ( ! $updated_ref ) return false;

    return true;
}

/**
 * Atomically sync a single branding image (logo OR app icon) + its owning
 * JSON page to GitHub in ONE commit. Generalized from
 * aps_sync_hero_image_to_github() — same scoped-safety pattern (only ever
 * reads/deletes tree entries under $image_folder), but takes an explicit
 * $image_url_field so it can be reused for any single-image field instead
 * of being hardcoded to `hero_image`.
 *
 * NOTE: when syncing branding with two images (logo_id + app_icon_id), call
 * this once per image field — each call is its own atomic commit against the
 * branch tip, so the second call will simply rebase on top of the first.
 *
 * @param int    $attachment_id   WP attachment ID of the image being synced
 * @param array  $page_data       The full settings array for this page (e.g. 'branding')
 * @param string $image_url_field The JSON key to write the resolved URL to, e.g. 'logo' or 'app_icon'
 * @param string $image_folder    e.g. 'public/branding'
 * @param string $json_path       e.g. 'public/config/branding.json'
 * @return bool|string true on success, 'no_changes' if nothing changed, false on failure.
 */
function aps_sync_logo_to_github( $attachment_id, &$page_data, $image_url_field, $image_folder = 'public/branding', $json_path = 'public/config/branding.json' ) {

    $owner  = Qwoo_Technical_Settings::get_key('GITHUB_REPO_OWNER');
    $repo   = Qwoo_Technical_Settings::get_key('GITHUB_REPO_NAME');
    $token  = Qwoo_Technical_Settings::get_key('GITHUB_TOKEN');
    $branch = Qwoo_Technical_Settings::get_key('GITHUB_BRANCH') ?: 'main';

    if ( empty($owner) || empty($repo) || empty($token) ) {
        return false;
    }

    $attachment_path = get_attached_file( $attachment_id );
    if ( ! $attachment_path || ! file_exists( $attachment_path ) ) {
        return false;
    }

    $image_folder = trim( $image_folder, '/' );

    // Keep the original filename (sanitized), namespaced by field so the
    // logo and app icon don't collide if they happen to share a filename.
    $original_filename = sanitize_file_name( basename( $attachment_path ) );
    $new_image_path     = "{$image_folder}/{$original_filename}";

    $backend_image_url = wp_get_attachment_url( $attachment_id );

    $image_data = file_get_contents( $attachment_path );
    if ( $image_data === false ) return false;
    $local_image_sha = sha1( "blob " . strlen( $image_data ) . "\0" . $image_data );

    $github_api = function( $method, $endpoint, $body = null ) use ( $owner, $repo, $token ) {
        $args = [
                'method'  => $method,
                'headers' => [
                        'Authorization' => "token {$token}",
                        'User-Agent'    => 'WordPress-GitHub-Client',
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/vnd.github+json',
                ],
                'timeout' => 30,
        ];
        if ( $body !== null ) {
            $args['body'] = json_encode( $body );
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}{$endpoint}";
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            return false;
        }

        return $decoded;
    };

    // 1. Latest commit on branch
    $ref = $github_api( 'GET', "/git/ref/heads/{$branch}" );
    if ( ! $ref ) return false;
    $base_commit_sha = $ref['object']['sha'];

    $commit = $github_api( 'GET', "/git/commits/{$base_commit_sha}" );
    if ( ! $commit ) return false;
    $base_tree_sha = $commit['tree']['sha'];

    // 2. Full recursive tree — read-only.
    $tree = $github_api( 'GET', "/git/trees/{$base_tree_sha}?recursive=1" );
    if ( ! $tree || empty( $tree['tree'] ) ) return false;

    // 3. SAFETY-SCOPED: only ever touch blobs whose path starts with
    // "{$image_folder}/{$image_url_field}-" — this keeps the logo sync from
    // ever deleting the app-icon file (or vice versa) even though they share
    // the same $image_folder.
    $field_prefix = "{$image_folder}/{$image_url_field}-";
    $stale_files = [];
    $image_already_current = false;
    $existing_json_sha = null;

    foreach ( $tree['tree'] as $entry ) {
        if ( $entry['type'] !== 'blob' ) continue;

        if ( $entry['path'] === $json_path ) {
            $existing_json_sha = $entry['sha'];
            continue;
        }

        if ( strpos( $entry['path'], $field_prefix ) !== 0 ) continue;

        if ( $entry['path'] === $new_image_path && $entry['sha'] === $local_image_sha ) {
            $image_already_current = true;
        } else {
            $stale_files[] = $entry['path'];
        }
    }

    $tree_updates = [];

    foreach ( $stale_files as $old_path ) {
        $tree_updates[] = [ 'path' => $old_path, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
    }

    if ( ! $image_already_current ) {
        $image_blob = $github_api( 'POST', '/git/blobs', [
                'content'  => base64_encode( $image_data ),
                'encoding' => 'base64',
        ] );
        if ( ! $image_blob ) return false;

        $tree_updates[] = [ 'path' => $new_image_path, 'mode' => '100644', 'type' => 'blob', 'sha' => $image_blob['sha'] ];
    }

    // 4. JSON — write the resolved backend URL under the given field name,
    // and drop the raw attachment-ID field so it never leaks to the frontend.
    unset( $page_data[ $image_url_field . '_id' ] );
    $page_data[ $image_url_field ] = $backend_image_url;
    $json_content = json_encode( $page_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

    $local_json_sha = sha1( "blob " . strlen( $json_content ) . "\0" . $json_content );
    $json_changed   = ( $existing_json_sha !== $local_json_sha );

    if ( $json_changed ) {
        $json_blob = $github_api( 'POST', '/git/blobs', [
                'content'  => base64_encode( $json_content ),
                'encoding' => 'base64',
        ] );
        if ( ! $json_blob ) return false;

        $tree_updates[] = [ 'path' => $json_path, 'mode' => '100644', 'type' => 'blob', 'sha' => $json_blob['sha'] ];
    }

    if ( empty( $tree_updates ) ) {
        return 'no_changes';
    }

    $new_tree = $github_api( 'POST', '/git/trees', [
            'base_tree' => $base_tree_sha,
            'tree'      => $tree_updates,
    ] );
    if ( ! $new_tree ) return false;

    $new_commit = $github_api( 'POST', '/git/commits', [
            'message' => "Update {$image_url_field} and branding config",
            'tree'    => $new_tree['sha'],
            'parents' => [ $base_commit_sha ],
    ] );
    if ( ! $new_commit ) return false;

    $updated_ref = $github_api( 'PATCH', "/git/refs/heads/{$branch}", [
            'sha'   => $new_commit['sha'],
            'force' => false,
    ] );
    if ( ! $updated_ref ) return false;

    return true;
}

function aps_sync_products_to_github() {

    $json = aps_generate_products_json();

    if (!$json) {
        return 'json_failed';
    }

    $new_hash = md5($json);
    $old_hash = get_option('aps_products_json_hash');

    if ($new_hash === $old_hash) {
        return 'no_changes';
    }

    $result = aps_commit_to_github($json);

    if ($result) {
        update_option('aps_products_json_hash', $new_hash);
    }

    return $result;
}
function aps_sync_categories_to_github() {

    $json = aps_generate_categories_json();

    if (!$json) {
        return 'json failed';
    }

    $new_hash = md5($json);

    $old_hash = get_option('aps_categories_json_hash');

    if ($new_hash === $old_hash) {
        return 'no changes';
    }

    $result = aps_commit_to_github(
            $json,
            'public/data/categories.json',
            'Update categories from WP'
    );

    if ($result) {
        update_option('aps_categories_json_hash', $new_hash);
    }
}

function aps_generate_price_meta_json() {

    $url = site_url('/wp-json/qwoo/v1/products-meta?category=all');

    $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                    'User-Agent' => 'WP-StoreAPI-Sync'
            ]
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);

    if (!$body) {
        return false;
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        return false;
    }

    return json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
}

function aps_sync_price_meta_to_github() {

    $json = aps_generate_price_meta_json();

    if (!$json) {
        return 'json failed';
    }

    $new_hash = md5($json);

    $old_hash = get_option('aps_price_meta_hash');

    if ($new_hash === $old_hash) {
        return 'no changes';
    }

    $result = aps_commit_to_github(
            $json,
            'public/data/price-meta.json',
            'Update price meta from WP'
    );

    if ($result) {
        update_option('aps_price_meta_hash', $new_hash);
    }
}

function aps_fetch_store_api($endpoint, $params = []) {

    $page = 1;

    $all_items = [];

    do {

        $query_args = array_merge([
                'page' => $page,
                'per_page' => 100,
                'include_sort_meta' => "false"
        ], $params);

        $url = add_query_arg(
                $query_args,
                site_url("/wp-json/wc/store/{$endpoint}")
        );

        $response = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => [
                        'User-Agent' => 'WP-StoreAPI-Sync'
                ]
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);

        if (!$body) {
            return false;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return false;
        }

        $all_items = array_merge($all_items, $decoded);

        $total_pages = max(
                1,
                (int)wp_remote_retrieve_header(
                        $response,
                        'x-wp-totalpages'
                )
        );

        $page++;

    } while ($page <= $total_pages);

    return json_encode(
            $all_items,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
}

function aps_generate_products_json() {
    return aps_fetch_store_api('products', ['per_page' => 6, 'include_sort_meta' => 'true']);
}
function aps_generate_categories_json() {
    return aps_fetch_store_api('products/categories', ['per_page' => 6]);
}

/**
 * Commit JSON to GitHub via REST API.
 */
function aps_commit_to_github($content, $path = null, $message = 'Auto-sync from WordPress') {

    $owner = Qwoo_Technical_Settings::get_key('GITHUB_REPO_OWNER');
    $repo  = Qwoo_Technical_Settings::get_key('GITHUB_REPO_NAME');
    $token = Qwoo_Technical_Settings::get_key('GITHUB_TOKEN');

    if ( empty($owner) || empty($repo) || empty($token) ) {
        return 'not_configured';
    }


    if ($path === null) {
        if (!defined('GITHUB_FILE_PATH')) {
            return 'missing_path';
        }

        $path = GITHUB_FILE_PATH;
    }

    $api_url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}";

    // 1. Get current file (if exists)
    $existing = wp_remote_get($api_url, [
            'headers' => [
                    'Authorization' => "token $token",
                    'User-Agent'    => 'WordPress-GitHub-Client'
            ]
    ]);

    $sha = null;
    $existing_content = null;

    if (is_wp_error($existing)) {
        error_log('APS Sync Error (GET): ' . $existing->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($existing);

    if ($response_code === 200) {
        error_log("APS Sync success. Status: $response_code. Path: $path.");

        $existing_body = json_decode(wp_remote_retrieve_body($existing), true);

        $sha = $existing_body['sha'] ?? null;

        if (!empty($existing_body['content'])) {
            $existing_content = base64_decode($existing_body['content']);
        }
    } elseif ($response_code !== 404) {
        error_log("APS Sync GET Failed. Status: $response_code. Path: $path.");
        return false;
    }

    // 2. 🔥 Normalize JSON to prevent false diffs
    $normalize_json = function($json) {
        $decoded = json_decode($json, true);
        return $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $json;
    };

    $normalized_new = $normalize_json($content);
    $normalized_existing = $existing_content ? $normalize_json($existing_content) : null;

    // 3. 🚨 Skip if identical (ONLY if file exists)
    if ($sha && $normalized_existing === $normalized_new) {
        // Optional debug:
        // error_log("APS Sync Skipped (no changes): $path");
        return 'no_changes';
    }

    // 4. Prepare data
    $data = [
            'message' => $message,
            'content' => base64_encode($normalized_new),
    ];

    if ($sha) {
        $data['sha'] = $sha;
    }

    // 5. Send request
    $response = wp_remote_request($api_url, [
            'method'  => 'PUT',
            'headers' => [
                    'Authorization' => "token $token",
                    'User-Agent'    => 'WordPress-GitHub-Client',
                    'Content-Type'  => 'application/json'
            ],
            'body' => json_encode($data)
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code < 200 || $code >= 300) {
        return false;
    }

    return true;
}
/**
 * Optional: Manual run via URL for debugging:
 * https://yourdomain.com/?run_product_sync=1
 */
add_action('init', function () {

    if (
            isset($_GET['run_product_sync']) &&
            current_user_can('manage_options')
    ) {

        echo '<pre>';

        echo "Running products sync...\n";

        $products = aps_sync_products_to_github();
        var_dump($products);

        echo "\n\nRunning categories sync...\n";

        $categories = aps_sync_categories_to_github();
        var_dump($categories);

        echo "\n\nRunning price meta sync...\n";

        $meta = aps_sync_price_meta_to_github();
        var_dump($meta);

        echo '</pre>';

        exit;
    }

});

add_action('woocommerce_update_product', 'handle_product_sale_change', 20, 1);

function handle_product_sale_change($product_id) {
    $product = wc_get_product($product_id);

    if (!$product || !$product->is_type('simple')) return;

    // Get current sale and regular price
    $sale_price = $product->get_sale_price();
    $regular_price = $product->get_regular_price();

    // No sale price, skip
    if (empty($sale_price)) return;

    // Use a transient to make sure we don't re-send
    /*if (get_transient("sale_push_sent_$product_id")) {
      return;
    }

    // Mark as sent for the next 12 hours
    set_transient("sale_push_sent_$product_id", 1, 12 * HOUR_IN_SECONDS);*/
    send_sale_push_notification($product);
}

//add custom page
class VH_Options {

    public $options;

    public function __construct() {
        // Delete options settings
        // delete_option( 'vh_plugin_options' );
        $this->options = get_option( 'vh_plugin_options' );
        $this->register_settings_and_fields();
    }

    public function add_menu_page()
    {
        // Last param, WP specific, accept class name and method
        add_options_page('Theme Options', 'Theme Options', 'manage_options', 'theme-options', array($this, 'display_options_page'));
    }

    public function display_options_page() {
        if(function_exists( 'wp_enqueue_media' )) {
            wp_enqueue_media();
        } else {
            wp_enqueue_style('thickbox');
            wp_enqueue_script('media-upload');
            wp_enqueue_script('thickbox');
        }
        ?>



        <script>
            jQuery(document).ready(function($) {
                $('.header_logo_upload').click(function(e) {
                    e.preventDefault();

                    var custom_uploader = wp.media({
                        title: 'Custom Image',
                        button: {
                            text: 'Upload Image'
                        },
                        multiple: false  // Set this to true to allow multiple files to be selected
                    })
                        .on('select', function() {
                            var attachment = custom_uploader.state().get('selection').first().toJSON();
                            $('.header_logo').attr('src', attachment.url);
                            $('.header_logo_url').val(attachment.url);

                        })
                        .open();
                });
            });
        </script>


        <div class="wrap">
            <h2>My Theme Options</h2>
            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php
                // WP security fields
                settings_fields('vh_plugin_options'); ?>
                <?php do_settings_sections( __FILE__ ); ?>
                <p class="submit">
                    <input name="submit" type="submit" class="button-primary" value="Save Changes" />
                </p>
            </form>
        </div>
        <?php
    }

    public function register_settings_and_fields()
    {
        // Groupname, name, 3rd param optional cb
        register_setting('vh_plugin_options', 'vh_plugin_options', array($this, 'vh_validate_settings'));
        // Id, title of section, cb, which page?
        add_settings_section('vh_main_section', 'Main Settings', array($this, 'vh_main_section_cb'), __FILE__);
        // Attach the banner field to a specific section
        add_settings_field('vh_banner_heading', 'Banner Heading: ', array($this, 'vh_banner_heading_setting'), __FILE__, 'vh_main_section');
        // Attach the logo field to a specific section
        add_settings_field('vh_logo', 'Logo: ', array($this, 'vh_logo_setting'), __FILE__, 'vh_main_section');
        // Attach the logo field to a specific section
        add_settings_field('vh_hero_image', 'Hero img: ', array($this, 'vh_hero_image_setting'), __FILE__, 'vh_main_section');
        // Attach color scheme drop down list
        add_settings_field('vh_color_scheme', 'Your Desire Color Scheme: ', array($this, 'vh_color_scheme_setting'), __FILE__, 'vh_main_section');
    }

    public function vh_main_section_cb()
    {
        // Optional

    }

    public function vh_validate_settings( $plugin_options )
    {
        if (!empty($_FILES['vh_logo_upload']['tmp_name'])) {
            $override = array('test_form' => false);
            $file = wp_handle_upload($_FILES['vh_logo_upload'], $override);
            $plugin_options['vh_logo'] = $file['url'];
        } else {
            $plugin_options['vh_logo'] = $this->options['vh_logo'];
        }

        return $plugin_options;
    }

    // Inputs

    // Banner heading
    public function vh_banner_heading_setting() {
        // Value gets data from the options
        echo "<input class='regular-text code' name='vh_plugin_options[vh_banner_heading]' type='text' value='{$this->options['vh_banner_heading']}' />";
    }

    // Logo upload
    public function vh_logo_setting() {
        echo '<input class="regular-text code" type="file" name="vh_logo_upload" /><br /><br/>';

        if ( isset( $this->options['vh_logo'] ) ) {
            echo "<img src='{$this->options['vh_logo']}' />";
        }
    }
    // Hero Image upload
    public function vh_hero_image_setting() {
        //echo '<input class="regular-text code" type="file" name="vh_hero_image_upload" /><br /><br/>';
        echo'<p>
                                <img class="header_logo" src="'.$this->options['vh_hero_image'].'" height="100" width="100"/>
                                <input class="header_logo_url" type="text" name="vh_plugin_options[vh_hero_image]" size="60" value="'.$this->options['vh_hero_image'].'">
                                <a href="#" class="header_logo_upload">Upload</a>
                
                </p>';

        /*if ( isset( $this->options['vh_hero_image'] ) ) {
            echo "<img src='{$this->options['vh_hero_image']}' />";
        }*/
    }

    // Color scheme
    public function vh_color_scheme_setting() {
        $items = array('Red', 'Green', 'Blue', 'Yellow');
        echo "<select name='vh_plugin_options[vh_color_scheme]'>";

        foreach ( $items as $item ) {
            // Check if the selected item is the same in db and store it in a variable
            $selected = ( $this->options['vh_color_scheme'] === $item) ? 'selected' : '';
            echo "<option value='{$item}' $selected>{$item}</option>";
        }

        echo "</select>";

    }

}

/*add_action( 'admin_menu', function() {
	VH_Options::add_menu_page();
} );*/

// When admin loads, create a new instance of class VH_Options to make $this available
add_action( 'admin_init', function() {
    new VH_Options();
} );