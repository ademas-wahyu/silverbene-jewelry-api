# Silverbene API Integration

<p align="center">
  <strong>🇬🇧 English</strong> | <a href="#bahasa-indonesia">🇮🇩 Bahasa Indonesia</a>
</p>

A WordPress plugin that seamlessly integrates your WooCommerce store with Silverbene services. Products are automatically synchronized on schedule, and WooCommerce orders are forwarded to Silverbene when their status changes to _processing_ or _completed_.

## Features

| Feature                    | Description                                                                                            |
| -------------------------- | ------------------------------------------------------------------------------------------------------ |
| 🔄 **Auto Product Sync**   | Automatically pull products from Silverbene including price, stock, images, categories, and attributes |
| 📦 **Order Sync**          | Automatically send orders to Silverbene when status changes                                            |
| 💰 **Price Adjustment**    | Apply percentage or fixed markup to product prices                                                     |
| ⏰ **Flexible Scheduling** | Choose sync interval: 15 minutes, hourly, twice daily, or daily                                        |
| 🔧 **Custom Endpoints**    | Configure custom API endpoints for your Silverbene setup                                               |
| 📊 **Documentation Tab**   | Built-in documentation with sync status and troubleshooting                                            |

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+
- Silverbene API credentials (API URL, API Key, optionally API Secret)
- WordPress Administrator access

## Installation

### Method 1: Upload via WordPress Admin

1. Download the plugin ZIP file
2. Go to **Plugins → Add New → Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Click **Activate Plugin**

### Method 2: Manual Installation

1. Extract the plugin folder to `/wp-content/plugins/`
2. Go to **Plugins** in WordPress admin
3. Find "Silverbene API Integration" and click **Activate**

### Method 3: Composer (for developers)

```bash
cd wp-content/plugins
git clone https://github.com/ademas-wahyu/silverbene-jewelry-api.git
cd silverbene-jewelry-api
composer install
```

## Configuration

### Step 1: Access Settings

Navigate to **Silverbene API** in the WordPress admin sidebar.

### Step 2: API Credentials

| Field          | Description                         | Example                        |
| -------------- | ----------------------------------- | ------------------------------ |
| **API URL**    | Base URL of Silverbene REST API     | `https://s.silverbene.com/api` |
| **API Key**    | Access token from Silverbene        | `your-api-key-here`            |
| **API Secret** | Optional, if required by Silverbene | `your-secret-here`             |

### Step 3: Sync Settings

| Setting              | Options                           | Description                                |
| -------------------- | --------------------------------- | ------------------------------------------ |
| **Enable Auto Sync** | On/Off                            | Toggle automatic product synchronization   |
| **Sync Interval**    | 15min, Hourly, Twice Daily, Daily | How often to sync products                 |
| **Sync Start Date**  | Date                              | Only sync products updated after this date |
| **Default Category** | Text                              | Category for products without one          |
| **Default Brand**    | Text                              | Brand attribute for all synced products    |

### Step 4: Price Adjustment

| Setting                 | Options                 | Description                        |
| ----------------------- | ----------------------- | ---------------------------------- |
| **Markup Type**         | Percentage, Fixed, None | How to adjust prices               |
| **Markup Value**        | Number                  | Amount to add (% or fixed)         |
| **Below $100 Markup**   | Number                  | Special markup for cheap items     |
| **Above $100 Markup**   | Number                  | Special markup for expensive items |
| **Pre-markup Shipping** | Number                  | Add shipping cost before markup    |

### Step 5: Custom Endpoints (Advanced)

| Endpoint         | Default                              | Description                     |
| ---------------- | ------------------------------------ | ------------------------------- |
| Products         | `/dropshipping/product_list`         | Fetch all products              |
| Products by Date | `/dropshipping/product_list_by_date` | Fetch products with date filter |
| Option Stock     | `/dropshipping/option_qty`           | Fetch product variant stock     |
| Orders           | `/dropshipping/create_order`         | Submit new orders               |
| Shipping Methods | `/dropshipping/get_shipping_method`  | Get available shipping          |

## Usage

### Product Synchronization

**Automatic Sync:**

- Enable auto sync in settings
- Products sync automatically based on your interval
- Plugin creates, updates products with categories, tags, attributes, and images

**Manual Sync:**

- Go to **Silverbene API → Settings**
- Click **Sinkronisasi Sekarang** (Sync Now)
- Wait for completion notification

### Order Synchronization

Orders are sent to Silverbene when:

- Status changes to **Processing**
- Status changes to **Completed**

The plugin:

- Adds `_silverbene_order_id` meta to successful orders
- Adds order notes for success/failure
- Logs errors for debugging

### Documentation Tab

Access **Silverbene API → Documentation** to view:

- Last sync status and timestamp
- Plugin features overview
- Configuration guide
- Troubleshooting tips
- Technical information (PHP version, memory limit, etc.)

## Troubleshooting

| Problem                | Solution                                                                                |
| ---------------------- | --------------------------------------------------------------------------------------- |
| Products not appearing | Check API Key and endpoints in settings                                                 |
| Sync fails             | Check WooCommerce logs: **WooCommerce → Status → Logs** (source: `silverbene-api-sync`) |
| Cron not running       | Verify WP-Cron is active or use server cron                                             |
| Timeout during sync    | Use a more recent start date to reduce product count                                    |
| Duplicate products     | Ensure SKUs are unique in Silverbene                                                    |
| Images not downloading | Check server has `allow_url_fopen` enabled                                              |

## Development

### Running Tests

```bash
composer install
composer test
```

### Test Coverage

The plugin includes PHPUnit tests for:

- API client functionality
- Error handling
- Response processing

## Changelog

### v1.1.0 (2026-01-06)

- **Fixed:** API timeout reduced from 300s to 60s
- **Fixed:** Memory exhaustion prevention with 85% threshold
- **Fixed:** Input validation strengthened with whitelists
- **Fixed:** Cron race condition resolved
- **New:** Documentation tab in admin settings

### v1.0.0

- Initial release
- Product synchronization
- Order synchronization
- Price markup system

## Support

For support or feature requests, contact the developer or open an issue on the project repository.

---

<a name="bahasa-indonesia"></a>

# Silverbene API Integration

<p align="center">
  <a href="#silverbene-api-integration">🇬🇧 English</a> | <strong>🇮🇩 Bahasa Indonesia</strong>
</p>

Plugin WordPress yang menghubungkan toko WooCommerce Anda dengan layanan Silverbene. Produk akan ditarik secara berkala sesuai jadwal yang ditentukan, dan setiap pesanan WooCommerce berstatus _processing_ maupun _completed_ akan diteruskan ke Silverbene secara otomatis.

## Fitur

| Fitur                               | Deskripsi                                                                          |
| ----------------------------------- | ---------------------------------------------------------------------------------- |
| 🔄 **Sinkronisasi Produk Otomatis** | Menarik produk dari Silverbene termasuk harga, stok, gambar, kategori, dan atribut |
| 📦 **Sinkronisasi Pesanan**         | Mengirim pesanan ke Silverbene saat status berubah                                 |
| 💰 **Penyesuaian Harga**            | Menambahkan markup persentase atau nominal tetap                                   |
| ⏰ **Penjadwalan Fleksibel**        | Pilih interval: 15 menit, per jam, 2x sehari, atau harian                          |
| 🔧 **Endpoint Kustom**              | Konfigurasi endpoint API sesuai setup Silverbene Anda                              |
| 📊 **Tab Dokumentasi**              | Dokumentasi bawaan dengan status sync dan troubleshooting                          |

## Prasyarat

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+
- Kredensial API Silverbene (URL API, API Key, opsional API Secret)
- Akses Administrator WordPress

## Instalasi

### Metode 1: Upload via Admin WordPress

1. Unduh file ZIP plugin
2. Buka **Plugins → Add New → Upload Plugin**
3. Pilih file ZIP dan klik **Install Now**
4. Klik **Activate Plugin**

### Metode 2: Instalasi Manual

1. Ekstrak folder plugin ke `/wp-content/plugins/`
2. Buka menu **Plugins** di admin WordPress
3. Cari "Silverbene API Integration" dan klik **Activate**

### Metode 3: Composer (untuk developer)

```bash
cd wp-content/plugins
git clone https://github.com/ademas-wahyu/silverbene-jewelry-api.git
cd silverbene-jewelry-api
composer install
```

## Konfigurasi

### Langkah 1: Akses Pengaturan

Buka menu **Silverbene API** di sidebar admin WordPress.

### Langkah 2: Kredensial API

| Field          | Deskripsi                            | Contoh                         |
| -------------- | ------------------------------------ | ------------------------------ |
| **URL API**    | URL dasar REST API Silverbene        | `https://s.silverbene.com/api` |
| **API Key**    | Token akses dari Silverbene          | `your-api-key-here`            |
| **API Secret** | Opsional, jika diperlukan Silverbene | `your-secret-here`             |

### Langkah 3: Pengaturan Sinkronisasi

| Pengaturan                 | Pilihan                              | Deskripsi                                           |
| -------------------------- | ------------------------------------ | --------------------------------------------------- |
| **Aktifkan Sync Otomatis** | On/Off                               | Toggle sinkronisasi produk otomatis                 |
| **Interval Sync**          | 15 menit, Per Jam, 2x Sehari, Harian | Seberapa sering sync produk                         |
| **Tanggal Mulai Sync**     | Tanggal                              | Hanya sync produk yang diupdate setelah tanggal ini |
| **Kategori Default**       | Teks                                 | Kategori untuk produk tanpa kategori                |
| **Brand Default**          | Teks                                 | Atribut brand untuk semua produk                    |

### Langkah 4: Penyesuaian Harga

| Pengaturan                      | Pilihan                      | Deskripsi                              |
| ------------------------------- | ---------------------------- | -------------------------------------- |
| **Tipe Markup**                 | Persentase, Tetap, Tidak Ada | Cara menyesuaikan harga                |
| **Nilai Markup**                | Angka                        | Jumlah yang ditambahkan (% atau tetap) |
| **Markup Di Bawah $100**        | Angka                        | Markup khusus untuk produk murah       |
| **Markup Di Atas $100**         | Angka                        | Markup khusus untuk produk mahal       |
| **Biaya Pengiriman Pre-markup** | Angka                        | Tambah biaya kirim sebelum markup      |

### Langkah 5: Endpoint Kustom (Lanjutan)

| Endpoint           | Default                              | Deskripsi                          |
| ------------------ | ------------------------------------ | ---------------------------------- |
| Produk             | `/dropshipping/product_list`         | Ambil semua produk                 |
| Produk per Tanggal | `/dropshipping/product_list_by_date` | Ambil produk dengan filter tanggal |
| Stok Opsi          | `/dropshipping/option_qty`           | Ambil stok varian produk           |
| Pesanan            | `/dropshipping/create_order`         | Kirim pesanan baru                 |
| Metode Pengiriman  | `/dropshipping/get_shipping_method`  | Ambil metode pengiriman            |

## Penggunaan

### Sinkronisasi Produk

**Sync Otomatis:**

- Aktifkan auto sync di pengaturan
- Produk akan sync otomatis berdasarkan interval
- Plugin membuat/update produk dengan kategori, tag, atribut, dan gambar

**Sync Manual:**

- Buka **Silverbene API → Pengaturan**
- Klik **Sinkronisasi Sekarang**
- Tunggu notifikasi selesai

### Sinkronisasi Pesanan

Pesanan dikirim ke Silverbene ketika:

- Status berubah ke **Processing** (Sedang Diproses)
- Status berubah ke **Completed** (Selesai)

Plugin akan:

- Menambahkan meta `_silverbene_order_id` ke pesanan sukses
- Menambahkan catatan pesanan untuk sukses/gagal
- Mencatat error untuk debugging

### Tab Dokumentasi

Akses **Silverbene API → Dokumentasi** untuk melihat:

- Status sync terakhir dan timestamp
- Overview fitur plugin
- Panduan konfigurasi
- Tips troubleshooting
- Informasi teknis (versi PHP, memory limit, dll.)

## Troubleshooting

| Masalah                  | Solusi                                                                               |
| ------------------------ | ------------------------------------------------------------------------------------ |
| Produk tidak muncul      | Cek API Key dan endpoint di pengaturan                                               |
| Sync gagal               | Cek log WooCommerce: **WooCommerce → Status → Logs** (sumber: `silverbene-api-sync`) |
| Cron tidak jalan         | Pastikan WP-Cron aktif atau gunakan server cron                                      |
| Timeout saat sync        | Gunakan tanggal mulai yang lebih dekat untuk mengurangi jumlah produk                |
| Produk duplikat          | Pastikan SKU unik di Silverbene                                                      |
| Gambar tidak terdownload | Cek server memiliki `allow_url_fopen` aktif                                          |

## Development

### Menjalankan Test

```bash
composer install
composer test
```

### Cakupan Test

Plugin ini menyertakan test PHPUnit untuk:

- Fungsionalitas API client
- Penanganan error
- Pemrosesan response

## Changelog

### v1.1.0 (2026-01-06)

- **Diperbaiki:** Timeout API dikurangi dari 300 detik ke 60 detik
- **Diperbaiki:** Pencegahan memory exhaustion dengan threshold 85%
- **Diperbaiki:** Validasi input diperkuat dengan whitelist
- **Diperbaiki:** Race condition cron diselesaikan
- **Baru:** Tab Dokumentasi di pengaturan admin

### v1.0.0

- Rilis awal
- Sinkronisasi produk
- Sinkronisasi pesanan
- Sistem markup harga

## Dukungan

Untuk dukungan atau permintaan fitur, hubungi developer atau buka issue di repositori proyek.

---

<p align="center">
  Made with ❤️ by <a href="https://github.com/ademas-wahyu">Wahyu (Vodeco Dev Core)</a>
</p>
