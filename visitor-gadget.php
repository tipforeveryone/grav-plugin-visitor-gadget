<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\VisitorGadget\StatsStore;
use Grav\Plugin\VisitorGadget\VisitorGadgetApiController;
use RocketTheme\Toolbox\Event\Event;

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

    private static bool $autoloadRegistered = false;

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onApiRegisterRoutes'  => ['onApiRegisterRoutes', 0],
            'onApiPluginPageInfo'  => ['onApiPluginPageInfo', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        self::registerAutoload();

        if ($this->isAdmin()) {
            $this->enable([
                'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
                'onAdminTaskExecute'       => ['onAdminTaskExecute', 0],
            ]);

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
     * Autoload cho Grav\Plugin\VisitorGadget\* (classes/) — đăng ký ngay khi
     * file này được require, không đợi onPluginsInitialized, vì
     * onApiRegisterRoutes/onApiPluginPageInfo (đăng ký tĩnh qua
     * getSubscribedEvents ở trên, để api plugin gọi được bất kể thứ tự khởi
     * tạo plugin) có thể tham chiếu VisitorGadgetApiController trước đó.
     * Dùng require thay vì require_once cứng trực tiếp để tránh lỗi "class
     * AbstractApiController not found" nếu file này được nạp trước api.php.
     */
    public static function registerAutoload(): void
    {
        if (self::$autoloadRegistered) {
            return;
        }
        self::$autoloadRegistered = true;

        spl_autoload_register(function (string $class): void {
            $prefix = 'Grav\\Plugin\\VisitorGadget\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $file = __DIR__ . '/classes/' . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * Backend cho field "Reset bộ đếm" trong Admin2
     * (admin-next/fields/visitor-gadget-reset.js).
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        if (!$this->config->get('plugins.visitor-gadget.enabled', true)) {
            return;
        }

        $routes = $event['routes'];
        $routes->get('/visitor-gadget/stats', [VisitorGadgetApiController::class, 'stats']);
        $routes->post('/visitor-gadget/reset', [VisitorGadgetApiController::class, 'reset']);
    }

    /**
     * Trang cấu hình plugin trong Admin2 (Plugins > [TIP] - Visitor Gadget):
     * vẫn form blueprint như mặc định, chỉ thêm action "Reset bộ đếm" cạnh
     * "Save" trên toolbar, gọi POST /visitor-gadget/reset (VisitorGadgetApiController::reset).
     */
    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'visitor-gadget') {
            return;
        }

        $event['definition'] = [
            'id'            => 'visitor-gadget',
            'plugin'        => 'visitor-gadget',
            'title'         => '[TIP] - Visitor Gadget',
            'icon'          => 'fa-eye',
            'page_type'     => 'blueprint',
            'blueprint'     => 'visitor-gadget',
            'data_endpoint' => '/config/plugins/visitor-gadget',
            'save_endpoint' => '/config/plugins/visitor-gadget',
            'actions'       => [
                ['id' => 'reset', 'label' => 'Reset bộ đếm', 'icon' => 'fa-refresh', 'endpoint' => '/visitor-gadget/reset'],
                ['id' => 'save', 'label' => 'Save', 'icon' => 'fa-check', 'primary' => true],
            ],
        ];
    }

    /**
     * Đăng ký admin/templates để admin-classic tìm thấy
     * templates/plugins/visitor-gadget-buttons.html.twig (nút "Reset bộ đếm"
     * chèn vào button-bar của trang cấu hình plugin, xem plugins.html.twig:
     * {% include 'plugins/'~admin.route~'-buttons.html.twig' ignore missing %}).
     */
    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/admin/templates';
        $event['paths'] = $paths;
    }

    /**
     * Xử lý task "visitorgadgetreset" gửi từ nút Reset trên trang cấu hình
     * plugin (admin/templates/plugins/visitor-gadget-buttons.html.twig).
     * Chỉ đặt lại số THỰC trong stats.json về 0, không đụng tới
     * initial_page_views/initial_unique_visitors trong cấu hình.
     */
    public function onAdminTaskExecute(Event $event): void
    {
        $controller = $event['controller'];
        $task = $controller->task ?? '';

        if ($task !== 'visitorgadgetreset') {
            return;
        }

        $event->stopPropagation();

        $admin = $this->grav['admin'];

        if (!$this->canResetFromAdmin()) {
            $admin->setMessage('Not authorized.', 'error');

            return;
        }

        StatsStore::reset($this->grav);
        $admin->setMessage('Đã đặt lại số đếm visitor-gadget (stats.json) về 0.', 'info');
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
        $stats = StatsStore::readForDisplay($this->grav);
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

        $file = StatsStore::file($this->grav);
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
            $stats = StatsStore::rawDefaults();
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

    /**
     * Quyền được bấm nút "Reset bộ đếm" trong trang cấu hình plugin.
     */
    private function canResetFromAdmin(): bool
    {
        $user = $this->grav['user'] ?? null;
        if (!$user || !$user->authenticated) {
            return false;
        }

        return $user->authorize('admin.super') === true;
    }
}

// Đăng ký autoload ngay khi file này được require (không đợi onPluginsInitialized).
VisitorGadgetPlugin::registerAutoload();
