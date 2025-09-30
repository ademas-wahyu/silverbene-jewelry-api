# Silverbene API Integration

Plugin WordPress ini menghubungkan toko WooCommerce Anda dengan layanan Silverbene. Produk akan ditarik secara berkala sesuai jadwal yang Anda tentukan, dan setiap pesanan WooCommerce berstatus *processing* maupun *completed* akan diteruskan ke Silverbene secara otomatis.

## Fitur Utama
- Sinkronisasi produk otomatis dari Silverbene ke WooCommerce (termasuk harga, stok, gambar, kategori, dan atribut).
- Tombol sinkronisasi manual langsung dari dasbor WordPress.
- Pengaturan penyesuaian harga (persentase atau nominal tetap).
- Pengiriman pesanan WooCommerce ke Silverbene ketika status berubah menjadi *processing* atau *completed*.
- Penjadwalan cron fleksibel (15 menit, setiap jam, dua kali sehari, atau harian).
- Dukungan endpoint kustom untuk menyesuaikan struktur API Silverbene Anda.

## Prasyarat
1. WordPress 6.0 atau lebih baru.
2. WooCommerce 7.0 atau lebih baru.
3. PHP 7.4 atau lebih baru.
4. Kredensial API Silverbene (URL dasar, API Key, dan jika diperlukan API Secret).
5. Akses untuk mengunggah plugin (akun Administrator WordPress).

## Instalasi
Ikuti langkah berikut untuk memasang plugin:

1. **Unduh berkas plugin**
   - Jika Anda memiliki arsip ZIP plugin ini, simpan di komputer lokal.
   - Jika bekerja dari repositori, kompres folder `silverbene-jewelry-api` menjadi file ZIP terlebih dahulu.

2. **Masuk ke dasbor WordPress**
   - Login sebagai Administrator di situs WooCommerce Anda.

3. **Unggah plugin**
   - Buka menu **Plugins → Add New**.
   - Klik tombol **Upload Plugin** lalu **Choose File** dan pilih file ZIP plugin.
   - Tekan **Install Now** dan tunggu proses unggah selesai.

4. **Aktifkan plugin**
   - Setelah instalasi selesai, klik **Activate Plugin**.
   - Plugin akan langsung mendaftarkan jadwal cron `silverbene_api_sync_products` dengan interval per jam secara bawaan.

## Konfigurasi
Setelah plugin aktif, lakukan konfigurasi kredensial dan sinkronisasi:

1. Masuk ke menu **Silverbene API** yang muncul di sidebar admin WordPress.
2. Pada tab **Kredensial API**, isi data berikut:
   - **URL API** – alamat dasar REST API Silverbene (contoh: `https://api.silverbene.com/v1`).
   - **API Key** – token yang diberikan oleh Silverbene.
   - **API Secret** – opsional, isi jika Silverbene menyediakan secret tambahan.
3. Pada bagian **Pengaturan Sinkronisasi**:
   - Centang **Aktifkan Sinkronisasi Otomatis** bila ingin penarikan berjalan berkala.
   - Pilih **Interval Sinkronisasi** sesuai kebutuhan (15 menit, setiap jam, dua kali sehari, atau harian).
   - Isi **Kategori Default** untuk produk yang datang tanpa kategori dari Silverbene.
   - Tentukan **Tipe Penyesuaian Harga** (`Persentase`, `Nominal Tetap`, atau `Tanpa Penyesuaian`).
   - Masukkan **Nilai Penyesuaian Harga** sesuai tipe yang dipilih.
4. Pada bagian **Endpoint Kustom**:
   - **Endpoint Produk** – path relatif untuk menarik data produk (default `/products`).
   - **Endpoint Pesanan** – path relatif untuk mengirim pesanan (default `/orders`).
5. Klik **Save Changes** di bagian bawah halaman.

> **Catatan:** Setiap kali pengaturan disimpan, plugin otomatis memuat ulang konfigurasi dan mengatur ulang jadwal cron agar mengikuti interval terbaru.

## Menjalankan Sinkronisasi Produk
- **Sinkronisasi Otomatis**: Bila opsi **Aktifkan Sinkronisasi Otomatis** dicentang, produk akan diperbarui sesuai interval yang dipilih. Plugin akan membuat, memperbarui, dan melengkapi produk dengan kategori, tag, atribut, serta gambar.
- **Sinkronisasi Manual**: Pada halaman pengaturan Silverbene, klik tombol **Sinkronisasi Sekarang** untuk menarik data terbaru kapan saja. Setelah selesai, notifikasi sukses akan muncul di bagian atas halaman.

## Sinkronisasi Pesanan ke Silverbene
- Pesanan WooCommerce akan dikirim ke Silverbene ketika statusnya berubah menjadi **Processing** atau **Completed**.
- Plugin akan menandai pesanan yang sukses dikirim dengan meta `_silverbene_order_id` dan menambahkan catatan di detail pesanan.
- Jika terjadi kegagalan, plugin juga menambahkan catatan sehingga Anda dapat meninjau log WooCommerce untuk investigasi lebih lanjut.

## Tips & Troubleshooting
- Pastikan cron WordPress berjalan. Anda dapat menggunakan plugin manajemen cron atau WP-CLI untuk memastikan jadwal `silverbene_api_sync_products` aktif.
- Jika produk tidak muncul, cek kembali API Key/Secret dan endpoint di halaman pengaturan.
- Untuk debugging tambahan, aktifkan log WooCommerce (`WooCommerce → Status → Logs`) dan cari sumber `silverbene-api-sync`.
- Pastikan SKU produk di Silverbene unik karena sinkronisasi menggunakan SKU untuk mencocokkan produk.

## Dukungan
Untuk dukungan lebih lanjut atau permintaan fitur, hubungi pengembang plugin ini melalui kanal resmi perusahaan atau repositori proyek.
