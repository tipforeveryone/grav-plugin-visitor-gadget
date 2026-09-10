<?php

namespace Grav\Plugin\VisitorGadget;

use Grav\Common\Grav;

/**
 * Đọc/ghi user/data/visitor-gadget/stats.json — số THỰC đã đếm được (mỗi lần
 * tải trang / khách mới), không bao giờ bị "cộng thêm" ghi thẳng vào file.
 * initial_page_views/initial_unique_visitors trong cấu hình chỉ là một
 * offset cộng thêm ở thời điểm HIỂN THỊ ra frontend (xem readForDisplay()) —
 * đổi con số này trong Admin có tác dụng ngay, không cần reset gì cả.
 * Nút "Reset" (Admin) / lệnh CLI `bin/plugin visitor-gadget reset` chỉ đưa
 * số thực trong file về lại 0, không đụng tới offset cấu hình.
 */
class StatsStore
{
    public static function file(Grav $grav): string
    {
        $dir = $grav['locator']->findResource('user://data', true, true);

        return $dir . '/visitor-gadget/stats.json';
    }

    /**
     * @return array{page_views: int, unique_visitors: int}
     */
    public static function rawDefaults(): array
    {
        return [
            'page_views'      => 0,
            'unique_visitors' => 0,
        ];
    }

    /**
     * Số thực đã đếm trong stats.json (không cộng offset cấu hình).
     *
     * @return array{page_views: int, unique_visitors: int}
     */
    public static function read(Grav $grav): array
    {
        $file = self::file($grav);
        if (!is_file($file)) {
            return self::rawDefaults();
        }

        $raw = file_get_contents($file);
        $stats = json_decode((string) $raw, true);

        return is_array($stats) ? array_merge(self::rawDefaults(), $stats) : self::rawDefaults();
    }

    /**
     * initial_page_views/initial_unique_visitors cấu hình — số cộng thêm vào
     * số thực trước khi hiển thị ra frontend.
     *
     * @return array{page_views: int, unique_visitors: int}
     */
    public static function offsets(Grav $grav): array
    {
        $config = $grav['config'];

        return [
            'page_views'      => max(0, (int) $config->get('plugins.visitor-gadget.initial_page_views', 0)),
            'unique_visitors' => max(0, (int) $config->get('plugins.visitor-gadget.initial_unique_visitors', 0)),
        ];
    }

    /**
     * Số hiển thị ra frontend = số thực trong stats.json + offset cấu hình.
     *
     * @return array{page_views: int, unique_visitors: int}
     */
    public static function readForDisplay(Grav $grav): array
    {
        $raw = self::read($grav);
        $offsets = self::offsets($grav);

        return [
            'page_views'      => $raw['page_views'] + $offsets['page_views'],
            'unique_visitors' => $raw['unique_visitors'] + $offsets['unique_visitors'],
        ];
    }

    /**
     * Ghi đè toàn bộ stats.json (có flock, tạo thư mục nếu chưa có).
     *
     * @param array{page_views: int, unique_visitors: int} $stats
     */
    public static function write(Grav $grav, array $stats): bool
    {
        $file = self::file($grav);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return false;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($stats));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }

    /**
     * Đặt lại số thực trong stats.json về 0. Offset cấu hình (initial_*)
     * không đổi, nên số hiển thị ra frontend sau reset = đúng offset đó.
     */
    public static function reset(Grav $grav): bool
    {
        return self::write($grav, self::rawDefaults());
    }
}
