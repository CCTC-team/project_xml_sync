<?php

namespace CCTC\ProjectXmlSyncModule;

/**
 * Compares the uploaded XML (source, e.g. TEST) with the target project (e.g. PROD) and
 * returns a flat list of diff items, one per field / event / rule / alert / ...
 *
 * Items are matched on natural keys only (field name, unique event name, rule name, title,
 * role name, ...) because database ids differ between servers.
 */
class DesignDiff
{
    const StatusAdded = 'added';
    const StatusChanged = 'changed';
    const StatusRemoved = 'removed';
    const StatusManual = 'manual';

    /** Component code => display label, in apply order. */
    const Components = [
        'project'   => 'Project settings',
        'arms'      => 'Arms',
        'events'    => 'Events',
        'fields'    => 'Fields (applied to Draft Mode)',
        'forms'     => 'Instruments (labels & custom CSS, applied to Draft Mode)',
        'order'     => 'Field & instrument order (applied to Draft Mode)',
        'mapping'   => 'Instrument-event mapping',
        'repeating' => 'Repeating instruments & events',
        'redcap_data_access_groups'            => 'Data access groups',
        'redcap_user_roles'                    => 'User roles',
        'redcap_data_quality_rules'            => 'Data quality rules',
        'redcap_surveys'                       => 'Survey settings',
        'redcap_surveys_queue'                 => 'Survey queue',
        'redcap_surveys_scheduler'             => 'Automated survey invitations',
        'redcap_alerts'                        => 'Alerts & notifications',
        'redcap_form_display_logic_conditions' => 'Form display logic',
        'redcap_reports'                       => 'Reports',
        'redcap_record_dashboards'             => 'Record status dashboards',
        'redcap_project_dashboards'            => 'Project dashboards',
        'redcap_descriptive_popups'            => 'Descriptive popups',
        'manual'    => 'Needs manual action (not applied by this module)',
    ];

    /** Columns of redcap_projects that are reported but never applied automatically. */
    const ManualProjectAttrs = [
        'randomization', 'mycap_enabled', 'protected_email_mode_logo',
        'datamart_enabled', 'datamart_allow_repeat_revision', 'datamart_allow_create_revision', 'datamart_cron_enabled',
        'realtime_webservice_type', 'realtime_webservice_offset_days', 'realtime_webservice_offset_plusminus',
    ];

    /** Columns that hold attachments (from ODM::$tableMappings, which is private). */
    const FileColumns = [
        'redcap_surveys' => ['logo', 'confirmation_email_attachment'],
        'redcap_alerts'  => ['email_attachment1', 'email_attachment2', 'email_attachment3', 'email_attachment4', 'email_attachment5'],
        'redcap_mycap_aboutpages' => ['custom_logo'],
        'redcap_econsent_forms' => ['consent_form_pdf_doc_id'],
    ];

    /** Columns holding unique event names in the XML (event_id in the database). */
    const EventColumns = [
        'redcap_surveys_scheduler' => ['event_id', 'condition_surveycomplete_event_id'],
        'redcap_surveys_queue'     => ['event_id', 'condition_surveycomplete_event_id'],
        'redcap_record_dashboards' => ['sort_event_id'],
        'redcap_alerts'            => ['form_name_event'],
        'redcap_surveys'           => ['pdf_save_to_event_id', 'pdf_econsent_firstname_event_id', 'pdf_econsent_lastname_event_id', 'pdf_econsent_dob_event_id'],
    ];

    /** Columns holding form names in the XML (survey_id in the database). */
    const SurveyColumns = [
        'redcap_surveys_scheduler' => ['survey_id', 'condition_surveycomplete_survey_id'],
        'redcap_surveys_queue'     => ['survey_id', 'condition_surveycomplete_survey_id'],
    ];

    /** Right needed (besides Project Design) to change a component; super users have all. */
    const RequiredRight = [
        'redcap_data_quality_rules' => 'data_quality_design',
        'redcap_data_access_groups' => 'data_access_groups',
        'redcap_user_roles'         => 'user_rights',
        'redcap_alerts'             => 'alerts',
        'redcap_reports'            => 'reports',
    ];

    /** Columns ignored by every comparison of a manual-only component (server-specific ids). */
    const ManualIgnore = ['ID', 'project_id', 'consent_id', 'code', 'config', 'task_id', 'ts_id', 'page_id', 'link_id',
                          'contact_id', 'theme_id', 'participant_id', 'rid', 'map_id', 'consent_form_id', 'snapshot_id', 'folder_id'];

    /**
     * Per-table settings for components the module can apply.
     * key: natural key columns; ignore: never compared or written; pk: database primary key.
     */
    public static function tableDefs(): array
    {
        return [
            'redcap_data_access_groups' => ['key' => ['group_name'], 'ignore' => ['group_id', 'project_id'], 'pk' => 'group_id'],
            'redcap_user_roles' => ['key' => ['role_name'], 'ignore' => ['role_id', 'project_id', 'unique_role_name'], 'pk' => 'role_id'],
            'redcap_data_quality_rules' => ['key' => ['rule_name'], 'ignore' => ['rule_id', 'project_id', 'rule_order'], 'pk' => 'rule_id'],
            'redcap_surveys' => ['key' => ['form_name'], 'ignore' => ['survey_id', 'project_id'], 'pk' => 'survey_id', 'form' => 'form_name'],
            'redcap_surveys_queue' => ['key' => ['survey_id', 'event_id'], 'ignore' => ['sq_id'], 'pk' => 'sq_id', 'form' => 'survey_id'],
            // The XML export keys ASIs by survey and event only (no instance column)
            'redcap_surveys_scheduler' => ['key' => ['survey_id', 'event_id'], 'ignore' => ['ss_id', 'active', 'instance'], 'pk' => 'ss_id', 'form' => 'survey_id'],
            'redcap_alerts' => ['key' => ['alert_title'], 'pk' => 'alert_id',
                'ignore' => ['alert_id', 'project_id', 'email_deleted', 'alert_order', 'email_timestamp_sent', 'email_sent', 'email_failed']],
            'redcap_form_display_logic_conditions' => ['key' => ['control_condition'], 'ignore' => ['control_id', 'project_id'], 'pk' => 'control_id'],
            'redcap_reports' => ['key' => ['title'], 'pk' => 'report_id',
                'ignore' => ['ID', 'report_id', 'project_id', 'unique_report_name', 'report_order', 'user_access', 'user_edit_access', 'hash', 'short_url', 'is_public']],
            'redcap_record_dashboards' => ['key' => ['title'], 'ignore' => ['rd_id', 'project_id'], 'pk' => 'rd_id'],
            'redcap_project_dashboards' => ['key' => ['title'], 'pk' => 'dash_id',
                'ignore' => ['ID', 'dash_id', 'project_id', 'dash_order', 'user_access', 'hash', 'short_url', 'is_public', 'cache_time', 'cache_content']],
            'redcap_descriptive_popups' => ['key' => ['inline_text'], 'ignore' => ['popup_id', 'project_id'], 'pk' => 'popup_id'],
        ];
    }

    /** Pseudo-columns in the XML that are not table columns but must be compared. */
    const ExtraColumns = [
        'redcap_reports' => ['redcap_reports_fields', 'redcap_reports_filter_dags', 'redcap_reports_filter_events'],
        'redcap_form_display_logic_conditions' => ['forms_events'],
    ];

    /** Natural key of a row, or null when it cannot be matched (blank key). */
    public static function rowKey(string $table, array $row): ?string
    {
        $def = self::tableDefs()[$table] ?? null;
        if ($def === null) return null;
        $parts = [];
        foreach ($def['key'] as $col) $parts[] = self::norm($row[$col] ?? '');
        if (implode('', $parts) === '') return null;
        return implode(' / ', $parts);
    }

    public static function norm($v): string
    {
        return trim(str_replace("\r\n", "\n", (string)$v));
    }

    /**
     * @param array $src  parsed uploaded XML
     * @param array $tgt  ProdSnapshot::build()
     * @param array $ctx  ['superUser'=>bool, 'rights'=>array, 'editProdEvents'=>bool, 'editProdRepeating'=>bool]
     * @return array{items: array, warnings: string[]}
     */
    public static function compute(array $src, array $tgt, array $ctx): array
    {
        $d = new self($src, $tgt, $ctx);
        $d->run();
        return ['items' => $d->items, 'warnings' => $d->warnings];
    }

    // ---------------------------------------------------------------------------------------

    private array $src;
    private array $tgt;
    private array $ctx;
    private array $items = [];
    private array $warnings = [];
    private bool $inProduction;

    private function __construct(array $src, array $tgt, array $ctx)
    {
        $this->src = $src;
        $this->tgt = $tgt;
        $this->ctx = $ctx;
        $this->inProduction = $tgt['status'] > 0;
    }

    private function run(): void
    {
        if ($this->src['hasClinicalData']) {
            $this->warnings[] = 'The uploaded XML contains record data. Only the project design is compared; no records are imported.';
        }
        if ($this->src['recordIdField'] !== '' && $this->src['recordIdField'] !== $this->tgt['recordIdField']) {
            $this->addManual('Record ID field', "The record ID field differs ({$this->tgt['recordIdField']} here, {$this->src['recordIdField']} in the XML). Field changes are disabled until this is resolved.");
        }
        if ($this->src['longitudinal'] !== $this->tgt['longitudinal']) {
            $this->addManual('Longitudinal setting', 'One project is longitudinal and the other is not. Enable/disable "Use longitudinal data collection" manually first; events and mapping are not compared.');
        }

        $this->diffProjectAttrs();
        if ($this->src['longitudinal'] && $this->tgt['longitudinal']) {
            $this->diffArms();
            $this->diffEvents();
        }
        $this->diffFields();
        $this->diffForms();
        $this->diffOrder();
        if ($this->src['longitudinal'] && $this->tgt['longitudinal']) $this->diffMapping();
        $this->diffRepeating();
        foreach (array_keys(self::tableDefs()) as $table) $this->diffTable($table);
        $this->diffManualTables();
        $this->applyGates();
    }

    // ---- item helpers -----------------------------------------------------------------------

    private function add(string $component, string $key, string $status, array $extra = []): void
    {
        $id = substr(sha1($component . '|' . $key), 0, 16);
        $this->items[$id] = array_merge([
            'id'        => $id,
            'component' => $component,
            'key'       => $key,
            'status'    => $status,
            'changes'   => [],
            'before'    => null,
            'after'     => null,
            'default'   => $status === self::StatusAdded || $status === self::StatusChanged,
            'blocked'   => null,
            'note'      => '',
            'approval'  => false,
        ], $extra);
    }

    private function addManual(string $key, string $note): void
    {
        $this->add('manual', $key, self::StatusManual, ['note' => $note, 'blocked' => 'Copy this manually', 'default' => false]);
    }

    /** [prop => [before, after]] for props that differ */
    private static function changes(array $before, array $after, array $props): array
    {
        $out = [];
        foreach ($props as $p) {
            $b = self::norm($before[$p] ?? '');
            $a = self::norm($after[$p] ?? '');
            if ($b !== $a) $out[$p] = [$b, $a];
        }
        return $out;
    }

    private function formIsLive(string $form): bool
    {
        return in_array($form, $this->tgt['liveForms'], true);
    }

    /** True when an instrument only exists after the draft is approved (production only). */
    private function needsApproval(string $form): bool
    {
        return $this->inProduction && !$this->formIsLive($form);
    }

    // ---- components -------------------------------------------------------------------------

    private function diffProjectAttrs(): void
    {
        $cols = array_unique(array_merge(array_keys($this->src['projectAttrs']), array_keys($this->tgt['projectAttrs'])));
        foreach ($cols as $col) {
            // Only compare attributes the uploaded XML actually carries
            if (!array_key_exists($col, $this->src['projectAttrs'])) continue;
            $b = self::norm($this->tgt['projectAttrs'][$col] ?? '');
            $a = self::norm($this->src['projectAttrs'][$col]);
            if ($b === $a) continue;
            $extra = ['changes' => [$col => [$b, $a]], 'before' => $b, 'after' => $a];
            if (in_array($col, self::ManualProjectAttrs, true)) {
                $this->add('manual', "Project setting: $col", self::StatusManual, $extra + ['blocked' => 'Change this in Project Setup / Control Center', 'default' => false]);
                continue;
            }
            if ($col === 'secondary_pk' && $a !== '' && $this->inProduction && !in_array($a, $this->tgt['liveFields'], true)) {
                $extra['approval'] = true;
            }
            $this->add('project', $col, self::StatusChanged, $extra);
        }
    }

    private function diffArms(): void
    {
        foreach ($this->src['arms'] as $num => $name) {
            if (!isset($this->tgt['arms'][$num])) {
                $this->add('arms', "Arm $num", self::StatusAdded, ['after' => ['arm_num' => $num, 'name' => $name], 'ref' => $num]);
            } elseif (self::norm($this->tgt['arms'][$num]) !== self::norm($name)) {
                $this->add('arms', "Arm $num", self::StatusChanged, ['changes' => ['name' => [$this->tgt['arms'][$num], $name]],
                    'after' => ['arm_num' => $num, 'name' => $name], 'ref' => $num]);
            }
        }
        foreach ($this->tgt['arms'] as $num => $name) {
            if (isset($this->src['arms'][$num])) continue;
            $blocked = null;
            foreach ($this->tgt['events'] as $uen => $ev) {
                if ((string)$ev['arm_num'] === (string)$num && isset($this->tgt['dataEvents'][$this->tgt['eventIds'][$uen] ?? 0])) {
                    $blocked = 'Arm has events containing data';
                }
            }
            $this->add('arms', "Arm $num", self::StatusRemoved, ['before' => ['arm_num' => $num, 'name' => $name], 'ref' => $num, 'blocked' => $blocked,
                'note' => 'Deleting an arm also deletes its events.']);
        }
    }

    private function diffEvents(): void
    {
        $props = ['event_name', 'arm_num', 'day_offset', 'offset_min', 'offset_max', 'custom_event_label'];
        foreach ($this->src['events'] as $uen => $ev) {
            if (!isset($this->tgt['events'][$uen])) {
                $this->add('events', $uen, self::StatusAdded, ['after' => $ev,
                    'note' => $this->renameHint($ev['event_name'], 'event_name', $this->tgt['events'], $this->src['events'])]);
                continue;
            }
            $ch = self::changes($this->tgt['events'][$uen], $ev, $props);
            if (isset($ch['arm_num'])) {
                $this->addManual("Event $uen", 'The event belongs to a different arm in the XML. Moving events between arms is not supported.');
                continue;
            }
            if ($ch) $this->add('events', $uen, self::StatusChanged, ['changes' => $ch, 'before' => $this->tgt['events'][$uen], 'after' => $ev]);
        }
        foreach ($this->tgt['events'] as $uen => $ev) {
            if (isset($this->src['events'][$uen])) continue;
            $eventId = $this->tgt['eventIds'][$uen] ?? 0;
            $this->add('events', $uen, self::StatusRemoved, ['before' => $ev,
                'blocked' => isset($this->tgt['dataEvents'][$eventId]) ? 'Event contains data' : null,
                'note' => $this->renameHint($ev['event_name'], 'event_name', $this->src['events'], $this->tgt['events'])]);
        }
    }

    /** "Possibly renamed" hint when an added/removed item looks like a renamed one. */
    private function renameHint(string $label, string $prop, array $otherSide, array $sameSide): string
    {
        foreach ($otherSide as $k => $row) {
            if (isset($sameSide[$k])) continue;
            $o = is_array($row) ? ($row[$prop] ?? '') : $row;
            similar_text(strtolower($label), strtolower((string)$o), $pct);
            if ($pct >= 70) return "Possibly renamed (compare with '$k').";
        }
        return '';
    }

    private function fieldProps(): array
    {
        return array_values(array_diff(array_values(\MetaData::getDataDictionaryHeaders()), ['field_name']));
    }

    const ExtraProps = ['stop_actions', 'video_url', 'video_display_inline', 'edoc_display_img', 'attachment'];

    private function diffFields(): void
    {
        $props = $this->fieldProps();
        $src = $this->src['fields'];
        $tgt = $this->tgt['fields'];
        foreach ($src as $field => $row) {
            $after = $row + ($this->src['fieldExtra'][$field] ?? []);
            if (!isset($tgt[$field])) {
                $this->add('fields', $field, self::StatusAdded, ['after' => $after, 'form' => $row['form_name']]);
                continue;
            }
            $before = $tgt[$field] + ($this->tgt['fieldExtra'][$field] ?? []);
            $ch = self::changes($before, $after, array_merge($props, self::ExtraProps));
            if (!$ch) continue;
            $note = '';
            if (isset($ch['field_type'])) $note = 'Field type changes can make existing data invalid; review the critical-change warnings before submitting the draft.';
            elseif (isset($ch['select_choices_or_calculations']) && in_array($row['field_type'], OdmDesignParser::ChoiceTypes, true)) {
                $removed = array_diff(array_keys(parseEnum(str_replace('|', "\\n", $ch['select_choices_or_calculations'][0]))),
                                      array_keys(parseEnum(str_replace('|', "\\n", $ch['select_choices_or_calculations'][1]))));
                if ($removed) $note = 'Choice code(s) removed: ' . implode(', ', $removed) . '. Existing data with these codes will no longer display.';
            }
            if (isset($ch['form_name'])) $note = trim($note . ' Field moves to a different instrument.');
            $this->add('fields', $field, self::StatusChanged, ['changes' => $ch, 'before' => $before, 'after' => $after, 'note' => $note, 'form' => $row['form_name']]);
        }
        foreach ($tgt as $field => $row) {
            if (isset($src[$field])) continue;
            $blocked = $field === $this->tgt['recordIdField'] ? 'The record ID field cannot be deleted' : null;
            $this->add('fields', $field, self::StatusRemoved, ['before' => $row + ($this->tgt['fieldExtra'][$field] ?? []), 'blocked' => $blocked,
                'note' => 'Data in this field stays in the database but is no longer shown. REDCap lists fields with data when you review the draft.',
                'form' => $row['form_name']]);
        }
    }

    private function diffForms(): void
    {
        foreach ($this->src['forms'] as $form => $f) {
            if (!isset($this->tgt['forms'][$form])) continue; // new instruments get their label/CSS with their fields
            $ch = self::changes($this->tgt['forms'][$form], $f, ['label', 'css']);
            if ($ch) $this->add('forms', $form, self::StatusChanged, ['changes' => $ch, 'before' => $this->tgt['forms'][$form], 'after' => $f]);
        }
    }

    private function diffOrder(): void
    {
        $common = array_intersect_key($this->src['fields'], $this->tgt['fields']);
        $srcOrder = array_values(array_intersect(array_keys($this->src['fields']), array_keys($common)));
        $tgtOrder = array_values(array_intersect(array_keys($this->tgt['fields']), array_keys($common)));
        $srcForms = array_values(array_intersect(array_keys($this->src['forms']), array_keys($this->tgt['forms'])));
        $tgtForms = array_values(array_intersect(array_keys($this->tgt['forms']), array_keys($this->src['forms'])));
        if ($srcOrder === $tgtOrder && $srcForms === $tgtForms) return;
        $note = [];
        if ($srcForms !== $tgtForms) $note[] = 'Instrument order differs.';
        $moved = 0;
        foreach ($srcOrder as $i => $f) if (($tgtOrder[$i] ?? null) !== $f) $moved++;
        if ($moved) $note[] = "$moved field position(s) differ.";
        $this->add('order', 'Field & instrument order', self::StatusChanged, [
            'changes' => ['instrument order' => [implode(', ', $tgtForms), implode(', ', $srcForms)]],
            'note' => implode(' ', $note) . ' When unticked, existing fields keep their current order and new fields are placed after their neighbour in the XML.',
        ]);
    }

    private function diffMapping(): void
    {
        $pairs = function (array $mapping): array {
            $out = [];
            foreach ($mapping as $uen => $forms) foreach ($forms as $form) $out["$uen -> $form"] = [$uen, $form];
            return $out;
        };
        $src = $pairs($this->src['mapping']);
        $tgt = $pairs($this->tgt['mapping']);
        foreach (array_diff_key($src, $tgt) as $key => [$uen, $form]) {
            $this->add('mapping', $key, self::StatusAdded, ['after' => ['event' => $uen, 'form' => $form], 'approval' => $this->needsApproval($form)]);
        }
        foreach (array_diff_key($tgt, $src) as $key => [$uen, $form]) {
            $eventId = $this->tgt['eventIds'][$uen] ?? 0;
            $hasData = isset($this->tgt['dataEventForms']["$eventId:$form"]);
            $this->add('mapping', $key, self::StatusRemoved, ['before' => ['event' => $uen, 'form' => $form],
                'note' => $hasData ? 'This instrument has data on this event; the data will be hidden (not deleted).' : '']);
        }
    }

    private function diffRepeating(): void
    {
        $flat = function (array $rep): array {
            $out = [];
            foreach ($rep as $uen => $forms) {
                if (!is_array($forms)) { $out["$uen (whole event)"] = [$uen, null, '']; continue; }
                foreach ($forms as $form => $label) $out["$uen -> $form"] = [$uen, $form, (string)$label];
            }
            return $out;
        };
        $src = $flat($this->src['repeating']);
        $tgt = $flat($this->tgt['repeating']);
        foreach ($src as $key => [$uen, $form, $label]) {
            $after = ['event' => $uen, 'form' => $form, 'label' => $label];
            $approval = $form !== null && $this->needsApproval($form);
            if (!isset($tgt[$key])) {
                $this->add('repeating', $key, self::StatusAdded, ['after' => $after, 'approval' => $approval]);
            } elseif (self::norm($tgt[$key][2]) !== self::norm($label)) {
                $this->add('repeating', $key, self::StatusChanged, ['after' => $after, 'approval' => $approval,
                    'changes' => ['custom label' => [$tgt[$key][2], $label]]]);
            }
        }
        foreach ($tgt as $key => [$uen, $form, $label]) {
            if (!isset($src[$key])) {
                $this->add('repeating', $key, self::StatusRemoved, ['before' => ['event' => $uen, 'form' => $form, 'label' => $label],
                    'note' => 'Existing repeating instances would no longer be accessible. Only do this if no repeating data exists.']);
            }
        }
    }

    /** Values of one XML row ready for comparison (attachments resolved, ignored columns removed). */
    private function comparableRow(string $table, array $row, array $attachments, array $ignore): array
    {
        $cols = getTableColumns($table);
        $out = [];
        foreach ($row as $col => $val) {
            if (in_array($col, $ignore, true)) continue;
            if (!array_key_exists($col, $cols) && !in_array($col, self::ExtraColumns[$table] ?? [], true)) continue;
            if (in_array($col, self::FileColumns[$table] ?? [], true) && $val !== '') {
                $att = $attachments[$val] ?? null;
                $val = $att ? 'file:' . sha1($att['Content']) . ':' . $att['DocName'] : '';
            }
            $out[$col] = self::norm($val);
        }
        return $out;
    }

    private function diffTable(string $table): void
    {
        if (!ProdSnapshot::tableExists($table)) return;
        $def = self::tableDefs()[$table];
        $srcRows = $this->src['vendor'][$table] ?? [];
        $tgtRows = $this->tgt['vendor'][$table] ?? [];
        $present = !empty($this->src['groupsPresent'][$table]);
        if (!$present && empty($tgtRows)) return;

        $index = function (array $rows, array $attachments, bool $isSrc) use ($table, $def): array {
            $out = [];
            foreach ($rows as $row) {
                $key = self::rowKey($table, $row);
                if ($key === null) {
                    if ($isSrc) $this->addManual(self::Components[$table] . ': item without a name', 'An item in the XML has a blank ' . implode('/', $def['key']) . ' and cannot be matched.');
                    continue;
                }
                if (isset($out[$key])) {
                    $this->addManual(self::Components[$table] . ": $key", 'More than one item shares this name' . ($isSrc ? ' in the XML' : ' in this project') . ', so it cannot be matched automatically.');
                    $out[$key] = false;
                    continue;
                }
                $out[$key] = ['raw' => $row, 'cmp' => $this->comparableRow($table, $row, $attachments, $def['ignore'])];
            }
            return $out;
        };
        $src = $index($srcRows, $this->src['attachments'], true);
        $tgt = $index($tgtRows, $this->tgt['attachments'], false);

        if (!$present && !empty($tgtRows)) {
            $this->warnings[] = 'The uploaded XML has no "' . self::Components[$table] . '" section. If the source project has any, re-export it with that option ticked; otherwise the existing items are listed as removed.';
        }

        foreach ($src as $key => $s) {
            if ($s === false || (isset($tgt[$key]) && $tgt[$key] === false)) continue;
            $form = isset($def['form']) ? ($s['raw'][$def['form']] ?? '') : '';
            $approval = $form !== '' && $this->needsApproval($form);
            if (!isset($tgt[$key])) {
                // Not exported but present in the database, e.g. survey settings while surveys are disabled
                $hidden = isset($this->tgt['rowIds'][$table][$key]);
                $this->add($table, $key, self::StatusAdded, ['after' => $s['cmp'], 'raw' => $s['raw'], 'approval' => $approval,
                    'note' => $this->tableNote($table, self::StatusAdded),
                    'blocked' => $hidden ? 'Already exists in this project but is inactive (e.g. surveys are disabled). Enable it first, then compare again' : null]);
                continue;
            }
            // The export writes every column (even empty ones), so a column missing on one side
            // means the servers run different REDCap versions, not that the value changed
            $props = array_intersect(array_keys($s['cmp']), array_keys($tgt[$key]['cmp']));
            $ch = self::changes($tgt[$key]['cmp'], $s['cmp'], $props);
            if ($ch) {
                $this->add($table, $key, self::StatusChanged, ['changes' => $ch, 'before' => $tgt[$key]['cmp'], 'after' => $s['cmp'],
                    'raw' => $s['raw'], 'approval' => $approval, 'rowId' => $this->tgt['rowIds'][$table][$key] ?? null]);
            }
        }
        foreach ($tgt as $key => $t) {
            if ($t === false || isset($src[$key])) continue;
            $blocked = $this->removalBlock($table, $key);
            $this->add($table, $key, self::StatusRemoved, ['before' => $t['cmp'], 'blocked' => $blocked,
                'rowId' => $this->tgt['rowIds'][$table][$key] ?? null, 'note' => $this->tableNote($table, self::StatusRemoved)]);
        }
    }

    private function tableNote(string $table, string $status): string
    {
        if ($status === self::StatusAdded && $table === 'redcap_alerts') return 'Added with the active/deactivated state it has in the XML.';
        if ($status === self::StatusAdded && $table === 'redcap_surveys_scheduler') return 'Added with the active state it has in the XML.';
        if ($status === self::StatusRemoved && $table === 'redcap_alerts') return 'The alert is deactivated (not permanently deleted), so its history is kept.';
        if ($status === self::StatusRemoved && $table === 'redcap_surveys_scheduler') return 'The invitation is deactivated (not deleted).';
        return '';
    }

    private function removalBlock(string $table, string $key): ?string
    {
        $id = $this->tgt['rowIds'][$table][$key] ?? null;
        if ($table === 'redcap_surveys') return 'Surveys are never deleted here; disable the survey in the Online Designer instead';
        if (!$id) return 'Cannot be matched to a database row';
        if ($table === 'redcap_user_roles') {
            $n = db_result(db_query("select count(*) from redcap_user_rights where project_id = ? and role_id = ?", [$this->tgt['project_id'], $id]), 0);
            if ($n > 0) return "$n user(s) are assigned to this role";
        }
        if ($table === 'redcap_data_access_groups') {
            $n = db_result(db_query("select count(*) from redcap_user_rights where project_id = ? and group_id = ?", [$this->tgt['project_id'], $id]), 0);
            if ($n > 0) return "$n user(s) are assigned to this DAG";
            $dataTable = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($this->tgt['project_id']) : 'redcap_data';
            $q = db_query("select 1 from $dataTable where project_id = ? and field_name = '__GROUPID__' and value = ? limit 1", [$this->tgt['project_id'], $id]);
            if (db_num_rows($q) > 0) return 'Records are assigned to this DAG';
        }
        return null;
    }

    /** Components the module reports but never applies. One item per component that differs. */
    private function diffManualTables(): void
    {
        $auto = array_merge(array_keys(self::tableDefs()), ['redcap_multilanguage_settings']);
        $tables = array_unique(array_merge(array_keys($this->src['vendor']), array_keys($this->tgt['vendor'])));
        foreach ($tables as $table) {
            if (in_array($table, $auto, true) && $table !== 'redcap_multilanguage_settings') continue;
            if (empty($this->src['groupsPresent'][$table])) continue;
            $sig = function (array $rows, array $attachments, array $idTitles) use ($table): array {
                $out = [];
                foreach ($rows as $row) {
                    $c = [];
                    foreach ($row as $col => $val) {
                        if (in_array($col, self::ManualIgnore, true)) continue;
                        if (in_array($col, self::FileColumns[$table] ?? [], true) && isset($attachments[$val])) $val = sha1($attachments[$val]['Content']);
                        if (in_array($col, ['redcap_reports_folders_items', 'redcap_project_dashboards_folders_items'], true)) {
                            $val = implode(',', array_map(fn($h) => $idTitles[$h] ?? $h, array_filter(explode(',', $val))));
                        }
                        $c[$col] = self::norm($val);
                    }
                    ksort($c);
                    $out[] = json_encode($c);
                }
                sort($out);
                return $out;
            };
            $s = $sig($this->src['vendor'][$table] ?? [], $this->src['attachments'], self::idTitles($this->src));
            $t = $sig($this->tgt['vendor'][$table] ?? [], $this->tgt['attachments'], self::idTitles($this->tgt));
            if ($s === $t) continue;
            $label = ucwords(str_replace('_', ' ', preg_replace('/^redcap_/', '', $table)));
            $this->addManual($label, sprintf('Differs (%d item(s) in the XML, %d in this project). This component is not applied automatically; review and copy it manually.', count($s), count($t)));
        }
    }

    /** sha1(report_id / dash_id) => title, to compare folder contents across servers */
    private static function idTitles(array $model): array
    {
        $map = [];
        foreach (['redcap_reports', 'redcap_project_dashboards'] as $t) {
            foreach ($model['vendor'][$t] ?? [] as $row) if (isset($row['ID'])) $map[$row['ID']] = $row['title'] ?? '';
        }
        return $map;
    }

    /** Production restrictions, user rights, status checks and draft-approval dependencies. */
    private function applyGates(): void
    {
        $super = $this->ctx['superUser'];
        $rights = $this->ctx['rights'];
        $status = $this->tgt['status'];
        $recordIdMismatch = isset($this->items[substr(sha1('manual|Record ID field'), 0, 16)]);

        foreach ($this->items as &$it) {
            if ($it['blocked'] !== null) { $it['default'] = false; continue; }
            $c = $it['component'];

            if ($status >= 2) {
                $it['blocked'] = 'The project is in Analysis/Cleanup or Completed status';
            } elseif (in_array($c, ['fields', 'forms', 'order'], true) && $this->tgt['draftMode'] === 2) {
                $it['blocked'] = 'Drafted changes are awaiting approval; field changes can be added after they are approved or rejected';
            } elseif (in_array($c, ['fields', 'forms', 'order'], true) && $recordIdMismatch) {
                $it['blocked'] = 'Record ID field differs';
            } elseif ($it['approval']) {
                $it['blocked'] = 'Available after the drafted instrument is approved (re-open this page afterwards)';
            } elseif (!$super && $this->inProduction && in_array($c, ['arms', 'events'], true) && !$this->ctx['editProdEvents']) {
                $it['blocked'] = 'Only administrators can change arms/events in production';
            } elseif (!$super && $this->inProduction && $c === 'mapping' && $it['status'] === self::StatusRemoved) {
                $it['blocked'] = 'Only administrators can remove instrument-event mappings in production';
            } elseif (!$super && $this->inProduction && $c === 'repeating' && !$this->ctx['editProdRepeating']) {
                $it['blocked'] = 'Only administrators can change the repeating setup in production';
            } elseif (!$super && isset(self::RequiredRight[$c]) && empty($rights[self::RequiredRight[$c]])) {
                $it['blocked'] = 'You need the "' . self::RequiredRight[$c] . '" user right';
            } elseif (in_array($c, ['arms', 'events'], true) && $it['status'] === self::StatusRemoved && !$super) {
                $it['blocked'] = 'Only administrators can delete arms/events';
            }
            if ($it['blocked'] !== null) $it['default'] = false;
        }
        unset($it);
    }
}
