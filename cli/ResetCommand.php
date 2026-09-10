<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\VisitorGadget\StatsStore;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * bin/plugin visitor-gadget reset
 *
 * Đặt lại số THỰC trong user/data/visitor-gadget/stats.json về 0 (mặc định),
 * hoặc về một mốc tuỳ ý qua --page-views / --unique-visitors. Không đụng tới
 * initial_page_views/initial_unique_visitors trong cấu hình — số đó là
 * offset cộng thêm vào số thực ở thời điểm hiển thị ra frontend, đổi trong
 * Admin có tác dụng ngay mà không cần reset. Đây là bản CLI tương đương với
 * nút "Reset bộ đếm" trong trang cấu hình plugin ở Admin.
 */
class ResetCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('reset')
            ->addOption('page-views', null, InputOption::VALUE_REQUIRED, 'Giá trị "lượt xem trang" thực để đặt lại (mặc định: 0)')
            ->addOption('unique-visitors', null, InputOption::VALUE_REQUIRED, 'Giá trị "khách ghé thăm" thực để đặt lại (mặc định: 0)')
            ->setDescription('Đặt lại số đếm THỰC của visitor-gadget (stats.json) — không đụng offset initial_* trong cấu hình')
            ->setHelp('The <info>reset</info> command overwrites the real counters in user/data/visitor-gadget/stats.json (default: 0/0), or the values passed via --page-views / --unique-visitors. It does not touch initial_page_views / initial_unique_visitors in the plugin config — those are added on top at display time.');
    }

    protected function serve()
    {
        $io = new SymfonyStyle($this->input, $this->output);

        $this->initializePlugins();

        require_once __DIR__ . '/../classes/StatsStore.php';

        $grav = Grav::instance();

        $pageViews = $this->input->getOption('page-views');
        $uniqueVisitors = $this->input->getOption('unique-visitors');

        $defaults = StatsStore::rawDefaults();
        $stats = [
            'page_views'      => $pageViews !== null ? max(0, (int) $pageViews) : $defaults['page_views'],
            'unique_visitors' => $uniqueVisitors !== null ? max(0, (int) $uniqueVisitors) : $defaults['unique_visitors'],
        ];

        if (!StatsStore::write($grav, $stats)) {
            $io->error('Không ghi được file ' . StatsStore::file($grav));

            return 1;
        }

        $io->success(sprintf(
            'Đã đặt lại số thực visitor-gadget: page_views=%d, unique_visitors=%d',
            $stats['page_views'],
            $stats['unique_visitors']
        ));

        return 0;
    }
}
