<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Qwoo_Technical_Settings {

    private static $option_key     = 'qwoo_technical_settings';
    private static $api_keys_option = 'qwoo_api_keys';

    // All API key field definitions
    private static $api_key_fields = [
        'vapid' => [
            'label'  => 'Push Notifications (VAPID)',
            'fields' => [
                'VAPID_API_PUBLIC_KEY'  => 'VAPID Public Key',
                'VAPID_API_PRIVATE_KEY' => 'VAPID Private Key',
            ]
        ],
        'github' => [
            'label'  => 'GitHub Integration',
            'fields' => [
                'GITHUB_REPO_OWNER' => 'Repository Owner',
                'GITHUB_REPO_NAME'  => 'Repository Name',
                'GITHUB_TOKEN'      => 'GitHub Token',
            ]
        ],
        'google' => [
            'label'  => 'Google OAuth',
            'fields' => [
                'GOOGLE_CLIENT_ID'     => 'Client ID',
                'GOOGLE_CLIENT_SECRET' => 'Client Secret',
                'GOOGLE_REDIRECT_URI'  => 'Redirect URI',
            ]
        ],
    ];

    public function __construct() {
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_qwoo_save_technical_settings', [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_qwoo_reset_api_key',           [ $this, 'ajax_reset_api_key' ] );
        add_action( 'rest_api_init', [ $this, 'setup_cors_headers' ], 15 );
    }

    /* ─────────────────────────────────────────
    PHP-level CORS fallback (works regardless
    of .htaccess writability or Apache/Nginx)
    ───────────────────────────────────────── */
    public function setup_cors_headers() {
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        add_filter( 'rest_pre_serve_request', [ $this, 'send_cors_headers' ] );
    }

    public function send_cors_headers( $value ) {
        $origin  = get_http_origin();
        $allowed = self::get_allowed_origins();

        if ( $origin && in_array( rtrim( $origin, '/' ), $allowed, true ) ) {
            header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE' );
            header( 'Access-Control-Allow-Headers: Cart-Token, Content-Type, Authorization, X-Requested-With, X-WP-Nonce' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Vary: Origin' );
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
            status_header( 204 );
            exit;
        }

        return $value;
    }

    /* ─────────────────────────────────────────
       Encryption helpers
    ───────────────────────────────────────── */
    /* ─────────────────────────────────────────
       Encryption helpers
    ───────────────────────────────────────── */
    private static function encrypt( $value ) {
        if ( empty( $value ) ) return '';
        $key    = self::get_encryption_key();
        $iv     = openssl_random_pseudo_bytes( 12 ); // 96-bit IV, recommended size for GCM
        $tag    = '';
        $cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

        if ( false === $cipher ) return '';

        // 'gcm:' prefix lets decrypt() tell new-format values apart from
        // legacy AES-256-CBC values still sitting in the DB.
        return 'gcm:' . base64_encode( $iv . $tag . $cipher );
    }

    private static function decrypt( $value ) {
        if ( empty( $value ) ) return '';
        $key = self::get_encryption_key();

        if ( strpos( $value, 'gcm:' ) === 0 ) {
            $raw = base64_decode( substr( $value, 4 ) );
            if ( false === $raw || strlen( $raw ) < 12 + 16 ) return '';

            $iv     = substr( $raw, 0, 12 );
            $tag    = substr( $raw, 12, 16 );
            $cipher = substr( $raw, 28 );

            $plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
            return false === $plain ? '' : $plain;
        }

        // Legacy AES-256-CBC format — kept only so keys saved before this
        // upgrade still decrypt correctly. Anything re-saved through the
        // settings UI from now on is written back out as GCM.
        $parts = explode( '::', base64_decode( $value ), 2 );
        if ( count( $parts ) !== 2 ) return '';
        [ $iv, $cipher ] = $parts;

        $plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
        return false === $plain ? '' : $plain;
    }

    private static function get_encryption_key() {
        // Use WordPress AUTH_KEY as the encryption secret — lives in wp-config.php, never in DB
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'qwoo-fallback-key-change-me';
        return hash( 'sha256', $salt, true );
    }

    /* ─────────────────────────────────────────
       Public: get a decrypted key by constant name
       Called from security.php / other includes
    ───────────────────────────────────────── */
    public static function get_key( $constant_name ) {
        // Prefer wp-config.php constant if defined
        if ( defined( $constant_name ) ) {
            return constant( $constant_name );
        }
        $stored = get_option( self::$api_keys_option, [] );
        if ( ! empty( $stored[ $constant_name ] ) ) {
            return self::decrypt( $stored[ $constant_name ] );
        }
        return '';
    }

    /* ─────────────────────────────────────────
       Public: get CORS settings
    ───────────────────────────────────────── */
    public static function get_allowed_origins() {
        $settings = get_option( self::$option_key, [] );
        $origins  = [];

        $site_origin = self::get_site_origin();
        if ( $site_origin ) {
            $origins[] = $site_origin;
        }

        if ( ! empty( $settings['frontend_domain'] ) ) {
            $origins[] = rtrim( $settings['frontend_domain'], '/' );
        }

        if ( ! empty( $settings['localhost_enabled'] ) && ! empty( $settings['localhost_port'] ) ) {
            $origins[] = 'https://localhost:' . intval( $settings['localhost_port'] );
        }

        return $origins;
    }

    /* ─────────────────────────────────────────
       Public: get push notification sender email
    ───────────────────────────────────────── */
    public static function get_push_email() {
        $settings = get_option( self::$option_key, [] );
        // Fall back to WordPress admin email if not set
        return ! empty( $settings['push_email'] ) ? $settings['push_email'] : get_option( 'admin_email' );
    }

    /* ─────────────────────────────────────────
       Register Settings
    ───────────────────────────────────────── */
    public function register_settings() {
        register_setting( 'qwoo_technical_group', self::$option_key );
    }

    /* ─────────────────────────────────────────
       Enqueue Assets
    ───────────────────────────────────────── */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'qwoo-technical-settings' ) === false ) return;

        wp_enqueue_style(
            'qwoo-technical-settings',
            QWOO_URL . 'assets/admin/css/technical-settings.css',
            [],
            QWOO_VERSION
        );

        wp_enqueue_script(
            'qwoo-technical-settings',
            QWOO_URL . 'assets/admin/js/technical-settings.js',
            [ 'jquery' ],
            QWOO_VERSION,
            true
        );

        wp_localize_script( 'qwoo-technical-settings', 'qwooTech', [
            'nonce'    => wp_create_nonce( 'qwoo_technical_nonce' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    /* ─────────────────────────────────────────
       AJAX: Save Settings
    ───────────────────────────────────────── */
    public function ajax_save_settings() {
        check_ajax_referer( 'qwoo_technical_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // ── Save CORS / general settings ──
        $posted   = $_POST['qwoo_technical'] ?? [];
        $settings = [
            'frontend_domain'   => esc_url_raw( $posted['frontend_domain'] ?? '' ),
            'localhost_enabled' => ! empty( $posted['localhost_enabled'] ),
            'localhost_port'    => absint( $posted['localhost_port'] ?? 9000 ),
            'push_email'        => sanitize_email( $posted['push_email'] ?? '' ),
        ];
        update_option( self::$option_key, $settings );

        // ── Save API keys (encrypt, skip placeholder values) ──
        $existing_keys = get_option( self::$api_keys_option, [] );
        $posted_keys   = $_POST['qwoo_api_keys'] ?? [];

        foreach ( $posted_keys as $key_name => $value ) {
            $value = sanitize_text_field( $value );
            // Skip if empty or still masked (user didn't change it)
            if ( empty( $value ) || $value === '••••••••' ) continue;
            $existing_keys[ $key_name ] = self::encrypt( $value );
        }
        update_option( self::$api_keys_option, $existing_keys );

        // ── Write .htaccess ──
        $result = $this->write_htaccess( $settings );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( 'Settings saved but .htaccess could not be updated: ' . $result->get_error_message() );
        }

        wp_send_json_success( 'Settings saved successfully.' );
    }

    /* ─────────────────────────────────────────
       AJAX: Reset a single API key
    ───────────────────────────────────────── */
    public function ajax_reset_api_key() {
        check_ajax_referer( 'qwoo_technical_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $key_name = sanitize_key( $_POST['key_name'] ?? '' );
        if ( empty( $key_name ) ) {
            wp_send_json_error( 'Invalid key name' );
        }

        $existing = get_option( self::$api_keys_option, [] );
        unset( $existing[ $key_name ] );
        update_option( self::$api_keys_option, $existing );

        wp_send_json_success( 'Key reset.' );
    }

    /* ─────────────────────────────────────────
       Write .htaccess
    ───────────────────────────────────────── */
    public function write_htaccess( $settings ) {
        $htaccess = get_home_path() . '.htaccess';

        if ( ! is_writable( $htaccess ) ) {
            return new WP_Error( 'not_writable', '.htaccess is not writable.' );
        }

        $origins = [];

        // Always allow the site's own origin (fixes admin/dashboard REST calls)
        $site_origin = $this->get_site_origin();
        if ( $site_origin ) {
            $origins[] = preg_quote( $site_origin, '!' );
        }

        if ( ! empty( $settings['frontend_domain'] ) ) {
            $escaped = preg_quote( rtrim( $settings['frontend_domain'], '/' ), '!' );
            if ( ! in_array( $escaped, $origins, true ) ) {
                $origins[] = $escaped;
            }
        }
        if ( ! empty( $settings['localhost_enabled'] ) && ! empty( $settings['localhost_port'] ) ) {
            $port    = intval( $settings['localhost_port'] );
            $literal = 'https://localhost:' . $port;
            $escaped = preg_quote( $literal, '!' );
            if ( ! in_array( $escaped, $origins, true ) ) {
                $origins[] = $escaped;
            }
        }

        if ( empty( $origins ) ) {
            insert_with_markers( $htaccess, 'qwoo-core', [] );
            return true;
        }

        $rules = [ '<IfModule mod_headers.c>', '<LocationMatch "^/wp-json/">', '    RewriteEngine On', '' ];

        $rules[] = '    RewriteCond %{HTTP_ORIGIN} !=""';
        foreach ( $origins as $origin ) {
            $rules[] = '    RewriteCond %{HTTP_ORIGIN} !^' . $origin . '$';
        }
        $rules[] = '    RewriteRule ^ - [F,L]';
        $rules[] = '';

        foreach ( $origins as $origin ) {
            $rules[] = '    SetEnvIf Origin "^' . $origin . '$" ACAO=$0';
        }

        $rules = array_merge( $rules, [
                '    Header always set Access-Control-Allow-Origin "%{ACAO}e" env=ACAO',
                '    Header always set Access-Control-Allow-Methods "GET, POST, OPTIONS, PUT, DELETE"',
                '    Header always set Access-Control-Allow-Headers "Cart-Token, Content-Type, Authorization, X-Requested-With, X-WP-Nonce"',
                '    Header always set Access-Control-Allow-Credentials "true"',
                '',
                '    RewriteCond %{REQUEST_METHOD} OPTIONS',
                '    RewriteRule ^ - [R=204,L]',
                '</LocationMatch>',
                '</IfModule>',
        ] );

        insert_with_markers( $htaccess, 'qwoo-core', $rules );
        return true;
    }

    /**
     * Get the scheme+host(+port) of this WordPress install, suitable
     * for use as an allowed CORS origin, escaped for a regex literal
     * match (minus the preg_quote, done by the caller).
     */
    public static function get_site_origin() {
        $home = home_url();
        $parts = wp_parse_url( $home );

        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if ( ! empty( $parts['port'] ) ) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /* ─────────────────────────────────────────
       Remove .htaccess rules (on deactivation)
    ───────────────────────────────────────── */
    public static function remove_htaccess() {
        $htaccess = get_home_path() . '.htaccess';
        if ( file_exists( $htaccess ) ) {
            insert_with_markers( $htaccess, 'qwoo-core', [] );
        }
    }

    /* ─────────────────────────────────────────
       Settings Page HTML
    ───────────────────────────────────────── */
    public function render_page() {
        $settings    = get_option( self::$option_key, [] );
        $stored_keys = get_option( self::$api_keys_option, [] );
        ?>
        <div class="wrap qwoo-tech-wrap">
            <div class="qwoo-tech-header">
                <div class="qwoo-tech-header__logo">⚙</div>
                <div>
                    <h1>Technical Settings</h1>
                    <p class="qwoo-tech-header__sub">CORS, security, and API configuration for your headless setup</p>
                </div>
            </div>

            <div id="qwoo-save-notice" class="qwoo-notice" style="display:none;"></div>

            <div class="qwoo-tech-grid">

                <!-- ── CORS & Domains ── -->
                <div class="qwoo-card">
                    <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">🌐</span>
                        <div>
                            <h2>CORS & Allowed Origins</h2>
                            <p>Controls which domains can access your REST API. Updates <code>.htaccess</code> automatically on save.</p>
                        </div>
                    </div>

                    <div class="qwoo-field">
                        <label for="frontend_domain">Frontend Domain</label>
                        <input
                            type="url"
                            id="frontend_domain"
                            name="qwoo_technical[frontend_domain]"
                            value="<?php echo esc_attr( $settings['frontend_domain'] ?? '' ); ?>"
                            placeholder="https://your-app.com"
                            class="qwoo-input"
                        />
                        <span class="qwoo-hint">The URL of your headless frontend app (no trailing slash)</span>
                    </div>

                    <div class="qwoo-field">
                        <label class="qwoo-toggle-label">
                            <input
                                type="checkbox"
                                id="localhost_enabled"
                                name="qwoo_technical[localhost_enabled]"
                                value="1"
                                <?php checked( ! empty( $settings['localhost_enabled'] ) ); ?>
                            />
                            <span class="qwoo-toggle"></span>
                            Allow localhost (development environment)
                        </label>
                    </div>

                    <div class="qwoo-field qwoo-localhost-port <?php echo empty( $settings['localhost_enabled'] ) ? 'qwoo-hidden' : ''; ?>">
                        <label for="localhost_port">Localhost Port</label>
                        <input
                            type="number"
                            id="localhost_port"
                            name="qwoo_technical[localhost_port]"
                            value="<?php echo esc_attr( $settings['localhost_port'] ?? 9000 ); ?>"
                            min="1"
                            max="65535"
                            class="qwoo-input qwoo-input--short"
                            placeholder="9000"
                        />
                        <span class="qwoo-hint">e.g. 9000 → allows <code>https://localhost:9000</code></span>
                    </div>
                </div>

                <!-- ── Abandoned Cart Cron ── -->
                <div class="qwoo-card">
                    <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">⏱</span>
                        <div>
                            <h2>Abandoned Cart Cron</h2>
                            <p>Paste this exact URL into your host's cron job panel (or crontab) to run abandoned-cart checks on a schedule. The security key below is generated automatically — you never need to set one yourself.</p>
                        </div>
                    </div>

                    <div class="qwoo-field">
                        <label>Cron URL</label>
                        <div class="qwoo-masked-row">
                            <input
                                type="text"
                                class="qwoo-input"
                                value="<?php echo esc_url( qwoo_get_cron_url() ); ?>"
                                readonly
                                onclick="this.select();"
                            />
                        </div>
                        <span class="qwoo-hint">Typical host setup: run this URL via <code>curl</code> or <code>wget</code> every 15–60 minutes. Requests without the correct key are rejected automatically.</span>
                    </div>
                </div>

                <!-- ── API Keys ── -->
                <?php foreach ( self::$api_key_fields as $group_key => $group ) : ?>
                <div class="qwoo-card">
                    <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">
                            <?php echo $group_key === 'vapid' ? '🔔' : ( $group_key === 'github' ? '🐙' : '🔑' ); ?>
                        </span>
                        <div>
                            <h2><?php echo esc_html( $group['label'] ); ?></h2>
                            <p>Keys are encrypted before storage using your WordPress secret key.</p>
                        </div>
                    </div>

                    <?php if ( $group_key === 'vapid' ) : ?>
                    <div class="qwoo-field">
                        <label for="push_email">Notification Sender Email</label>
                        <input
                            type="email"
                            id="push_email"
                            name="qwoo_technical[push_email]"
                            value="<?php echo esc_attr( $settings['push_email'] ?? get_option( 'admin_email' ) ); ?>"
                            placeholder="your@email.com"
                            class="qwoo-input"
                        />
                        <span class="qwoo-hint">Used as the VAPID <code>subject</code> — identifies who is sending push notifications. Defaults to your WordPress admin email.</span>
                    </div>
                    <?php endif; ?>

                    <?php foreach ( $group['fields'] as $const_name => $field_label ) :
                        $is_set = ! empty( $stored_keys[ $const_name ] ) || defined( $const_name );
                        $from_config = defined( $const_name );
                    ?>
                    <div class="qwoo-field qwoo-key-field" data-key="<?php echo esc_attr( $const_name ); ?>">
                        <label><?php echo esc_html( $field_label ); ?>
                            <?php if ( $from_config ) : ?>
                                <span class="qwoo-badge qwoo-badge--config">wp-config.php</span>
                            <?php elseif ( $is_set ) : ?>
                                <span class="qwoo-badge qwoo-badge--set">Saved</span>
                            <?php else : ?>
                                <span class="qwoo-badge qwoo-badge--unset">Not set</span>
                            <?php endif; ?>
                        </label>

                        <?php if ( $from_config ) : ?>
                            <div class="qwoo-config-note">
                                Defined in <code>wp-config.php</code> — plugin will use that value automatically.
                            </div>
                        <?php elseif ( $is_set ) : ?>
                            <div class="qwoo-masked-row">
                                <input type="text" class="qwoo-input qwoo-input--masked" value="••••••••••••••••" readonly />
                                <button
                                    type="button"
                                    class="qwoo-btn qwoo-btn--ghost qwoo-reset-key"
                                    data-key="<?php echo esc_attr( $const_name ); ?>"
                                >Reset</button>
                            </div>
                        <?php else : ?>
                            <input
                                type="text"
                                name="qwoo_api_keys[<?php echo esc_attr( $const_name ); ?>]"
                                class="qwoo-input qwoo-input--key"
                                placeholder="Enter <?php echo esc_attr( $field_label ); ?>"
                                autocomplete="off"
                            />
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

            </div><!-- /.qwoo-tech-grid -->

            <!-- ── Save Button ── -->
            <div class="qwoo-tech-footer">
                <button type="button" id="qwoo-save-technical" class="qwoo-btn qwoo-btn--primary">
                    <span class="qwoo-btn__text">Save Settings</span>
                    <span class="qwoo-btn__loader" style="display:none;">Saving…</span>
                </button>
            </div>
        </div>
        <?php
    }
}