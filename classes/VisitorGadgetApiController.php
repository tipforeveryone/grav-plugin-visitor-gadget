<?php

namespace Grav\Plugin\VisitorGadget;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backend cho field "Reset bộ đếm" (admin-next/fields/visitor-gadget-reset.js)
 * trên trang cấu hình plugin trong Admin2. Đặt lại số THỰC trong stats.json
 * về 0 — không đụng tới initial_page_views/initial_unique_visitors (offset
 * cấu hình vẫn cộng thêm vào khi hiển thị ra frontend).
 */
class VisitorGadgetApiController extends AbstractApiController
{
    public function stats(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        return ApiResponse::create($this->statsBreakdown());
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        StatsStore::reset($this->grav);

        return ApiResponse::create($this->statsBreakdown());
    }

    /**
     * @return array{raw: array{page_views: int, unique_visitors: int}, offset: array{page_views: int, unique_visitors: int}, display: array{page_views: int, unique_visitors: int}}
     */
    private function statsBreakdown(): array
    {
        return [
            'raw'     => StatsStore::read($this->grav),
            'offset'  => StatsStore::offsets($this->grav),
            'display' => StatsStore::readForDisplay($this->grav),
        ];
    }
}
