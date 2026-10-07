<?php

namespace CCTC\ProjectXmlSyncModule;

/**
 * The target project's current design, in the same shape OdmDesignParser returns for the
 * uploaded XML, plus the database row ids needed to update/delete matched items.
 *
 * The project's own Project XML is generated and parsed, so both sides of the diff have been
 * through identical export conversions (event_id -> unique event name, survey_id -> form,
 * edoc -> attachment, etc.). In Draft Mode the drafted fields/instruments are exported
 * instead of the live ones, so field changes are diffed against the draft.
 */
class ProdSnapshot
{
    public static function build(int $pid): array
    {
        $Proj = new \Project($pid, true);
        $status = (int)$Proj->project['status'];
        $draftMode = (int)$Proj->project['draft_mode'];
        $useDraft = $status > 0 && $draftMode > 0;

        $xml = self::exportXml($Proj, $useDraft);
        $model = OdmDesignParser::parse($xml);

        // Drafted custom CSS lives in redcap_forms_temp (REDCap 15+)
        if ($useDraft && self::tableExists('redcap_forms_temp')) {
            $q = db_query("select form_name, custom_css from redcap_forms_temp where project_id = ?", [$pid]);
            $css = [];
            while ($row = db_fetch_assoc($q)) $css[$row['form_name']] = (string)$row['custom_css'];
            foreach ($model['forms'] as $form => &$f) $f['css'] = $css[$form] ?? '';
            unset($f);
        }

        $model['xml']          = $xml;
        $model['project_id']   = $pid;
        $model['status']       = $status;
        $model['draftMode']    = $draftMode;
        $model['useDraft']     = $useDraft;
        $model['liveForms']    = array_keys($Proj->forms);
        $model['liveFields']   = array_keys($Proj->metadata);
        $model['eventIds']     = self::eventIds($Proj);
        $model['rowIds']       = self::rowIds($pid, $Proj);
        $model['dataEvents']   = self::eventsWithData($pid);
        $model['dataEventForms'] = self::eventFormsWithData($pid, $Proj);
        return $model;
    }

    /** The project's Project XML (metadata only, every optional component, alerts/ASIs as-is). */
    public static function exportXml(\Project $Proj, bool $useDraft = false): string
    {
        $exportProj = $Proj;
        if ($useDraft) {
            $exportProj = clone $Proj;
            $exportProj->loadMetadataTemp();
            if (!empty($exportProj->metadata_temp)) {
                $exportProj->metadata = $exportProj->metadata_temp;
                $exportProj->forms = $exportProj->forms_temp;
            }
        }
        return \ODM::getOdmOpeningTag($Proj->project['app_title'])
             . \ODM::getOdmMetadata($exportProj, false, false, 'alertsenable,asienable', true)
             . \ODM::getOdmClosingTag();
    }

    /** unique event name => event_id */
    public static function eventIds(\Project $Proj): array
    {
        $ids = [];
        foreach ($Proj->getUniqueEventNames() as $eventId => $uen) $ids[$uen] = (int)$eventId;
        return $ids;
    }

    /**
     * [table => [naturalKey => primary key]] for every component table the module can update.
     * Rows are translated (event_id -> unique event name, survey_id -> form) exactly as the
     * XML export does so the natural keys match those computed from the XML.
     */
    public static function rowIds(int $pid, \Project $Proj): array
    {
        $uen = $Proj->getUniqueEventNames();
        if (!is_array($uen)) $uen = [];
        $surveyForm = [];
        foreach ($Proj->surveys as $sid => $s) $surveyForm[$sid] = $s['form_name'];
        $ids = [];
        foreach (DesignDiff::tableDefs() as $table => $def) {
            if (empty($def['pk']) || !self::tableExists($table)) continue;
            $pk = $def['pk'];
            if (in_array($table, ['redcap_surveys_scheduler', 'redcap_surveys_queue'], true)) {
                $sql = "select t.* from $table t join redcap_surveys s on s.survey_id = t.survey_id where s.project_id = ?";
            } else {
                $sql = "select * from $table where project_id = ?";
            }
            $q = db_query($sql, [$pid]);
            while ($row = db_fetch_assoc($q)) {
                foreach (['event_id', 'condition_surveycomplete_event_id', 'form_name_event'] as $c) {
                    if (isset($row[$c]) && $row[$c] !== '' && isset($uen[$row[$c]])) $row[$c] = $uen[$row[$c]];
                }
                foreach (['survey_id', 'condition_surveycomplete_survey_id'] as $c) {
                    if (isset($row[$c]) && isset($surveyForm[$row[$c]])) $row[$c] = $surveyForm[$row[$c]];
                }
                if ($table === 'redcap_surveys_queue' && !$Proj->longitudinal) $row['event_id'] = $uen[$Proj->firstEventId] ?? $row['event_id'];
                $key = DesignDiff::rowKey($table, $row);
                if ($key === null) continue;
                // Duplicate natural keys cannot be matched safely
                $ids[$table][$key] = isset($ids[$table][$key]) ? false : (int)$row[$pk];
            }
        }
        return $ids;
    }

    /** event_id => true for events that hold any data */
    public static function eventsWithData(int $pid): array
    {
        $table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($pid) : 'redcap_data';
        $q = db_query("select distinct event_id from $table where project_id = ?", [$pid]);
        $out = [];
        while ($row = db_fetch_assoc($q)) $out[(int)$row['event_id']] = true;
        return $out;
    }

    /** "event_id:form" => true for instruments holding data on an event */
    public static function eventFormsWithData(int $pid, \Project $Proj): array
    {
        $table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($pid) : 'redcap_data';
        $q = db_query("select distinct event_id, field_name from $table where project_id = ?", [$pid]);
        $out = [];
        while ($row = db_fetch_assoc($q)) {
            $form = $Proj->metadata[$row['field_name']]['form_name'] ?? null;
            if ($form !== null && $row['field_name'] !== $Proj->table_pk) $out[$row['event_id'] . ':' . $form] = true;
        }
        return $out;
    }

    public static function tableExists(string $table): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cols = getTableColumns($table);
            $cache[$table] = is_array($cols) && !empty($cols);
        }
        return $cache[$table];
    }
}
