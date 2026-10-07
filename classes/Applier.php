<?php

namespace CCTC\ProjectXmlSyncModule;

/**
 * Applies selected diff items to the target project.
 *
 * Components are applied in dependency order (project settings, arms, events, fields/draft,
 * mapping, repeating setup, then the other components), each in its own transaction, so a
 * failure in one component does not leave it half-applied and does not stop the others.
 * Every applied item is written to the project's Logging page.
 */
class Applier
{
    private ProjectXmlSyncModule $module;
    private int $pid;
    private array $src;
    private array $tgt;
    /** @var array<string, array> selected items, keyed by id */
    private array $selected;
    private array $results = [];

    public function __construct(ProjectXmlSyncModule $module, int $pid, array $src, array $tgt, array $selected)
    {
        $this->module = $module;
        $this->pid = $pid;
        $this->src = $src;
        $this->tgt = $tgt;
        $this->selected = $selected;
    }

    /** @return array<string, array{status:string, message:string}> keyed by item id */
    public function run(): array
    {
        $byComponent = [];
        foreach ($this->selected as $it) $byComponent[$it['component']][] = $it;

        $designDone = false;
        foreach (array_keys(DesignDiff::Components) as $component) {
            if (empty($byComponent[$component])) continue;
            if (in_array($component, ['fields', 'forms', 'order'], true)) {
                // Fields, instrument labels/CSS and order are one data-dictionary save
                if ($designDone) continue;
                $designDone = true;
                $items = array_merge($byComponent['fields'] ?? [], $byComponent['forms'] ?? [], $byComponent['order'] ?? []);
                $this->transaction($items, fn() => $this->applyDesign($items));
                continue;
            }
            $items = $byComponent[$component];
            $this->transaction($items, fn() => $this->applyComponent($component, $items));
        }
        return $this->results;
    }

    /** Run one component's changes in a transaction; any exception rolls the whole component back. */
    private function transaction(array $items, callable $fn): void
    {
        db_query("SET AUTOCOMMIT=0");
        db_query("BEGIN");
        $pending = $this->results;
        try {
            $fn();
            db_query("COMMIT");
        } catch (\Throwable $e) {
            db_query("ROLLBACK");
            $this->results = $pending;
            foreach ($items as $it) {
                $this->results[$it['id']] = ['status' => 'failed', 'message' => $e->getMessage()];
            }
            $this->module->log('Project XML Sync: component failed', [
                'component' => $items[0]['component'], 'error' => $e->getMessage(),
            ]);
        }
        db_query("SET AUTOCOMMIT=1");
        // Later components must see this component's changes (new events, forms, surveys ...)
        new \Project($this->pid, true);
    }

    private function done(array $it, string $message = ''): void
    {
        $this->results[$it['id']] = ['status' => 'applied', 'message' => $message];
    }

    private function log(string $table, string $display, string $description): void
    {
        \Logging::logEvent('', $table, 'MANAGE', $this->pid, $display, 'Project XML Sync: ' . $description);
    }

    private function applyComponent(string $component, array $items): void
    {
        switch ($component) {
            case 'project':   $this->applyProjectAttrs($items); break;
            case 'arms':      $this->applyArms($items); break;
            case 'events':    $this->applyEvents($items); break;
            case 'mapping':   $this->applyMapping($items); break;
            case 'repeating': $this->applyRepeating($items); break;
            case 'redcap_data_quality_rules': $this->applyDqRules($items); break;
            default:          $this->applyTable($component, $items);
        }
    }

    // ---- project settings ---------------------------------------------------------------

    private function applyProjectAttrs(array $items): void
    {
        $cols = getTableColumns('redcap_projects');
        $Proj = new \Project($this->pid, true);
        foreach ($items as $it) {
            $col = $it['key'];
            if (!array_key_exists($col, $cols)) throw new \Exception("Unknown project setting '$col'");
            $val = $it['after'];
            if (strpos($col, 'survey_auth_event_id') === 0 && $val !== '') {
                $val = $Proj->getEventIdUsingUniqueEventName($val);
                if (!$val) throw new \Exception("Event '{$it['after']}' for $col does not exist");
            }
            db_query("update redcap_projects set `$col` = ? where project_id = ?", [$val === '' ? null : $val, $this->pid]);
            $this->log('redcap_projects', "project_id = {$this->pid}", "set project setting '$col'");
            $this->done($it);
        }
    }

    // ---- arms & events -------------------------------------------------------------------

    private function applyArms(array $items): void
    {
        foreach ($items as $it) {
            $num = (int)$it['ref'];
            if ($it['status'] === DesignDiff::StatusRemoved) {
                \Arm::delArm($this->pid, $num);
                $this->log('redcap_events_arms', "arm_num = $num", "delete arm $num");
            } else {
                [, $errors] = \Arm::addArms($this->pid, [['arm_num' => $num, 'name' => $it['after']['name']]], false);
                if ($errors) throw new \Exception(strip_tags(implode('; ', $errors)));
                $this->log('redcap_events_arms', "arm_num = $num", ($it['status'] === DesignDiff::StatusAdded ? 'add' : 'rename') . " arm $num");
            }
            $this->done($it);
        }
    }

    private function applyEvents(array $items): void
    {
        foreach ($items as $it) {
            $uen = $it['key'];
            $eventId = (new \Project($this->pid, true))->getEventIdUsingUniqueEventName($uen);
            $message = '';
            if ($it['status'] === DesignDiff::StatusRemoved) {
                if (!$eventId) throw new \Exception("Event '$uen' not found");
                \Event::delete($eventId);
                $this->log('redcap_events_metadata', "event_id = $eventId", "delete event '$uen'");
            } elseif ($it['status'] === DesignDiff::StatusChanged) {
                if (!$eventId) throw new \Exception("Event '$uen' not found");
                \Event::update($eventId, $it['after']);
                $this->log('redcap_events_metadata', "event_id = $eventId", "update event '$uen'");
            } else {
                if (!\Event::create($this->pid, $it['after'])) throw new \Exception("Could not create event '$uen' (does arm {$it['after']['arm_num']} exist?)");
                // REDCap derives the unique event name; check it matches the source project
                if (!(new \Project($this->pid, true))->getEventIdUsingUniqueEventName($uen)) {
                    $message = "Created, but REDCap gave it a different unique event name than '$uen'. Mappings, ASIs and logic that reference '$uen' must be checked.";
                }
                $this->log('redcap_events_metadata', "unique_event_name = $uen", "add event '$uen'");
            }
            $this->done($it, $message);
        }
    }

    // ---- fields, instruments & order (Draft Mode) ----------------------------------------

    private function applyDesign(array $items): void
    {
        $Proj = new \Project($this->pid, true);
        $status = (int)$Proj->project['status'];
        if ($status > 0 && (int)$Proj->project['draft_mode'] === 2) throw new \Exception('Drafted changes are awaiting approval');
        $enteredDraft = false;
        if ($status > 0 && (int)$Proj->project['draft_mode'] === 0) {
            $this->enterDraftMode();
            $enteredDraft = true;
            $Proj = new \Project($this->pid, true);
        }
        $useDraft = $status > 0;
        $metadataTable = $useDraft ? 'redcap_metadata_temp' : 'redcap_metadata';

        $fieldItems = $formItems = [];
        $orderTicked = false;
        foreach ($items as $it) {
            if ($it['component'] === 'fields') $fieldItems[$it['key']] = $it;
            elseif ($it['component'] === 'forms') $formItems[$it['key']] = $it;
            elseif ($it['component'] === 'order') $orderTicked = true;
        }

        // Current dictionary (the draft in production) is the base; only ticked items change it
        $base = \MetaData::getDataDictionary('array', false, [], [], false, $useDraft, null, $this->pid);
        $rows = $this->mergeDictionary($base, $fieldItems, $orderTicked);

        $headers = array_values(\MetaData::getDataDictionaryHeaders());
        $flat = [];
        foreach ($rows as $row) {
            $ordered = [];
            foreach ($headers as $h) $ordered[$h] = (string)($row[$h] ?? '');
            $ordered['field_annotation'] = ltrim($ordered['field_annotation'], ' ');
            $flat[] = $ordered;
        }
        $dd = \MetaData::convertFlatMetadataToDDarray($flat);

        // error_checking() reads these globals; make sure they describe the target project now
        $GLOBALS['Proj'] = $Proj;
        $GLOBALS['status'] = $status;
        $GLOBALS['draft_mode'] = $useDraft ? 1 : 0;
        [$errors, , $dd] = \MetaData::error_checking($dd, false, false);
        if (!empty($errors)) {
            $msgs = array_map(fn($e) => trim(html_entity_decode(strip_tags(is_array($e) ? implode(' ', $e) : $e), ENT_QUOTES)), $errors);
            throw new \Exception('REDCap rejected the data dictionary: ' . implode(' | ', array_slice($msgs, 0, 10)));
        }
        try { \MetaData::createDataDictionarySnapshot($this->pid); } catch (\Throwable $e) { /* snapshot is a convenience only */ }
        $sqlErrors = \MetaData::save_metadata($dd, false, true, $this->pid);
        if (!empty($sqlErrors)) throw new \Exception('Saving the data dictionary failed');

        $mode = $useDraft ? ' (draft)' : '';
        // Labels and CSS: new instruments take the XML's, existing ones only when ticked
        $baseForms = array_unique(array_column($base, 'form_name'));
        $newForms = array_diff(array_unique(array_column($rows, 'form_name')), $baseForms);
        foreach ($newForms as $form) {
            if (!isset($this->src['forms'][$form])) continue;
            \MetaData::setFormLabel($form, $this->src['forms'][$form]['label'], $useDraft);
            $this->setFormCss($form, $this->src['forms'][$form]['css']);
        }
        foreach ($formItems as $form => $it) {
            if (isset($it['changes']['label'])) \MetaData::setFormLabel($form, $it['after']['label'], $useDraft);
            if (isset($it['changes']['css'])) {
                if (!$this->setFormCss($form, $it['after']['css'])) throw new \Exception('Custom CSS for instruments needs REDCap 15 or later');
            }
            $this->log($metadataTable, "form_name = '$form'", "update instrument '$form'$mode");
            $this->done($it);
        }

        // Attributes that are not in the data dictionary
        foreach ($fieldItems as $field => $it) {
            if ($it['status'] === DesignDiff::StatusRemoved) continue;
            $extra = $this->src['fieldExtra'][$field] ?? array_fill_keys(DesignDiff::ExtraProps, '');
            $changedExtra = $it['status'] === DesignDiff::StatusAdded ? array_filter($extra, fn($v) => $v !== '')
                : array_intersect_key($it['changes'], array_flip(DesignDiff::ExtraProps));
            if ($changedExtra) $this->applyFieldExtra($metadataTable, $field, $extra);
        }

        foreach ($fieldItems as $field => $it) {
            $verb = ['added' => 'add', 'changed' => 'update', 'removed' => 'delete'][$it['status']];
            $this->log($metadataTable, "field_name = '$field'", "$verb field '$field'$mode");
            $this->done($it, $enteredDraft ? 'Project entered Draft Mode' : '');
        }
        if ($orderTicked) {
            foreach ($items as $it) if ($it['component'] === 'order') {
                $this->log($metadataTable, "project_id = {$this->pid}", "reorder fields and instruments$mode");
                $this->done($it);
            }
        }
    }

    /**
     * Merge ticked field changes into the current dictionary.
     * Unticked items keep the current definition; field order follows the XML when the order
     * item is ticked, otherwise new fields are placed after their neighbour in the XML.
     */
    private function mergeDictionary(array $base, array $fieldItems, bool $orderTicked): array
    {
        $src = $this->src['fields'];
        $remove = $take = [];
        foreach ($fieldItems as $f => $it) {
            if ($it['status'] === DesignDiff::StatusRemoved) $remove[$f] = true;
            else $take[$f] = true;
        }
        $rowFor = fn($f) => isset($take[$f]) ? $src[$f] : $base[$f];

        if ($orderTicked) {
            $seq = [];
            foreach (array_keys($src) as $f) {
                if ((isset($base[$f]) && !isset($remove[$f])) || isset($take[$f])) $seq[] = $f;
            }
            // Fields only in this project and not removed: keep them after their current predecessor
            $prev = null;
            foreach (array_keys($base) as $f) {
                if (!in_array($f, $seq, true) && !isset($remove[$f])) $seq = $this->insertAfter($seq, $f, $prev);
                if (in_array($f, $seq, true)) $prev = $f;
            }
        } else {
            $seq = array_values(array_filter(array_keys($base), fn($f) => !isset($remove[$f])));
            $prev = null;
            foreach (array_keys($src) as $f) {
                if (!isset($base[$f]) && isset($take[$f])) $seq = $this->insertAfter($seq, $f, $prev, $src[$f]['form_name'], $base);
                if (in_array($f, $seq, true)) $prev = $f;
            }
        }

        // Instruments must be contiguous: group fields by instrument in order of first appearance
        $rows = [];
        foreach ($seq as $f) $rows[$f] = $rowFor($f);
        $byForm = [];
        foreach ($rows as $f => $r) $byForm[$r['form_name']][$f] = $r;
        $out = [];
        foreach ($byForm as $formRows) $out += $formRows;

        // Record ID field first
        $pk = $this->tgt['recordIdField'];
        if (isset($out[$pk])) $out = [$pk => $out[$pk]] + $out;
        return $out;
    }

    private function insertAfter(array $seq, string $field, ?string $after, ?string $form = null, array $base = []): array
    {
        $pos = $after === null ? false : array_search($after, $seq, true);
        // A new field whose neighbour is in another instrument goes to the end of its own instrument
        if ($form !== null && $pos !== false && isset($base[$after]) && $base[$after]['form_name'] !== $form) $pos = false;
        if ($pos === false && $form !== null) {
            foreach ($seq as $i => $f) if (($base[$f]['form_name'] ?? $this->src['fields'][$f]['form_name'] ?? null) === $form) $pos = $i;
        }
        if ($pos === false) { $seq[] = $field; return $seq; }
        array_splice($seq, $pos + 1, 0, [$field]);
        return $seq;
    }

    private function applyFieldExtra(string $table, string $field, array $extra): void
    {
        db_query("update $table set stop_actions = ?, video_url = ?, video_display_inline = ?, edoc_display_img = ?
                  where project_id = ? and field_name = ?",
            [$extra['stop_actions'] === '' ? null : $extra['stop_actions'],
             $extra['video_url'] === '' ? null : $extra['video_url'],
             (int)$extra['video_display_inline'], (int)$extra['edoc_display_img'], $this->pid, $field]);
        $att = $this->src['attachments']['__field_' . $field] ?? null;
        if ($att !== null && ($this->tgt['fieldExtra'][$field]['attachment'] ?? '') !== ($extra['attachment'] ?? '')) {
            $docId = $this->uploadAttachment($att);
            db_query("update $table set edoc_id = ? where project_id = ? and field_name = ?", [$docId, $this->pid, $field]);
        }
    }

    private function setFormCss(string $form, string $css): bool
    {
        if (!method_exists('\Design', 'setFormCustomCSS')) return $css === '';
        \Design::setFormCustomCSS($this->pid, $form, $css);
        return true;
    }

    /** Port of Design/draft_mode_enter.php */
    private function enterDraftMode(): void
    {
        $n = db_result(db_query("select count(*) from redcap_metadata_temp where project_id = ?", [$this->pid]), 0);
        if ($n > 0) throw new \Exception('Draft Mode tables are not empty; open the Online Designer to resolve this first');
        $pairs = [
            'redcap_metadata' => 'redcap_metadata_temp',
            'redcap_forms' => 'redcap_forms_temp',
            'redcap_multilanguage_metadata' => 'redcap_multilanguage_metadata_temp',
            'redcap_multilanguage_config' => 'redcap_multilanguage_config_temp',
            'redcap_multilanguage_ui' => 'redcap_multilanguage_ui_temp',
        ];
        foreach ($pairs as $live => $temp) {
            if (!ProdSnapshot::tableExists($live) || !ProdSnapshot::tableExists($temp)) continue;
            $cols = array_intersect(array_keys(getTableColumns($temp)), array_keys(getTableColumns($live)));
            $colList = '`' . implode('`, `', $cols) . '`';
            if (!db_query("insert into $temp ($colList) select $colList from $live where project_id = ?", [$this->pid])) {
                throw new \Exception("Could not enter Draft Mode (copying $live)");
            }
        }
        db_query("update redcap_projects set draft_mode = 1 where project_id = ?", [$this->pid]);
        \Logging::logEvent('', 'redcap_projects', 'MANAGE', $this->pid, "project_id = {$this->pid}", 'Enter draft mode');
    }

    // ---- mapping & repeating -------------------------------------------------------------

    private function applyMapping(array $items): void
    {
        $Proj = new \Project($this->pid, true);
        $current = [];
        foreach ($Proj->eventsForms as $eventId => $forms) {
            $uen = $Proj->getUniqueEventNames($eventId);
            foreach ($forms as $form) $current["$uen|$form"] = ['arm_num' => $Proj->eventInfo[$eventId]['arm_num'], 'unique_event_name' => $uen, 'form' => $form];
        }
        foreach ($items as $it) {
            $pair = $it['status'] === DesignDiff::StatusRemoved ? $it['before'] : $it['after'];
            $k = "{$pair['event']}|{$pair['form']}";
            if ($it['status'] === DesignDiff::StatusRemoved) {
                unset($current[$k]);
            } else {
                $eventId = $Proj->getEventIdUsingUniqueEventName($pair['event']);
                if (!$eventId) throw new \Exception("Event '{$pair['event']}' does not exist (add the event first)");
                $current[$k] = ['arm_num' => $Proj->eventInfo[$eventId]['arm_num'], 'unique_event_name' => $pair['event'], 'form' => $pair['form']];
            }
        }
        if (empty($current)) {
            $Proj->clearEventForms();
        } else {
            [, $errors] = \Event::saveEventMapping($this->pid, array_values($current));
            if ($errors) throw new \Exception(strip_tags(implode('; ', $errors)));
        }
        foreach ($items as $it) {
            $verb = $it['status'] === DesignDiff::StatusRemoved ? 'remove' : 'add';
            $this->log('redcap_events_forms', $it['key'], "$verb instrument-event mapping {$it['key']}");
            $this->done($it);
        }
    }

    private function applyRepeating(array $items): void
    {
        $Proj = new \Project($this->pid, true);
        foreach ($items as $it) {
            $r = $it['status'] === DesignDiff::StatusRemoved ? $it['before'] : $it['after'];
            $eventId = $Proj->longitudinal ? $Proj->getEventIdUsingUniqueEventName($r['event']) : $Proj->firstEventId;
            if (!$eventId) throw new \Exception("Event '{$r['event']}' does not exist");
            if ($it['status'] === DesignDiff::StatusRemoved) {
                if ($r['form'] === null) db_query("delete from redcap_events_repeat where event_id = ? and form_name is null", [$eventId]);
                else db_query("delete from redcap_events_repeat where event_id = ? and form_name = ?", [$eventId, $r['form']]);
                $verb = 'remove';
            } elseif ($r['form'] === null) {
                // A repeating event replaces any repeating instruments on that event
                db_query("delete from redcap_events_repeat where event_id = ?", [$eventId]);
                db_query("insert into redcap_events_repeat (event_id) values (?)", [$eventId]);
                $verb = 'set';
            } else {
                db_query("delete from redcap_events_repeat where event_id = ? and form_name is null", [$eventId]);
                db_query("insert into redcap_events_repeat (event_id, form_name, custom_repeat_form_label) values (?, ?, ?)
                          on duplicate key update custom_repeat_form_label = values(custom_repeat_form_label)",
                    [$eventId, $r['form'], $r['label'] === '' ? null : filter_tags($r['label'])]);
                $verb = 'set';
            }
            $this->log('redcap_events_repeat', $it['key'], "$verb repeating setup {$it['key']}");
            $this->done($it);
        }
    }

    // ---- data quality rules --------------------------------------------------------------

    private function applyDqRules(array $items): void
    {
        foreach ($items as $it) {
            $name = $it['key'];
            if ($it['status'] === DesignDiff::StatusAdded) {
                $rte = ((string)($it['raw']['real_time_execute'] ?? '0') === '1') ? 'y' : 'n';
                if (!\DataQuality::addDQRule($this->pid, $it['raw']['rule_name'], $it['raw']['rule_logic'], $rte)) throw new \Exception("Could not add rule '$name'");
                $verb = 'add';
            } elseif ($it['status'] === DesignDiff::StatusChanged) {
                db_query("update redcap_data_quality_rules set rule_logic = ?, real_time_execute = ? where rule_id = ? and project_id = ?",
                    [$it['raw']['rule_logic'], (int)$it['raw']['real_time_execute'], $it['rowId'], $this->pid]);
                $verb = 'update';
            } else {
                db_query("delete from redcap_data_quality_rules where rule_id = ? and project_id = ?", [$it['rowId'], $this->pid]);
                $verb = 'delete';
            }
            $this->log('redcap_data_quality_rules', "rule_name = '$name'", "$verb data quality rule '$name'");
            $this->done($it);
        }
    }

    // ---- other components (generic) --------------------------------------------------------

    private function applyTable(string $table, array $items): void
    {
        $def = DesignDiff::tableDefs()[$table];
        $label = DesignDiff::Components[$table];
        foreach ($items as $it) {
            $message = '';
            if ($it['status'] === DesignDiff::StatusAdded) {
                $row = $this->checkFromAddress($table, $it['raw'], $message);
                new \Project($this->pid, true); // addArrayToTable uses the cached project
                \ODM::addArrayToTable([$table => [$row], 'redcap_odm_attachment' => $this->src['attachments']]);
                $verb = 'add';
            } elseif ($it['status'] === DesignDiff::StatusChanged) {
                if (!$it['rowId']) throw new \Exception("'{$it['key']}' cannot be matched to a database row");
                $this->updateRow($table, $def, $it, $message);
                $verb = 'update';
            } else {
                if (!$it['rowId']) throw new \Exception("'{$it['key']}' cannot be matched to a database row");
                $this->removeRow($table, $def, (int)$it['rowId']);
                $verb = in_array($table, ['redcap_alerts', 'redcap_surveys_scheduler'], true) ? 'deactivate' : 'delete';
            }
            $this->log($table, "{$it['key']}", "$verb $label item '{$it['key']}'");
            $this->done($it, $message);
        }
    }

    /** Same safeguard as core XML import: a From address must belong to the user applying it. */
    private function checkFromAddress(string $table, array $row, string &$message): array
    {
        $col = $table === 'redcap_alerts' ? 'email_from' : ($table === 'redcap_surveys_scheduler' ? 'email_sender' : null);
        if ($col === null || ($row[$col] ?? '') === '') return $row;
        $info = \User::getUserInfo(USERID);
        $belongs = method_exists('\User', 'emailBelongsToUser')
            ? \User::emailBelongsToUser($row[$col], USERID)
            : in_array(strtolower(trim($row[$col])), array_map(fn($e) => strtolower(trim((string)$e)),
                [$info['user_email'] ?? '', $info['user_email2'] ?? '', $info['user_email3'] ?? '']), true);
        if (!$belongs) {
            $mine = $info['user_email'];
            $message = "From address '{$row[$col]}' is not one of your verified addresses, so '$mine' was used instead.";
            $row[$col] = $mine;
        }
        return $row;
    }

    private function updateRow(string $table, array $def, array $it, string &$message): void
    {
        $Proj = new \Project($this->pid, true);
        $cols = getTableColumns($table);
        $row = $this->checkFromAddress($table, $it['raw'], $message);
        $set = [];
        $params = [];
        foreach ($row as $col => $val) {
            if (!array_key_exists($col, $cols) || in_array($col, $def['ignore'], true)) continue;
            if (!array_key_exists($col, $it['changes'])) continue; // only write what differs
            if (in_array($col, DesignDiff::EventColumns[$table] ?? [], true) && $val !== '') {
                $val = $Proj->getEventIdUsingUniqueEventName($val);
                if (!$val) throw new \Exception("Event '{$row[$col]}' does not exist");
            }
            if (in_array($col, DesignDiff::SurveyColumns[$table] ?? [], true) && $val !== '') {
                $val = $Proj->forms[$val]['survey_id'] ?? null;
                if (!$val) throw new \Exception("'{$row[$col]}' is not a survey in this project");
            }
            if (in_array($col, DesignDiff::FileColumns[$table] ?? [], true)) {
                $val = $val === '' || !isset($this->src['attachments'][$val]) ? null : $this->uploadAttachment($this->src['attachments'][$val]);
            }
            $set[] = "`$col` = ?";
            $params[] = ($val === '' && $this->isNullable($table, $col)) ? null : $val;
        }
        if ($set) {
            $params[] = $it['rowId'];
            db_query("update `$table` set " . implode(', ', $set) . " where `{$def['pk']}` = ?", $params);
        }
        $this->updateChildren($table, $it, $Proj);
    }

    /** Report fields/filters and form display logic targets live in child tables. */
    private function updateChildren(string $table, array $it, \Project $Proj): void
    {
        $id = (int)$it['rowId'];
        if ($table === 'redcap_reports') {
            if (isset($it['changes']['redcap_reports_fields'])) {
                db_query("delete from redcap_reports_fields where report_id = ?", [$id]);
                $order = 1;
                foreach (array_filter(explode(',', $it['raw']['redcap_reports_fields'] ?? '')) as $f) {
                    db_query("insert into redcap_reports_fields (report_id, field_name, field_order) values (?, ?, ?)", [$id, $f, $order++]);
                }
            }
            if (isset($it['changes']['redcap_reports_filter_dags'])) {
                db_query("delete from redcap_reports_filter_dags where report_id = ?", [$id]);
                $dags = $Proj->getUniqueGroupNames();
                foreach (array_filter(explode(',', $it['raw']['redcap_reports_filter_dags'] ?? '')) as $d) {
                    $gid = array_search($d, $dags);
                    if ($gid === false) throw new \Exception("DAG '$d' does not exist");
                    db_query("insert into redcap_reports_filter_dags (report_id, group_id) values (?, ?)", [$id, $gid]);
                }
            }
            if (isset($it['changes']['redcap_reports_filter_events'])) {
                db_query("delete from redcap_reports_filter_events where report_id = ?", [$id]);
                foreach (array_filter(explode(',', $it['raw']['redcap_reports_filter_events'] ?? '')) as $e) {
                    $eid = $Proj->getEventIdUsingUniqueEventName($e);
                    if (!$eid) throw new \Exception("Event '$e' does not exist");
                    db_query("insert into redcap_reports_filter_events (report_id, event_id) values (?, ?)", [$id, $eid]);
                }
            }
        } elseif ($table === 'redcap_form_display_logic_conditions' && isset($it['changes']['forms_events'])) {
            db_query("delete from redcap_form_display_logic_targets where control_id = ?", [$id]);
            foreach (array_filter(explode(',', $it['raw']['forms_events'] ?? '')) as $fe) {
                [$uen, $form] = array_pad(explode(':', $fe, 2), 2, '');
                $eid = $uen === '' ? null : $Proj->getEventIdUsingUniqueEventName($uen);
                if ($uen !== '' && !$eid) throw new \Exception("Event '$uen' does not exist");
                db_query("insert into redcap_form_display_logic_targets (control_id, form_name, event_id) values (?, ?, ?)", [$id, $form, $eid]);
            }
        }
    }

    private function removeRow(string $table, array $def, int $id): void
    {
        if ($table === 'redcap_alerts') {
            db_query("update redcap_alerts set email_deleted = 1 where alert_id = ? and project_id = ?", [$id, $this->pid]);
        } elseif ($table === 'redcap_surveys_scheduler') {
            db_query("update redcap_surveys_scheduler set active = 0 where ss_id = ?", [$id]);
        } elseif ($table === 'redcap_surveys_queue') {
            db_query("delete from redcap_surveys_queue where sq_id = ?", [$id]);
        } else {
            db_query("delete from `$table` where `{$def['pk']}` = ? and project_id = ?", [$id, $this->pid]);
        }
    }

    private function isNullable(string $table, string $col): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cache[$table] = [];
            $q = db_query("select column_name, is_nullable from information_schema.columns where table_schema = database() and table_name = ?", [$table]);
            while ($r = db_fetch_assoc($q)) $cache[$table][$r['column_name'] ?? $r['COLUMN_NAME']] = ($r['is_nullable'] ?? $r['IS_NULLABLE']) === 'YES';
        }
        return $cache[$table][$col] ?? false;
    }

    private function uploadAttachment(array $att): int
    {
        $tmp = APP_PATH_TEMP . date('YmdHis') . '_' . substr(sha1(random_bytes(8)), 0, 6) . '_' . str_replace(' ', '_', basename($att['DocName']));
        file_put_contents($tmp, base64_decode($att['Content']));
        $docId = \Files::uploadFile(['name' => basename($att['DocName']), 'type' => $att['MimeType'], 'size' => filesize($tmp), 'tmp_name' => $tmp]);
        if (file_exists($tmp)) @unlink($tmp);
        if (!is_numeric($docId)) throw new \Exception("Could not store attachment '{$att['DocName']}'");
        return (int)$docId;
    }
}
