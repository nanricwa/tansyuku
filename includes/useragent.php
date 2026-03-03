<?php
/**
 * User-Agent解析（デバイス/OS/ブラウザ判定）
 */

class UserAgentParser
{
    /**
     * UAを解析して情報を返す
     * @return array ['device_type' => string, 'os' => string, 'browser' => string]
     */
    public static function parse(string $ua): array
    {
        return [
            'device_type' => self::detectDeviceType($ua),
            'os' => self::detectOS($ua),
            'browser' => self::detectBrowser($ua),
        ];
    }

    private static function detectDeviceType(string $ua): string
    {
        $ua = strtolower($ua);

        // タブレット判定（モバイルより先にチェック）
        if (preg_match('/ipad|tablet|playbook|silk|kindle/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/android/i', $ua) && !preg_match('/mobile/i', $ua)) {
            return 'tablet';
        }

        // モバイル判定
        if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone|blackberry|opera mini|opera mobi/i', $ua)) {
            return 'mobile';
        }

        // bot判定
        if (preg_match('/bot|crawler|spider|slurp|mediapartners/i', $ua)) {
            return 'other';
        }

        return 'pc';
    }

    private static function detectOS(string $ua): string
    {
        $patterns = [
            'Windows 11'  => '/Windows NT 10\.0.*Build\/2[2-9]|Windows NT 10\.0.*Win64/i',
            'Windows 10'  => '/Windows NT 10\.0/i',
            'Windows 8.1' => '/Windows NT 6\.3/i',
            'Windows 8'   => '/Windows NT 6\.2/i',
            'Windows 7'   => '/Windows NT 6\.1/i',
            'macOS'       => '/Macintosh|Mac OS X/i',
            'iOS'         => '/iPhone|iPad|iPod/i',
            'Android'     => '/Android/i',
            'Linux'       => '/Linux/i',
            'Chrome OS'   => '/CrOS/i',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return 'Other';
    }

    private static function detectBrowser(string $ua): string
    {
        // 順序が重要: 特殊なブラウザを先に判定
        $patterns = [
            'Edge'    => '/Edg(?:e|A|iOS)?\/[\d.]+/i',
            'Opera'   => '/OPR\/|Opera/i',
            'Vivaldi' => '/Vivaldi/i',
            'Brave'   => '/Brave/i',
            'Samsung' => '/SamsungBrowser/i',
            'LINE'    => '/Line\//i',
            'Chrome'  => '/Chrome\/[\d.]+/i',
            'Firefox' => '/Firefox\/[\d.]+/i',
            'Safari'  => '/Safari\/[\d.]+/i',
            'IE'      => '/MSIE|Trident/i',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return 'Other';
    }
}
