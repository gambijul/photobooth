<?php

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;

/**
 * Collects a health snapshot of the photobooth for the admin dashboard.
 *
 * Everything is read from /proc, /sys and the data folder, without root
 * rights and without extra dependencies. Values that cannot be read on the
 * current system are returned as null.
 */
class SystemStatusService
{
    public const CAMERA_SERVICE_PORT = 5555;

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [
            'time' => date('c'),
            'system' => $this->getSystem(),
            'disk' => $this->getDisk(),
            'camera' => $this->getCamera(),
            'photos' => $this->getPhotos(),
            'printer' => $this->getPrinter(),
            'uploads' => $this->getUploads(),
            'errors' => $this->getRecentErrors(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystem(): array
    {
        $temperature = null;
        $raw = $this->readFile('/sys/class/thermal/thermal_zone0/temp');
        if ($raw !== null && is_numeric($raw)) {
            $temperature = round(((int) $raw) / 1000, 1);
        }

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $cpuCount = $this->getCpuCount();

        $memory = null;
        $meminfo = $this->readFile('/proc/meminfo');
        if ($meminfo !== null
            && preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $total)
            && preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $available)
        ) {
            $memory = [
                'total' => (int) $total[1] * 1024,
                'available' => (int) $available[1] * 1024,
            ];
        }

        $uptime = null;
        $rawUptime = $this->readFile('/proc/uptime');
        if ($rawUptime !== null) {
            $uptime = (int) floatval(explode(' ', $rawUptime)[0]);
        }

        return [
            'temperature' => $temperature,
            'throttling' => $this->getThrottling(),
            'load' => $load === false ? null : array_map(fn ($value) => round($value, 2), $load),
            'cpu_count' => $cpuCount,
            'memory' => $memory,
            'uptime' => $uptime,
        ];
    }

    /**
     * Raspberry Pi firmware throttling flags, readable without root through
     * sysfs on recent kernels (vcgencmd needs access to /dev/vcio).
     *
     * @return array<string, bool|string>|null
     */
    public function getThrottling(): ?array
    {
        $raw = $this->readFile('/sys/devices/platform/soc/soc:firmware/get_throttled');
        if ($raw === null || !ctype_xdigit($raw)) {
            return null;
        }
        $flags = hexdec($raw);

        return [
            'raw' => '0x' . $raw,
            'under_voltage' => (bool) ($flags & 0x1),
            'frequency_capped' => (bool) ($flags & 0x2),
            'throttled' => (bool) ($flags & 0x4),
            'soft_temperature_limit' => (bool) ($flags & 0x8),
            'under_voltage_occurred' => (bool) ($flags & 0x10000),
            'frequency_capped_occurred' => (bool) ($flags & 0x20000),
            'throttled_occurred' => (bool) ($flags & 0x40000),
            'soft_temperature_limit_occurred' => (bool) ($flags & 0x80000),
        ];
    }

    /**
     * @return array<string, int>|null
     */
    public function getDisk(): ?array
    {
        $path = FolderEnum::DATA->absolute();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false) {
            return null;
        }

        return ['total' => (int) $total, 'free' => (int) $free];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCamera(): array
    {
        $connection = @fsockopen('127.0.0.1', self::CAMERA_SERVICE_PORT, $errno, $errstr, 0.5);
        $serviceRunning = $connection !== false;
        if ($connection !== false) {
            fclose($connection);
        }

        return [
            'service_running' => $serviceRunning,
            'usb_cameras' => $this->getUsbCameras(),
            'video_devices' => array_values(array_map('basename', glob('/dev/video*') ?: [])),
        ];
    }

    /**
     * USB devices exposing a "Still Image" interface (class 06, PTP), which is
     * what gphoto2 cameras use.
     *
     * @return string[]
     */
    public function getUsbCameras(): array
    {
        $cameras = [];
        foreach (glob('/sys/bus/usb/devices/*/bInterfaceClass') ?: [] as $classFile) {
            if (trim((string) @file_get_contents($classFile)) !== '06') {
                continue;
            }
            // interface folder "1-1.3:1.0" belongs to device folder "1-1.3"
            $device = dirname(dirname($classFile)) . '/' . explode(':', basename(dirname($classFile)))[0];
            $manufacturer = $this->readFile($device . '/manufacturer') ?? '';
            $product = $this->readFile($device . '/product') ?? '';
            // products often already start with the brand ("NIKON DSC D3200")
            $name = $manufacturer !== '' && stripos($product, $manufacturer) === 0
                ? $product
                : trim($manufacturer . ' ' . $product);
            $cameras[] = $name !== '' ? $name : basename($device);
        }

        return array_values(array_unique($cameras));
    }

    /**
     * @return array<string, int|null>
     */
    public function getPhotos(): array
    {
        $count = null;
        $images = FolderEnum::IMAGES->absolute();
        if (is_dir($images)) {
            $count = count(glob($images . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,JPG,mp4,gif}', GLOB_BRACE) ?: []);
        }

        $printManager = PrintManagerService::getInstance();

        return [
            'count' => $count,
            'prints' => $printManager->getPrintCountFromDB(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPrinter(): ?array
    {
        if (!function_exists('shell_exec')) {
            return null;
        }
        $status = shell_exec('LANG=C timeout 3 lpstat -p 2>/dev/null');
        if (!is_string($status) || trim($status) === '') {
            return null;
        }
        $printers = [];
        // e.g. "printer SELPHY is idle.  enabled since ..."
        //      "printer SELPHY now printing SELPHY-7.  enabled since ..."
        //      "printer SELPHY disabled since ... -"
        foreach (explode("\n", trim($status)) as $line) {
            if (!preg_match('/^printer (\S+) /', $line, $match)) {
                continue;
            }
            $state = 'unknown';
            if (str_contains($line, 'disabled')) {
                $state = 'disabled';
            } elseif (str_contains($line, 'now printing')) {
                $state = 'printing';
            } elseif (str_contains($line, 'is idle')) {
                $state = 'idle';
            }
            $printers[] = ['name' => $match[1], 'state' => $state];
        }
        $jobs = shell_exec('LANG=C timeout 3 lpstat -o 2>/dev/null');
        $jobCount = is_string($jobs) && trim($jobs) !== '' ? count(explode("\n", trim($jobs))) : 0;

        return ['printers' => $printers, 'jobs' => $jobCount];
    }

    /**
     * @return array<string, mixed>
     */
    public function getUploads(): array
    {
        $config = ConfigurationService::getInstance()->getConfiguration();
        $queue = FolderEnum::DATA->absolute() . DIRECTORY_SEPARATOR . 'remote-queue';

        return [
            'enabled' => (bool) ($config['ftp']['enabled'] ?? false),
            'pending' => is_dir($queue) ? count(glob($queue . DIRECTORY_SEPARATOR . '*.json') ?: []) : null,
        ];
    }

    /**
     * Last error lines of the main log, newest first.
     *
     * @return string[]
     */
    public function getRecentErrors(int $limit = 5): array
    {
        $file = LoggerService::getInstance()->getLogger('main')->getFile();
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $size = (int) filesize($file);
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [];
        }
        // only read the end of the file, logs can grow large
        fseek($handle, max(0, $size - 65536));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $tail = str_replace("\0", '', $tail);
        $errors = [];
        foreach (array_reverse(explode("\n", $tail)) as $line) {
            if (str_contains($line, '][ERROR]') || str_contains($line, '][CRITICAL]')) {
                $errors[] = mb_strimwidth(trim($line), 0, 300, '…');
                if (count($errors) >= $limit) {
                    break;
                }
            }
        }

        return $errors;
    }

    protected function getCpuCount(): ?int
    {
        $cpuinfo = $this->readFile('/proc/cpuinfo');
        if ($cpuinfo === null) {
            return null;
        }
        $count = preg_match_all('/^processor\s*:/m', $cpuinfo);

        return $count > 0 ? $count : null;
    }

    protected function readFile(string $path): ?string
    {
        if (!@is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : trim($content);
    }

    public static function getInstance(): self
    {
        if (!isset($GLOBALS[self::class])) {
            $GLOBALS[self::class] = new self();
        }

        return $GLOBALS[self::class];
    }
}
