<?php
class Silverbene_API
{
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
    public function __construct(Silverbene_API_Client $client, Silverbene_Sync $sync_handler)
    {
        $this->client = $client;
        $this->sync_handler = $sync_handler;
    }

    /**
     * Initialize hooks.
     */
    public function initialize()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_silverbene_manual_sync', array($this, 'handle_manual_sync'));
        add_action('update_option_' . SILVERBENE_API_SETTINGS_OPTION, array($this, 'after_settings_saved'), 10, 2);
        add_filter('cron_schedules', array($this, 'register_custom_cron_schedules'));
        add_action('admin_notices', array($this, 'maybe_show_admin_notice'));
    }

    /**
     * Get capability required to manage Silverbene settings.
     *
     * @return string
     */
    private function get_manage_capability()
    {
        $capability = apply_filters('silverbene_api_manage_capability', 'manage_options');

        if (!is_string($capability) || '' === $capability) {
            return 'manage_options';
        }

        return $capability;
    }

    /**
     * Register admin menu page.
     */
    public function add_admin_menu()
    {
        add_menu_page(
            __('Silverbene API', 'silverbene-api-integration'),
            __('Silverbene API', 'silverbene-api-integration'),
            $this->get_manage_capability(),
            'silverbene-api',
            array($this, 'render_settings_page'),
            'dashicons-cart'
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings()
    {
        register_setting('silverbene_api_settings_group', SILVERBENE_API_SETTINGS_OPTION, array($this, 'sanitize_settings'));

        add_settings_section(
            'silverbene_api_credentials_section',
            __('Kredensial API', 'silverbene-api-integration'),
            '__return_false',
            'silverbene-api'
        );

        add_settings_field(
            'api_url',
            __('URL API', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_credentials_section',
            array(
                'label_for' => 'api_url',
                'type' => 'text',
                'description' => __('URL dasar untuk REST API Silverbene.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'api_key',
            __('API Key', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_credentials_section',
            array(
                'label_for' => 'api_key',
                'type' => 'password',
                'description' => __('Masukkan API Key dari dashboard Silverbene.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'api_secret',
            __('API Secret', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_credentials_section',
            array(
                'label_for' => 'api_secret',
                'type' => 'password',
                'description' => __('Masukkan API Secret apabila dibutuhkan oleh Silverbene.', 'silverbene-api-integration'),
            )
        );

        add_settings_section(
            'silverbene_api_sync_section',
            __('Pengaturan Sinkronisasi', 'silverbene-api-integration'),
            '__return_false',
            'silverbene-api'
        );

        add_settings_field(
            'sync_enabled',
            __('Aktifkan Sinkronisasi Otomatis', 'silverbene-api-integration'),
            array($this, 'render_checkbox_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'sync_enabled',
                'description' => __('Apabila dicentang, plugin akan menarik produk secara otomatis sesuai jadwal.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'sync_interval',
            __('Interval Sinkronisasi', 'silverbene-api-integration'),
            array($this, 'render_select_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'sync_interval',
                'options' => array(
                    'fifteen_minutes' => __('Setiap 15 Menit', 'silverbene-api-integration'),
                    'hourly' => __('Setiap Jam', 'silverbene-api-integration'),
                    'twicedaily' => __('Dua Kali Sehari', 'silverbene-api-integration'),
                    'daily' => __('Harian', 'silverbene-api-integration'),
                ),
                'description' => __('Tentukan seberapa sering data produk akan diperbarui.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'sync_start_date',
            __('Tanggal Mulai Sinkronisasi', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'sync_start_date',
                'type' => 'date',
                'description' => __('Digunakan sebagai batas awal ketika opsi silverbene_last_sync_timestamp kosong. Untuk memaksa impor ulang periode lama, kosongkan nilai timestamp terakhir terlebih dahulu.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'default_category',
            __('Kategori Default', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'default_category',
                'type' => 'text',
                'placeholder' => __('Misal: Jewelry', 'silverbene-api-integration'),
                'description' => __('Kategori yang akan diberikan bila produk dari API tidak memiliki kategori.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'default_brand',
            __('Brand Default', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'default_brand',
                'type' => 'text',
                'placeholder' => __('Misal: Silverbene', 'silverbene-api-integration'),
                'description' => __('Brand yang akan otomatis di-set ke setiap produk yang diimpor.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'price_markup_type',
            __('Tipe Penyesuaian Harga', 'silverbene-api-integration'),
            array($this, 'render_select_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'price_markup_type',
                'options' => array(
                    'percentage' => __('Persentase', 'silverbene-api-integration'),
                    'fixed' => __('Nominal Tetap', 'silverbene-api-integration'),
                    'none' => __('Tanpa Penyesuaian', 'silverbene-api-integration'),
                ),
                'description' => __('Sesuaikan harga jual dengan persentase atau nominal tertentu.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'pre_markup_shipping_fee',
            __('Biaya Pengiriman Pra-Markup', 'silverbene-api-integration'),
            array($this, 'render_number_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'pre_markup_shipping_fee',
                'min' => 0,
                'step' => '0.01',
                'description' => __('Nilai ini akan dijumlahkan ke harga dasar sebelum penyesuaian markup dihitung.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'price_markup_value',
            __('Nilai Penyesuaian Harga', 'silverbene-api-integration'),
            array($this, 'render_number_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'price_markup_value',
                'min' => 0,
                'step' => '0.01',
                'description' => __('Masukkan nilai markup. Untuk tipe persentase gunakan angka 10 untuk 10%. Nilai ini juga akan dipakai jika kolom markup khusus dikosongkan.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'price_markup_value_below_100',
            __('Markup untuk Harga < 100', 'silverbene-api-integration'),
            array($this, 'render_number_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'price_markup_value_below_100',
                'min' => 0,
                'step' => '0.01',
                'description' => __('Nilai markup khusus yang diterapkan ketika harga dasar produk kurang dari 100. Biarkan kosong untuk menggunakan nilai penyesuaian utama.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'price_markup_value_above_100',
            __('Markup untuk Harga ≥ 100', 'silverbene-api-integration'),
            array($this, 'render_number_field'),
            'silverbene-api',
            'silverbene_api_sync_section',
            array(
                'label_for' => 'price_markup_value_above_100',
                'min' => 0,
                'step' => '0.01',
                'description' => __('Nilai markup khusus yang diterapkan ketika harga dasar produk 100 atau lebih. Biarkan kosong untuk menggunakan nilai penyesuaian utama.', 'silverbene-api-integration'),
            )
        );

        add_settings_section(
            'silverbene_api_endpoints_section',
            __('Endpoint Kustom', 'silverbene-api-integration'),
            '__return_false',
            'silverbene-api'
        );

        add_settings_field(
            'products_endpoint',
            __('Endpoint Produk', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_endpoints_section',
            array(
                'label_for' => 'products_endpoint',
                'type' => 'text',
                'placeholder' => '/dropshipping/product_list',
                'description' => __('Endpoint relatif untuk mengambil data produk.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'products_by_date_endpoint',
            __('Endpoint Produk per Tanggal', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_endpoints_section',
            array(
                'label_for' => 'products_by_date_endpoint',
                'type' => 'text',
                'placeholder' => '/dropshipping/product_list_by_date',
                'description' => __('Endpoint relatif untuk mengambil produk berdasarkan rentang tanggal.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'option_qty_endpoint',
            __('Endpoint Stok Opsi', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_endpoints_section',
            array(
                'label_for' => 'option_qty_endpoint',
                'type' => 'text',
                'placeholder' => '/dropshipping/option_qty',
                'description' => __('Endpoint relatif untuk mengambil stok tiap opsi produk.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'orders_endpoint',
            __('Endpoint Pesanan', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_endpoints_section',
            array(
                'label_for' => 'orders_endpoint',
                'type' => 'text',
                'placeholder' => '/dropshipping/create_order',
                'description' => __('Endpoint relatif untuk membuat pesanan di Silverbene.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'shipping_methods_endpoint',
            __('Endpoint Metode Pengiriman', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_endpoints_section',
            array(
                'label_for' => 'shipping_methods_endpoint',
                'type' => 'text',
                'placeholder' => '/dropshipping/get_shipping_method',
                'description' => __('Endpoint relatif untuk mengambil daftar metode pengiriman.', 'silverbene-api-integration'),
            )
        );

        // WhatsApp Settings Section.
        add_settings_section(
            'silverbene_api_whatsapp_section',
            __('Pengaturan WhatsApp', 'silverbene-api-integration'),
            '__return_false',
            'silverbene-api'
        );

        add_settings_field(
            'whatsapp_enabled',
            __('Aktifkan Tombol WhatsApp', 'silverbene-api-integration'),
            array($this, 'render_checkbox_field'),
            'silverbene-api',
            'silverbene_api_whatsapp_section',
            array(
                'label_for' => 'whatsapp_enabled',
                'description' => __('Tampilkan tombol "Buy via WhatsApp" di halaman produk.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'whatsapp_number',
            __('Nomor WhatsApp', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_whatsapp_section',
            array(
                'label_for' => 'whatsapp_number',
                'type' => 'text',
                'placeholder' => __('Contoh: 628123456789', 'silverbene-api-integration'),
                'description' => __('Masukkan nomor WhatsApp dengan format internasional tanpa tanda + atau spasi.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'whatsapp_button_text',
            __('Teks Tombol', 'silverbene-api-integration'),
            array($this, 'render_text_field'),
            'silverbene-api',
            'silverbene_api_whatsapp_section',
            array(
                'label_for' => 'whatsapp_button_text',
                'type' => 'text',
                'placeholder' => __('Buy via WhatsApp', 'silverbene-api-integration'),
                'description' => __('Teks yang akan ditampilkan pada tombol WhatsApp.', 'silverbene-api-integration'),
            )
        );

        add_settings_field(
            'whatsapp_button_position',
            __('Posisi Tombol', 'silverbene-api-integration'),
            array($this, 'render_select_field'),
            'silverbene-api',
            'silverbene_api_whatsapp_section',
            array(
                'label_for' => 'whatsapp_button_position',
                'options' => array(
                    'after_add_to_cart' => __('Setelah Tombol Add to Cart', 'silverbene-api-integration'),
                    'replace_add_to_cart' => __('Ganti Tombol Add to Cart', 'silverbene-api-integration'),
                ),
                'description' => __('Pilih posisi tombol WhatsApp di halaman produk.', 'silverbene-api-integration'),
            )
        );
    }

    /**
     * Sanitasi data pengaturan sebelum disimpan.
     *
     * @param array $input Raw input values.
     * @return array
     */
    public function sanitize_settings($input)
    {
        $output = get_option(SILVERBENE_API_SETTINGS_OPTION, array());

        $allowed_intervals = array('fifteen_minutes', 'hourly', 'twicedaily', 'daily');
        $allowed_markup_types = array('percentage', 'fixed', 'none');

        if (isset($input['api_url'])) {
            $raw_url = esc_url_raw(trim($input['api_url']));
            if ('' !== $raw_url && filter_var($raw_url, FILTER_VALIDATE_URL)) {
                $output['api_url'] = $raw_url;
            }
        }

        if (!empty($input['api_key'])) {
            $output['api_key'] = sanitize_text_field($input['api_key']);
        }

        if (!empty($input['api_secret'])) {
            $output['api_secret'] = sanitize_text_field($input['api_secret']);
        }

        $output['sync_enabled'] = !empty($input['sync_enabled']);

        $sync_interval = isset($input['sync_interval']) ? sanitize_key($input['sync_interval']) : 'hourly';
        $output['sync_interval'] = in_array($sync_interval, $allowed_intervals, true) ? $sync_interval : 'hourly';

        $output['default_category'] = isset($input['default_category']) ? sanitize_text_field($input['default_category']) : '';
        $output['default_brand'] = isset($input['default_brand']) ? sanitize_text_field($input['default_brand']) : '';

        $markup_type = isset($input['price_markup_type']) ? sanitize_key($input['price_markup_type']) : 'none';
        $output['price_markup_type'] = in_array($markup_type, $allowed_markup_types, true) ? $markup_type : 'none';

        $output['price_markup_value'] = isset($input['price_markup_value']) ? max(0, floatval($input['price_markup_value'])) : 0;
        $output['pre_markup_shipping_fee'] = isset($input['pre_markup_shipping_fee']) ? max(0, floatval($input['pre_markup_shipping_fee'])) : 0;

        if (isset($input['sync_start_date'])) {
            $raw_date = trim((string) $input['sync_start_date']);

            if ('' === $raw_date) {
                $output['sync_start_date'] = '';
            } else {
                $timestamp = strtotime($raw_date);
                $is_valid = false !== $timestamp && gmdate('Y-m-d', $timestamp) === $raw_date;

                $output['sync_start_date'] = $is_valid ? $raw_date : '';
            }
        }

        if (isset($input['price_markup_value_below_100'])) {
            $raw_value = trim((string) $input['price_markup_value_below_100']);
            $output['price_markup_value_below_100'] = '' === $raw_value ? '' : max(0, floatval($raw_value));
        }

        if (isset($input['price_markup_value_above_100'])) {
            $raw_value = trim((string) $input['price_markup_value_above_100']);
            $output['price_markup_value_above_100'] = '' === $raw_value ? '' : max(0, floatval($raw_value));
        }

        $endpoints = array(
            'products_endpoint',
            'products_by_date_endpoint',
            'option_qty_endpoint',
            'orders_endpoint',
            'shipping_methods_endpoint',
        );

        foreach ($endpoints as $endpoint) {
            if (!empty($input[$endpoint])) {
                $sanitized = sanitize_text_field(trim($input[$endpoint]));
                if (preg_match('/^\/[a-zA-Z0-9_\-\/]*$/', $sanitized)) {
                    $output[$endpoint] = $sanitized;
                }
            }
        }

        // WhatsApp settings.
        $output['whatsapp_enabled'] = !empty($input['whatsapp_enabled']);

        if (isset($input['whatsapp_number'])) {
            // Only allow digits.
            $output['whatsapp_number'] = preg_replace('/[^0-9]/', '', $input['whatsapp_number']);
        }

        if (isset($input['whatsapp_button_text'])) {
            $output['whatsapp_button_text'] = sanitize_text_field($input['whatsapp_button_text']);
        }

        $allowed_positions = array('after_add_to_cart', 'replace_add_to_cart');
        if (isset($input['whatsapp_button_position'])) {
            $position = sanitize_key($input['whatsapp_button_position']);
            $output['whatsapp_button_position'] = in_array($position, $allowed_positions, true) ? $position : 'after_add_to_cart';
        }

        return $output;
    }

    /**
     * Render settings page.
     */
    public function render_settings_page()
    {
        if (!current_user_can($this->get_manage_capability())) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        $allowed_tabs = array('settings', 'documentation');
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'settings';
        }

        $settings = get_option(SILVERBENE_API_SETTINGS_OPTION, array());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Silverbene API Integration', 'silverbene-api-integration'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=silverbene-api&tab=settings')); ?>"
                    class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Pengaturan', 'silverbene-api-integration'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=silverbene-api&tab=documentation')); ?>"
                    class="nav-tab <?php echo 'documentation' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Dokumentasi', 'silverbene-api-integration'); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                if ('settings' === $active_tab) {
                    $this->render_settings_tab();
                } elseif ('documentation' === $active_tab) {
                    $this->render_documentation_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings tab content.
     */
    private function render_settings_tab()
    {
        ?>
        <p><?php esc_html_e('Masukkan kredensial API dan konfigurasi sinkronisasi agar produk Silverbene otomatis tersinkron dengan WooCommerce Anda.', 'silverbene-api-integration'); ?>
        </p>
        <form method="post" action="options.php">
            <?php
            settings_fields('silverbene_api_settings_group');
            do_settings_sections('silverbene-api');
            submit_button();
            ?>
        </form>

        <hr />

        <h2><?php esc_html_e('Sinkronisasi Manual', 'silverbene-api-integration'); ?></h2>
        <p><?php esc_html_e('Klik tombol di bawah untuk langsung menarik data produk terbaru dari Silverbene.', 'silverbene-api-integration'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('silverbene_manual_sync'); ?>
            <input type="hidden" name="action" value="silverbene_manual_sync" />
            <?php submit_button(__('Sinkronisasi Sekarang', 'silverbene-api-integration'), 'secondary'); ?>
        </form>
        <?php
    }

    /**
     * Render documentation tab content.
     */
    private function render_documentation_tab()
    {
        $last_sync = get_option('silverbene_last_sync_status', array());
        $last_sync_time = isset($last_sync['timestamp']) ? wp_date('Y-m-d H:i:s', $last_sync['timestamp']) : '-';
        $last_sync_status = isset($last_sync['success']) ? ($last_sync['success'] ? __('Berhasil', 'silverbene-api-integration') : __('Gagal', 'silverbene-api-integration')) : '-';
        ?>
        <style>
            .silverbene-docs h2 {
                border-bottom: 1px solid #ccc;
                padding-bottom: 10px;
                margin-top: 30px;
            }

            .silverbene-docs h2:first-child {
                margin-top: 0;
            }

            .silverbene-docs table {
                border-collapse: collapse;
                width: 100%;
                max-width: 800px;
            }

            .silverbene-docs th,
            .silverbene-docs td {
                border: 1px solid #ddd;
                padding: 10px;
                text-align: left;
            }

            .silverbene-docs th {
                background: #f5f5f5;
            }

            .silverbene-docs code {
                background: #f0f0f0;
                padding: 2px 6px;
                border-radius: 3px;
            }

            .silverbene-docs .status-box {
                background: #fff;
                border: 1px solid #ccc;
                padding: 15px;
                margin: 10px 0;
                max-width: 600px;
            }

            .silverbene-docs .status-success {
                border-left: 4px solid #46b450;
            }

            .silverbene-docs .status-error {
                border-left: 4px solid #dc3232;
            }

            .silverbene-docs ul {
                margin-left: 20px;
            }

            .silverbene-docs li {
                margin-bottom: 5px;
            }
        </style>

        <div class="silverbene-docs">
            <h2><?php esc_html_e('Status Sinkronisasi', 'silverbene-api-integration'); ?></h2>
            <div
                class="status-box <?php echo isset($last_sync['success']) && $last_sync['success'] ? 'status-success' : 'status-error'; ?>">
                <p><strong><?php esc_html_e('Sinkronisasi Terakhir:', 'silverbene-api-integration'); ?></strong>
                    <?php echo esc_html($last_sync_time); ?></p>
                <p><strong><?php esc_html_e('Status:', 'silverbene-api-integration'); ?></strong>
                    <?php echo esc_html($last_sync_status); ?></p>
                <?php if (isset($last_sync['message'])): ?>
                    <p><strong><?php esc_html_e('Pesan:', 'silverbene-api-integration'); ?></strong>
                        <?php echo esc_html($last_sync['message']); ?></p>
                <?php endif; ?>
            </div>

            <h2><?php esc_html_e('Tentang Plugin', 'silverbene-api-integration'); ?></h2>
            <p><?php esc_html_e('Plugin Silverbene API Integration menghubungkan toko WooCommerce Anda dengan layanan Silverbene untuk sinkronisasi produk dan pesanan secara otomatis.', 'silverbene-api-integration'); ?>
            </p>

            <h2><?php esc_html_e('Fitur Utama', 'silverbene-api-integration'); ?></h2>
            <ul>
                <li><?php esc_html_e('Sinkronisasi produk otomatis dari Silverbene ke WooCommerce (harga, stok, gambar, kategori, atribut)', 'silverbene-api-integration'); ?>
                </li>
                <li><?php esc_html_e('Tombol sinkronisasi manual di dashboard', 'silverbene-api-integration'); ?></li>
                <li><?php esc_html_e('Pengaturan penyesuaian harga (persentase atau nominal tetap)', 'silverbene-api-integration'); ?>
                </li>
                <li><?php esc_html_e('Pengiriman pesanan WooCommerce ke Silverbene otomatis', 'silverbene-api-integration'); ?>
                </li>
                <li><?php esc_html_e('Penjadwalan cron fleksibel (15 menit, setiap jam, dua kali sehari, harian)', 'silverbene-api-integration'); ?>
                </li>
            </ul>

            <h2><?php esc_html_e('Cara Konfigurasi', 'silverbene-api-integration'); ?></h2>
            <table>
                <tr>
                    <th><?php esc_html_e('Langkah', 'silverbene-api-integration'); ?></th>
                    <th><?php esc_html_e('Deskripsi', 'silverbene-api-integration'); ?></th>
                </tr>
                <tr>
                    <td>1</td>
                    <td><?php esc_html_e('Masukkan URL API Silverbene (contoh: https://s.silverbene.com/api)', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
                <tr>
                    <td>2</td>
                    <td><?php esc_html_e('Masukkan API Key yang didapat dari dashboard Silverbene', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
                <tr>
                    <td>3</td>
                    <td><?php esc_html_e('Aktifkan sinkronisasi otomatis dan pilih interval yang diinginkan', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
                <tr>
                    <td>4</td>
                    <td><?php esc_html_e('Atur kategori default dan penyesuaian harga (opsional)', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
                <tr>
                    <td>5</td>
                    <td><?php esc_html_e('Klik "Save Changes" dan lakukan sinkronisasi manual pertama', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Sinkronisasi Pesanan', 'silverbene-api-integration'); ?></h2>
            <p><?php esc_html_e('Pesanan WooCommerce akan dikirim ke Silverbene secara otomatis ketika status berubah menjadi:', 'silverbene-api-integration'); ?>
            </p>
            <ul>
                <li><code>processing</code> - <?php esc_html_e('Sedang Diproses', 'silverbene-api-integration'); ?></li>
                <li><code>completed</code> - <?php esc_html_e('Selesai', 'silverbene-api-integration'); ?></li>
            </ul>

            <h2><?php esc_html_e('Troubleshooting', 'silverbene-api-integration'); ?></h2>
            <table>
                <tr>
                    <th><?php esc_html_e('Masalah', 'silverbene-api-integration'); ?></th>
                    <th><?php esc_html_e('Solusi', 'silverbene-api-integration'); ?></th>
                </tr>
                <tr>
                    <td><?php esc_html_e('Produk tidak muncul', 'silverbene-api-integration'); ?></td>
                    <td><?php esc_html_e('Cek API Key dan endpoint di pengaturan. Pastikan produk memiliki stok.', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Sinkronisasi gagal', 'silverbene-api-integration'); ?></td>
                    <td><?php esc_html_e('Cek log di WooCommerce → Status → Logs (sumber: silverbene-api-sync)', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Cron tidak berjalan', 'silverbene-api-integration'); ?></td>
                    <td><?php esc_html_e('Pastikan WP-Cron aktif atau gunakan sistem cron server.', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Timeout saat sinkronisasi', 'silverbene-api-integration'); ?></td>
                    <td><?php esc_html_e('Gunakan tanggal mulai yang lebih dekat untuk mengurangi jumlah produk.', 'silverbene-api-integration'); ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Informasi Teknis', 'silverbene-api-integration'); ?></h2>
            <table>
                <tr>
                    <th><?php esc_html_e('Item', 'silverbene-api-integration'); ?></th>
                    <th><?php esc_html_e('Nilai', 'silverbene-api-integration'); ?></th>
                </tr>
                <tr>
                    <td><?php esc_html_e('Versi Plugin', 'silverbene-api-integration'); ?></td>
                    <td><code><?php echo esc_html(defined('SILVERBENE_API_VERSION') ? SILVERBENE_API_VERSION : '1.0.0'); ?></code>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Versi PHP', 'silverbene-api-integration'); ?></td>
                    <td><code><?php echo esc_html(PHP_VERSION); ?></code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Versi WordPress', 'silverbene-api-integration'); ?></td>
                    <td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Versi WooCommerce', 'silverbene-api-integration'); ?></td>
                    <td><code><?php echo esc_html(defined('WC_VERSION') ? WC_VERSION : '-'); ?></code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Memory Limit', 'silverbene-api-integration'); ?></td>
                    <td><code><?php echo esc_html(ini_get('memory_limit')); ?></code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Max Execution Time', 'silverbene-api-integration'); ?></td>
                    <td><code><?php echo esc_html(ini_get('max_execution_time')); ?>s</code></td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Handle manual sync request.
     */
    public function handle_manual_sync()
    {
        if (!current_user_can($this->get_manage_capability())) {
            wp_die(__('Anda tidak memiliki izin untuk melakukan tindakan ini.', 'silverbene-api-integration'));
        }

        check_admin_referer('silverbene_manual_sync');

        $result = $this->sync_handler->sync_products(true);

        $redirect_url = add_query_arg(
            array(
                'page' => 'silverbene-api',
                'synced' => $result ? 'true' : 'failed',
                'timestamp' => time(),
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Setelah pengaturan disimpan, segarkan cache settings dan jadwalkan ulang cron.
     */
    public function after_settings_saved()
    {
        $this->client->refresh_settings();
        $this->maybe_reschedule_cron();
    }

    /**
     * Tampilkan notifikasi admin setelah sinkronisasi manual.
     */
    public function maybe_show_admin_notice()
    {
        if (!isset($_GET['page']) || 'silverbene-api' !== $_GET['page']) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $notice_displayed = false;

        if (function_exists('get_transient')) {
            $notice = get_transient('silverbene_sync_admin_notice');

            if (!empty($notice['message'])) {
                $type = !empty($notice['type']) ? $notice['type'] : 'info';
                $class = 'notice notice-' . ('error' === $type ? 'error' : ('success' === $type ? 'success' : 'info')) . ' is-dismissible';

                printf(
                    '<div class="%1$s"><p>%2$s</p></div>',
                    esc_attr($class),
                    esc_html($notice['message'])
                );

                delete_transient('silverbene_sync_admin_notice');
                $notice_displayed = true;
            }
        }

        if (!isset($_GET['synced'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        if ('true' === $_GET['synced']) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sinkronisasi produk berhasil dijalankan.', 'silverbene-api-integration') . '</p></div>';

            return;
        }

        if ('failed' === $_GET['synced'] && !$notice_displayed) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Sinkronisasi produk gagal dijalankan. Silakan cek log untuk detail.', 'silverbene-api-integration') . '</p></div>';
        }
    }

    /**
     * Tambahkan custom interval cron.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function register_custom_cron_schedules($schedules)
    {
        if (!isset($schedules['fifteen_minutes'])) {
            $schedules['fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Setiap 15 Menit', 'silverbene-api-integration'),
            );
        }

        return $schedules;
    }

    /**
     * Jadwalkan ulang cron bila opsi berubah.
     */
    public function maybe_reschedule_cron()
    {
        $settings = $this->client->get_settings();
        $enabled = !empty($settings['sync_enabled']);
        $interval = !empty($settings['sync_interval']) ? $settings['sync_interval'] : 'hourly';

        $timestamp = wp_next_scheduled('silverbene_api_sync_products');

        if (!$enabled) {
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'silverbene_api_sync_products');
            }
            return;
        }

        if ($timestamp) {
            $current_schedule = wp_get_schedule('silverbene_api_sync_products');
            if ($current_schedule === $interval) {
                return;
            }
            wp_unschedule_event($timestamp, 'silverbene_api_sync_products');
        }

        wp_schedule_event(time(), $interval, 'silverbene_api_sync_products');
    }

    /**
     * Render helper for text fields.
     *
     * @param array $args Field arguments.
     */
    public function render_text_field($args)
    {
        $settings = get_option(SILVERBENE_API_SETTINGS_OPTION, array());
        $value = isset($settings[$args['label_for']]) ? $settings[$args['label_for']] : '';
        $type = isset($args['type']) ? $args['type'] : 'text';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        ?>
        <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($args['label_for']); ?>"
            name="<?php echo esc_attr(SILVERBENE_API_SETTINGS_OPTION . '[' . $args['label_for'] . ']'); ?>"
            value="<?php echo esc_attr($value); ?>" class="regular-text"
            placeholder="<?php echo esc_attr($placeholder); ?>" />
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
    <?php
    }

    /**
     * Render helper for checkbox field.
     *
     * @param array $args Field args.
     */
    public function render_checkbox_field($args)
    {
        $settings = get_option(SILVERBENE_API_SETTINGS_OPTION, array());
        $checked = !empty($settings[$args['label_for']]);
        ?>
        <label for="<?php echo esc_attr($args['label_for']); ?>">
            <input type="checkbox" id="<?php echo esc_attr($args['label_for']); ?>"
                name="<?php echo esc_attr(SILVERBENE_API_SETTINGS_OPTION . '[' . $args['label_for'] . ']'); ?>" value="1"
                <?php checked($checked); ?> />
            <?php if (!empty($args['description'])): ?>
                <span class="description"><?php echo esc_html($args['description']); ?></span>
            <?php endif; ?>
        </label>
        <?php
    }

    /**
     * Render helper for select field.
     *
     * @param array $args Field args.
     */
    public function render_select_field($args)
    {
        $settings = get_option(SILVERBENE_API_SETTINGS_OPTION, array());
        $value = isset($settings[$args['label_for']]) ? $settings[$args['label_for']] : '';
        ?>
        <select id="<?php echo esc_attr($args['label_for']); ?>"
            name="<?php echo esc_attr(SILVERBENE_API_SETTINGS_OPTION . '[' . $args['label_for'] . ']'); ?>">
            <?php foreach ($args['options'] as $option_value => $label): ?>
                <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, $option_value); ?>>
                    <?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
    <?php
    }

    /**
     * Render helper for number field.
     *
     * @param array $args Field args.
     */
    public function render_number_field($args)
    {
        $settings = get_option(SILVERBENE_API_SETTINGS_OPTION, array());
        $value = isset($settings[$args['label_for']]) ? $settings[$args['label_for']] : '';
        $min = isset($args['min']) ? $args['min'] : 0;
        $step = isset($args['step']) ? $args['step'] : '1';
        ?>
        <input type="number" id="<?php echo esc_attr($args['label_for']); ?>"
            name="<?php echo esc_attr(SILVERBENE_API_SETTINGS_OPTION . '[' . $args['label_for'] . ']'); ?>"
            value="<?php echo esc_attr($value); ?>" min="<?php echo esc_attr($min); ?>"
            step="<?php echo esc_attr($step); ?>" />
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
    <?php
    }
}
