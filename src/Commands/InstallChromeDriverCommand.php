<?php

namespace Procket\Phquery\Commands;

use Composer\InstalledVersions;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Dusk\OperatingSystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use ZipArchive;

class InstallChromeDriverCommand extends Command
{
    /**
     * The input interface implementation.
     *
     * @var InputInterface|null
     */
    protected ?InputInterface $input = null;

    /**
     * The output interface implementation.
     *
     * @var OutputInterface|null
     */
    protected ?OutputInterface $output = null;

    /**
     * Download slugs for the available operating systems.
     *
     * @var array
     */
    protected array $slugs = [
        'linux' => 'linux64',
        'mac' => 'mac-x64',
        'mac-intel' => 'mac-x64',
        'mac-arm' => 'mac-arm64',
        'win' => 'win32',
    ];

    /**
     * The legacy versions for the ChromeDriver.
     *
     * @var array
     */
    protected array $legacyVersions = [
        43 => '2.20',
        44 => '2.20',
        45 => '2.20',
        46 => '2.21',
        47 => '2.21',
        48 => '2.21',
        49 => '2.22',
        50 => '2.22',
        51 => '2.23',
        52 => '2.24',
        53 => '2.26',
        54 => '2.27',
        55 => '2.28',
        56 => '2.29',
        57 => '2.29',
        58 => '2.31',
        59 => '2.32',
        60 => '2.33',
        61 => '2.34',
        62 => '2.35',
        63 => '2.36',
        64 => '2.37',
        65 => '2.38',
        66 => '2.40',
        67 => '2.41',
        68 => '2.42',
        69 => '2.44',
    ];

    /**
     * Path to the bin directory.
     *
     * @var string|null
     */
    protected ?string $binDirectory = null;

    /**
     * The default commands to detect the installed Chrome / Chromium version.
     *
     * @var array
     */
    protected array $chromeVersionCommands = [
        'linux' => [
            '/usr/bin/google-chrome --version',
            '/usr/bin/chromium-browser --version',
            '/usr/bin/chromium --version',
            '/usr/bin/google-chrome-stable --version',
        ],
        'mac' => [
            '/Applications/Google\ Chrome\ for\ Testing.app/Contents/MacOS/Google\ Chrome\ for\ Testing --version',
            '/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome --version',
        ],
        'mac-intel' => [
            '/Applications/Google\ Chrome\ for\ Testing.app/Contents/MacOS/Google\ Chrome\ for\ Testing --version',
            '/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome --version',
        ],
        'mac-arm' => [
            '/Applications/Google\ Chrome\ for\ Testing.app/Contents/MacOS/Google\ Chrome\ for\ Testing --version',
            '/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome --version',
        ],
        'win' => [
            'reg query "HKEY_CURRENT_USER\Software\Google\Chrome\BLBeacon" /v version',
        ],
    ];

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(
            'install:chrome-driver'
        )->addArgument(
            'version',
            InputArgument::OPTIONAL,
            'Install a given version of ChromeDriver'
        )->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'Install a ChromeDriver binary for every OS'
        )->addOption(
            'detect',
            null,
            InputOption::VALUE_NONE,
            'Detect the installed Chrome / Chromium version'
        )->addOption(
            'proxy',
            null,
            InputOption::VALUE_OPTIONAL,
            'The proxy to download the binary through (example: "tcp://127.0.0.1:9000")'
        )->addOption(
            'ssl-no-verify',
            null,
            InputOption::VALUE_NONE,
            'Bypass SSL certificate verification when installing through a proxy'
        )->setDescription(
            'Install the ChromeDriver binary'
        );
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $version = $this->version();
        $all = $this->input->getOption('all');
        $currentOS = OperatingSystem::id();

        foreach (array_keys($this->slugs) as $os) {
            if ($all || ($os === $currentOS)) {
                $archive = $this->download($version, $os);

                $binary = $this->extract($version, $archive);

                $this->rename($binary, $os);
            }
        }

        $this->output->writeln(sprintf(
            '<info>ChromeDriver %s successfully installed for version %s.</info>',
            $all ? 'binaries' : 'binary', $version
        ));

        return Command::SUCCESS;
    }

    /**
     * Get the desired ChromeDriver version.
     *
     * @return string
     * @throws Exception
     */
    protected function version(): string
    {
        $version = $this->input->getArgument('version');

        if (!$version || $this->input->getOption('detect')) {
            $version = $this->detectChromeVersion(OperatingSystem::id());
        }

        if (!$version) {
            return $this->latestVersion();
        }

        if (!ctype_digit((string)$version)) {
            return (string)$version;
        }

        $version = (int)$version;

        if ($version < 70) {
            return (string)$this->legacyVersions[$version];
        } else if ($version < 115) {
            return $this->fetchChromeVersionFromUrl($version);
        }

        $milestones = $this->resolveChromeVersionsPerMilestone();
        if (!isset($milestones['milestones'][$version]['version'])) {
            throw new Exception('Could not determine the ChromeDriver version.');
        }

        return (string)$milestones['milestones'][$version]['version'];
    }

    /**
     * Get the latest stable ChromeDriver version.
     *
     * @return string
     * @throws Exception
     */
    protected function latestVersion(): string
    {
        $versions = json_decode($this->getUrl(
            'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json'
        ), true);
        if (!isset($versions['channels']['Stable']['version'])) {
            throw new Exception('Could not get the latest ChromeDriver version.');
        }

        return (string)$versions['channels']['Stable']['version'];
    }

    /**
     * Detect the installed Chrome / Chromium major version.
     *
     * @param string $os
     * @return int|bool
     */
    protected function detectChromeVersion(string $os): bool|int
    {
        foreach ($this->chromeVersionCommands[$os] as $command) {
            $process = Process::fromShellCommandline($command);

            $process->run();

            preg_match('/(\d+)(\.\d+){3}/', $process->getOutput(), $matches);

            if (!isset($matches[1])) {
                continue;
            }

            return (int)$matches[1];
        }

        $this->output->writeln('<error>Chrome version could not be detected.</error>');

        return false;
    }

    /**
     * Get the path to the bin directory.
     *
     * @return string|null
     * @throws Exception
     */
    protected function getBinDirectory(): ?string
    {
        if (!isset($this->binDirectory)) {
            $package = 'laravel/dusk';
            $duskPath = InstalledVersions::getInstallPath($package);
            if (!is_dir($duskPath)) {
                throw new Exception("Invalid path to package [$package].");
            }
            $this->binDirectory = $duskPath . '/bin/';
        }

        return $this->binDirectory;
    }

    /**
     * Download the ChromeDriver archive.
     *
     * @param string $version
     * @param string $os
     * @return string
     * @throws GuzzleException
     * @throws Exception
     */
    protected function download(string $version, string $os): string
    {
        $url = $this->resolveChromeDriverDownloadUrl($version, $os);
        $resource = Utils::tryFopen($archive = $this->getBinDirectory() . 'chromedriver.zip', 'w');
        $client = new Client();

        $response = $client->get($url, array_merge([
            'sink' => $resource,
            'verify' => $this->input->getOption('ssl-no-verify') === false,
        ], array_filter([
            'proxy' => $this->input->getOption('proxy'),
        ])));

        if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
            throw new Exception("Unable to download ChromeDriver from [{$url}].");
        }

        return $archive;
    }

    /**
     * Extract the ChromeDriver binary from the archive and delete the archive.
     *
     * @param string $version
     * @param string $archive
     * @return string
     * @throws Exception
     */
    protected function extract(string $version, string $archive): string
    {
        $zip = new ZipArchive;

        $zip->open($archive);

        $binary = null;

        for ($fileIndex = 0; $fileIndex < $zip->numFiles; $fileIndex++) {
            $filename = $zip->getNameIndex($fileIndex);

            if (Str::startsWith(basename($filename), 'chromedriver')) {
                $binary = $filename;

                $zip->extractTo($this->getBinDirectory(), $binary);

                break;
            }
        }

        $zip->close();

        unlink($archive);

        if (!$binary) {
            throw new Exception('Could not extract the ChromeDriver binary.');
        }

        return $binary;
    }

    /**
     * Rename the ChromeDriver binary and make it executable.
     *
     * @param string $binary
     * @param string $os
     * @return void
     * @throws Exception
     */
    protected function rename(string $binary, string $os): void
    {
        $binary = str_replace(DIRECTORY_SEPARATOR, '/', $binary);

        $newName = Str::contains($binary, '/') ?
            Str::after(str_replace('chromedriver', 'chromedriver-' . $os, $binary), '/') :
            str_replace('chromedriver', 'chromedriver-' . $os, $binary);

        rename($this->getBinDirectory() . $binary, $this->getBinDirectory() . $newName);

        chmod($this->getBinDirectory() . $newName, 0755);
    }

    /**
     * Get the Chrome version from URL.
     *
     * @param int $version
     * @return string
     */
    protected function fetchChromeVersionFromUrl(int $version): string
    {
        return trim((string)$this->getUrl(
            sprintf('https://chromedriver.storage.googleapis.com/LATEST_RELEASE_%d', $version)
        ));
    }

    /**
     * Get the Chrome versions per milestone.
     *
     * @return array
     */
    protected function resolveChromeVersionsPerMilestone(): array
    {
        return json_decode($this->getUrl(
            'https://googlechromelabs.github.io/chrome-for-testing/latest-versions-per-milestone-with-downloads.json'
        ), true);
    }

    /**
     * Resolve the download URL.
     *
     * @param string $version
     * @param string $os
     * @return string
     * @throws Exception
     */
    protected function resolveChromeDriverDownloadUrl(string $version, string $os): string
    {
        $slug = $this->chromeDriverSlug($os, $version);

        if (version_compare($version, '115.0', '<')) {
            return sprintf('https://chromedriver.storage.googleapis.com/%s/chromedriver_%s.zip', $version, $slug);
        }

        $milestone = (int)$version;

        $versions = $this->resolveChromeVersionsPerMilestone();
        if (!isset($versions['milestones'][$milestone]['downloads']['chromedriver'])) {
            throw new Exception('Could not get the ChromeDriver version.');
        }

        /** @var array<string, mixed> $chromeDrivers */
        $chromeDrivers = $versions['milestones'][$milestone]['downloads']['chromedriver'];
        $platformDriver = collect($chromeDrivers)->firstWhere('platform', $slug);
        if (!isset($platformDriver['url'])) {
            throw new Exception('Could not get the ChromeDriver version.');
        }

        return $platformDriver['url'];
    }

    /**
     * Resolve the ChromeDriver slug for the given operating system.
     *
     * @param string $os
     * @param string|null $version
     * @return string
     */
    protected function chromeDriverSlug(string $os, string $version = null): string
    {
        $slug = $this->slugs[$os] ?? null;

        if (is_null($slug)) {
            throw new InvalidArgumentException("Unable to find ChromeDriver slug for Operating System [$os]");
        }

        if (!is_null($version) && version_compare($version, '115.0', '<')) {
            if ($slug === 'mac-arm64') {
                return version_compare($version, '106.0.5249', '<') ? 'mac64_m1' : 'mac_arm64';
            } elseif ($slug === 'mac-x64') {
                return 'mac64';
            }
        }

        return $slug;
    }

    /**
     * Get the contents of a URL using the 'proxy' and 'ssl-no-verify' command options.
     *
     * @param string $url
     * @return string
     * @throws GuzzleException
     * @throws Exception
     */
    protected function getUrl(string $url): string
    {
        $client = new Client();

        $response = $client->get($url, array_merge([
            'verify' => $this->input->getOption('ssl-no-verify') === false,
        ], array_filter([
            'proxy' => $this->input->getOption('proxy'),
        ])));

        if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
            throw new Exception("Unable to fetch contents from [$url].");
        }

        return (string)$response->getBody();
    }
}