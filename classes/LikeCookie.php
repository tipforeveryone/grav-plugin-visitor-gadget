<?php

namespace Grav\Plugin\VisitorGadget;

/**
 * 1 cookie duy nhất chứa map route -> 'like'|'dislike' của khách hiện tại.
 * Dùng để: (1) tô sáng đúng nút khi tải lại trang, (2) server biết phản ứng
 * TRƯỚC ĐÓ để cộng/trừ đúng khi khách đổi ý hoặc bấm lại để bỏ chọn.
 *
 * Cố ý đơn giản hoá ("cookie đơn giản", không cộng thêm IP-hash như
 * PopularityTracker): xoá cookie hoặc dùng trình duyệt ẩn danh thì vote lại
 * được — chấp nhận được cho 1 bộ đếm mang tính tham khảo trên blog nhỏ,
 * không phải cơ chế chống gian lận.
 */
class LikeCookie
{
    private const NAME = 'tip_pl_reactions';

    public static function get(string $route): ?string
    {
        $reaction = self::readAll()[$route] ?? null;

        return in_array($reaction, ['like', 'dislike'], true) ? $reaction : null;
    }

    public static function set(string $route, ?string $reaction, int $days): void
    {
        $map = self::readAll();

        if ($reaction === null) {
            unset($map[$route]);
        } else {
            $map[$route] = $reaction;
        }

        setcookie(self::NAME, json_encode($map), [
            'expires'  => time() + $days * 86400,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function readAll(): array
    {
        $raw = $_COOKIE[self::NAME] ?? '';
        if ($raw === '') {
            return [];
        }

        $map = json_decode((string) $raw, true);

        return is_array($map) ? $map : [];
    }
}
