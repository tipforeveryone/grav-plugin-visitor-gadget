<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;

/**
 * Tip - Visitor Gadget
 *
 * Widget đếm "Lượt xem trang" (tổng số lần tải trang) và "Số khách ghé thăm"
 * (khách unique, nhận diện qua cookie dài hạn) hiển thị dạng ô số ở footer.
 * Dữ liệu lưu trong 1 file JSON tại user/data/visitor-gadget/stats.json
 * (đường dẫn qua locator user://data, ghi có flock để an toàn khi có nhiều
 * request cùng lúc). Lượt ghé thăm của admin đã đăng nhập (kể cả khi browse
 * frontend, không chỉ trong /admin) KHÔNG được tính, theo đúng logic
 * canEdit() đã dùng ở admin-quick-menu / in-place-edit-button.
 */
class VisitorGadgetPlugin extends Plugin
{
    private const COOKIE_NAME = 'tip_vg_uid';

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onTwigTemplatePaths'  => ['onTwigTemplatePaths', 0],
            'onTwigInitialized'    => ['onTwigInitialized', 0],
            'onTwigSiteVariables'  => ['onTwigSiteVariables', 0],
            'onOutputGenerated'    => ['onOutputGenerated', 0],
        ]);
    }

    /**
     * Cờ báo cho theme biết hàm twig tip_visitor_gadget() đang sẵn sàng, để
     * footer.html.twig có thể gọi an toàn ngay cả khi plugin bị tắt sau này
     * (tránh lỗi "unknown function" khi plugin không đăng ký hàm twig).
     */
    public function onTwigSiteVariables(): void
    {
        $this->grav['twig']->twig_vars['tip_visitor_gadget_available'] = true;
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Đăng ký hàm twig tip_visitor_gadget(pageviews_label, visitors_label)
     * để theme gọi ở footer (theme tự quản lý chữ song ngữ vi/en, giống
     * cách footer.md đang làm với email_label/phone_label).
     */
    public function onTwigInitialized(): void
    {
        $this->grav['twig']->twig()->addFunction(
            new \Twig\TwigFunction(
                'tip_visitor_gadget',
                [$this, 'renderWidget'],
                ['is_safe' => ['html']]
            )
        );
    }

    /**
     * Chèn CSS trực tiếp vào <head> (theme không render {{ assets.css() }},
     * xem ghi chú trong in-place-edit-button plugin). Ghi lượt ghé thăm sau
     * khi trang đã render xong -> con số hiển thị trên trang hiện tại là số
     * liệu TRƯỚC lượt ghé thăm này, cập nhật cho lần tải trang kế tiếp.
     */
    public function onOutputGenerated(): void
    {
        $output = $this->grav->output;
        if (strpos($output, '</head>') !== false) {
            $base = rtrim($this->grav['uri']->rootUrl(false), '/');
            $href = $base . '/user/plugins/visitor-gadget/css/visitor-gadget.css';

            $cssFile = __DIR__ . '/css/visitor-gadget.css';
            if (is_file($cssFile)) {
                $href .= '?v=' . filemtime($cssFile);
            }

            $link = '<link rel="stylesheet" href="' . $href . '">';
            $this->grav->output = str_replace('</head>', $link . "\n</head>", $output);
        }

        $this->recordVisit();
    }

    /**
     * Render widget: 2 khối "nhãn + dãy ô số" (đệm số 0 phía trước theo
     * số chữ số cấu hình, mặc định 6, giống hình mẫu).
     */
    public function renderWidget(string $pageviewsLabel = 'Lượt xem trang', string $visitorsLabel = 'Số khách ghé thăm'): string
    {
        $stats = $this->readStats();
        $digits = max(1, (int) $this->config->get('plugins.visitor-gadget.digits', 6));

        return $this->grav['twig']->processTemplate('partials/visitor-gadget.html.twig', [
            'pageviews_label'  => $pageviewsLabel,
            'visitors_label'   => $visitorsLabel,
            'pageviews_digits' => $this->toDigits((int) $stats['page_views'], $digits),
            'visitors_digits'  => $this->toDigits((int) $stats['unique_visitors'], $digits),
        ]);
    }

    /**
     * Tăng "lượt xem trang" mỗi lần tải trang; tăng "khách ghé thăm" chỉ khi
     * chưa có cookie định danh (khách mới). Bỏ qua hoàn toàn nếu người xem
     * là admin đã đăng nhập, dù đang browse ở đâu trên frontend.
     */
    private function recordVisit(): void
    {
        if ($this->isLoggedInAdmin()) {
            return;
        }

        $file = $this->statsFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return;
        }

        flock($fp, LOCK_EX);

        $raw = stream_get_contents($fp);
        $stats = json_decode((string) $raw, true);
        if (!is_array($stats)) {
            $stats = $this->defaultStats();
        }

        $stats['page_views'] = (int) ($stats['page_views'] ?? 0) + 1;

        if (empty($_COOKIE[self::COOKIE_NAME])) {
            $stats['unique_visitors'] = (int) ($stats['unique_visitors'] ?? 0) + 1;

            $days = max(1, (int) $this->config->get('plugins.visitor-gadget.visitor_cookie_days', 365));
            setcookie(self::COOKIE_NAME, bin2hex(random_bytes(8)), [
                'expires'  => time() + $days * 86400,
                'path'     => '/',
                'samesite' => 'Lax',
            ]);
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($stats));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * @return array{page_views: int, unique_visitors: int}
     */
    private function readStats(): array
    {
        $file = $this->statsFile();
        if (!is_file($file)) {
            return $this->defaultStats();
        }

        $raw = file_get_contents($file);
        $stats = json_decode((string) $raw, true);

        return is_array($stats) ? array_merge($this->defaultStats(), $stats) : $this->defaultStats();
    }

    /**
     * @return array{page_views: int, unique_visitors: int}
     */
    private function defaultStats(): array
    {
        return [
            'page_views'      => max(0, (int) $this->config->get('plugins.visitor-gadget.initial_page_views', 0)),
            'unique_visitors' => max(0, (int) $this->config->get('plugins.visitor-gadget.initial_unique_visitors', 0)),
        ];
    }

    private function statsFile(): string
    {
        $dir = $this->grav['locator']->findResource('user://data', true, true);

        return $dir . '/visitor-gadget/stats.json';
    }

    /**
     * @return array<int, string>
     */
    private function toDigits(int $n, int $length): array
    {
        return str_split(str_pad((string) max(0, $n), $length, '0', STR_PAD_LEFT));
    }

    /**
     * True nếu user đã đăng nhập và có quyền vào admin (copy logic từ
     * admin-quick-menu / in-place-edit-button để loại admin khỏi số đếm).
     */
    private function isLoggedInAdmin(): bool
    {
        $user = $this->grav['user'] ?? null;
        if (!$user || !$user->authenticated) {
            return false;
        }

        return $user->authorize('admin.login') === true
            || $user->authorize('admin.super') === true
            || $user->authorize('admin.pages') === true;
    }
}
