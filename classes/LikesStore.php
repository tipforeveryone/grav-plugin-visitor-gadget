<?php

namespace Grav\Plugin\VisitorGadget;

use Grav\Common\Grav;

/**
 * Đọc/ghi user/data/visitor-gadget/likes.json — số like/dislike THỰC theo
 * từng route. Ghi thưa: chỉ route có ít nhất 1 like/dislike mới có entry
 * (entry về lại 0/0 thì bị xoá khỏi file), đúng yêu cầu "chỉ nhớ các trang
 * nào được like hoặc dislike". Cùng cơ chế khoá flock() như StatsStore,
 * tách file riêng vì đơn vị ghi ở đây là 1 route, không phải 1 bộ đếm
 * toàn site.
 */
class LikesStore
{
    public static function file(Grav $grav): string
    {
        $dir = $grav['locator']->findResource('user://data', true, true);

        return $dir . '/visitor-gadget/likes.json';
    }

    /**
     * @return array{like: int, dislike: int}
     */
    public static function getCounts(Grav $grav, string $route): array
    {
        $file = self::file($grav);
        if (!is_file($file)) {
            return self::normalizeEntry([]);
        }

        $raw = file_get_contents($file);
        $data = json_decode((string) $raw, true);
        $data = is_array($data) ? $data : [];

        return self::normalizeEntry($data[$route] ?? []);
    }

    /**
     * Ghi nhận đổi phản ứng của 1 khách cho 1 route: bỏ phiếu cũ ($from, có
     * thể null nếu chưa từng phản ứng) và cộng phiếu mới ($to, có thể null
     * nếu khách bấm lại để bỏ chọn). Toàn bộ đọc-sửa-ghi nằm trong 1 khoá
     * flock để không mất dữ liệu khi nhiều request tới cùng lúc.
     *
     * @return array{like: int, dislike: int}
     */
    public static function applyReaction(Grav $grav, string $route, ?string $from, ?string $to): array
    {
        $file = self::file($grav);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return self::normalizeEntry([]);
        }

        flock($fp, LOCK_EX);

        $raw = stream_get_contents($fp);
        $data = json_decode((string) $raw, true);
        $data = is_array($data) ? $data : [];

        $entry = self::normalizeEntry($data[$route] ?? []);
        if ($from !== null) {
            $entry[$from] = max(0, $entry[$from] - 1);
        }
        if ($to !== null) {
            $entry[$to]++;
        }

        if ($entry['like'] === 0 && $entry['dislike'] === 0) {
            unset($data[$route]);
        } else {
            $data[$route] = $entry;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $entry;
    }

    /**
     * @param mixed $entry
     * @return array{like: int, dislike: int}
     */
    private static function normalizeEntry(mixed $entry): array
    {
        $entry = is_array($entry) ? $entry : [];

        return [
            'like'    => max(0, (int) ($entry['like'] ?? 0)),
            'dislike' => max(0, (int) ($entry['dislike'] ?? 0)),
        ];
    }
}
