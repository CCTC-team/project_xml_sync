<?php

namespace CCTC\ProjectXmlSyncModule;

/**
 * Side-effect-free reader for a REDCap Project XML (CDISC ODM with redcap: extensions).
 *
 * Core's ODM::parseOdm() parses AND commits in one pass (deleting arms/events and inserting
 * duplicates on an existing project), so it cannot be used here. This class follows the same
 * tag handling but only returns arrays; nothing is written to the database or edoc storage.
 *
 * Both the uploaded XML and the target project's own XML go through this parser so that the
 * diff compares identical shapes.
 */
class OdmDesignParser
{
    // Field types that carry a choice list in the data dictionary
    const ChoiceTypes = ['radio', 'dropdown', 'checkbox', 'sql'];

    // Project attributes present in the XML that are informational only (not design)
    const IgnoredProjectAttrs = ['purpose', 'purpose_other', 'project_note'];

    /**
     * @return array{
     *   recordIdField:string, longitudinal:bool, projectAttrs:array, arms:array, events:array,
     *   mapping:array, forms:array, fields:array, fieldExtra:array, repeating:array,
     *   vendor:array, groupsPresent:array, attachments:array, hasClinicalData:bool, title:string
     * }
     * @throws \Exception when the file is not a REDCap Project XML with metadata
     */
    public static function parse(string $xml): array
    {
        $xml = self::prepare($xml);
        if ($xml === '') throw new \Exception('The file is empty.');

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // LIBXML_PARSEHUGE: base64 attachments can make very long text nodes
        $ok = $doc->loadXML($xml, LIBXML_PARSEHUGE | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->documentElement === null || $doc->documentElement->localName !== 'ODM') {
            throw new \Exception('The file is not a valid CDISC ODM / REDCap Project XML file.');
        }

        $study = self::firstChild($doc->documentElement, 'Study');
        $globals = $study ? self::firstChild($study, 'GlobalVariables') : null;
        $mdv = $study ? self::firstChild($study, 'MetaDataVersion') : null;
        if ($globals === null || $mdv === null) {
            throw new \Exception('The XML has no project metadata (Study / GlobalVariables / MetaDataVersion). Export the project with "Download metadata only (XML)" or "XML project file".');
        }

        $out = [
            'title'          => self::text(self::firstChild($globals, 'StudyName')),
            'recordIdField'  => $mdv->getAttribute('redcap:RecordIdField'),
            'longitudinal'   => false,
            'projectAttrs'   => [],
            'arms'           => [],
            'events'         => [],
            'mapping'        => [],
            'forms'          => [],
            'fields'         => [],
            'fieldExtra'     => [],
            'repeating'      => [],
            'vendor'         => [],
            'groupsPresent'  => [],
            'attachments'    => [],
            'hasClinicalData'=> $doc->getElementsByTagName('ClinicalData')->length > 0,
        ];

        self::parseGlobals($globals, $out);
        self::parseEvents($mdv, $out);
        self::parseFormsAndFields($mdv, $out);

        return $out;
    }

    /**
     * Same newline handling as ODM::parseOdm(): line breaks inside attribute values would be
     * normalised to spaces by any XML parser, so encode them before parsing.
     */
    private static function prepare(string $xml): string
    {
        // Strip a UTF-8 BOM if present
        if (substr($xml, 0, 3) === "\xEF\xBB\xBF") $xml = substr($xml, 3);
        $xml = trim($xml);
        $xml = str_replace("\t", "", $xml);
        $xml = str_replace("\r", "\n", $xml);
        $xml = str_replace("\n\n", "\n", $xml);
        $xml = str_replace("\n", "&#10;", $xml);
        $xml = str_replace(">&#10;<", ">\n<", $xml);
        // The XML declaration may now contain &#10; between attributes; tidy it
        return preg_replace('/^<\?xml([^>]*)\?>/', '<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }

    private static function parseGlobals(\DOMElement $globals, array &$out): void
    {
        $attrMap = \ODM::getProjectAttrMappings(true, false, true, true, true, true, true);

        foreach (self::childElements($globals) as $el) {
            $tag = $el->nodeName;

            // Project-level attribute (redcap_projects column)
            if (isset($attrMap[$tag])) {
                $col = $attrMap[$tag];
                if (in_array($col, self::IgnoredProjectAttrs, true)) continue;
                if ($col === 'protected_email_mode_logo') {
                    $content = self::text($el);
                    $out['projectAttrs'][$col] = $content === '' ? '' : 'file:' . sha1($content) . ':' . $el->getAttribute('DocName');
                    if ($content !== '') {
                        $out['attachments']['__protected_email_logo'] = [
                            'DocName' => $el->getAttribute('DocName'), 'MimeType' => $el->getAttribute('MimeType'), 'Content' => $content,
                        ];
                    }
                } else {
                    $out['projectAttrs'][$col] = self::text($el);
                }
                continue;
            }

            if ($tag === 'redcap:RepeatingInstrumentsAndEvents') {
                foreach ($el->getElementsByTagName('*') as $r) {
                    $uen = $r->getAttribute('redcap:UniqueEventName');
                    if ($uen === '') continue;
                    if ($r->nodeName === 'redcap:RepeatingEvent') {
                        $out['repeating'][$uen] = 'WHOLE';
                    } elseif ($r->nodeName === 'redcap:RepeatingInstrument' && $r->hasAttribute('redcap:RepeatInstrument')) {
                        if (!isset($out['repeating'][$uen]) || !is_array($out['repeating'][$uen])) $out['repeating'][$uen] = [];
                        $out['repeating'][$uen][$r->getAttribute('redcap:RepeatInstrument')] = $r->getAttribute('redcap:CustomLabel');
                    }
                }
                continue;
            }

            // Component groups: <redcap:XxxGroup><redcap:Xxx a=".." .../></redcap:XxxGroup>
            if (strpos($tag, 'redcap:') === 0 && substr($tag, -5) === 'Group') {
                $inner = substr($tag, 7, -5);
                if ($inner === 'OdmAttachment') {
                    foreach (self::childElements($el) as $att) {
                        $out['attachments'][$att->getAttribute('ID')] = [
                            'DocName' => $att->getAttribute('DocName'),
                            'MimeType' => $att->getAttribute('MimeType'),
                            'Content' => self::text($att),
                        ];
                    }
                    continue;
                }
                $table = 'redcap_' . fromCamelCase($inner);
                $out['groupsPresent'][$table] = true;
                foreach (self::childElements($el) as $row) {
                    $attrs = [];
                    foreach ($row->attributes as $a) $attrs[$a->nodeName] = $a->nodeValue;
                    if ($table === 'redcap_multilanguage_settings' && isset($attrs['settings'])) {
                        $attrs = ['settings_hash' => self::mlmHash($attrs['settings'])];
                    }
                    if (!empty($attrs)) $out['vendor'][$table][] = $attrs;
                }
            }
        }
    }

    /**
     * Multi-language settings are one base64 serialized blob; compare by hash, leaving out values
     * that differ between servers (project id, status, version, alert/ASI id keyed maps).
     */
    private static function mlmHash(string $settings): string
    {
        $mlm = @unserialize(base64_decode($settings), ['allowed_classes' => false]);
        if (!is_array($mlm)) return sha1($settings);
        unset($mlm['version'], $mlm['projectId'], $mlm['status'], $mlm['alertSources'], $mlm['asiSources'], $mlm['excludedAlerts']);
        ksort($mlm);
        return sha1(json_encode($mlm));
    }

    private static function parseEvents(\DOMElement $mdv, array &$out): void
    {
        $protocol = self::firstChild($mdv, 'Protocol');
        $refs = $protocol ? self::childElements($protocol, 'StudyEventRef') : [];
        $out['longitudinal'] = count($refs) > 1;

        foreach (self::childElements($mdv, 'StudyEventDef') as $ev) {
            $uen = $ev->getAttribute('redcap:UniqueEventName');
            if ($uen === '') $uen = \ODM::cleanVarName($ev->getAttribute('OID'));
            $armNum = $ev->getAttribute('redcap:ArmNum') ?: '1';
            $out['events'][$uen] = [
                'event_name'         => $ev->hasAttribute('redcap:EventName') ? $ev->getAttribute('redcap:EventName') : $ev->getAttribute('Name'),
                'arm_num'            => $armNum,
                'day_offset'         => $ev->getAttribute('redcap:DayOffset'),
                'offset_min'         => $ev->getAttribute('redcap:OffsetMin'),
                'offset_max'         => $ev->getAttribute('redcap:OffsetMax'),
                'custom_event_label' => $ev->getAttribute('redcap:CustomEventLabel'),
            ];
            $out['arms'][$armNum] = $ev->hasAttribute('redcap:ArmName') ? $ev->getAttribute('redcap:ArmName') : "Arm $armNum";
            $out['mapping'][$uen] = [];
            foreach (self::childElements($ev, 'FormRef') as $fr) {
                $form = $fr->getAttribute('redcap:FormName');
                if ($form === '') $form = \ODM::cleanVarName(preg_replace('/^Form\./', '', $fr->getAttribute('FormOID')));
                $out['mapping'][$uen][] = $form;
            }
        }
        // A single StudyEventDef means a classic project
        if (count($out['events']) <= 1 && !$out['longitudinal']) {
            $out['events'] = $out['arms'] = $out['mapping'] = [];
        }
        ksort($out['arms']);
    }

    private static function parseFormsAndFields(\DOMElement $mdv, array &$out): void
    {
        $headers = array_values(\MetaData::getDataDictionaryHeaders());
        $blank = array_fill_keys($headers, '');

        // Forms in order, and the ItemGroups each one contains
        $groupForm = [];
        foreach (self::childElements($mdv, 'FormDef') as $fd) {
            $form = $fd->getAttribute('redcap:FormName');
            if ($form === '') $form = \ODM::cleanVarName(preg_replace('/^Form\./', '', $fd->getAttribute('OID')));
            $out['forms'][$form] = [
                'label' => $fd->getAttribute('Name'),
                'css'   => $fd->hasAttribute('redcap:CustomCSS') ? $fd->getAttribute('redcap:CustomCSS') : '',
            ];
            foreach (self::childElements($fd, 'ItemGroupRef') as $igr) {
                $groupForm[$igr->getAttribute('ItemGroupOID')] = $form;
            }
        }

        // Field order and required flag come from ItemGroupDef/ItemRef
        $order = [];
        $mandatory = [];
        foreach (self::childElements($mdv, 'ItemGroupDef') as $igd) {
            $form = $groupForm[$igd->getAttribute('OID')] ?? null;
            if ($form === null) continue;
            foreach (self::childElements($igd, 'ItemRef') as $ir) {
                $field = $ir->getAttribute('redcap:Variable');
                if ($field === '') $field = \ODM::cleanVarName($ir->getAttribute('ItemOID'));
                if ($field === $form . '_complete' || isset($order[$field])) continue;
                $order[$field] = $form;
                $mandatory[$field] = strtolower($ir->getAttribute('Mandatory')) === 'yes';
            }
        }

        // Code lists by OID
        $codeLists = [];
        foreach (self::childElements($mdv, 'CodeList') as $cl) {
            $codeLists[$cl->getAttribute('OID')] = $cl;
        }

        // ItemDefs (checkboxes have one per choice; the first one carries the field attributes)
        $itemDefs = [];
        foreach (self::childElements($mdv, 'ItemDef') as $id) {
            $field = $id->getAttribute('redcap:Variable');
            if ($field === '') $field = \ODM::cleanVarName($id->getAttribute('OID'));
            if (!isset($itemDefs[$field])) $itemDefs[$field] = $id;
        }

        $reserved = array_keys(\Project::$reserved_field_names ?? []);
        foreach ($order as $field => $form) {
            if (!isset($itemDefs[$field])) continue;
            $id = $itemDefs[$field];
            // Exports that include data add pseudo-fields (DAG, survey identifier, survey timestamps)
            if (in_array($field, $reserved, true)) continue;
            if (!$id->hasAttribute('redcap:FieldType') && substr($field, -10) === '_timestamp') continue;
            $row = $blank;
            $row['field_name'] = $field;
            $row['form_name'] = $form;
            $type = $id->getAttribute('redcap:FieldType');
            if ($type === '') $type = \ODM::convertOdmToRedcapFieldType($id->getAttribute('DataType'))['field_type'] ?? 'text';
            $row['field_type'] = $type;
            $row['text_validation_type_or_show_slider_number'] = $id->getAttribute('redcap:TextValidationType');
            $row['section_header'] = $id->getAttribute('redcap:SectionHeader');
            $row['field_note'] = $id->getAttribute('redcap:FieldNote');
            $row['identifier'] = $id->getAttribute('redcap:Identifier');
            $row['branching_logic'] = $id->getAttribute('redcap:BranchingLogic');
            $row['required_field'] = $id->hasAttribute('redcap:RequiredField')
                ? $id->getAttribute('redcap:RequiredField') : ($mandatory[$field] ? 'y' : '');
            $row['custom_alignment'] = $id->getAttribute('redcap:CustomAlignment');
            $row['question_number'] = $id->getAttribute('redcap:QuestionNumber');
            $row['matrix_group_name'] = $id->getAttribute('redcap:MatrixGroupName');
            $row['matrix_ranking'] = $id->getAttribute('redcap:MatrixRanking');
            $row['field_annotation'] = $id->getAttribute('redcap:FieldAnnotation');

            // Label: formatted (HTML) version wins over plain text
            $question = self::firstChild($id, 'Question');
            if ($question) {
                $fmt = self::firstChild($question, 'redcap:FormattedTranslatedText');
                $row['field_label'] = $fmt ? self::text($fmt) : self::text(self::firstChild($question, 'TranslatedText'));
            }

            // Choices / calculation / slider labels / ontology
            if ($type === 'calc') {
                $row['select_choices_or_calculations'] = $id->getAttribute('redcap:Calculation');
            } elseif ($type === 'slider') {
                $row['select_choices_or_calculations'] = $id->getAttribute('redcap:SliderLabels');
            } elseif ($id->hasAttribute('redcap:OntologySearch')) {
                $row['select_choices_or_calculations'] = $id->getAttribute('redcap:OntologySearch');
            } else {
                $ref = self::firstChild($id, 'CodeListRef');
                $cl = $ref ? ($codeLists[$ref->getAttribute('CodeListOID')] ?? null) : null;
                if ($cl !== null && !in_array($type, ['yesno', 'truefalse'], true)) {
                    $row['select_choices_or_calculations'] = $cl->hasAttribute('redcap:CheckboxChoices')
                        ? $cl->getAttribute('redcap:CheckboxChoices')
                        : self::codeListChoices($cl);
                }
            }

            // Validation min/max
            foreach (self::childElements($id, 'RangeCheck') as $rc) {
                $cmp = $rc->getAttribute('Comparator');
                $val = self::text(self::firstChild($rc, 'CheckValue'));
                if ($cmp === 'GE' || $cmp === 'GT') $row['text_validation_min'] = $val;
                if ($cmp === 'LE' || $cmp === 'LT') $row['text_validation_max'] = $val;
            }

            $out['fields'][$field] = self::normaliseFieldRow($row);

            // Attributes that are not in the data dictionary
            $extra = [
                'stop_actions'         => $id->getAttribute('redcap:StopActions'),
                'video_url'            => $id->getAttribute('redcap:VideoUrl'),
                'video_display_inline' => $id->getAttribute('redcap:VideoDisplayInline'),
                'edoc_display_img'     => $id->getAttribute('redcap:InlineImage'),
                'attachment'           => '',
            ];
            $att = self::firstChild($id, 'redcap:Attachment');
            if ($att) {
                $content = self::text($att);
                $extra['attachment'] = 'file:' . sha1($content) . ':' . $att->getAttribute('DocName');
                $out['attachments']['__field_' . $field] = [
                    'DocName' => $att->getAttribute('DocName'), 'MimeType' => $att->getAttribute('MimeType'), 'Content' => $content,
                ];
            }
            if (implode('', $extra) !== '') $out['fieldExtra'][$field] = $extra;
        }
    }

    /** "code, label | code, label" from CodeListItems (HTML-formatted labels win). */
    private static function codeListChoices(\DOMElement $cl): string
    {
        $choices = [];
        foreach (self::childElements($cl, 'CodeListItem') as $item) {
            $decode = self::firstChild($item, 'Decode');
            $label = '';
            if ($decode) {
                $fmt = self::firstChild($decode, 'redcap:FormattedTranslatedText');
                $label = $fmt ? self::text($fmt) : str_replace(["\r\n", "\n"], ["\n", ""], nl2br(self::text(self::firstChild($decode, 'TranslatedText'))));
            }
            $choices[] = $item->getAttribute('CodedValue') . ', ' . $label;
        }
        return implode(' | ', $choices);
    }

    /**
     * Convert back-end values exported in the XML to the front-end values used by the data
     * dictionary (the same conversions MetaData::getDataDictionary() applies).
     */
    public static function normaliseFieldRow(array $row): array
    {
        $typeMap = ['select' => 'dropdown', 'textarea' => 'notes'];
        if (isset($typeMap[$row['field_type']])) $row['field_type'] = $typeMap[$row['field_type']];
        $val = $row['text_validation_type_or_show_slider_number'];
        if (in_array($val, ['date', 'datetime', 'datetime_seconds'], true)) $val .= '_ymd';
        elseif ($val === 'int') $val = 'integer';
        elseif ($val === 'float') $val = 'number';
        $row['text_validation_type_or_show_slider_number'] = $val;
        foreach (['identifier', 'required_field', 'matrix_ranking'] as $flag) {
            $v = strtolower(trim((string)$row[$flag]));
            $row[$flag] = ($v === 'y' || $v === '1') ? 'y' : '';
        }
        if (in_array($row['field_type'], self::ChoiceTypes, true)) {
            $row['select_choices_or_calculations'] = self::canonicalChoices($row['select_choices_or_calculations']);
        }
        if ($row['field_type'] === 'slider' && $row['custom_alignment'] === '') $row['custom_alignment'] = 'RV';
        // Excel guard added by getDataDictionary() for annotations starting with @
        $row['field_annotation'] = ltrim((string)$row['field_annotation'], ' ');
        foreach ($row as $k => $v) $row[$k] = str_replace("\r\n", "\n", (string)$v);
        return $row;
    }

    /** Normalise a choice list to "code, label | code, label" with consistent spacing. */
    public static function canonicalChoices(string $choices): string
    {
        $choices = str_replace("\\n", '|', $choices);
        $parts = [];
        foreach (explode('|', $choices) as $c) {
            $c = trim($c);
            if ($c === '') continue;
            $pos = strpos($c, ',');
            $parts[] = $pos === false ? $c : trim(substr($c, 0, $pos)) . ', ' . trim(substr($c, $pos + 1));
        }
        return implode(' | ', $parts);
    }

    // ---- DOM helpers --------------------------------------------------------------------

    /** @return \DOMElement[] */
    private static function childElements(\DOMElement $parent, ?string $name = null): array
    {
        $list = [];
        foreach ($parent->childNodes as $n) {
            if ($n instanceof \DOMElement && ($name === null || $n->nodeName === $name)) $list[] = $n;
        }
        return $list;
    }

    private static function firstChild(\DOMElement $parent, string $name): ?\DOMElement
    {
        foreach ($parent->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->nodeName === $name) return $n;
        }
        return null;
    }

    private static function text(?\DOMElement $el): string
    {
        return $el === null ? '' : (string)$el->textContent;
    }
}
