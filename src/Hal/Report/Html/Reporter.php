<?php
declare(strict_types=1);

namespace Hal\Report\Html;

use Hal\Application\Config\Config;
use Hal\Application\Config\ConfigBagInterface;
use Hal\Component\Output\Output;
use Hal\Metric\Consolidated;
use Hal\Metric\Group\Group;
use Hal\Metric\Metrics;
use Hal\Report\ReporterInterface;
use JsonException;
use RuntimeException;
use stdClass;
use function array_map;
use function array_values;
use function dirname;
use function end;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_writable;
use function json_decode;
use function json_encode;
use function mkdir;
use function natsort;
use function ob_get_clean;
use function ob_start;
use function rtrim;
use function shell_exec;
use function sprintf;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * This class is responsible for the report on HTML files.
 * TODO: Create a View class that manages the rendering.
 */
final class Reporter implements ReporterInterface
{
    private string $templateDir;
    /** @var array<Group> */
    private array $groups = [];
    private string|null $currentGroup = null;
    private string $assetPath = '';

    /**
     * @param ConfigBagInterface $config
     * @param Output $output
     * @param ViewHelper $viewHelper
     */
    public function __construct(
        private readonly ConfigBagInterface $config,
        private readonly Output $output,
        private readonly ViewHelper $viewHelper
    ) {
        $this->templateDir = dirname(__DIR__, 4) . '/templates/html_report/';
    }


    /**
     * {@inheritDoc}
     * @throws JsonException
     */
    public function generate(Metrics $metrics): void
    {
        $logDir = $this->config->get('report-html');
        if (!$logDir) {
            return;
        }
        $logDir = rtrim($logDir, '/') . '/';
        $this->createFolderIfNotExists($logDir);
        if (!is_writable($logDir)) {
            throw new RuntimeException(sprintf('Unable to write in the directory "%s"', $logDir));
        }

        // consolidate
        /** @var Group[] $groups */
        $groups = $this->config->get('groups');
        $this->groups = $groups;
        $consolidatedGroups = [];
        foreach ($groups as $group) {
            $reducedMetricsByGroup = $group->reduceMetrics($metrics);
            $consolidatedGroups[$group->name] = new Consolidated($reducedMetricsByGroup);
        }
        $consolidated = new Consolidated($metrics);

        // history of builds
        $today = (object)[
            'avg' => $consolidated->getAvg(),
            'sum' => $consolidated->getSum()
        ];
        $files = glob($logDir . 'js/history-*.json');
        natsort($files);
        $history = array_map(
            static function (string $filename): stdClass {
                /* @TODO: Remove @noinspection once https://github.com/kalessil/phpinspectionsea/issues/1725 fixed. */
                /** @noinspection JsonEncodingApiUsageInspection */
                return json_decode(file_get_contents($filename), flags: JSON_THROW_ON_ERROR);
            },
            array_values($files)
        );

        // copy sources
        array_map(
            function (string $folder) use ($logDir): void {
                $destination = $logDir . $folder;
                $this->createFolderIfNotExists($destination);
                shell_exec('cp -r ' . $this->templateDir . $folder . ' ' . $destination);
            },
            ['js', 'css', 'images', 'fonts']
        );
        // render dynamic pages
        $this->renderHtmlPages($logDir, $consolidated, $history);

        // js data
        $currentData = json_encode($today, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents($logDir . sprintf('js/history-%d.json', count($files) + 1), $currentData);
        file_put_contents($logDir . 'js/latest.json', $currentData);

        // consolidated by groups
        foreach ($consolidatedGroups as $name => $consolidated) {
            $this->currentGroup = $name;
            $this->assetPath = '../';

            $this->renderHtmlPages($logDir . $name . '/', $consolidated, $history);
        }

        $this->output->writeln(sprintf('HTML report generated in "%s" directory', $logDir));
    }

    /**
     * @param string $destination
     * @param Consolidated $consolidated
     * @param array $history
     * @return void
     * @throws JsonException
     */
    private function renderHtmlPages(string $destination, Consolidated $consolidated, array $history): void
    {
        // HTML files to generate
        $filesToGenerate = [
            'index',
            'loc',
            'relations',
            'coupling',
            'all',
            'oop',
            'complexity',
            'panel',
            'violations',
            'packages',
            'package_relations',
            'composer',
        ];

        $this->createFolderIfNotExists($destination);

        foreach ($filesToGenerate as $filename) {
            $this->renderPage(
                sprintf('%s%s.php', $this->templateDir, $filename),
                sprintf('%s%s.html', $destination, $filename),
                $consolidated,
                $history
            );

            file_put_contents(
                $destination . 'classes.js',
                'var classes = ' . json_encode($consolidated->getClasses(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            );
        }
    }

    /**
     * @param string $source
     * @param string $destination
     * @param Consolidated $consolidated
     * @param array<int, mixed> $history
     */
    public function renderPage(string $source, string $destination, Consolidated $consolidated, array $history): void
    {
        if (!is_writable(dirname($destination))) {
            throw new RuntimeException(sprintf('Unable to write in the directory "%s"', dirname($destination)));
        }

        $this->sum = $sum = $consolidated->getSum();
        $this->avg = $avg = $consolidated->getAvg();
        $this->classes = $classes = $consolidated->getClasses();
        $this->files = $files = $consolidated->getFiles();
        $this->project = $project = $consolidated->getProject();
        $this->packages = $packages = $consolidated->getPackages();
        $config = $this->config;
        $this->history = $history;

        ob_start();
        require $source;
        $content = ob_get_clean();
        file_put_contents($destination, $content);
    }

    /**
     * @param $type
     * @param $key
     * @param $lowIsBetter
     * @return string
     */
    protected function getTrend($type, $key, $lowIsBetter = false): string
    {
        if (!$this->isHomePage()) {
            return '';
        }

        $svg = [];
        $svg['gt'] = '<svg fill="#000000" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg">
    <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>
    <path d="M0 0h24v24H0z" fill="none"/>
</svg>';
        $svg['eq'] = '<svg fill="#000000" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg">
    <path d="M22 12l-4-4v3H3v2h15v3z"/>
    <path d="M0 0h24v24H0z" fill="none"/>
</svg>';
        $svg['lt'] = '<svg fill="#000000" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg">
    <path d="M16 18l2.29-2.29-4.88-4.88-4 4L2 7.41 3.41 6l6 6 4-4 6.3 6.29L22 12v6z"/>
    <path d="M0 0h24v24H0z" fill="none"/>
</svg>';

        $last = end($this->history);
        if (!isset($last->$type->$key)) {
            return '';
        }

        $oldValue = $last->$type->$key;
        $newValue = $this->$type->$key ?? 0;
        $trendIndex = 1 + ($newValue <=> $oldValue);

        $diff = $newValue - $oldValue;
        $diff = ($diff > 0) ? '+' . $diff : $diff;

        $trendCodes = [0 => 'lt', 1 => 'eq', 2 => 'gt'];
        $trendNames = [0 => ($lowIsBetter ? 'good' : 'bad'), 1 => 'neutral', 2 => ($lowIsBetter ? 'bad' : 'good')];

        return sprintf(
            '<span title="Last value: %s" class="progress progress-%s progress-%s">%s %s</span>',
            $oldValue,
            $trendNames[$trendIndex],
            $trendCodes[$trendIndex],
            $diff,
            $svg[$trendCodes[$trendIndex]]
        );
    }

    /**
     * @return bool
     */
    private function isHomePage(): bool
    {
        return null === $this->currentGroup;
    }

    /**
     * Creates the folder at the location given in argument if not already exists.
     * Rights given to this folder are 755.
     *
     * @param string $path
     * @return void
     */
    private function createFolderIfNotExists(string $path): void
    {
        if (!file_exists($path)) {
            mkdir($path, 0o755, true);
        }
    }
}
