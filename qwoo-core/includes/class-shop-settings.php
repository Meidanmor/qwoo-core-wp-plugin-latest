<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Shop_Settings_Builder {

    /**
     * Whitelisted homepage section types and their field schema.
     * Each field maps to a sanitizer key handled in sanitize_section_data().
     * Add new section types here + a matching case in sanitize_section_data()
     * + a matching template/renderer in shop-builder.js + a matching Vue
     * component in the frontend's SectionRenderer.
     */
    const SECTION_SCHEMA = [
            'banner' => [
                    'label'  => 'Promo Banner',
                    'fields' => [
                            'text'       => 'text',
                            'link_url'   => 'url',
                            'link_text'  => 'text',
                            'bg_color'   => 'color',
                            'text_color' => 'color',
                    ],
            ],
            'newsletter_signup' => [
                    'label'  => 'Newsletter Signup',
                    'fields' => [
                            'title'       => 'text',
                            'subtitle'    => 'text',
                            'button_text' => 'text',
                    ],
            ],
            'category_grid' => [
                    'label'  => 'Category Grid',
                    'fields' => [
                            'title'         => 'text',
                            'category_ids'  => 'int_array',
                    ],
            ],
            'testimonials' => [
                    'label'  => 'Testimonials',
                    'fields' => [
                            'title' => 'text',
                            'items' => 'testimonial_items',
                    ],
            ],
    ];

    // HTML allowed inside hero_title (and other rich-text fields) — safe
    // inline formatting only, no scripts/attributes that could inject JS.
    const RICH_TEXT_ALLOWED_TAGS = [
            'span'   => [ 'class' => [], 'style' => [] ],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'br'     => [],
    ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'register_shop_settings' ] );
        add_action( 'rest_api_init',         [ $this, 'register_preview_endpoint' ] );
        add_action( 'wp_ajax_save_shop_builder_draft',      [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_push_to_github',               [ $this, 'handle_github_push' ] );
        add_action( 'wp_ajax_shop_builder_product_search',  [ $this, 'add_featured_products_search_ajax' ] );
        add_action( 'wp_ajax_shop_builder_category_search', [ $this, 'ajax_category_search' ] );
        add_action( 'wp_ajax_shop_builder_generate_icons',  [ $this, 'ajax_generate_and_sync_icons' ] );
    }

    /* -------------------------
       Product Search AJAX
       ------------------------- */
    public function add_featured_products_search_ajax() {
        check_ajax_referer( 'shop_builder_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';

        $products = wc_get_products( [
                'limit'  => 20,
                'status' => 'publish',
                's'      => $term,
        ] );

        $results = [];
        foreach ( $products as $product ) {
            $results[] = [
                    'id'    => $product->get_id(),
                    'text'  => $product->get_name(),
                    'thumb' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' )
            ];
        }

        wp_send_json( $results );
    }

    /* -------------------------
       Category Search AJAX (for the Category Grid section)
       ------------------------- */
    public function ajax_category_search() {
        check_ajax_referer( 'shop_builder_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';

        $terms = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'name__like' => $term,
                'number'     => 20,
        ] );

        $results = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term_obj ) {
                $thumb_id = get_term_meta( $term_obj->term_id, 'thumbnail_id', true );
                $results[] = [
                        'id'    => $term_obj->term_id,
                        'text'  => $term_obj->name,
                        'thumb' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '',
                ];
            }
        }

        wp_send_json( $results );
    }

    /* -------------------------
       Icon set generation + sync AJAX
       ------------------------- */
    public function ajax_generate_and_sync_icons() {
        check_ajax_referer( 'shop_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $options = get_option( 'shop_builder_options', [] );
        $icon_attachment_id = intval( $options['branding']['app_icon_id'] ?? 0 );

        if ( ! $icon_attachment_id ) {
            wp_send_json_error( 'No App Icon has been set yet. Upload one in the Branding tab and save first.' );
        }

        require_once __DIR__ . '/class-icon-generator.php';

        $result = Qwoo_Icon_Generator::generate_from_attachment( $icon_attachment_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $sync = Qwoo_Icon_Generator::sync_to_github( $result['files'] );

        if ( $sync === true ) {
            $msg = 'Icon set generated and pushed to GitHub.';
            if ( ! empty( $result['warnings'] ) ) {
                $msg .= ' Note: ' . implode( ' ', $result['warnings'] );
            }
            wp_send_json_success( $msg );
        } elseif ( $sync === 'no_changes' ) {
            wp_send_json_success( 'Icon set unchanged — nothing to push.' );
        } else {
            wp_send_json_error( 'Failed to push icon set to GitHub. Check the error log.' );
        }
    }

    /* -------------------------
       REST Preview Endpoint
       ------------------------- */
    public function register_preview_endpoint() {
        register_rest_route( 'shop-builder/v1', '/preview/(?P<page>[a-zA-Z0-9-]+)', [
                'methods'             => 'GET',
                'callback'            => function ( $data ) {
                    $options = get_option( 'shop_builder_options', [] );
                    $page    = $options[ $data['page'] ] ?? [];

                    if ( $data['page'] === 'home' && ! empty( $page['hero_image_id'] ) ) {
                        $page['hero_image'] = wp_get_attachment_url( $page['hero_image_id'] );
                        unset( $page['hero_image_id'] );
                    }

                    // ✅ FIX: this endpoint was leaking raw WP attachment IDs
                    // (logo_id / app_icon_id) straight to the frontend instead
                    // of resolving them to real URLs. Now converts
                    // logo_id -> logo and app_icon_id -> app_icon.
                    if ( $data['page'] === 'branding' ) {
                        if ( ! empty( $page['logo_id'] ) ) {
                            $page['logo'] = wp_get_attachment_url( $page['logo_id'] );
                            unset( $page['logo_id'] );
                        }
                        if ( ! empty( $page['app_icon_id'] ) ) {
                            $page['app_icon'] = wp_get_attachment_url( $page['app_icon_id'] );
                            unset( $page['app_icon_id'] );
                        }
                    }

                    return new WP_REST_Response( $page, 200 );
                },
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
        ] );
    }

    /* -------------------------
       Enqueue Assets
       ------------------------- */
    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_qwoo-settings' !== $hook ) return;

        if ( class_exists( 'WooCommerce' ) ) {
            wp_enqueue_script( 'select2' );
            wp_enqueue_style( 'select2' );
            wp_enqueue_script( 'wc-enhanced-select' );
        }

        wp_enqueue_media();
        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_enqueue_style(
                'shop-builder-style',
                QWOO_URL . 'assets/admin/css/shop-builder.css',
                [],
                QWOO_VERSION
        );

        wp_enqueue_script(
                'shop-builder-script',
                QWOO_URL . 'assets/admin/js/shop-builder.js',
                [ 'jquery', 'select2', 'jquery-ui-sortable' ],
                QWOO_VERSION,
                true
        );

        wp_localize_script( 'shop-builder-script', 'shopBuilder', [
                'nonce'          => wp_create_nonce( 'shop_builder_nonce' ),
                'ajax_url'       => admin_url( 'admin-ajax.php' ),
                'sectionSchema'  => self::SECTION_SCHEMA,
        ] );
    }

    /* -------------------------
       Admin Menu
       ------------------------- */
    public function add_admin_menu() {
        add_menu_page(
                'Q-Woo Settings',
                'Q-Woo Settings',
                'manage_options',
                'qwoo-settings',
                [ $this, 'settings_page_html' ],
                'dashicons-store',
                60
        );

        add_submenu_page(
                'qwoo-settings',
                'Shop Builder',
                'Shop Builder',
                'manage_options',
                'qwoo-settings',
                [ $this, 'settings_page_html' ]
        );

        add_submenu_page(
                'qwoo-settings',
                'Technical Settings',
                'Technical Settings',
                'manage_options',
                'qwoo-technical-settings',
                [ $this, 'render_technical_settings' ]
        );
    }

    public function render_technical_settings() {
        if ( class_exists( 'Qwoo_Technical_Settings' ) ) {
            ( new Qwoo_Technical_Settings() )->render_page();
        }
    }

    /* -------------------------
       Register Settings
       ------------------------- */
    public function register_shop_settings() {
        register_setting( 'shop_builder_group', 'shop_builder_options', [
                'sanitize_callback' => [ $this, 'sanitize_options' ]
        ] );
    }

    /* -------------------------
       Sanitize helpers for section data
       ------------------------- */
    private function sanitize_section_data( $type, $raw_data ) {
        if ( ! isset( self::SECTION_SCHEMA[ $type ] ) || ! is_array( $raw_data ) ) {
            return [];
        }

        $clean = [];
        foreach ( self::SECTION_SCHEMA[ $type ]['fields'] as $field => $kind ) {
            $value = $raw_data[ $field ] ?? null;

            switch ( $kind ) {
                case 'text':
                    $clean[ $field ] = sanitize_text_field( $value ?? '' );
                    break;

                case 'rich_text':
                    $clean[ $field ] = wp_kses( (string) ( $value ?? '' ), self::RICH_TEXT_ALLOWED_TAGS );
                    break;

                case 'url':
                    $clean[ $field ] = esc_url_raw( $value ?? '' );
                    break;

                case 'color':
                    $clean[ $field ] = sanitize_hex_color( $value ?? '' ) ?: '';
                    break;

                case 'int_array':
                    $clean[ $field ] = is_array( $value ) ? array_map( 'intval', $value ) : [];
                    break;

                case 'testimonial_items':
                    $items = [];
                    if ( is_array( $value ) ) {
                        foreach ( array_slice( $value, 0, 20 ) as $item ) {
                            // ✅ FIX: field renamed quote -> review_text. Accept
                            // review_text from new submissions, but fall back
                            // to the legacy `quote` key so testimonials saved
                            // before this change aren't silently blanked out.
                            $review_text = $item['review_text'] ?? $item['quote'] ?? '';

                            $items[] = [
                                    'name'        => sanitize_text_field( $item['name'] ?? '' ),
                                    'review_text' => sanitize_textarea_field( $review_text ),
                            ];
                        }
                    }
                    $clean[ $field ] = $items;
                    break;

                default:
                    // Unknown field kind — drop it rather than pass raw input through.
                    break;
            }
        }

        return $clean;
    }

    private function sanitize_sections( $raw_sections ) {
        if ( ! is_array( $raw_sections ) ) return [];

        $clean = [];
        foreach ( $raw_sections as $section ) {
            if ( ! is_array( $section ) ) continue;

            $type = sanitize_key( $section['type'] ?? '' );
            if ( ! isset( self::SECTION_SCHEMA[ $type ] ) ) continue; // reject unknown types

            $id = preg_match( '/^sec_[a-zA-Z0-9]{6,20}$/', $section['id'] ?? '' )
                    ? $section['id']
                    : 'sec_' . substr( wp_generate_password( 12, false ), 0, 10 );

            $clean[] = [
                    'id'      => $id,
                    'type'    => $type,
                    'enabled' => ! empty( $section['enabled'] ),
                    'data'    => $this->sanitize_section_data( $type, $section['data'] ?? [] ),
            ];
        }

        return $clean;
    }

    /* -------------------------
       Sanitize Options
       ------------------------- */
    public function sanitize_options( $input ) {
        if ( isset( $input['home']['featured_products'] ) ) {
            $input['home']['featured_products'] = array_map( 'intval', (array) $input['home']['featured_products'] );
        }

        if ( isset( $input['header']['announcement'] ) ) {
            $ann = $input['header']['announcement'];
            $input['header']['announcement'] = [
                    'enabled'    => ! empty( $ann['enabled'] ),
                    'text'       => sanitize_text_field( $ann['text'] ?? '' ),
                    'bg_color'   => sanitize_hex_color( $ann['bg_color'] ?? '#000000' ),
                    'text_color' => sanitize_hex_color( $ann['text_color'] ?? '#ffffff' ),
            ];
        }

        if ( isset( $input['header']['navigation'] ) ) {
            $normalized = [];
            foreach ( $input['header']['navigation'] as $key => $item ) {
                $normalized[] = [
                        'key'     => sanitize_key( $key ),
                        'label'   => sanitize_text_field( $item['label'] ?? '' ),
                        'enabled' => isset( $item['enabled'] ),
                ];
            }
            $input['header']['navigation'] = $normalized;
        }

        if ( isset( $input['header']['settings'] ) ) {
            $input['header']['settings'] = [
                    'sticky'      => ! empty( $input['header']['settings']['sticky'] ),
                    'show_search' => ! empty( $input['header']['settings']['show_search'] ),
            ];
        }

        // ✅ FIX: was sanitize_text_field() (stripped all HTML). Now allows a
        // safe subset of inline tags (span/strong/em/b/i/br) via wp_kses so
        // things like "Your new <span>Home</span>" survive intact, while
        // still blocking scripts/attributes/anything dangerous.
        if ( isset( $input['home']['hero_title'] ) ) {
            $input['home']['hero_title'] = wp_kses( $input['home']['hero_title'], self::RICH_TEXT_ALLOWED_TAGS );
        }

        if ( isset( $input['home']['hero_image_id'] ) ) {
            $id = intval( $input['home']['hero_image_id'] );
            $input['home']['hero_image_id'] = ( $id > 0 && get_post_type( $id ) === 'attachment' ) ? $id : 0;
        }

        if ( isset( $input['home']['sections'] ) ) {
            $input['home']['sections'] = $this->sanitize_sections( $input['home']['sections'] );
        }

        if ( isset( $input['checkout']['checkout_notice'] ) ) {
            $input['checkout']['checkout_notice'] = sanitize_textarea_field( $input['checkout']['checkout_notice'] );
        }

        // Branding: logo (any aspect ratio) and app icon (square, used for
        // favicon / PWA manifest) are kept as two separate fields on purpose
        // — see integration notes on why they shouldn't be the same image.
        if ( isset( $input['branding']['logo_id'] ) ) {
            $id = intval( $input['branding']['logo_id'] );
            $input['branding']['logo_id'] = ( $id > 0 && get_post_type( $id ) === 'attachment' ) ? $id : 0;
        }

        if ( isset( $input['branding']['app_icon_id'] ) ) {
            $id = intval( $input['branding']['app_icon_id'] );
            $input['branding']['app_icon_id'] = ( $id > 0 && get_post_type( $id ) === 'attachment' ) ? $id : 0;
        }

        return $input;
    }

    /* -------------------------
       Save Draft (AJAX)
       ------------------------- */
    public function ajax_save_settings() {
        check_ajax_referer( 'shop_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! isset( $_POST['shop_builder_options'] ) ) {
            wp_send_json_error( 'No data received' );
        }

        $new_options      = $this->sanitize_options( wp_unslash( $_POST['shop_builder_options'] ) );
        $existing_options = get_option( 'shop_builder_options', [] );
        $updated_options  = array_replace_recursive( $existing_options, $new_options );

        // array_replace_recursive would merge numeric-keyed arrays (like
        // `sections`) element-by-element instead of replacing the whole
        // list — force a full replace for section/list-type fields so
        // deletions/reorders from the client are respected.
        if ( isset( $new_options['home']['sections'] ) ) {
            $updated_options['home']['sections'] = $new_options['home']['sections'];
        }
        if ( isset( $new_options['home']['featured_products'] ) ) {
            $updated_options['home']['featured_products'] = $new_options['home']['featured_products'];
        }
        if ( isset( $new_options['header']['navigation'] ) ) {
            $updated_options['header']['navigation'] = $new_options['header']['navigation'];
        }

        update_option( 'shop_builder_options', $updated_options );

        wp_send_json_success( 'Draft saved successfully!' );
    }

    /* -------------------------
       Push to GitHub (AJAX)
       ------------------------- */
    public function handle_github_push() {
        check_ajax_referer( 'shop_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $options       = get_option( 'shop_builder_options', [] );
        // ✅ FIX: 'branding' was missing here — the Branding tab (logo +
        // app icon) was being saved as a draft but never actually pushed
        // to GitHub. Added below.
        $allowed_pages = [ 'header', 'home', 'checkout', 'branding' ];

        $success_count = 0;
        $skipped_count = 0;

        foreach ( $allowed_pages as $page_slug ) {
            if ( ! isset( $options[ $page_slug ] ) ) continue;

            $page_data = $options[ $page_slug ];
            $path      = "public/config/{$page_slug}.json";

            if ( $page_slug === 'home' && ! empty( $page_data['hero_image_id'] ) ) {
                $home_data = $page_data;
                unset( $home_data['hero_image_id'] );

                $result = aps_sync_hero_image_to_github( $page_data['hero_image_id'], $home_data, 'public/homepage-hero', $path );

                if ( $result === true ) {
                    $success_count++;
                    continue;
                } elseif ( $result === 'no_changes' ) {
                    $skipped_count++;
                    continue;
                } else {
                    error_log( 'Qwoo: hero image sync failed, falling back to json-only push for home.' );
                }
            }

            // Branding has up to two image fields (logo_id, app_icon_id) that
            // each need to be synced as real files alongside branding.json,
            // same atomic pattern as the hero image above. App icon set
            // generation/sync is handled separately via the "Generate & Push
            // Icon Set" button — this only handles the raw logo/app-icon
            // source images + branding.json.
            if ( $page_slug === 'branding' ) {
                $branding_data = $page_data;
                $synced_any_image = false;
                $image_sync_failed = false;

                foreach ( [ 'logo_id' => 'logo', 'app_icon_id' => 'app_icon' ] as $id_field => $url_field ) {
                    if ( empty( $branding_data[ $id_field ] ) ) continue;

                    $result = aps_sync_logo_to_github(
                            $branding_data[ $id_field ],
                            $branding_data,      // still looks the same...
                            $url_field,
                            'public/branding',
                            $path
                    );

                    if ( $result === true ) {
                        $synced_any_image = true;
                    } elseif ( $result === 'no_changes' ) {
                        // no-op, another field or the json itself may still change
                    } else {
                        $image_sync_failed = true;
                        error_log( "Qwoo: branding image sync failed for {$id_field}, falling back to json-only push for branding." );
                    }
                }

                if ( $synced_any_image && ! $image_sync_failed ) {
                    $success_count++;
                    continue;
                } elseif ( ! $image_sync_failed && ! $synced_any_image ) {
                    $skipped_count++;
                    continue;
                }
                // fall through to plain json-only push below if an image sync failed
            }

            $content = json_encode( $page_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            $result  = aps_commit_to_github( $content, $path, "Update shop config for: {$page_slug}" );

            if ( $result === true ) {
                $success_count++;
            } elseif ( $result === 'no_changes' ) {
                $skipped_count++;
            }
        }

        if ( $success_count > 0 || $skipped_count > 0 ) {
            wp_send_json_success( "Updated: {$success_count}, Skipped (no changes): {$skipped_count}" );
        } else {
            wp_send_json_error( 'No valid pages found to sync.' );
        }
    }

    /* -------------------------
       Section rendering helpers (admin form)
       ------------------------- */
    private function render_section_row( $index, $section ) {
        $type = $section['type'];
        $data = $section['data'] ?? [];
        $label = self::SECTION_SCHEMA[ $type ]['label'] ?? $type;
        ?>
        <div class="section-row" data-index="<?php echo esc_attr( $index ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
            <input type="hidden" name="shop_builder_options[home][sections][<?php echo $index; ?>][id]" value="<?php echo esc_attr( $section['id'] ); ?>" />
            <input type="hidden" name="shop_builder_options[home][sections][<?php echo $index; ?>][type]" value="<?php echo esc_attr( $type ); ?>" />

            <div class="section-row-header">
                <span class="section-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>
                <button type="button" class="section-toggle-btn button-link" aria-expanded="true" title="Collapse/expand this section">
                    <span class="dashicons dashicons-arrow-up-alt2"></span>
                </button>
                <strong class="section-title"><?php echo esc_html( $label ); ?></strong>
                <label class="section-enabled-toggle">
                    <input type="checkbox" name="shop_builder_options[home][sections][<?php echo $index; ?>][enabled]" value="1" <?php checked( $section['enabled'], true ); ?> />
                    Enabled
                </label>
                <button type="button" class="button button-link-delete remove-section-btn">Remove</button>
            </div>

            <div class="section-row-body">
                <?php $this->render_section_fields( $index, $type, $data ); ?>
            </div>
        </div>
        <?php
    }

    private function render_section_fields( $index, $type, $data ) {
        $name = "shop_builder_options[home][sections][{$index}][data]";

        switch ( $type ) {
            case 'banner':
                ?>
                <p><label>Text</label><br>
                    <input type="text" class="large-text" name="<?php echo $name; ?>[text]" value="<?php echo esc_attr( $data['text'] ?? '' ); ?>" /></p>
                <p><label>Link URL</label><br>
                    <input type="url" class="large-text" name="<?php echo $name; ?>[link_url]" value="<?php echo esc_attr( $data['link_url'] ?? '' ); ?>" /></p>
                <p><label>Link Text</label><br>
                    <input type="text" name="<?php echo $name; ?>[link_text]" value="<?php echo esc_attr( $data['link_text'] ?? '' ); ?>" /></p>
                <p>
                    Background: <input type="color" name="<?php echo $name; ?>[bg_color]" value="<?php echo esc_attr( $data['bg_color'] ?? '#000000' ); ?>" />
                    &nbsp; Text: <input type="color" name="<?php echo $name; ?>[text_color]" value="<?php echo esc_attr( $data['text_color'] ?? '#ffffff' ); ?>" />
                </p>
                <?php
                break;

            case 'newsletter_signup':
                ?>
                <p><label>Title</label><br>
                    <input type="text" class="large-text" name="<?php echo $name; ?>[title]" value="<?php echo esc_attr( $data['title'] ?? '' ); ?>" /></p>
                <p><label>Subtitle</label><br>
                    <input type="text" class="large-text" name="<?php echo $name; ?>[subtitle]" value="<?php echo esc_attr( $data['subtitle'] ?? '' ); ?>" /></p>
                <p><label>Button Text</label><br>
                    <input type="text" name="<?php echo $name; ?>[button_text]" value="<?php echo esc_attr( $data['button_text'] ?? 'Subscribe' ); ?>" /></p>
                <?php
                break;

            case 'category_grid':
                ?>
                <p><label>Title</label><br>
                    <input type="text" class="large-text" name="<?php echo $name; ?>[title]" value="<?php echo esc_attr( $data['title'] ?? '' ); ?>" /></p>
                <p><label>Categories</label><br>
                <div class="custom-select-wrapper">
                    <select class="category-select" name="<?php echo $name; ?>[category_ids][]" multiple="multiple" style="width:100%;" data-placeholder="Type to search categories...">
                        <?php
                        foreach ( (array) ( $data['category_ids'] ?? [] ) as $cat_id ) {
                            $term = get_term( $cat_id, 'product_cat' );
                            if ( $term && ! is_wp_error( $term ) ) {
                                echo '<option value="' . esc_attr( $cat_id ) . '" selected>' . esc_html( $term->name ) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div></p>
                <?php
                break;

            case 'testimonials':
                ?>
                <p><label>Title</label><br>
                    <input type="text" class="large-text" name="<?php echo $name; ?>[title]" value="<?php echo esc_attr( $data['title'] ?? '' ); ?>" /></p>
                <div class="testimonial-items">
                    <?php
                    $items = $data['items'] ?? [];
                    foreach ( $items as $i => $item ) {
                        $this->render_testimonial_item( $name, $i, $item );
                    }
                    ?>
                </div>
                <button type="button" class="button add-testimonial-btn">+ Add Testimonial</button>
                <?php
                break;
        }
    }

    private function render_testimonial_item( $parent_name, $i, $item ) {
        // ✅ FIX: field renamed quote -> review_text, with a fallback read of
        // the legacy `quote` key so testimonials saved before this change
        // still display their existing text in the admin form.
        $review_text = $item['review_text'] ?? $item['quote'] ?? '';
        ?>
        <div class="testimonial-item" style="border:1px solid #ddd; padding:10px; margin-bottom:8px;">
            <input type="text" placeholder="Name and Title" name="<?php echo $parent_name; ?>[items][<?php echo $i; ?>][name]" value="<?php echo esc_attr( $item['name'] ?? '' ); ?>" />
            <textarea placeholder="Review Text" rows="2" class="large-text" name="<?php echo $parent_name; ?>[items][<?php echo $i; ?>][review_text]"><?php echo esc_textarea( $review_text ); ?></textarea>
            <button type="button" class="button button-link-delete remove-testimonial-btn">Remove</button>
        </div>
        <?php
    }

    /* -------------------------
       Settings Page HTML
       ------------------------- */
    public function settings_page_html() {
        $options        = get_option( 'shop_builder_options', [] );
        $header         = $options['header'] ?? [];
        $branding       = $options['branding'] ?? [];

        $tech_settings  = get_option( 'qwoo_technical_settings', [] );
        $frontend_url   = ! empty( $tech_settings['frontend_domain'] )
                ? trailingslashit( $tech_settings['frontend_domain'] ) . '?preview=true'
                : 'about:blank';

        $logo_id     = intval( $branding['logo_id'] ?? 0 );
        $logo_url    = $logo_id ? wp_get_attachment_url( $logo_id ) : '';
        $icon_id     = intval( $branding['app_icon_id'] ?? 0 );
        $icon_url    = $icon_id ? wp_get_attachment_url( $icon_id ) : '';
        ?>
        <div class="wrap">
            <h1>Shop Builder</h1>
            <div class="shop-builder-wrapper">

                <div class="shop-builder-sidebar">
                    <nav class="nav-tab-wrapper">
                        <a class="nav-tab nav-tab-active" data-target="tab-header">Header</a>
                        <a class="nav-tab" data-target="tab-home">Homepage</a>
                        <a class="nav-tab" data-target="tab-checkout">Checkout</a>
                        <a class="nav-tab" data-target="tab-branding">Branding</a>
                    </nav>

                    <form action="options.php" method="post">
                        <?php settings_fields( 'shop_builder_group' ); ?>

                        <!-- ===================== HEADER TAB ===================== -->
                        <div id="tab-header" class="tab-content active">
                            <h2>Header Settings</h2>

                            <h3>Announcement Bar</h3>
                            <label>
                                <input type="checkbox"
                                       name="shop_builder_options[header][announcement][enabled]"
                                       value="1"
                                        <?php checked( $header['announcement']['enabled'] ?? false, true ); ?> />
                                Enable Announcement Bar
                            </label>
                            <p>
                                <input type="text"
                                       name="shop_builder_options[header][announcement][text]"
                                       value="<?php echo esc_attr( $header['announcement']['text'] ?? '' ); ?>"
                                       class="large-text"
                                       placeholder="Enter announcement text..." />
                            </p>
                            <p>
                                Background Color:
                                <input type="color"
                                       name="shop_builder_options[header][announcement][bg_color]"
                                       value="<?php echo esc_attr( $header['announcement']['bg_color'] ?? '#000000' ); ?>" />
                                &nbsp; Text Color:
                                <input type="color"
                                       name="shop_builder_options[header][announcement][text_color]"
                                       value="<?php echo esc_attr( $header['announcement']['text_color'] ?? '#ffffff' ); ?>" />
                            </p>

                            <hr>

                            <h3>Navigation Items</h3>
                            <p>Enable/disable and rename menu items</p>

                            <?php
                            $default_nav = [
                                    'home'     => 'Home',
                                    'products' => 'Products',
                                    'cart'     => 'Cart',
                                    'checkout' => 'Checkout',
                                    'account'  => 'My Account',
                            ];

                            $saved_nav = $header['navigation'] ?? [];
                            $nav_map   = [];
                            foreach ( $saved_nav as $item ) {
                                if ( isset( $item['key'] ) ) {
                                    $nav_map[ $item['key'] ] = $item;
                                }
                            }

                            foreach ( $default_nav as $key => $label ) :
                                $item = $nav_map[ $key ] ?? [];
                                ?>
                                <div style="margin-bottom: 10px; display:flex; align-items:center; gap:10px;">
                                    <label style="min-width:80px;">
                                        <input type="checkbox"
                                               name="shop_builder_options[header][navigation][<?php echo esc_attr( $key ); ?>][enabled]"
                                               value="1"
                                                <?php checked( $item['enabled'] ?? true, true ); ?> />
                                        Enable
                                    </label>
                                    <input type="text"
                                           name="shop_builder_options[header][navigation][<?php echo esc_attr( $key ); ?>][label]"
                                           value="<?php echo esc_attr( $item['label'] ?? $label ); ?>"
                                           placeholder="<?php echo esc_attr( $label ); ?>" />
                                </div>
                            <?php endforeach; ?>

                            <hr>

                            <h3>General Settings</h3>
                            <label>
                                <input type="checkbox"
                                       name="shop_builder_options[header][settings][sticky]"
                                       value="1"
                                        <?php checked( $header['settings']['sticky'] ?? true, true ); ?> />
                                Sticky Header
                            </label>
                            <br>
                            <label>
                                <input type="checkbox"
                                       name="shop_builder_options[header][settings][show_search]"
                                       value="1"
                                        <?php checked( $header['settings']['show_search'] ?? false, true ); ?> />
                                Show Search Icon
                            </label>
                        </div>

                        <!-- ===================== HOME TAB ===================== -->
                        <div id="tab-home" class="tab-content">
                            <h2>Homepage Settings</h2>

                            <p><strong>Hero Title</strong></p>
                            <input type="text"
                                   name="shop_builder_options[home][hero_title]"
                                   value="<?php echo esc_attr( $options['home']['hero_title'] ?? '' ); ?>"
                                   class="large-text" />
                            <p class="description">Basic inline HTML is allowed, e.g. <code>Your new &lt;span&gt;Home&lt;/span&gt;</code>.</p>

                            <div class="field-group" style="margin-top:20px;">
                                <label><strong>Hero Image</strong></label>
                                <p>
                                    <?php
                                    $hero_image_id = $options['home']['hero_image_id'] ?? 0;
                                    $hero_image_url = $hero_image_id ? wp_get_attachment_url( $hero_image_id ) : '';
                                    ?>
                                    <img id="hero-image-preview"
                                         src="<?php echo esc_url( $hero_image_url ); ?>"
                                         style="max-width:200px; max-height:150px; display:<?php echo $hero_image_url ? 'block' : 'none'; ?>; margin-bottom:10px;" />
                                </p>
                                <input type="hidden"
                                       id="hero-image-id"
                                       name="shop_builder_options[home][hero_image_id]"
                                       value="<?php echo esc_attr( $hero_image_id ); ?>" />
                                <button type="button" id="hero-image-upload-btn" class="button button-secondary">
                                    <?php echo $hero_image_url ? 'Change Image' : 'Select Image'; ?>
                                </button>
                                <button type="button" id="hero-image-remove-btn" class="button" style="<?php echo $hero_image_url ? '' : 'display:none;'; ?>">
                                    Remove
                                </button>
                            </div>

                            <div class="field-group" style="margin-top:20px;">
                                <label><strong>Featured Products</strong></label>
                                <div class="custom-select-wrapper">
                                    <select
                                            id="hp-product-select"
                                            name="shop_builder_options[home][featured_products][]"
                                            multiple="multiple"
                                            style="width: 100%;"
                                            data-placeholder="Type to search products..."
                                    >
                                        <?php
                                        if ( ! empty( $options['home']['featured_products'] ) ) {
                                            foreach ( (array) $options['home']['featured_products'] as $prod_id ) {
                                                $product = wc_get_product( $prod_id );
                                                if ( $product ) {
                                                    echo '<option value="' . esc_attr( $prod_id ) . '" selected>'
                                                            . esc_html( $product->get_name() )
                                                            . '</option>';
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <hr style="margin:30px 0;">

                            <div class="field-group">
                                <div style="display:flex; align-items:center; justify-content:space-between;">
                                    <label><strong>Homepage Sections</strong></label>
                                    <div class="add-section-control">
                                        <select id="add-section-type">
                                            <?php foreach ( self::SECTION_SCHEMA as $type => $conf ) : ?>
                                                <option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $conf['label'] ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" id="add-section-btn" class="button button-secondary">+ Add Section</button>
                                    </div>
                                </div>
                                <p class="description">
                                    Drag to reorder. These render below the hero on the homepage, in this order.
                                    <button type="button" id="collapse-all-sections" class="button-link">Collapse All</button>
                                    &nbsp;|&nbsp;
                                    <button type="button" id="expand-all-sections" class="button-link">Expand All</button>
                                </p>

                                <div id="sections-container">
                                    <?php
                                    $sections = $options['home']['sections'] ?? [];
                                    foreach ( $sections as $index => $section ) {
                                        if ( isset( self::SECTION_SCHEMA[ $section['type'] ?? '' ] ) ) {
                                            $this->render_section_row( $index, $section );
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- ===================== CHECKOUT TAB ===================== -->
                        <div id="tab-checkout" class="tab-content">
                            <h2>Checkout Settings</h2>

                            <p><strong>Checkout Notice</strong></p>
                            <textarea name="shop_builder_options[checkout][checkout_notice]"
                                      class="large-text"
                                      rows="5"><?php echo esc_textarea( $options['checkout']['checkout_notice'] ?? '' ); ?></textarea>
                        </div>

                        <!-- ===================== BRANDING TAB ===================== -->
                        <div id="tab-branding" class="tab-content">
                            <h2>Branding</h2>

                            <div class="field-group">
                                <label><strong>Header Logo</strong></label>
                                <p class="description">Any aspect ratio — this is used in the site header only.</p>
                                <p>
                                    <img id="logo-preview" src="<?php echo esc_url( $logo_url ); ?>"
                                         style="max-width:240px; max-height:100px; display:<?php echo $logo_url ? 'block' : 'none'; ?>; margin-bottom:10px;" />
                                </p>
                                <input type="hidden" id="logo-id" name="shop_builder_options[branding][logo_id]" value="<?php echo esc_attr( $logo_id ); ?>" />
                                <button type="button" id="logo-upload-btn" class="button button-secondary"><?php echo $logo_url ? 'Change Logo' : 'Select Logo'; ?></button>
                                <button type="button" id="logo-remove-btn" class="button" style="<?php echo $logo_url ? '' : 'display:none;'; ?>">Remove</button>
                            </div>

                            <hr style="margin:30px 0;">

                            <div class="field-group">
                                <label><strong>App Icon</strong></label>
                                <p class="description">
                                    Square image, <strong>at least 512×512px</strong>. This generates the favicon, iOS home-screen icon,
                                    and the full Android/PWA icon set (including the maskable variant) used when a customer installs
                                    the app to their home screen. Keep the important part of the logo away from the very edges —
                                    Android may crop up to ~20% off each side on some devices.
                                </p>
                                <p>
                                    <img id="app-icon-preview" src="<?php echo esc_url( $icon_url ); ?>"
                                         style="max-width:150px; max-height:150px; display:<?php echo $icon_url ? 'block' : 'none'; ?>; margin-bottom:10px; border:1px solid #ddd;" />
                                </p>
                                <input type="hidden" id="app-icon-id" name="shop_builder_options[branding][app_icon_id]" value="<?php echo esc_attr( $icon_id ); ?>" />
                                <button type="button" id="app-icon-upload-btn" class="button button-secondary"><?php echo $icon_url ? 'Change App Icon' : 'Select App Icon'; ?></button>
                                <button type="button" id="app-icon-remove-btn" class="button" style="<?php echo $icon_url ? '' : 'display:none;'; ?>">Remove</button>
                                <br><br>
                                <button type="button" id="generate-icons-btn" class="button button-secondary">Generate &amp; Push Icon Set to GitHub</button>
                                <span id="icon-gen-status" style="margin-left:10px; font-weight:500;"></span>
                                <p class="description">Save your draft first if you just changed the App Icon, then click this to (re)generate all sizes and push them.</p>
                            </div>
                        </div>

                        <!-- ===================== ACTIONS ===================== -->
                        <div style="margin-top: 30px; display: flex; align-items: center; gap: 15px; flex-wrap:wrap;">
                            <?php submit_button( 'Save Draft', 'primary', 'submit', false ); ?>
                            <button type="button" id="push-to-github" class="button button-secondary">
                                Push to Live Website
                            </button>
                            <span id="sync-status" style="font-weight: 500;"></span>
                        </div>

                    </form>
                </div>

                <!-- ===================== PREVIEW PANEL ===================== -->
                <div class="shop-builder-preview">
                    <div class="preview-header">
                        <span>Live Preview (Headless App)</span>
                        <button type="button" id="update-preview" class="button button-small">Refresh View</button>
                    </div>
                    <iframe id="shop-preview-frame" src="<?php echo esc_url( $frontend_url ); ?>"></iframe>
                </div>

            </div>
        </div>
        <?php
    }
}

new Shop_Settings_Builder();