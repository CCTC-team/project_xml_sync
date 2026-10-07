<?php

namespace CCTC\ProjectXmlSyncModule;

/** HTML builders for the Project XML Sync pages. Every value is escaped here. */
class Rendering
{
    public static function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    public static function header(): string
    {
        return "<div class='projhdr'><i class='fas fa-code-compare'></i> Project XML Sync</div>";
    }

    public static function statusBadge(string $status): string
    {
        $map = [
            DesignDiff::StatusAdded   => ['pxs-added', 'Added'],
            DesignDiff::StatusChanged => ['pxs-changed', 'Changed'],
            DesignDiff::StatusRemoved => ['pxs-removed', 'Removed'],
            DesignDiff::StatusManual  => ['pxs-manual', 'Manual'],
        ];
        [$cls, $label] = $map[$status] ?? ['pxs-manual', $status];
        return "<span class='pxs-badge $cls'>" . self::e($label) . "</span>";
    }

    public static function intro(string $projectTitle, int $status, int $draftMode): string
    {
        $statusText = [0 => 'Development', 1 => 'Production', 2 => 'Analysis/Cleanup', 3 => 'Completed'][$status] ?? (string)$status;
        if ($status > 0) $statusText .= $draftMode === 1 ? ' — in Draft Mode' : ($draftMode === 2 ? ' — drafted changes awaiting approval' : '');
        return "<p>Compare this project with a Project XML exported from another server (for example the TEST copy of this trial) and copy the differences across. "
             . "Nothing changes until you tick items and click <b>Apply selected changes</b>.</p>"
             . "<p><b>This project:</b> " . self::e($projectTitle) . " &nbsp;|&nbsp; <b>Status:</b> " . self::e($statusText) . "</p>";
    }

    public static function uploadForm(string $action, ?array $info): string
    {
        $current = '';
        if ($info) {
            $current = "<div class='pxs-current'><i class='fas fa-file-code'></i> Comparing with <b>" . self::e($info['name']) . "</b>"
                     . " (uploaded by " . self::e($info['user']) . " at " . self::e($info['time']) . ")"
                     . " <form method='post' action='" . self::e($action) . "' style='display:inline'>"
                     . "<input type='hidden' name='pxs_action' value='clear'>"
                     . "<button type='submit' class='btn btn-xs btn-outline-secondary'>Remove</button></form></div>";
        }
        return $current
             . "<details class='pxs-help'" . ($info ? '' : ' open') . "><summary>How to export the XML from the source project</summary>"
             . "<ol><li>On the source server open the project, go to <b>Project Setup &gt; Other Functionality &gt; Copy or Back Up the Project</b> (or <b>Project Home &gt; Download metadata only (XML)</b>).</li>"
             . "<li>Choose <b>metadata only</b> and tick <b>every</b> optional item (DQ rules, DAGs, surveys, ASIs, alerts, reports, roles, dashboards, form display logic, ...). "
             . "Anything not ticked is not compared, and existing items here would be listed as removed.</li>"
             . "<li>Upload the downloaded <code>.xml</code> file below.</li></ol>"
             . "<p>Not carried by the XML (check manually): user accounts and rights assignments, DAG assignments, External Module settings, the randomization allocation table.</p></details>"
             . "<form method='post' enctype='multipart/form-data' action='" . self::e($action) . "' class='pxs-upload'>"
             . "<input type='hidden' name='pxs_action' value='upload'>"
             . "<label for='pxs_xml'>Select the Project XML file</label> "
             . "<input type='file' id='pxs_xml' name='pxs_xml' accept='.xml,text/xml,application/xml' required> "
             . "<button type='submit' class='btn btn-sm btn-primary'><i class='fas fa-upload'></i> " . ($info ? 'Upload a different XML' : 'Upload and compare') . "</button>"
             . "</form>";
    }

    public static function warnings(array $warnings): string
    {
        $html = '';
        foreach ($warnings as $w) $html .= "<div class='yellow pxs-msg'><i class='fas fa-exclamation-triangle'></i> " . self::e($w) . "</div>";
        return $html;
    }

    /** Summary counts per component */
    public static function summary(array $items): string
    {
        if (empty($items)) {
            return "<div class='green pxs-msg'><i class='fas fa-check'></i> No differences found — this project matches the uploaded XML.</div>";
        }
        $counts = [];
        foreach ($items as $it) $counts[$it['component']][$it['status']] = ($counts[$it['component']][$it['status']] ?? 0) + 1;
        $rows = '';
        foreach (DesignDiff::Components as $code => $label) {
            if (!isset($counts[$code])) continue;
            $c = $counts[$code];
            $rows .= "<tr><td><a href='#pxs-" . self::e($code) . "'>" . self::e($label) . "</a></td>"
                   . "<td class='pxs-num'>" . ($c['added'] ?? '') . "</td><td class='pxs-num'>" . ($c['changed'] ?? '') . "</td>"
                   . "<td class='pxs-num'>" . ($c['removed'] ?? '') . "</td><td class='pxs-num'>" . ($c['manual'] ?? '') . "</td></tr>";
        }
        return "<table class='pxs-summary'><tr><th>Component</th><th>Added</th><th>Changed</th><th>Removed</th><th>Manual</th></tr>$rows</table>";
    }

    /** One section per component with a checkbox per item */
    /**
     * Instrument x event grid (like "Designate Instruments for My Events") showing the current
     * mapping and what the uploaded XML changes. Cells are linked to the item checkboxes.
     */
    public static function mappingGrid(array $items, array $src, array $tgt): string
    {
        $byPair = [];
        foreach ($items as $it) {
            $p = $it['status'] === DesignDiff::StatusRemoved ? $it['before'] : $it['after'];
            $byPair[$p['event'] . '|' . $p['form']] = $it;
        }
        $has = function (array $mapping, string $uen, string $form): bool {
            return in_array($form, $mapping[$uen] ?? [], true);
        };

        // Events: this project's order first, then events only in the XML; grouped by arm
        $events = $tgt['events'];
        foreach ($src['events'] as $uen => $ev) if (!isset($events[$uen])) $events[$uen] = $ev + ['_new' => true];
        uasort($events, fn($a, $b) => (int)$a['arm_num'] <=> (int)$b['arm_num']);
        // Instruments: XML order first (the design being copied), then ones only in this project
        $forms = [];
        foreach ($src['forms'] as $f => $d) $forms[$f] = $d['label'];
        foreach ($tgt['forms'] as $f => $d) if (!isset($forms[$f])) $forms[$f] = $d['label'];

        $armHeads = '';
        $arms = [];
        foreach ($events as $ev) $arms[$ev['arm_num']] = ($arms[$ev['arm_num']] ?? 0) + 1;
        foreach ($arms as $num => $span) {
            $name = $src['arms'][$num] ?? $tgt['arms'][$num] ?? "Arm $num";
            $armHeads .= "<th colspan='$span' class='pxs-grid-arm'>Arm " . self::e($num) . ": " . self::e($name) . "</th>";
        }
        $eventHeads = '';
        foreach ($events as $uen => $ev) {
            $label = $ev['event_name'] . (!empty($ev['_new']) ? ' (new)' : (!isset($src['events'][$uen]) ? ' (not in XML)' : ''));
            $eventHeads .= "<th class='pxs-grid-event' title='" . self::e($uen) . "'>" . self::e($label) . "</th>";
        }

        $rows = '';
        foreach ($forms as $form => $label) {
            $newForm = !isset($tgt['forms'][$form]) ? " <span class='pxs-grid-tag'>new</span>" : (!isset($src['forms'][$form]) ? " <span class='pxs-grid-tag'>not in XML</span>" : '');
            $rows .= "<tr><td class='pxs-grid-form'>" . self::e($label) . " <span class='pxs-small'>(" . self::e($form) . ")</span>$newForm</td>";
            foreach (array_keys($events) as $uen) {
                $now = $has($tgt['mapping'], $uen, $form);
                $xml = $has($src['mapping'], $uen, $form);
                $it = $byPair["$uen|$form"] ?? null;
                if ($it !== null) {
                    $added = $it['status'] === DesignDiff::StatusAdded;
                    $cls = $added ? 'pxs-cell-added' : 'pxs-cell-removed';
                    $icon = $added ? 'fa-plus' : 'fa-minus';
                    $lock = $it['blocked'] !== null ? " <i class='fas fa-lock' title='" . self::e($it['blocked']) . "'></i>" : '';
                    $title = ($added ? 'Add' : 'Remove') . " $form on $uen" . ($it['blocked'] !== null ? " — {$it['blocked']}" : ' (click to select/unselect)');
                    $rows .= "<td class='pxs-cell $cls" . ($it['blocked'] !== null ? ' pxs-cell-locked' : '') . "' data-item='" . self::e($it['id']) . "' title='" . self::e($title) . "'>"
                           . "<i class='fas $icon'></i>$lock</td>";
                } elseif ($now && $xml) {
                    $rows .= "<td class='pxs-cell pxs-cell-same' title='Mapped in both'><i class='fas fa-check'></i></td>";
                } else {
                    $rows .= "<td class='pxs-cell'></td>";
                }
            }
            $rows .= "</tr>";
        }

        return "<div class='pxs-grid-wrap'><table class='pxs-grid'>"
             . "<tr><th rowspan='2' class='pxs-grid-corner'>Instrument</th>$armHeads</tr><tr>$eventHeads</tr>$rows</table>"
             . "<div class='pxs-grid-legend'><span class='pxs-cell-same'><i class='fas fa-check'></i></span> mapped (no change) &nbsp; "
             . "<span class='pxs-cell-added'><i class='fas fa-plus'></i></span> will be added &nbsp; "
             . "<span class='pxs-cell-removed'><i class='fas fa-minus'></i></span> will be removed &nbsp; "
             . "<i class='fas fa-lock'></i> locked (hover for the reason) &nbsp; faded = not selected. Click a cell to select/unselect it.</div></div>";
    }

    public static function diffForm(array $items, string $action, bool $canApply, ?array $src = null, ?array $tgt = null): string
    {
        if (empty($items)) return '';
        $byComponent = [];
        foreach ($items as $it) $byComponent[$it['component']][] = $it;

        $html = "<form method='post' action='" . self::e($action) . "' id='pxs-apply-form'>";
        foreach (DesignDiff::Components as $code => $label) {
            if (empty($byComponent[$code])) continue;
            $html .= "<div class='pxs-section' id='pxs-" . self::e($code) . "'>"
                   . "<h4>" . self::e($label) . " <span class='pxs-count'>(" . count($byComponent[$code]) . ")</span>";
            if ($code !== 'manual' && $canApply) {
                $html .= " <a href='#' class='pxs-select' data-component='" . self::e($code) . "' data-select='1'>select all</a>"
                       . " | <a href='#' class='pxs-select' data-component='" . self::e($code) . "' data-select='0'>none</a>";
            }
            $html .= "</h4>";
            if ($code === 'mapping' && $src !== null && $tgt !== null) {
                $html .= self::mappingGrid($byComponent[$code], $src, $tgt);
            }
            $html .= "<table class='pxs-items'><tr><th class='pxs-cb'></th><th>Status</th><th>Item</th><th>Details</th></tr>";
            foreach ($byComponent[$code] as $it) $html .= self::itemRow($it, $canApply);
            $html .= "</table></div>";
        }
        if ($canApply) {
            $html .= "<div class='pxs-actions'><span id='pxs-selected-count'></span> "
                   . "<button type='submit' class='btn btn-primary' id='pxs-apply'><i class='fas fa-check'></i> Apply selected changes</button>"
                   . "<p class='pxs-small'>A backup of this project's current design (Project XML) is stored before anything is changed.</p></div>";
        }
        return $html . "</form>";
    }

    private static function itemRow(array $it, bool $canApply): string
    {
        $disabled = !$canApply || $it['blocked'] !== null;
        $checked = $it['default'] && !$disabled ? ' checked' : '';
        $cb = $it['component'] === 'manual' ? '' :
            "<input type='checkbox' name='items[]' value='" . self::e($it['id']) . "' data-component='" . self::e($it['component']) . "'"
            . ($disabled ? ' disabled' : '') . "$checked>";
        $notes = '';
        if ($it['blocked'] !== null) $notes .= "<div class='pxs-blocked'><i class='fas fa-lock'></i> " . self::e($it['blocked']) . "</div>";
        if ($it['note'] !== '') $notes .= "<div class='pxs-note'>" . self::e($it['note']) . "</div>";
        return "<tr class='pxs-row pxs-row-" . self::e($it['status']) . "'><td class='pxs-cb'>$cb</td><td>" . self::statusBadge($it['status']) . "</td>"
             . "<td class='pxs-key'>" . self::e($it['key']) . "</td><td>" . self::details($it) . $notes . "</td></tr>";
    }

    private static function details(array $it): string
    {
        if ($it['status'] === DesignDiff::StatusChanged && !empty($it['changes'])) {
            $rows = '';
            foreach ($it['changes'] as $prop => [$before, $after]) {
                $rows .= "<tr><td class='pxs-prop'>" . self::e($prop) . "</td><td class='pxs-before'>" . self::value($before)
                       . "</td><td class='pxs-arrow'>&rarr;</td><td class='pxs-after'>" . self::value($after) . "</td></tr>";
            }
            return "<table class='pxs-changes'>$rows</table>";
        }
        $row = $it['status'] === DesignDiff::StatusRemoved ? $it['before'] : $it['after'];
        if (!is_array($row)) return '';
        $summary = self::rowSummary($it['component'], $row);
        $full = '';
        foreach ($row as $k => $v) {
            if ((string)$v === '') continue;
            $full .= "<tr><td class='pxs-prop'>" . self::e($k) . "</td><td>" . self::value($v) . "</td></tr>";
        }
        return self::e($summary) . ($full !== '' ? "<details><summary>all properties</summary><table class='pxs-changes'>$full</table></details>" : '');
    }

    private static function rowSummary(string $component, array $row): string
    {
        switch ($component) {
            case 'fields':  return "{$row['field_type']} on '{$row['form_name']}': " . self::short(strip_tags($row['field_label'] ?? ''));
            case 'events':  return "{$row['event_name']} (arm {$row['arm_num']})";
            case 'arms':    return (string)($row['name'] ?? '');
            case 'mapping': return "{$row['form']} on {$row['event']}";
            case 'repeating': return $row['form'] === null ? 'Whole event repeats' : "Repeating instrument" . ($row['label'] !== '' ? " (label: {$row['label']})" : '');
            case 'redcap_data_quality_rules': return self::short($row['rule_logic'] ?? '');
            case 'redcap_alerts': return self::short($row['email_subject'] ?? '');
            default: return '';
        }
    }

    private static function short(string $s, int $len = 120): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }

    private static function value($v): string
    {
        $v = (string)$v;
        if ($v === '') return "<span class='pxs-empty'>(empty)</span>";
        if (strpos($v, 'file:') === 0) {
            $parts = explode(':', $v, 3);
            return "<i class='fas fa-paperclip'></i> " . self::e($parts[2] ?? 'file');
        }
        return "<span class='pxs-val'>" . nl2br(self::e($v)) . "</span>";
    }

    public static function results(array $items, array $results): string
    {
        $applied = $failed = 0;
        $rows = '';
        foreach ($items as $id => $it) {
            if (!isset($results[$id])) continue;
            $r = $results[$id];
            $r['status'] === 'applied' ? $applied++ : $failed++;
            $icon = $r['status'] === 'applied' ? "<i class='fas fa-check' style='color:green'></i>" : "<i class='fas fa-times' style='color:#c00'></i>";
            $rows .= "<tr><td>$icon " . self::e($r['status']) . "</td><td>" . self::e(DesignDiff::Components[$it['component']] ?? $it['component']) . "</td>"
                   . "<td>" . self::statusBadge($it['status']) . " " . self::e($it['key']) . "</td><td>" . self::e($r['message']) . "</td></tr>";
        }
        $msg = "<div class='" . ($failed ? 'red' : 'green') . " pxs-msg'><b>$applied</b> change(s) applied" . ($failed ? ", <b>$failed</b> failed (failed components were rolled back)" : '') . ".</div>";
        return $msg . "<table class='pxs-items'><tr><th>Result</th><th>Component</th><th>Item</th><th>Message</th></tr>$rows</table>";
    }

    public static function nextSteps(int $pid, bool $fieldsDrafted, bool $hasPendingApproval): string
    {
        $html = '';
        if ($fieldsDrafted) {
            $designer = APP_PATH_WEBROOT . "Design/online_designer.php?pid=$pid";
            $summary = APP_PATH_WEBROOT . "Design/project_modifications.php?pid=$pid";
            $html .= "<div class='blue pxs-msg'><b>Field changes are in Draft Mode.</b> Review them in the "
                   . "<a href='" . self::e($summary) . "'>summary of drafted changes</a> (critical changes are flagged there), then click "
                   . "<b>Submit Changes for Review</b> in the <a href='" . self::e($designer) . "'>Online Designer</a>.</div>";
        }
        if ($hasPendingApproval) {
            $html .= "<div class='yellow pxs-msg'>Some items depend on new instruments (mapping, repeating setup, surveys). "
                   . "Once the drafted changes are approved, open Project XML Sync again: the XML is kept and the remaining items will be available.</div>";
        }
        return $html;
    }

    public static function runHistory(array $runs, callable $url): string
    {
        if (empty($runs)) return '';
        $rows = '';
        foreach ($runs as $i => $r) {
            $rows .= "<tr><td>" . self::e($r['time']) . "</td><td>" . self::e($r['user']) . "</td><td>" . self::e($r['xml_name']) . "</td>"
                   . "<td class='pxs-num'>" . (int)$r['applied'] . "</td><td class='pxs-num'>" . (int)$r['failed'] . "</td><td>"
                   . "<a href='" . self::e($url('report_csv.php', ['run' => $i])) . "'>report (CSV)</a>"
                   . (!empty($r['backup_doc_id']) ? " | <a href='" . self::e($url('download.php', ['doc' => $r['backup_doc_id']])) . "'>design before (XML)</a>" : '')
                   . (!empty($r['xml_doc_id']) ? " | <a href='" . self::e($url('download.php', ['doc' => $r['xml_doc_id']])) . "'>uploaded XML</a>" : '')
                   . "</td></tr>";
        }
        return "<details class='pxs-history'><summary>Previous runs (" . count($runs) . ")</summary>"
             . "<table class='pxs-items'><tr><th>When</th><th>User</th><th>XML</th><th>Applied</th><th>Failed</th><th>Downloads</th></tr>$rows</table></details>";
    }
}
