<?php

namespace Grav\Plugin\VisitorGadget;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backend công khai (không cần API key/JWT — xem
 * VisitorGadgetPlugin::onApiCollectPublicRoutes) cho khối like/dislike ở
 * cuối bài viết (tip_page_likes() / templates/partials/page-likes.html.twig).
 * Ai cũng gọi được; chống spam bằng rate limit chung của api plugin
 * (ApiRouter áp dụng cho mọi route kể cả public), không phải bằng auth.
 */
class LikesApiController extends AbstractApiController
{
    public function react(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $route = ltrim(trim((string) ($body['route'] ?? '')), '/');
        $reaction = (string) ($body['reaction'] ?? '');

        if ($route === '' || !in_array($reaction, ['like', 'dislike'], true)) {
            throw new ValidationException('route and reaction ("like" hoặc "dislike") là bắt buộc.');
        }

        // route phải khớp 1 trang thật đã publish — chặn ghi like cho route
        // bịa đặt (tránh phình likes.json lẫn đếm nhầm). resolvePageByRoute()
        // (AbstractApiController) tự enablePages() — trong ngữ cảnh API,
        // Pages có thể chưa được bật như ở frontend, nên find() trần sẽ luôn
        // trả về null.
        $page = $this->resolvePageByRoute($route);
        if ($page === null || !$page->published()) {
            throw new ValidationException('Route không tồn tại.');
        }

        // Admin đã đăng nhập không được tính vào bộ đếm (giống quy ước loại
        // trừ admin của bộ đếm lượt xem) — trả về số hiện tại, không ghi gì.
        if (AdminGuard::isLoggedInAdmin($this->grav)) {
            return ApiResponse::create(array_merge(
                LikesStore::getCounts($this->grav, $route),
                ['route' => $route, 'reaction' => null],
            ));
        }

        $prev = LikeCookie::get($route);
        // Bấm lại đúng nút đã chọn = bỏ chọn (toggle off); bấm nút còn lại =
        // đổi ý sang phản ứng mới.
        $next = $prev === $reaction ? null : $reaction;

        $counts = LikesStore::applyReaction($this->grav, $route, $prev, $next);

        $days = max(1, (int) $this->config->get('plugins.visitor-gadget.like_cookie_days', 365));
        LikeCookie::set($route, $next, $days);

        return ApiResponse::create(array_merge($counts, [
            'route'    => $route,
            'reaction' => $next,
        ]));
    }
}
