<?php
/**
 * QRコード生成（Google Charts API利用）
 * phpqrcodeライブラリ不要で軽量に実現
 */

class QRCode
{
    /**
     * QRコード画像のURLを返す（Google Charts API）
     */
    public static function getImageUrl(string $data, int $size = 200): string
    {
        return 'https://chart.googleapis.com/chart?chs=' . $size . 'x' . $size
            . '&cht=qr&chl=' . urlencode($data)
            . '&choe=UTF-8';
    }

    /**
     * QRコード画像をPNG形式で直接出力
     */
    public static function outputPng(string $data, int $size = 200): void
    {
        $url = self::getImageUrl($data, $size);
        $image = file_get_contents($url);
        if ($image !== false) {
            header('Content-Type: image/png');
            header('Content-Disposition: attachment; filename="qrcode.png"');
            echo $image;
        } else {
            http_response_code(500);
            echo 'QRコード生成に失敗しました。';
        }
    }

    /**
     * QRコードのimgタグを返す
     */
    public static function imgTag(string $data, int $size = 150, string $alt = 'QR Code'): string
    {
        $url = htmlspecialchars(self::getImageUrl($data, $size), ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
        return '<img src="' . $url . '" alt="' . $alt . '" width="' . $size . '" height="' . $size . '" class="qr-code">';
    }
}
