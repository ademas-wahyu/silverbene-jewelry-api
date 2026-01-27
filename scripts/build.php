#!/usr/bin/env php
<?php
/**
 * Build Script untuk Silverbene Jewelry API Plugin
 * 
 * Script ini akan mengompres plugin menjadi file .zip
 * dan menyimpannya di folder dist/
 */

// Direktori root plugin
$pluginDir = dirname(__DIR__);
$pluginName = 'silverbene-jewelry-api';

// Folder output
$distDir = $pluginDir . '/dist';

// Nama file zip dengan versi (bisa diambil dari file utama plugin)
$mainPluginFile = $pluginDir . '/silverbene-api-integration.php';
$version = '1.0.0';

// Coba ambil versi dari file plugin utama
if (file_exists($mainPluginFile)) {
    $content = file_get_contents($mainPluginFile);
    if (preg_match('/Version:\s*(.+)/i', $content, $matches)) {
        $version = trim($matches[1]);
    }
}

$zipFileName = "{$pluginName}-{$version}.zip";
$zipFilePath = $distDir . '/' . $zipFileName;

// File dan folder yang akan dikecualikan dari zip
$excludePatterns = [
    '.git',
    '.gitignore',
    '.phpunit.result.cache',
    'phpunit.xml.dist',
    'tests',
    'vendor',
    'composer.lock',
    'scripts',
    'dist',
    'node_modules',
    '.DS_Store',
    'Thumbs.db',
    '.env',
    '.env.local',
];

echo "🔧 Memulai proses build...\n";
echo "📁 Plugin: {$pluginName}\n";
echo "📌 Versi: {$version}\n\n";

// Buat folder dist jika belum ada
if (!is_dir($distDir)) {
    mkdir($distDir, 0755, true);
    echo "✅ Folder dist/ dibuat\n";
}

// Hapus file zip lama jika ada
if (file_exists($zipFilePath)) {
    unlink($zipFilePath);
    echo "🗑️  File zip lama dihapus\n";
}

// Buat file zip
$zip = new ZipArchive();
if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "❌ Gagal membuat file zip\n";
    exit(1);
}

/**
 * Fungsi untuk menambahkan file ke zip secara rekursif
 */
function addFilesToZip(ZipArchive $zip, string $dir, string $baseDir, array $excludePatterns, string $zipPrefix): int
{
    $count = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($baseDir) + 1);

        // Cek apakah file/folder harus dikecualikan
        $shouldExclude = false;
        foreach ($excludePatterns as $pattern) {
            if (strpos($relativePath, $pattern) === 0 || strpos($relativePath, '/' . $pattern) !== false) {
                $shouldExclude = true;
                break;
            }
        }

        if ($shouldExclude) {
            continue;
        }

        // Tambahkan ke zip dengan prefix folder plugin
        $zipPath = $zipPrefix . '/' . $relativePath;

        if ($file->isDir()) {
            $zip->addEmptyDir($zipPath);
        } else {
            $zip->addFile($filePath, $zipPath);
            $count++;
        }
    }

    return $count;
}

echo "📦 Mengompres file...\n";

// Tambahkan file ke zip
$fileCount = addFilesToZip($zip, $pluginDir, $pluginDir, $excludePatterns, $pluginName);

$zip->close();

// Hitung ukuran file
$fileSize = filesize($zipFilePath);
$fileSizeFormatted = number_format($fileSize / 1024, 2) . ' KB';
if ($fileSize > 1024 * 1024) {
    $fileSizeFormatted = number_format($fileSize / (1024 * 1024), 2) . ' MB';
}

echo "\n✅ Build selesai!\n";
echo "📁 File: dist/{$zipFileName}\n";
echo "📊 Ukuran: {$fileSizeFormatted}\n";
echo "📄 Total file: {$fileCount} file\n";
