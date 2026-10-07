<?php

namespace CCTC\ProjectXmlSyncModule;

use ExternalModules\AbstractExternalModule;

require_once __DIR__ . '/classes/OdmDesignParser.php';
require_once __DIR__ . '/classes/ProdSnapshot.php';
require_once __DIR__ . '/classes/DesignDiff.php';
require_once __DIR__ . '/classes/Applier.php';

class ProjectXmlSyncModule extends AbstractExternalModule {

    // Project setting (not in config.json) holding the last uploaded XML: doc_id, name, sha1, user, time
    const UploadSettingKey = 'last-upload';
    // Project setting holding the history of applied runs (newest first)
    const RunsSettingKey = 'run-history';
    const MaxRunsKept = 50;

    public function __construct()
    {
        parent::__construct();
        // The upload and run history are stored as project settings by users with Design rights,
        // who usually lack module-configuration rights. Access is checked by userCanUse() instead.
        $this->disableUserBasedSettingPermissions();
    }

    /**
     * Show the link only to users who may use the page.
     */
    public function redcap_module_link_check_display($project_id, $link)
    {
        return $this->userCanUse() ? $link : 0;
    }

    /**
     * True for administrators, and (unless restricted) for users with Project Design & Setup rights.
     * Uses getUser() so impersonation is honoured.
     */
    public function userCanUse(): bool
    {
        if ($this->isRealSuperUser()) return true;
        if ($this->getProjectSetting('restrict-to-super-users')) return false;
        $user = $this->getUser();
        if ($user === null) return false;
        $rights = $user->getRights();
        return !empty($rights['design']);
    }

    /** Super user who is not currently impersonating another user. */
    public function isRealSuperUser(): bool
    {
        return \UserRights::isSuperUserNotImpersonator();
    }

    /**
     * Store an uploaded XML file as an edoc and remember it for this project.
     * Returns the stored upload info.
     */
    public function storeUpload(string $tmpPath, string $originalName): array
    {
        // saveFile() names the edoc after the temp file, so give it the original name first
        $named = APP_PATH_TEMP . date('YmdHis') . '_' . substr(sha1(random_bytes(8)), 0, 6) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
        if (!copy($tmpPath, $named)) throw new \Exception('Could not read the uploaded file');
        $docId = $this->saveFile($named, $this->getProjectId());
        @unlink($named);
        $info = [
            'doc_id' => (int)$docId,
            'name'   => basename($originalName),
            'sha1'   => sha1_file($tmpPath),
            'user'   => $this->getUser()->getUsername(),
            'time'   => NOW,
        ];
        $this->setProjectSetting(self::UploadSettingKey, json_encode($info));
        return $info;
    }

    public function getUploadInfo(): ?array
    {
        $raw = $this->getProjectSetting(self::UploadSettingKey);
        $info = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($info) && !empty($info['doc_id']) ? $info : null;
    }

    public function clearUpload(): void
    {
        $this->removeProjectSetting(self::UploadSettingKey);
    }

    /** Contents of the stored XML, or null if it is missing. */
    public function getUploadXml(?array $info = null): ?string
    {
        $info = $info ?? $this->getUploadInfo();
        if ($info === null) return null;
        [, , $contents] = \Files::getEdocContentsAttributes($info['doc_id']);
        return ($contents === false || $contents === null) ? null : $contents;
    }

    /** Store the current project's own XML as an edoc before changes are applied; returns doc_id. */
    public function storeBackup(string $xml): int
    {
        $path = APP_PATH_TEMP . date('YmdHis') . '_pid' . $this->getProjectId() . '_before_xml_sync.REDCap.xml';
        file_put_contents($path, $xml);
        $docId = $this->saveFile($path, $this->getProjectId());
        @unlink($path);
        return (int)$docId;
    }

    /**
     * Parse the stored XML, snapshot this project and diff them.
     * @return array{src:array, tgt:array, items:array, warnings:array}
     * @throws \Exception when no XML is stored or it cannot be parsed
     */
    public function buildDiff(): array
    {
        $xml = $this->getUploadXml();
        if ($xml === null) throw new \Exception('The uploaded XML file could not be found. Please upload it again.');
        $src = OdmDesignParser::parse($xml);
        $tgt = ProdSnapshot::build((int)$this->getProjectId());
        $user = $this->getUser();
        $ctx = [
            'superUser'         => $this->isRealSuperUser(),
            'rights'            => $user ? (array)$user->getRights() : [],
            'editProdEvents'    => $this->configFlag('enable_edit_prod_events'),
            'editProdRepeating' => $this->configFlag('enable_edit_prod_repeating_setup'),
        ];
        $diff = DesignDiff::compute($src, $tgt, $ctx);
        return ['src' => $src, 'tgt' => $tgt, 'items' => $diff['items'], 'warnings' => $diff['warnings']];
    }

    /** A REDCap system configuration flag (redcap_config). */
    private function configFlag(string $name): bool
    {
        if (isset($GLOBALS[$name])) return (string)$GLOBALS[$name] === '1';
        $q = db_query("select value from redcap_config where field_name = ?", [$name]);
        return db_num_rows($q) > 0 && (string)db_result($q, 0) === '1';
    }

    public function getRuns(): array
    {
        $raw = $this->getProjectSetting(self::RunsSettingKey);
        $runs = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($runs) ? $runs : [];
    }

    public function addRun(array $run): void
    {
        $runs = $this->getRuns();
        array_unshift($runs, $run);
        $this->setProjectSetting(self::RunsSettingKey, json_encode(array_slice($runs, 0, self::MaxRunsKept)));
    }

    /** True if the edoc belongs to a run or upload of this project (guards the download page). */
    public function isKnownDoc(int $docId): bool
    {
        $info = $this->getUploadInfo();
        if ($info && (int)$info['doc_id'] === $docId) return true;
        foreach ($this->getRuns() as $run) {
            if ((int)($run['backup_doc_id'] ?? 0) === $docId || (int)($run['xml_doc_id'] ?? 0) === $docId) return true;
        }
        return false;
    }

    public function redcap_module_save_configuration($project_id): void
    {
        $this->auditConfigurationChange($project_id);
    }

    private function auditConfigurationChange($project_id): void
    {
        $config   = $this->getConfig();
        $isSystem = empty($project_id);
        $scope    = $isSystem ? 'system' : 'project';
        $keys     = $this->collectSettingKeys($config[$scope . '-settings'] ?? []);
        if (empty($keys)) return;

        $snapshotKey = 'audit-snapshot-' . $scope;
        $read  = fn($k)     => $isSystem ? $this->getSystemSetting($k)    : $this->getProjectSetting($k);
        $write = fn($k, $v) => $isSystem ? $this->setSystemSetting($k, $v) : $this->setProjectSetting($k, $v);

        $new = [];
        foreach ($keys as $k) $new[$k] = $this->normaliseSetting($read($k));

        $rawOld = $read($snapshotKey);
        $old = is_string($rawOld) ? json_decode($rawOld, true) : null;
        // First save has no prior snapshot: treat the baseline as empty so the
        // initial configuration's real values are still logged ((empty) -> value),
        // while settings left blank stay '' vs '' and produce no noise.
        if (!is_array($old)) $old = [];

        $changed = false;
        foreach ($keys as $k) {
            $before = $this->normaliseSetting($old[$k] ?? null);
            $after  = $new[$k];
            if ($before !== $after) {
                $changed = true;
                $this->log("Configuration changed ($scope)", [
                    'project_id' => $project_id,
                    'setting'    => $k,
                    'old_value'  => $before === '' ? '(empty)' : $before,
                    'new_value'  => $after  === '' ? '(empty)' : $after,
                ]);
            }
        }
        if ($changed) $write($snapshotKey, json_encode($new));
    }

    private function collectSettingKeys(array $settings): array
    {
        $keys = [];
        foreach ($settings as $s) {
            if (!isset($s['key'])) continue;
            if (($s['type'] ?? '') === 'descriptive') continue;
            $keys[] = $s['key'];
        }
        return $keys;
    }

    private function normaliseSetting($v): string
    {
        if ($v === null || $v === false) return '';
        if ($v === true) return '1';
        if (is_array($v)) {
            foreach ($v as $entry) {
                $hasValue = is_array($entry) ? !empty($entry) : trim((string) ($entry ?? '')) !== '';
                if ($hasValue) return json_encode($v);
            }
            return '';
        }
        return trim((string) $v);
    }
}
