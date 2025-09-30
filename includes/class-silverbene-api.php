<?php
class Silverbene_API {
    private $api_url = 'https://api.silverbene.com/v1';
    private $api_token = 'YOUR_API_TOKEN';

    public function initialize() {
        // Menambahkan menu dan sub-menu di admin
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Silverbene API',
            'Silverbene API',
            'manage_options',
            'silverbene-api',
            array( $this, 'settings_page' ),
            'dashicons-cart'
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Pengaturan API Silverbene</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'silverbene_api_settings' );
                do_settings_sections( 'silverbene-api' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
