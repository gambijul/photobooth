<?php

require_once __DIR__ . '/../admin_boot.php';

use Photobooth\Service\ApplicationService;
use Photobooth\Service\LanguageService;
use Photobooth\Utility\PathUtility;

$languageService = LanguageService::getInstance();
$pageTitle = $languageService->translate('dashboard') . ' - ' . ApplicationService::getInstance()->getTitle();
include PathUtility::getAbsolutePath('admin/components/head.admin.php');
include PathUtility::getAbsolutePath('admin/helper/index.php');

$labelKeys = [
    'dashboard_system', 'dashboard_temperature', 'dashboard_throttling', 'dashboard_load', 'dashboard_memory',
    'dashboard_uptime', 'dashboard_disk', 'dashboard_camera', 'dashboard_camera_service', 'dashboard_camera_usb',
    'dashboard_photos', 'dashboard_photo_count', 'dashboard_print_count', 'dashboard_printer', 'dashboard_print_jobs',
    'dashboard_uploads', 'dashboard_uploads_pending', 'dashboard_errors', 'dashboard_no_errors', 'dashboard_ok',
    'dashboard_running', 'dashboard_stopped', 'dashboard_not_detected', 'dashboard_none', 'dashboard_disabled',
    'dashboard_unavailable', 'dashboard_updated', 'dashboard_connection_lost', 'dashboard_free',
    'dashboard_under_voltage', 'dashboard_throttled_now', 'dashboard_throttled_past', 'dashboard_idle',
    'dashboard_printing',
];
$labels = [];
foreach ($labelKeys as $key) {
    $labels[$key] = $languageService->translate($key);
}

?>
<style>
    .dashboard-card { min-width: 0; }
    .dashboard-wide { grid-column: 1 / -1; }
    .dashboard-row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.35rem 0; border-bottom: 1px solid #e5e7eb; }
    .dashboard-row:last-child { border-bottom: 0; }
    .dashboard-value { font-weight: 600; text-align: right; overflow-wrap: anywhere; }
    .dashboard-dot { display: inline-block; width: 0.6rem; height: 0.6rem; border-radius: 50%; margin-right: 0.4rem; background: #9ca3af; }
    .dashboard-dot--ok { background: #16a34a; }
    .dashboard-dot--warn { background: #d97706; }
    .dashboard-dot--bad { background: #dc2626; }
    .dashboard-errors li { font-family: monospace; font-size: 0.75rem; padding: 0.35rem 0; border-bottom: 1px solid #e5e7eb; overflow-wrap: anywhere; }
    .dashboard-errors li:last-child { border-bottom: 0; }
</style>
<div class="w-full h-full fixed bg-brand-1 overflow-x-hidden overflow-y-auto">
    <div class="w-full flex items-center justify-center flex-col px-6 py-12">
        <div class="w-full rounded-lg p-4 md:p-8 bg-white flex flex-col shadow-xl" style="max-width: 64rem;">
            <div class="w-full flex items-center pb-3 mb-3 border-b border-solid border-gray-200">
                <a href="<?=PathUtility::getPublicPath('admin')?>" class="h-4 mr-4 flex items-center justify-center border-r border-solid border-black border-opacity-20 pr-3">
                    <span class="fa fa-chevron-left text-brand-1 text-opacity-60 text-md hover:text-opacity-100 transition-all"></span>
                </a>
                <h2 class="text-brand-1 text-xl font-bold"><?= $languageService->translate('dashboard') ?></h2>
                <span id="dashboard-updated" class="text-sm ml-auto"></span>
            </div>
            <div id="dashboard" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
        </div>
    </div>
</div>
<script>
(function () {
    const labels = <?= json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const endpoint = <?= json_encode(PathUtility::getPublicPath('api/dashboard.php')) ?>;
    const container = document.getElementById('dashboard');
    const updated = document.getElementById('dashboard-updated');

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(i > 1 ? 1 : 0) + ' ' + units[i];
    }
    function formatUptime(seconds) {
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        return (d ? d + ' d ' : '') + (d || h ? h + ' h ' : '') + m + ' min';
    }
    function dot(level) {
        return '<span class="dashboard-dot' + (level ? ' dashboard-dot--' + level : '') + '"></span>';
    }
    function row(label, value, level) {
        return '<div class="dashboard-row"><span>' + escapeHtml(label) + '</span><span class="dashboard-value">' +
            (level !== undefined ? dot(level) : '') + value + '</span></div>';
    }
    function card(title, body) {
        return '<div class="dashboard-card rounded-lg p-4 border border-solid border-gray-200">' +
            '<h3 class="font-bold text-brand-1 mb-2">' + escapeHtml(title) + '</h3>' + body + '</div>';
    }
    const na = () => escapeHtml(labels.dashboard_unavailable);

    function render(s) {
        const sys = s.system;
        let html = '';

        // system
        let body = '';
        if (sys.temperature !== null) {
            const level = sys.temperature >= 80 ? 'bad' : sys.temperature >= 70 ? 'warn' : 'ok';
            body += row(labels.dashboard_temperature, sys.temperature.toFixed(1) + ' °C', level);
        }
        if (sys.throttling) {
            const t = sys.throttling;
            const now = [];
            if (t.under_voltage) now.push(labels.dashboard_under_voltage);
            if (t.throttled || t.frequency_capped || t.soft_temperature_limit) now.push(labels.dashboard_throttled_now);
            const past = t.under_voltage_occurred || t.throttled_occurred || t.frequency_capped_occurred || t.soft_temperature_limit_occurred;
            const text = now.length ? now.join(', ') : past ? labels.dashboard_throttled_past : labels.dashboard_ok;
            body += row(labels.dashboard_throttling, escapeHtml(text) + ' <span class="text-sm">(' + escapeHtml(t.raw) + ')</span>', now.length ? 'bad' : past ? 'warn' : 'ok');
        }
        if (sys.load) {
            const level = sys.cpu_count && sys.load[0] > sys.cpu_count ? 'warn' : 'ok';
            body += row(labels.dashboard_load, sys.load.join(' / ') + (sys.cpu_count ? ' <span class="text-sm">(' + sys.cpu_count + ' CPU)</span>' : ''), level);
        }
        if (sys.memory) {
            const used = sys.memory.total - sys.memory.available;
            const ratio = used / sys.memory.total;
            body += row(labels.dashboard_memory, formatBytes(used) + ' / ' + formatBytes(sys.memory.total), ratio > 0.9 ? 'bad' : ratio > 0.75 ? 'warn' : 'ok');
        }
        if (sys.uptime !== null) {
            body += row(labels.dashboard_uptime, formatUptime(sys.uptime));
        }
        if (s.disk) {
            const ratio = s.disk.free / s.disk.total;
            body += row(labels.dashboard_disk, formatBytes(s.disk.free) + ' ' + escapeHtml(labels.dashboard_free) + ' / ' + formatBytes(s.disk.total), ratio < 0.05 ? 'bad' : ratio < 0.15 ? 'warn' : 'ok');
        }
        html += card(labels.dashboard_system, body || row('', na()));

        // camera
        const cam = s.camera;
        body = row(labels.dashboard_camera_service, escapeHtml(cam.service_running ? labels.dashboard_running : labels.dashboard_stopped), cam.service_running ? 'ok' : 'bad');
        body += row(labels.dashboard_camera_usb, cam.usb_cameras.length ? escapeHtml(cam.usb_cameras.join(', ')) : escapeHtml(labels.dashboard_not_detected), cam.usb_cameras.length ? 'ok' : 'bad');
        html += card(labels.dashboard_camera, body);

        // photos and printer
        body = row(labels.dashboard_photo_count, s.photos.count === null ? na() : s.photos.count);
        body += row(labels.dashboard_print_count, s.photos.prints === null ? '0' : s.photos.prints);
        if (s.printer) {
            if (s.printer.printers.length) {
                s.printer.printers.forEach((p) => {
                    const state = labels['dashboard_' + p.state] || p.state;
                    body += row(labels.dashboard_printer + ' ' + p.name, escapeHtml(state), p.state === 'disabled' ? 'bad' : 'ok');
                });
            } else {
                body += row(labels.dashboard_printer, escapeHtml(labels.dashboard_none));
            }
            body += row(labels.dashboard_print_jobs, s.printer.jobs, s.printer.jobs > 2 ? 'warn' : undefined);
        }
        html += card(labels.dashboard_photos, body);

        // uploads
        if (!s.uploads.enabled) {
            body = row(labels.dashboard_uploads, escapeHtml(labels.dashboard_disabled));
        } else if (s.uploads.pending === null) {
            body = row(labels.dashboard_uploads_pending, '0', 'ok');
        } else {
            body = row(labels.dashboard_uploads_pending, s.uploads.pending, s.uploads.pending > 0 ? 'warn' : 'ok');
        }
        html += card(labels.dashboard_uploads, body);

        // errors
        body = s.errors.length
            ? '<ul class="dashboard-errors">' + s.errors.map((e) => '<li>' + escapeHtml(e) + '</li>').join('') + '</ul>'
            : '<p class="text-sm">' + escapeHtml(labels.dashboard_no_errors) + '</p>';
        html += '<div class="dashboard-wide">' + card(labels.dashboard_errors, body) + '</div>';

        container.innerHTML = html;
        updated.textContent = labels.dashboard_updated + ' ' + new Date(s.time).toLocaleTimeString();
    }

    function refresh() {
        fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(response.status);
                }
                return response.json();
            })
            .then(render)
            .catch(() => {
                updated.textContent = labels.dashboard_connection_lost;
            })
            .finally(() => setTimeout(refresh, 5000));
    }
    refresh();
})();
</script>
<?php

include PathUtility::getAbsolutePath('admin/components/footer.scripts.php');
include PathUtility::getAbsolutePath('admin/components/footer.admin.php');
