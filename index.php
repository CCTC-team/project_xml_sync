<?php
//DO NOT use a namespace here as gives access then to root e.g. RCView, Records etc

/** @var \CCTC\ProjectXmlSyncModule\ProjectXmlSyncModule $module */

require_once __DIR__ . '/Rendering.php';

use CCTC\ProjectXmlSyncModule\OdmDesignParser;
use CCTC\ProjectXmlSyncModule\Rendering;

$pid = (int)$module->getProjectId();
$selfUrl = $module->getUrl('index.php');

echo "<link rel='stylesheet' href='" . Rendering::e($module->getUrl('css/sync.css')) . "'>";
echo Rendering::header();

if (!$module->userCanUse()) {
    echo "<p class='red'>You do not have permission to use Project XML Sync in this project (Project Design &amp; Setup rights are required).</p>";
    return;
}

$messages = '';
$action = $_POST['pxs_action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    $file = $_FILES['pxs_xml'] ?? null;
    try {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('No file was received. Please choose the XML file and try again.');
        }
        if ($file['size'] / 1024 / 1024 > maxUploadSize()) {
            throw new Exception('The file is larger than the maximum upload size (' . maxUploadSize() . ' MB).');
        }
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xml') {
            throw new Exception('Please upload a REDCap Project XML file (.xml).');
        }
        // Reject anything that is not a usable Project XML before storing it
        OdmDesignParser::parse(file_get_contents($file['tmp_name']));
        $info = $module->storeUpload($file['tmp_name'], $file['name']);
        $module->log('XML uploaded', ['file' => $info['name'], 'sha1' => $info['sha1'], 'doc_id' => $info['doc_id']]);
        $messages .= "<div class='green pxs-msg'>Uploaded <b>" . Rendering::e($info['name']) . "</b>.</div>";
    } catch (Throwable $e) {
        $messages .= "<div class='red pxs-msg'>" . Rendering::e($e->getMessage()) . "</div>";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear') {
    $module->clearUpload();
}

$Proj = new Project($pid, true);
echo Rendering::intro($Proj->project['app_title'], (int)$Proj->project['status'], (int)$Proj->project['draft_mode']);
echo $messages;

$info = $module->getUploadInfo();
echo Rendering::uploadForm($selfUrl, $info);

if ($info) {
    try {
        $diff = $module->buildDiff();
        $canApply = (int)$Proj->project['status'] < 2;
        echo "<h3 class='pxs-h'>Differences</h3>";
        echo "<p class='pxs-small'>Comparing <b>" . Rendering::e($diff['src']['title']) . "</b> (uploaded XML) with this project"
           . ($diff['tgt']['useDraft'] ? " — fields are compared with the current <b>draft</b>" : '') . ".</p>";
        echo Rendering::warnings($diff['warnings']);
        echo Rendering::summary($diff['items']);
        echo Rendering::diffForm($diff['items'], $module->getUrl('apply.php'), $canApply, $diff['src'], $diff['tgt']);
    } catch (Throwable $e) {
        $module->log('Diff failed', ['error' => $e->getMessage()]);
        echo "<div class='red pxs-msg'>The comparison could not be completed: " . Rendering::e($e->getMessage()) . "</div>";
    }
}

echo Rendering::runHistory($module->getRuns(), fn($page, $params) => $module->getUrl($page) . '&' . http_build_query($params));
echo "<script src='" . Rendering::e($module->getUrl('js/sync.js')) . "'></script>";
