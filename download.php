<?php
//DO NOT use a namespace here as gives access then to root e.g. RCView, Records etc

/** @var \CCTC\ProjectXmlSyncModule\ProjectXmlSyncModule $module */

if (!$module->userCanUse()) {
    http_response_code(403);
    exit('Access denied');
}

$docId = filter_input(INPUT_GET, 'doc', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
// Only files this module stored for this project can be downloaded
if (!$docId || !$module->isKnownDoc($docId)) {
    http_response_code(404);
    exit('File not found');
}
[$mime, $name, $contents] = Files::getEdocContentsAttributes($docId);
if ($contents === false) {
    http_response_code(404);
    exit('File not found');
}
$pid = (int)$module->getProjectId();
Logging::logEvent('', 'redcap_edocs_metadata', 'MANAGE', $pid, "doc_id = $docId", 'Project XML Sync: download XML');

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($name)) . '"');
echo $contents;
