<?php
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
// Load WordPress
require dirname(__FILE__, 5) . '/wp-load.php'; // navigate up to WP root
require_once dirname(__FILE__, 2) . '/includes/vendor/autoload.php';

// Only WordPress's own auto-generated secret may trigger this file.
// See qwoo_get_cron_url() in qwoo-core.php for the ready-to-use URL —
// it's shown in the admin settings screen, no manual setup required.
if ( ! hash_equals( qwoo_get_cron_secret(), $_GET['secret'] ?? '' ) ) {
    http_response_code(403);
    exit('Forbidden');
}

function get_cart_item_count_by_token($cart_token)
{
    // 1. Tell WooCommerce to specifically look for this session
    // This is the "Magic" part for Cron jobs
    add_filter('woocommerce_store_api_cart_token', function () use ($cart_token) {
        return $cart_token;
    });

    $url = get_rest_url(null, 'wc/store/cart');

    $response = wp_remote_get($url, [
        'headers' => [
            'Cart-Token' => $cart_token,
            'Content-Type' => 'application/json',
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        return 0;
    }

    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);

    if (empty($body['items']) || !is_array($body['items'])) {
        return 0;
    }

    return count($body['items']);
}

if(!function_exists('get_fcm_access_token')) {
    function get_fcm_access_token()
    {
        $serviceAccountPath = QWOO_FIREBASE_SA_PATH;

        if (!file_exists($serviceAccountPath)) {
            throw new Exception('Firebase service account file not found');
        }

        $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

        $now = time();

        $jwtHeader = base64url_encode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $jwtClaimSet = base64url_encode(json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $serviceAccount['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $unsignedJwt = $jwtHeader . '.' . $jwtClaimSet;

        openssl_sign($unsignedJwt, $signature, $serviceAccount['private_key'], 'sha256');

        $jwt = $unsignedJwt . '.' . base64url_encode($signature);

        $response = wp_remote_post($serviceAccount['token_uri'], [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            throw new Exception('Failed to obtain FCM access token');
        }

        return $body['access_token'];
    }
}
function crone_check_and_send_abandoned_cart_push() {
    global $wpdb;

    $table = $wpdb->prefix . 'pwa_push_subscriptions';

    $threshold_minutes = 1;

    $subs = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT *
            FROM $table
            WHERE cart_token IS NOT NULL
            AND last_seen_at IS NOT NULL
            AND last_seen_at <= DATE_SUB(NOW(), INTERVAL %d MINUTE)
            ",
            $threshold_minutes
        ),
        ARRAY_A
    );

    if (empty($subs)) {
        return;
    }

    foreach ($subs as $sub) {

        $item_count = get_cart_item_count_by_token($sub['cart_token']);

        if ($item_count <= 0) {
            $wpdb->update(
                $table,
                ['last_seen_at' => null],
                ['id' => $sub['id']]
            );
            continue;
        }

        crone_send_abandoned_cart_push($sub);

        // Prevent repeat sends
        $wpdb->update(
            $table,
            ['last_seen_at' => null],
            ['id' => $sub['id']]
        );
    }
}

/**
 * Updated to take the specific subscription array directly
 */
function crone_send_abandoned_cart_push($subscription_data)
{
    if (!empty($subscription_data['p256dh'])) {
        $tech_settings  = get_option( 'qwoo_technical_settings', [] );
        $frontend_url   = ! empty( $tech_settings['frontend_domain'] )
            ? $tech_settings['frontend_domain']
            : 'about:blank';

        $auth = [
            'VAPID' => [
                'subject' => 'mailto:meidanmuzrafi@gmail.com',
                'publicKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PUBLIC_KEY' ),
                'privateKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PRIVATE_KEY' ),
            ]
        ];

        $payload = json_encode([
            'notification' => [
                'title' => '🛒 You left something behind!',
                'body' => 'Your cart is waiting — complete your purchase before items run out!',
                'data' => ['url' => '/cart/'],
                'icon' => $frontend_url.'/icons/icon-128x128.png',
                'badge' => $frontend_url.'/icons/favicon-32x32.png',
                'vibrate' => [200, 100, 200],
                'requireInteraction' => true,
            ]
        ]);

        $webPush = new WebPush($auth, ['timeout' => 5]);

        try {
            $subscription = Subscription::create([
                'endpoint' => $subscription_data['endpoint'],
                'keys' => [
                    'p256dh' => $subscription_data['p256dh'],
                    'auth' => $subscription_data['auth_key'],
                ]
            ]);

            $webPush->queueNotification($subscription, $payload);

            foreach ($webPush->flush() as $report) {
                /*if ($report->isSuccess()) {
                    error_log("✅ Push succeeded for device: " . ($subscription_data['device_id'] ?? 'unknown'));
                } else {
                    error_log("❌ Push failed: " . $report->getReason());
                    // Optional: If reason is 410 (Gone), you could remove it from the main list here
                }*/
            }
        } catch (Exception $e) {
            error_log("❌ WebPush Error: " . $e->getMessage());
        }
    } else {
        $accessToken = get_fcm_access_token(); // you already implemented this

        if (empty($subscription_data['endpoint']) || strlen($subscription_data['endpoint']) < 50) {
            throw new Exception('Invalid FCM token');
        }

        $message = [
            'message' => [
                'token' => $subscription_data['endpoint'],
                'notification' => [
                    'title' => '🛒 You left something behind!',
                    'body' => 'Your cart is waiting — complete your purchase before items run out!',
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'icon' => 'ic_stat_notify', // must exist in Android resources
                        'color' => '#FFFFFF',
                        'default_vibrate_timings' => true,
                        'channel_id' => 'abandoned_cart',
                        'tag' => 'abandoned_cart',
                    ],
                ],
                'data' => [
                    'url' => '/cart/',
                    'type' => 'abandoned_cart',
                ],
            ],
        ];

        $response = wp_remote_post(
            'https://fcm.googleapis.com/v1/projects/naturabloom-push/messages:send',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($message),
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response)) {
            //error_log('[Native Push] WP_Error: ' . $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
        }

    }
}

// Execute
crone_check_and_send_abandoned_cart_push();