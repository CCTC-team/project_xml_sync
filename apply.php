<?php
//DO NOT use a namespace here as gives access then to root e.g. RCView, Records etc

/** @var \CCTC\ProjectXmlSyncModule\ProjectXmlSyncModule $module */

require_once __DIR__ . '/Rendering.php';

use CCTC\ProjectXmlSyncModule\Applier;
use CCTC\ProjectXmlSyncModule\DesignDiff;
use CCTC\ProjectXmlSyncModule\Rendering;

$pid = (int)$module->getProjectId();

// This page is not a menu link, so add the project header/footer here
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
echo "<link rel='stylesheet' href='" . Rendering::e($module->getUrl('css/sync.css')) . "'>";
echo Rendering::header();
$back = "<p><a href='" . Rendering::e($module->getUrl('index.php')) . "'><i class='fas fa-arrow-left'></i> Back to Project XML Sync</a></p>";

$finish = function (string $html) use ($back) {
    echo $html . $back;
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
    exit;
};

// Rights are checked again here: the menu link being hidden does not protect this page
if (!$module->userCanUse()) {
    $finish("<p class='red'>You do not have permission to use Project XML Sync in this project.</p>");
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $finish("<p>Nothing to apply. Select changes on the Project XML Sync page first.</p>");
}

$requested = array_filter((array)($_POST['items'] ?? []), fn($v) => is_string($v) && preg_match('/^[0-9a-f]{16}$/', $v));
if (empty($requested)) {
    $finish("<p class='yellow pxs-msg'>No changes were selected.</p>");
}

try {
    // Recompute the diff server-side; only ids that are still valid and not blocked are applied
    $diff = $module->buildDiff();
} catch (Throwable $e) {
    $finish("<div class='red pxs-msg'>The comparison could not be completed: " . Rendering::e($e->getMessage()) . "</div>");
}
$selected = [];
$skipped = [];
foreach ($requested as $id) {
    $it = $diff['items'][$id] ?? null;
    if ($it === null) { $skipped[] = $id; continue; }
    if ($it['blocked'] !== null || $it['component'] === 'manual') { $skipped[] = $id; continue; }
    $selected[$id] = $it;
}
if (empty($selected)) {
    $finish("<div class='yellow pxs-msg'>None of the selected changes can be applied any more (the project or the XML changed since the page was loaded). Please review the differences again.</div>");
}

$info = $module->getUploadInfo();
$backupDocId = $module->storeBackup($diff['tgt']['xml']);

$results = (new Applier($module, $pid, $diff['src'], $diff['tgt'], $selected))->run();

$applied = count(array_filter($results, fn($r) => $r['status'] === 'applied'));
$failed = count($results) - $applied;
$report = [];
foreach ($diff['items'] as $id => $it) {
    $report[] = [
        'component' => DesignDiff::Components[$it['component']] ?? $it['component'],
        'item'      => $it['key'],
        'difference'=> $it['status'],
        'selected'  => isset($selected[$id]) ? 'yes' : 'no',
        'result'    => $results[$id]['status'] ?? (isset($selected[$id]) ? 'not run' : ($it['blocked'] !== null ? 'blocked: ' . $it['blocked'] : 'not selected')),
        'message'   => $results[$id]['message'] ?? '',
        'details'   => implode('; ', array_map(fn($p, $c) => "$p: '{$c[0]}' -> '{$c[1]}'", array_keys($it['changes']), $it['changes'])),
    ];
}
$module->addRun([
    'time'          => NOW,
    'user'          => USERID,
    'xml_name'      => $info['name'] ?? '',
    'xml_sha1'      => $info['sha1'] ?? '',
    'xml_doc_id'    => $info['doc_id'] ?? null,
    'backup_doc_id' => $backupDocId,
    'applied'       => $applied,
    'failed'        => $failed,
    'report'        => $report,
]);
$module->log('Sync applied', [
    'xml_file' => $info['name'] ?? '', 'xml_sha1' => $info['sha1'] ?? '', 'applied' => $applied, 'failed' => $failed,
    'skipped' => count($skipped), 'backup_doc_id' => $backupDocId,
]);

$fieldsDrafted = false;
foreach ($selected as $id => $it) {
    if (in_array($it['component'], ['fields', 'forms', 'order'], true) && ($results[$id]['status'] ?? '') === 'applied' && $diff['tgt']['status'] > 0) $fieldsDrafted = true;
}
$pending = count(array_filter($diff['items'], fn($it) => $it['approval']));

$html = "<h3 class='pxs-h'>Results</h3>";
if ($skipped) $html .= "<div class='yellow pxs-msg'>" . count($skipped) . " selected item(s) were skipped because they are no longer valid or are blocked.</div>";
$html .= Rendering::results($diff['items'], $results);
$html .= Rendering::nextSteps($pid, $fieldsDrafted, $pending > 0);
$html .= "<p><a href='" . Rendering::e($module->getUrl('report_csv.php') . '&run=0') . "'><i class='fas fa-file-csv'></i> Download the run report (CSV)</a></p>";
$finish($html);
