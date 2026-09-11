<?php

namespace Grav\Plugin\VisitorGadget;

use Grav\Common\Grav;

/**
 * "Khách đang xem có phải admin đã đăng nhập không" — dùng chung cho cả bộ
 * đếm lượt xem (VisitorGadgetPlugin::recordVisit) lẫn bộ đếm like/dislike
 * (LikesApiController::react), cùng 1 logic canEdit()-derived đã dùng ở
 * admin-quick-menu / in-place-edit-button.
 */
class AdminGuard
{
    public static function isLoggedInAdmin(Grav $grav): bool
    {
        $user = $grav['user'] ?? null;
        if (!$user || !$user->authenticated) {
            return false;
        }

        return $user->authorize('admin.login') === true
            || $user->authorize('admin.super') === true
            || $user->authorize('admin.pages') === true;
    }
}
