<?php
class Silverbene_API {
    /**
     * API client instance.
     *
     * @var Silverbene_API_Client
     */
    private $client;

    /**
     * Sync handler instance.
     *
     * @var Silverbene_Sync
     */
    private $sync_handler;

    /**
     * Constructor.
     *
     * @param Silverbene_API_Client $client       API client.
     * @param Silverbene_Sync       $sync_handler Sync handler.
     */
    public function __construct( Silverbene_API_Client $client, Silverbene_Sync $sync_handler ) {
        $this->client       = $client;
        $this->sync_handler = $sync_handler;
    }

    /**
     * Initialize hooks.
     */
    public function initialize() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_silverbene_manual_sync', array( $this, 'handle_manual_sync' ) );
        add_action( 'update_option_' . SILVERBENE_API_SETTINGS_OPTION, array( $this, 'after_settings_saved' ), 10, 2 );
        add_filter( 'cron_schedules', array( $this, 'register_custom_cron_schedules' ) );
        add_action( 'init', array( $this, 'maybe_reschedule_cron' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_admin_notice' ) );
    }

    /**
     * Register admin menu page.
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Silverbene API', 'silverbene-api-integration' ),
            __( 'Silverbene API', 'silverbene-api-integration' ),
            'manage_options',
            'silverbene-api',
            array( $this, 'render_settings_page' ),
            'dashicons-cart'
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting( 'silverbene_api_settings_group', SILVERBENE_API_SETTINGS_OPTION, array( $this, 'sanitize_settings' ) );

        add_settings_section(
            'silverbene_api_credentials_section',
            __( 'Kredensial API', 'silverbene-api-integration' ),
            '__return_false',
            'silverbene-api'
        );

        add_settings_field(
            'api_url',
            __( 'URL API', 'silverbene-api-integration' ),
            array( $this, 'render_text_field' ),
            'silverbene-api',
            'silverbene_api_credentials_section',
            array(
                'label_for' => 'api_url',
                'type'      => 'text',
                'description' => __( 'URL dasar untuk REST API Silverbene.', 'silverbene-api-integration' ),
            )
        );

        add_settings_field(
            'api_key',
            __( 'API Key', 'silverbene-api-integration' ),
            array( $this, 'render_text_field' ),
            'silverbene-api',
            'silverbene_api_credentials_section',
            array(
                'label_for' => 'api_key',
                'type'      => 'password',
                'description' => __( 'Masukkan API Key dari dashboard Silverbene.', 'silverbene-api-integration' ),
            )
        );

        add_settings_field(
            'api_secret',
            __( 'API Secret', 'silverbene-api-integration' ),
            array( $this, 'render_text_field' ),
            'silverbene-api',
            'silverbene_api_credentials_section',
            array(
                'label_for' => 'api_secret',
                'type'      => 'password',
                'description' => __( 'Masukkan API Secret apabila dibutuhkan oleh Silverbene.', 'silverbene-api-integration' ),
            )
        );

        add_settings_section(
            'silverbene_api_sync_section',
            __( 'Pengaturan Sinkronisasi', 'silverbene-api-integration' ),
            '__return_false',
            'silverbene-api'
        );

        add_settings_field(
            'sync_enabled',
            __( 'Aktifkan Sinkronisasi Otomatis', 'silverbene-api-integration' ),
            array( $this, 'render_checkbox_field' ),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'sync_enabled',
                'description' => __( 'Apabila dicentang, plugin akan menarik produk secara otomatis sesuai jadwal.', 'silverbene-api-integration' ),
            )
        );

        add_settings_field(
            'sync_interval',
            __( 'Interval Sinkronisasi', 'silverbene-api-integration' ),
            array( $this, 'render_select_field' ),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'sync_interval',
                'options'   => array(
                    'fifteen_minutes' => __( 'Setiap 15 Menit', 'silverbene-api-integration' ),
                    'hourly'          => __( 'Setiap Jam', 'silverbene-api-integration' ),
                    'twicedaily'      => __( 'Dua Kali Sehari', 'silverbene-api-integration' ),
                    'daily'           => __( 'Harian', 'silverbene-api-integration' ),
                ),
                'description' => __( 'Tentukan seberapa sering data produk akan diperbarui.', 'silverbene-api-integration' ),
            )
        );

        add_settings_field(
            'default_category',
            __( 'Kategori Default', 'silverbene-api-integration' ),
            array( $this, 'render_text_field' ),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for'   => 'default_category',
                'type'        => 'text',
                'placeholder' => __( 'Misal: Jewelry', 'silverbene-api-integration' ),
                'description' => __( 'Kategori yang akan diberikan bila produk dari API tidak memiliki kategori.', 'silverbene-api-integration' ),
            )
        );

        add_settings_field(
            'price_markup_type',
            __( 'Tipe Penyesuaian Harga', 'silverbene-api-integration' ),
            array( $this, 'render_select_field' ),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'price_markup_type',
                'options'   => array(
                    'percentage' => __( 'Persentase', 'silverbene-api-integration' ),
                    'fixed'      => __( 'Nominal Tetap', 'silverbene-api-integration' ),
                    'none'       => __( 'Tanpa Penyesuaian', 'silverbene-api-integration' ),
                ),
                'description' => __( 'Sesuaikan harga jual dengan persentase atau nominal tertentu.', 'silverbene-api-integration' ),
            )
        );

        add_settings_field(
            'price_markup_value',
            __( 'Nilai Penyesuaian Harga', 'silverbene-api-integration' ),
            array( $this, 'render_number_field' ),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'price_markup_value',
                'min'       => 0,
                'step'      => '0.01',
                'description' => __( 'Masukkan nilai markup. Untuk tipe persentase gunakan angka 10 untuk 10%.', 'silverbene-api-integration' ),
            )
        );

        add_settings_section(
            'silverbene_api_endpoints_section',
            __( 'Endpoint Kustom', 'silverbene-api-integration' ),
            '__return_false',
            'silverbene-api'
        );

        add_settings_field(
            'products_endpoint',
            __( 'Endpoint Produk', 'silverbene-api-integration' ),
            array( $this, 'render_text_field' ),
            'silverbene-api',
            'silverbene_api_endpoints_section',
            array(
                'label_for' => 'products_endpoint',
                'type'      => 'text',
                'placeholder' => '/products',
                'description' => __( 'Endpoint relatif untuk mengambil data produk.', 'silverbene-api-integration' ),
            )
        );

        add_settings_field(
            'orders_endpoint',
            __( 'Endpoint Pesanan', 'silverbene-api-integration' ),
            array( $this, 'render_text_field' ),
            'silverbene-api',
            'silverbene_api_endpoints_section',
            array(
                'label_for' => 'orders_endpoint',
                'type'      => 'text',
                'placeholder' => '/orders',
                'description' => __( 'Endpoint relatif untuk membuat pesanan di Silverbene.', 'silverbene-api-integration' ),
            )
        );
    }

    /**
     * Sanitasi data pengaturan sebelum disimpan.
     *
     * @param array $input Raw input values.
     * @return array
     */
    public function sanitize_settings( $input ) {
        $output = array();
        $output['api_url']            = isset( $input['api_url'] ) ? esc_url_raw( $input['api_url'] ) : '';
        $output['api_key']            = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
        $output['api_secret']         = isset( $input['api_secret'] ) ? sanitize_text_field( $input['api_secret'] ) : '';
        $output['sync_enabled']       = ! empty( $input['sync_enabled'] );
        $output['sync_interval']      = isset( $input['sync_interval'] ) ? sanitize_key( $input['sync_interval'] ) : 'hourly';
        $output['default_category']   = isset( $input['default_category'] ) ? sanitize_text_field( $input['default_category'] ) : '';
        $output['price_markup_type']  = isset( $input['price_markup_type'] ) ? sanitize_key( $input['price_markup_type'] ) : 'none';
        $output['price_markup_value'] = isset( $input['price_markup_value'] ) ? floatval( $input['price_markup_value'] ) : 0;
        $output['products_endpoint']  = isset( $input['products_endpoint'] ) ? sanitize_text_field( $input['products_endpoint'] ) : '/products';
        $output['orders_endpoint']    = isset( $input['orders_endpoint'] ) ? sanitize_text_field( $input['orders_endpoint'] ) : '/orders';

        return $output;
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = get_option( SILVERBENE_API_SETTINGS_OPTION, array() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pengaturan API Silverbene', 'silverbene-api-integration' ); ?></h1>
            <p><?php esc_html_e( 'Masukkan kredensial API dan konfigurasi sinkronisasi agar produk Silverbene otomatis tersinkron dengan WooCommerce Anda.', 'silverbene-api-integration' ); ?></p>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'silverbene_api_settings_group' );
                do_settings_sections( 'silverbene-api' );
                submit_button();
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Sinkronisasi Manual', 'silverbene-api-integration' ); ?></h2>
            <p><?php esc_html_e( 'Klik tombol di bawah untuk langsung menarik data produk terbaru dari Silverbene.', 'silverbene-api-integration' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'silverbene_manual_sync' ); ?>
                <input type="hidden" name="action" value="silverbene_manual_sync" />
                <?php submit_button( __( 'Sinkronisasi Sekarang', 'silverbene-api-integration' ), 'secondary' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle manual sync request.
     */
    public function handle_manual_sync() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Anda tidak memiliki izin untuk melakukan tindakan ini.', 'silverbene-api-integration' ) );
        }

        check_admin_referer( 'silverbene_manual_sync' );

        $this->sync_handler->sync_products( true );

        $redirect_url = add_query_arg(
            array(
                'page'       => 'silverbene-api',
                'synced'     => 'true',
                'timestamp'  => time(),
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Setelah pengaturan disimpan, segarkan cache settings dan jadwalkan ulang cron.
     */
    public function after_settings_saved() {
        $this->client->refresh_settings();
        $this->maybe_reschedule_cron();
    }

    /**
     * Tampilkan notifikasi admin setelah sinkronisasi manual.
     */
    public function maybe_show_admin_notice() {
        if ( ! isset( $_GET['page'], $_GET['synced'] ) || 'silverbene-api' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        if ( 'true' !== $_GET['synced'] ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sinkronisasi produk berhasil dijalankan.', 'silverbene-api-integration' ) . '</p></div>';
    }

    /**
     * Tambahkan custom interval cron.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function register_custom_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['fifteen_minutes'] ) ) {
            $schedules['fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Setiap 15 Menit', 'silverbene-api-integration' ),
            );
        }

        return $schedules;
    }

    /**
     * Jadwalkan ulang cron bila opsi berubah.
     */
    public function maybe_reschedule_cron() {
        $settings = $this->client->get_settings();
        $enabled  = ! empty( $settings['sync_enabled'] );
        $interval = ! empty( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'hourly';

        $timestamp = wp_next_scheduled( 'silverbene_api_sync_products' );

        if ( $enabled ) {
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, 'silverbene_api_sync_products' );
            }

            wp_schedule_event( time(), $interval, 'silverbene_api_sync_products' );
        } else {
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, 'silverbene_api_sync_products' );
            }
        }
    }

    /**
     * Render helper for text fields.
     *
     * @param array $args Field arguments.
     */
    public function render_text_field( $args ) {
        $settings = get_option( SILVERBENE_API_SETTINGS_OPTION, array() );
        $value    = isset( $settings[ $args['label_for'] ] ) ? $settings[ $args['label_for'] ] : '';
        $type     = isset( $args['type'] ) ? $args['type'] : 'text';
        $placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
        ?>
        <input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( SILVERBENE_API_SETTINGS_OPTION . '[' . $args['label_for'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render helper for checkbox field.
     *
     * @param array $args Field args.
     */
    public function render_checkbox_field( $args ) {
        $settings = get_option( SILVERBENE_API_SETTINGS_OPTION, array() );
        $checked  = ! empty( $settings[ $args['label_for'] ] );
        ?>
        <label for="<?php echo esc_attr( $args['label_for'] ); ?>">
            <input type="checkbox" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( SILVERBENE_API_SETTINGS_OPTION . '[' . $args['label_for'] . ']' ); ?>" value="1" <?php checked( $checked ); ?> />
            <?php if ( ! empty( $args['description'] ) ) : ?>
                <span class="description"><?php echo esc_html( $args['description'] ); ?></span>
            <?php endif; ?>
        </label>
        <?php
    }

    /**
     * Render helper for select field.
     *
     * @param array $args Field args.
     */
    public function render_select_field( $args ) {
        $settings = get_option( SILVERBENE_API_SETTINGS_OPTION, array() );
        $value    = isset( $settings[ $args['label_for'] ] ) ? $settings[ $args['label_for'] ] : '';
        ?>
        <select id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( SILVERBENE_API_SETTINGS_OPTION . '[' . $args['label_for'] . ']' ); ?>">
            <?php foreach ( $args['options'] as $option_value => $label ) : ?>
                <option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render helper for number field.
     *
     * @param array $args Field args.
     */
    public function render_number_field( $args ) {
        $settings = get_option( SILVERBENE_API_SETTINGS_OPTION, array() );
        $value    = isset( $settings[ $args['label_for'] ] ) ? $settings[ $args['label_for'] ] : '';
        $min      = isset( $args['min'] ) ? $args['min'] : 0;
        $step     = isset( $args['step'] ) ? $args['step'] : '1';
        ?>
        <input type="number" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( SILVERBENE_API_SETTINGS_OPTION . '[' . $args['label_for'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" step="<?php echo esc_attr( $step ); ?>" />
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }
}
