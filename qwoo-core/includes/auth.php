<?php
/**
 * Qwoo Auth
 * Handles login, logout, user endpoints, and cookie-based REST authentication.
 */

function qwoo_get_authenticated_user(): WP_User|false {
    $user_id = wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE] ?? '', 'logged_in');
    if (!$user_id) return false;

    // Also verify the session token is still alive server-side
    $manager = WP_Session_Tokens::get_instance($user_id);
    $token   = wp_get_session_token();  // extracts token from the current cookie

    if (!$manager->verify($token)) return false;
    wp_set_current_user($user_id);

    return get_userdata($user_id);
}

function qwoo_require_login()
{
    if (!is_user_logged_in()) {
        return new WP_Error(
            'rest_forbidden',
            __('Authentication required.'),
            ['status' => 401]
        );
    }

    return true;
}

// ─── Register Routes ──────────────────────────────────────────────────────────
add_action('rest_api_init', function () {
    register_rest_route('qwoo/v1', '/nonce', [
        'methods' => 'GET',
        'callback' => function () {
            qwoo_get_authenticated_user();
            $nonce = wp_create_nonce('wp_rest');
            return new WP_REST_Response([
                'nonce' => $nonce
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});
add_action('rest_api_init', function () {

    // Login
    register_rest_route('qwoo/v1', '/login', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_login',
        'permission_callback' => function () {
            return qwoo_rate_limit_check('login', 10, 5 * MINUTE_IN_SECONDS);
        },
        'args'                => [
            'username' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'password' => [
                'required' => true,
                'type'     => 'string',
                // Do NOT sanitize passwords — strips valid special characters
            ],
        ],
    ]);

    // Logout
    register_rest_route('qwoo/v1', '/logout', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_logout',
        'permission_callback' => 'qwoo_require_login'
    ]);

    // Me — GET and POST
    register_rest_route('qwoo/v1', '/me', [
        [
            'methods'             => 'GET',
            'callback'            => 'qwoo_get_me',
            'permission_callback' => 'qwoo_require_login'
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'qwoo_update_me',
            'permission_callback' => 'qwoo_require_login',
            'args'                => [
                'first_name' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'last_name' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ],
    ]);

    // Google Login
    register_rest_route('qwoo/v1', '/google-login', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_google_login',
        'permission_callback' => function () {
            return qwoo_rate_limit_check('google_login', 10, 5 * MINUTE_IN_SECONDS);
        },
    ]);

    // Google Login Redirect (OAuth code exchange)
    register_rest_route('qwoo/v1', '/google-login-redirect', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_google_login_redirect',
        'permission_callback' => function () {
            return qwoo_rate_limit_check('google_login', 10, 5 * MINUTE_IN_SECONDS);
        },
    ]);

});

// ─── Login ────────────────────────────────────────────────────────────────────

function qwoo_handle_login(WP_REST_Request $request): WP_REST_Response {
    $username = $request->get_param('username');
    $password = $request->get_param('password');

    $user = wp_signon(
        [
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        ],
        true // always secure — both localhost (via proxy) and production are HTTPS
    );

    if (is_wp_error($user)) {
        $code = $user->get_error_code();

        $messages = [
            'invalid_username'   => 'No account found with that username or email.',
            'invalid_email'      => 'No account found with that email address.',
            'incorrect_password' => 'Incorrect password. Please try again.',
            'empty_username'     => 'Please enter your username or email.',
            'empty_password'     => 'Please enter your password.',
            'invalidcombo'       => 'Incorrect username or password.',
        ];

        return new WP_REST_Response(
            [
                'success' => false,
                'code'    => $code,
                'message' => $messages[$code] ?? 'Login failed. Please check your credentials and try again.',
            ],
            401
        );
    }

    return new WP_REST_Response(
        [
            'success' => true,
            'user'    => qwoo_get_user_data($user->ID),
        ],
        200
    );
}

function qwoo_merge_persistent_cart_after_login(int $user_id): void {
    if (!function_exists('WC') || !WC()->cart) return;

    $meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
    $saved    = get_user_meta($user_id, $meta_key, true);

    if (empty($saved['cart']) || !is_array($saved['cart'])) return;

    $current_contents = WC()->cart->get_cart();

    foreach ($saved['cart'] as $item) {
        // Build the same signature WC uses internally so we don't duplicate
        // a line that's already present in the guest cart.
        $cart_id = WC()->cart->generate_cart_id(
            $item['product_id'] ?? 0,
            $item['variation_id'] ?? 0,
            $item['variation'] ?? [],
            $item['cart_item_data'] ?? []
        );

        if (isset($current_contents[$cart_id])) continue;

        WC()->cart->add_to_cart(
            $item['product_id'] ?? 0,
            $item['quantity'] ?? 1,
            $item['variation_id'] ?? 0,
            $item['variation'] ?? []
        );
    }

    WC()->cart->calculate_totals();
}
// ─── Logout ───────────────────────────────────────────────────────────────────

function qwoo_handle_logout(): WP_REST_Response {
    $user_id = wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE] ?? '', 'logged_in');

    if (!$user_id) {
        return new WP_REST_Response(
            ['success' => false, 'message' => 'No active session.'],
            400
        );
    }

    wp_logout();

    return new WP_REST_Response(['success' => true], 200);
}

// ─── Me — GET ─────────────────────────────────────────────────────────────────

function qwoo_get_me(): WP_REST_Response
{
    $user_id = get_current_user_id();

    return new WP_REST_Response(
        ['success' => true, 'user' => qwoo_get_user_data($user_id)],
        200
    );
}

// ─── Me — POST ────────────────────────────────────────────────────────────────

function qwoo_update_me(WP_REST_Request $request): WP_REST_Response
{
    $user_id = get_current_user_id();

    $updates = ['ID' => $user_id];

    $first_name = $request->get_param('first_name');
    $last_name = $request->get_param('last_name');

    if ($first_name !== null) $updates['first_name'] = $first_name;
    if ($last_name !== null) $updates['last_name'] = $last_name;

    if (count($updates) === 1) {
        return new WP_REST_Response(
            ['success' => false, 'message' => 'No fields provided to update.'],
            400
        );
    }

    $result = wp_update_user($updates);

    if (is_wp_error($result)) {
        return new WP_REST_Response(
            ['success' => false, 'message' => 'Failed to update profile. Please try again.'],
            500
        );
    }

    return new WP_REST_Response(
        [
            'success' => true,
            'user' => qwoo_get_user_data($user_id),
        ],
        200
    );
}

// ─── Google Login ─────────────────────────────────────────────────────────────

function qwoo_handle_google_login(WP_REST_Request $request): WP_REST_Response {
    $token = sanitize_text_field($request->get_param('token') ?? '');

    if (!$token) {
        return new WP_REST_Response(['success' => false, 'message' => 'Missing token'], 400);
    }

    // Verify token with Google
    $response = wp_remote_get("https://oauth2.googleapis.com/tokeninfo?id_token={$token}");
    if (is_wp_error($response)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Google token validation failed'], 401);
    }

    $data = json_decode(wp_remote_retrieve_body($response));
    if (!isset($data->email) || $data->email_verified !== "true") {
        return new WP_REST_Response(['success' => false, 'message' => 'Invalid Google token'], 401);
    }

    if (!hash_equals(Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_ID' ), $data->aud ?? '')) {
        return new WP_REST_Response(['success' => false, 'message' => 'Token not issued for this app'], 401);
    }

    // Get or create user
    $user = get_user_by('email', $data->email);

    if (!$user) {
        $email      = sanitize_email($data->email);
        $base_name  = sanitize_user(current(explode('@', $email)), true);
        $username   = username_exists($base_name)
            ? $base_name . wp_generate_password(4, false)
            : $base_name;
        $first_name = ucfirst($base_name);

        $user_id = wp_create_user($username, wp_generate_password(), $email);

        if (is_wp_error($user_id)) {
            return new WP_REST_Response(['success' => false, 'message' => 'User creation failed'], 500);
        }

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'display_name' => $first_name,
        ]);

        wp_new_user_notification($user_id, null, 'user');

        $user = get_userdata($user_id);
    }

    // Log the user in via WP session cookie — same as regular login
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    return new WP_REST_Response(
        [
            'success' => true,
            'user'    => qwoo_get_user_data($user->ID),
        ],
        200
    );
}

// ─── Google Login Redirect (OAuth code exchange) ──────────────────────────────

function qwoo_handle_google_login_redirect(WP_REST_Request $request): WP_REST_Response {
    $code = sanitize_text_field($request->get_param('code') ?? '');

    if (!$code) {
        return new WP_REST_Response(['success' => false, 'message' => 'Missing code'], 400);
    }

    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'body' => [
            'code'          => $code,
            'client_id'     => Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_ID' ),
            'client_secret' => Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_SECRET' ),
            'redirect_uri'  => Qwoo_Technical_Settings::get_key( 'GOOGLE_REDIRECT_URI' ),
            'grant_type'    => 'authorization_code',
        ],
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    ]);

    if (is_wp_error($response)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Failed to exchange code'], 500);
    }

    $tokens = json_decode(wp_remote_retrieve_body($response));

    if (!isset($tokens->id_token)) {
        return new WP_REST_Response(['success' => false, 'message' => 'No ID token returned'], 401);
    }

    $request->set_param('token', $tokens->id_token);

    return qwoo_handle_google_login($request);
}

// ─── Helper: Clean user payload ───────────────────────────────────────────────

function qwoo_get_user_data(int $user_id): array {
    $user = get_userdata($user_id);
    if (!$user) {
        return [];
    }

    return [
        'id'         => $user->ID,
        'email'      => $user->user_email,
        'first_name' => $user->first_name,
        'last_name'  => $user->last_name,
        'is_admin'   => user_can($user, 'manage_woocommerce'),
    ];
}