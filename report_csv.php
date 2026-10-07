<?php
//DO NOT use a namespace here as gives access then to root e.g. RCView, Records etc

/** @var \CCTC\ProjectXmlSyncModule\ProjectXmlSyncModule $module */

if (!$module->userCanUse()) {
    http_response_code(403);
    exit('Access denied');
}

$runIndex = filter_input(INPUT_GET, 'run', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$runs = $module->getRuns();
if ($runIndex === false || $runIndex === null || !isset($runs[$runIndex])) {
    http_response_code(404);
    exit('Run not found');
}
$run = $runs[$runIndex];

$pid = (int)$module->getProjectId();
$filename = 'ProjectXmlSync_pid' . $pid . '_' . preg_replace('/[^0-9]/', '', $run['time']) . '.csv';
Logging::logEvent('', 'redcap_external_modules_log', 'MANAGE', $pid, "run = {$run['time']}", 'Project XML Sync: export run report');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Run time', $run['time']], ',', '"', '');
fputcsv($out, ['User', $run['user']], ',', '"', '');
fputcsv($out, ['Uploaded XML', $run['xml_name'] . ' (sha1 ' . $run['xml_sha1'] . ')'], ',', '"', '');
fputcsv($out, ['Applied', $run['applied'], 'Failed', $run['failed']], ',', '"', '');
fputcsv($out, [], ',', '"', '');
fputcsv($out, ['Component', 'Item', 'Difference', 'Selected', 'Result', 'Message', 'Details'], ',', '"', '');
foreach ($run['report'] ?? [] as $r) {
    // Prevent spreadsheet formula injection from values that came from the XML
    $row = array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v, array_values($r));
    fputcsv($out, $row, ',', '"', '');
}
fclose($out);
