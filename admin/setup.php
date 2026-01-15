<?php
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/../class/woobanksync.class.php';

$langs->loadLangs(array('admin', 'banks', 'ecm', 'woobanksync@woobanksync'));
if (!$user->admin) accessforbidden();

if (!function_exists('wbs_set_const_safe')) {
    function wbs_set_const_safe($db, $name, $value, $type = 'chaine', $visible = 0, $note = '', $entity = 1)
    {
        global $conf;

        if (function_exists('dolibarr_set_const')) {
            $result = dolibarr_set_const($db, $name, $value, $type, $visible, $note, $entity);
        } else {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "const WHERE name='" . $db->escape($name) . "' AND entity=" . ((int) $entity);
            $resql = $db->query($sql);
            if ($resql && ($obj = $db->fetch_object($resql))) {
                $sql = "UPDATE " . MAIN_DB_PREFIX . "const SET value='" . $db->escape((string) $value) . "', type='" . $db->escape($type) . "', visible=" . ((int) $visible) . ", note='" . $db->escape((string) $note) . "' WHERE rowid=" . ((int) $obj->rowid);
            } else {
                $sql = "INSERT INTO " . MAIN_DB_PREFIX . "const (name, entity, value, type, visible, note) VALUES ('" . $db->escape($name) . "', " . ((int) $entity) . ", '" . $db->escape((string) $value) . "', '" . $db->escape($type) . "', " . ((int) $visible) . ", '" . $db->escape((string) $note) . "')";
            }
            $result = $db->query($sql) ? 1 : -1;
        }

        if ($result > 0) {
            if (!isset($conf->global)) $conf->global = new stdClass();
            $conf->global->$name = $value;
        }
        return $result;
    }
}

$action = GETPOST('action', 'aZ09');
$sync = new WooBankSync($db, $conf, $langs);

function wbs_bank_select($name, $selected)
{
    global $db, $conf;
    $html = '<select class="flat minwidth300" name="' . dol_escape_htmltag($name) . '">';
    $html .= '<option value="">-- not mapped --</option>';
    $sql = 'SELECT rowid, ref, label FROM ' . MAIN_DB_PREFIX . 'bank_account WHERE entity IN (0,' . ((int) $conf->entity) . ') AND clos=0 ORDER BY label ASC';
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $value = (string) $obj->rowid;
            $label = trim($obj->ref . ' - ' . $obj->label);
            $html .= '<option value="' . dol_escape_htmltag($value) . '"' . ($value === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($label) . '</option>';
        }
    }
    $html .= '</select>';
    return $html;
}

function wbs_meta_select($name, $keys, $selected, $placeholder)
{
    $html = '<select class="flat minwidth300" name="' . dol_escape_htmltag($name) . '">';
    $html .= '<option value="">' . dol_escape_htmltag($placeholder) . '</option>';
    foreach ((array) $keys as $key) {
        $html .= '<option value="' . dol_escape_htmltag($key) . '"' . ((string) $key === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($key) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function wbs_ecm_folder_select($name, $selected)
{
    global $db, $conf;
    $html = '<select class="flat minwidth300" name="' . dol_escape_htmltag($name) . '">';
    $html .= '<option value="">-- no folder --</option>';
    $sql = 'SELECT rowid, label FROM ' . MAIN_DB_PREFIX . 'ecm_directories WHERE entity IN (0,' . ((int) $conf->entity) . ') ORDER BY label ASC';
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $value = (string) $obj->rowid;
            $html .= '<option value="' . dol_escape_htmltag($value) . '"' . ($value === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($obj->label) . '</option>';
        }
    }
    $html .= '</select>';
    return $html;
}

if ($action === 'save_api') {
    $keys = array('WBS_WOO_URL', 'WBS_WOO_CONSUMER_KEY', 'WBS_WOO_CONSUMER_SECRET', 'WBS_SYNC_FROM_DATE', 'WBS_ORDER_STATUSES', 'WBS_MANUAL_SYNC_PAGES', 'WBS_MANUAL_SYNC_PER_PAGE');
    foreach ($keys as $key) wbs_set_const_safe($db, $key, GETPOST($key, 'restricthtml'), 'chaine', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_DRY_RUN', GETPOST('WBS_DRY_RUN', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    setEventMessages('API settings saved.', null, 'mesgs');
}

if ($action === 'refresh') {
    list($ok, $msg) = $sync->refreshWooDiscovery();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'autosetup') {
    list($ok, $msg) = $sync->autoCreateAndMapAccounts();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'save_map') {
    $sync->saveGatewayMapFromPost();
    setEventMessages('Payment method mapping saved.', null, 'mesgs');
}

if ($action === 'save_docs') {
    wbs_set_const_safe($db, 'WBS_DOCUMENT_SYNC_ENABLED', GETPOST('WBS_DOCUMENT_SYNC_ENABLED', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_DOCUMENT_FOLDER_ID', GETPOST('WBS_DOCUMENT_FOLDER_ID', 'int'), 'chaine', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_BANK_EXTRAFIELD_ENABLED', GETPOST('WBS_BANK_EXTRAFIELD_ENABLED', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_BANK_EXTRAFIELD_CODE', GETPOST('WBS_BANK_EXTRAFIELD_CODE', 'aZ09'), 'chaine', 0, '', $conf->entity);
    setEventMessages('Document settings saved.', null, 'mesgs');
}

if ($action === 'create_invoice_extrafield') {
    list($ok, $msg) = $sync->createAndMapInvoiceBankExtraField();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'createdocs') {
    list($ok, $msg) = $sync->createDocumentFolder();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'dbcheck') {
    list($ok, $msg) = $sync->runDatabaseChecks();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'desync') {
    if (GETPOST('confirm_desync', 'alpha') !== 'yes') {
        setEventMessages('Desync was not confirmed.', null, 'errors');
    } else {
        list($ok, $msg) = $sync->desyncAllSyncedEntries();
        setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
    }
}
