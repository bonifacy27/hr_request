<?php
/**
 * list.php — список заявок на оффер (ИБ 218)
 * URL: /forms/staff_recruitment/offer/list.php
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\Context;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Заявки на оффер');

if (!Loader::includeModule('main')) { ShowError('Модуль main не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
if (!Loader::includeModule('iblock')) { ShowError('Модуль iblock не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
if (!Loader::includeModule('bizproc')) { ShowError('Модуль bizproc не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
if (!Loader::includeModule('lists')) { ShowError('Модуль lists не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
CJSCore::Init(['popup', 'ui.entity-selector', 'ui.buttons', 'ui.notification']);

const IBL_OFFERS = 218;
const PROP_CANDIDATE_FIO = 'PROPERTY_1157';
const PROP_CANDIDATE_PHONE = 'PROPERTY_1158';
const PROP_PLANNED_SEND_DATE = 'PROPERTY_1159';
const PROP_IS_CHIEF_POSITION = 'PROPERTY_1618';
const PROP_POSITION = 'PROPERTY_1161';
const PROP_DIRECTION = 'PROPERTY_1996';
const PROP_DEPARTMENT = 'PROPERTY_1163';
const PROP_CHIEF_FIO_FROM_LIST = 'PROPERTY_1164';
const PROP_CHIEF_FIO_TEXT = 'PROPERTY_1168';
const PROP_CHIEF_POSITION = 'PROPERTY_1169';
const PROP_BONUS_RUB_GROSS = 'PROPERTY_1170';
const PROP_MONTH_INCOME_AVG_GROSS = 'PROPERTY_1172';
const PROP_SALARY_NDFL = 'PROPERTY_1166';
const PROP_ISN_NDFL = 'PROPERTY_1167';
const PROP_BONUS_RUB_NDFL = 'PROPERTY_1171';
const PROP_MONTH_INCOME_AVG_NDFL = 'PROPERTY_1173';
const PROP_SALARY = 'PROPERTY_1165';
const PROP_ISN = 'PROPERTY_1184';
const PROP_BONUS_TYPE = 'PROPERTY_1998';
const PROP_BONUS_PERCENT = 'PROPERTY_1186';
const PROP_TRIAL_PERIOD = 'PROPERTY_2001';
const PROP_PLANNED_START_DATE = 'PROPERTY_1174';
const PROP_BENEFITS = 'PROPERTY_1177';
const PROP_WORK_FORMAT = 'PROPERTY_1327';
const PROP_OFFICE = 'PROPERTY_1326';
const PROP_WORK_SCHEDULE = 'PROPERTY_1328';
const PROP_WORK_START = 'PROPERTY_1329';
const PROP_EQUIPMENT = 'PROPERTY_2070';
const PROP_EQUIPMENT_TEXT = 'PROPERTY_3130';
const PROP_CONTRACT_TYPE = 'PROPERTY_2002';
const PROP_ORGANIZATION = 'PROPERTY_2753';
const PROP_HOUSING_COMPENSATION = 'PROPERTY_3147';
const PROP_REGION_LOCATION = 'PROPERTY_1767';
const PROP_PERSONAL_ALLOWANCE = 'PROPERTY_1234';
const PROP_RAYON_COEFFICIENT = 'PROPERTY_1235';
const PROP_RECRUITER = 'PROPERTY_1190';
const PROP_STATUS = 'PROPERTY_1189';
const PROP_OFFER_PDF = 'PROPERTY_1222';
const PROP_REQUEST_ID = 'PROPERTY_1601';
const PROP_CANDIDATE_ID = 'PROPERTY_1603';
const PROP_FW_CANDIDATE_ID = 'PROPERTY_1602';
const PROP_COMMENT = 'PROPERTY_2857';
const CB_GLOBAL_VAR_ID = 'Variable1722502594854';
const RECRUIT_HEAD_GLOBAL_VAR_ID = 'Variable1722503621093';
const PDF_WORKFLOW_TEMPLATE_ID = 1353;
const PDF_WORKFLOW_EXTRA_USER_ID = 3532;
const PDF_ALLOWED_STATUS_ENUM_IDS = [882, 883];
const HRD_APPROVAL_STATUS_ENUM_ID = 879;
const HRD_COMMENTS_PROPERTY_ID = 3171;
const COMMENTS_ADMIN_USER_ID = 3532;
const OFFER_RIGHTS_WORKFLOW_TEMPLATE_ID = 707;
const IBL_REQUESTS = 201;
const IBL_CANDIDATES = 207;
const CANDIDATE_REQUEST_PROPERTY_ID = 1596;
const REQUEST_STATUS_PROPERTY_ID = 1042;
const REQUEST_CANCELLED_STATUS_ENUM_ID = 795;
const OFFER_CANCELLED_STATUS_ENUM_ID = 7396;
const REQUEST_REPEAT_WORKFLOW_TEMPLATE_ID = 1269;

function decodeStatusHistoryHtml(string $raw): string
{
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('/[ÐÑ]/u', $decoded)) {
        $decoded = mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
    }
    $decoded = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $decoded);
    $decoded = preg_replace('/<br\\s*\\/?>/iu', "<br>", $decoded);
    $decoded = strip_tags($decoded, '<br>');
    return trim($decoded);
}

function getStatusBadgeColor(string $status): string
{
    $map = [
        'Согласование рук. ОПП' => '#fb923c',
        'Согласование C&B' => '#fb923c',
        'Согласование рук. ОМиОР' => '#fb923c',
        'Согласование HRD' => '#fb923c',
        'Согласование рук-ля' => '#fb923c',
        'Доработка' => '#fde047',
        'Согласовано' => '#86efac',
        'Оффер сформирован' => '#60a5fa',
        'Оффер принят' => '#22c55e',
        'Оффер не принят' => '#ef4444',
        'Черновик' => '#9ca3af',
        'Отменен' => '#ef4444',
    ];
    return $map[$status] ?? '#cbd5e1';
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function displayValue($value): string
{
    if (is_array($value)) {
        $value = implode(', ', array_filter(array_map('strval', $value), static function ($part) {
            return trim($part) !== '';
        }));
    }
    $decoded = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim($decoded);
}


function formatMoneyForDisplay($value): string
{
    $normalized = str_replace(["\xc2\xa0", ' '], '', trim((string)$value));
    $normalized = str_replace(',', '.', $normalized);
    if ($normalized === '' || !is_numeric($normalized)) {
        return trim((string)$value);
    }
    $number = (float)$normalized;
    $decimals = floor($number) == $number ? 0 : 2;
    return number_format($number, $decimals, ',', ' ');
}

function getUserDisplayNameById(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $user = CUser::GetByID($userId)->Fetch();
    if (!$user) {
        return '';
    }
    $name = trim((string)CUser::FormatName(CSite::GetNameFormat(false), $user, true, false));
    return $name !== '' ? $name : (string)($user['LOGIN'] ?? $userId);
}

function getFieldValue(array $fields, string $property): string
{
    return displayValue($fields[$property . '_VALUE'] ?? '');
}

function getUrlFromHtmlField(array $fields, string $property): string
{
    $htmlValue = $fields[$property . '_VALUE'] ?? '';
    if (is_array($htmlValue)) {
        $htmlValue = $htmlValue['TEXT'] ?? reset($htmlValue);
    }

    $htmlValue = html_entity_decode((string)$htmlValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $htmlValue, $matches)) {
        return '';
    }

    $url = html_entity_decode(trim($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}


function getLinkedElementName(int $elementId): string
{
    static $cache = [];
    if ($elementId <= 0) {
        return '';
    }
    if (array_key_exists($elementId, $cache)) {
        return $cache[$elementId];
    }

    $row = CIBlockElement::GetList([], ['ID' => $elementId], false, ['nTopCount' => 1], ['ID', 'NAME'])->GetNext();
    $cache[$elementId] = $row ? displayValue($row['NAME'] ?? '') : '';
    return $cache[$elementId];
}

function loadOfferPropertyValues(int $offerId, array $propertyIds): array
{
    $values = [];
    if ($offerId <= 0 || empty($propertyIds)) {
        return $values;
    }

    $rs = CIBlockElement::GetProperty(
        IBL_OFFERS,
        $offerId,
        ['sort' => 'asc', 'id' => 'asc'],
        ['ID' => array_values(array_unique(array_map('intval', $propertyIds)))]
    );

    while ($property = $rs->Fetch()) {
        $propertyId = (int)($property['ID'] ?? 0);
        if ($propertyId <= 0) {
            continue;
        }

        $rawValue = $property['VALUE'] ?? '';
        $displayValue = $property['VALUE_ENUM'] ?? '';
        if ($propertyId === 1164 && is_numeric($rawValue)) {
            $displayValue = getUserDisplayNameById((int)$rawValue);
        }
        if ($displayValue === '' || $displayValue === null) {
            if (($property['PROPERTY_TYPE'] ?? '') === 'E' && is_numeric($rawValue)) {
                $displayValue = getLinkedElementName((int)$rawValue);
            }
        }
        if ($displayValue === '' || $displayValue === null) {
            $displayValue = $rawValue;
        }

        $preparedValue = displayValue($displayValue);
        if ($preparedValue === '') {
            continue;
        }
        if (isset($values[$propertyId]) && $values[$propertyId] !== '') {
            $values[$propertyId] .= ', ' . $preparedValue;
        } else {
            $values[$propertyId] = $preparedValue;
        }
    }

    return $values;
}

function getPropertyValue(array $properties, int $propertyId): string
{
    return (string)($properties[$propertyId] ?? '');
}

function renderOfferDetailsHtml(array $details): string
{
    $html = '';
    foreach ($details as $section) {
        $html .= '<div class="offer-detail-section">';
        $html .= '<div class="offer-detail-title">' . h($section['title']) . '</div>';
        $html .= '<div class="offer-detail-grid">';
        foreach ($section['rows'] as $row) {
            $value = trim((string)($row['value'] ?? ''));
            $html .= '<div class="offer-detail-label">' . h($row['label']) . '</div>';
            $html .= '<div class="offer-detail-value">' . h($value !== '' ? $value : '—') . '</div>';
        }
        $html .= '</div></div>';
    }
    return $html;
}

function buildUrl(array $paramsToSet = [], array $paramsToUnset = []): string
{
    $query = $_GET;
    foreach ($paramsToUnset as $k) {
        unset($query[$k]);
    }
    foreach ($paramsToSet as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return $path . (empty($query) ? '' : '?' . http_build_query($query));
}

function sortLink(string $key, string $title, string $currentSort, string $currentDir): string
{
    $dir = ($currentSort === $key && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $url = buildUrl(['sort' => $key, 'dir' => $dir, 'PAGEN_1' => 1]);
    $arrow = '';
    if ($currentSort === $key) {
        $arrow = $currentDir === 'ASC' ? ' ▲' : ' ▼';
    }
    return '<a href="' . h($url) . '">' . h($title) . $arrow . '</a>';
}

function userIdFromValue($raw): int
{
    $value = trim((string)$raw);
    if ($value === '') return 0;
    if (stripos($value, 'user_') === 0) return (int)substr($value, 5);
    if (preg_match('/(\d+)/', $value, $m)) return (int)$m[1];
    return (int)$value;
}

function formatUserName(array $u): string
{
    $tmpl = \CSite::GetNameFormat(false);
    return trim(\CUser::FormatName($tmpl, $u, true, false)) ?: ($u['LOGIN'] ?? ('user#' . (int)$u['ID']));
}

function getGlobalVarUserList(string $varId): array
{
    $users = [];
    try {
        $conn = \Bitrix\Main\Application::getConnection();
        $sqlVarId = $conn->getSqlHelper()->forSql($varId);
        $row = $conn->query("SELECT PROPERTY_VALUE FROM b_bp_global_var WHERE ID = '{$sqlVarId}' LIMIT 1")->fetch();
        if ($row && !empty($row['PROPERTY_VALUE'])) {
            $decoded = @unserialize($row['PROPERTY_VALUE'], ['allowed_classes' => false]);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $item = trim((string)$item);
                    if ($item !== '') $users[] = mb_strtolower($item);
                }
            }
        }
    } catch (\Throwable $e) {
        return [];
    }
    return array_values(array_unique($users));
}

function extractElementIdFromDocumentId($documentId, int $iblockId): int
{
    if (is_array($documentId)) {
        $candidate = $documentId[2] ?? '';
        if (is_numeric($candidate)) return (int)$candidate;
        $documentId = (string)$candidate;
    }

    $raw = trim((string)$documentId);
    if ($raw === '') return 0;

    if (preg_match('/(?:lists|iblock)_' . (int)$iblockId . '_(\d+)/', $raw, $m)) {
        return (int)$m[1];
    }
    if (is_numeric($raw)) {
        return (int)$raw;
    }
    return 0;
}

function getCurrentUserRunningTaskMapForOffers(int $userId, int $iblockId): array
{
    static $cache = [];
    $cacheKey = $iblockId . ':' . $userId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $map = [];
    if ($userId <= 0) {
        $cache[$cacheKey] = $map;
        return $map;
    }

    try {
        $rs = \CBPTaskService::GetList(
            ['ID' => 'ASC'],
            ['USER_ID' => $userId, 'STATUS' => \CBPTaskStatus::Running],
            false,
            false,
            ['ID', 'DOCUMENT_ID']
        );
        while ($t = $rs->GetNext()) {
            $taskId = (int)($t['ID'] ?? 0);
            if ($taskId <= 0) continue;

            $elementId = extractElementIdFromDocumentId($t['DOCUMENT_ID'] ?? '', $iblockId);
            if ($elementId <= 0 && !empty($t['DOCUMENT_ID'])) {
                $docRaw = is_array($t['DOCUMENT_ID']) ? json_encode($t['DOCUMENT_ID'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$t['DOCUMENT_ID'];
                if (preg_match('/(?:lists|iblock)_' . (int)$iblockId . '_(\d+)/', $docRaw, $m)) {
                    $elementId = (int)$m[1];
                }
            }

            if ($elementId > 0 && !isset($map[$elementId])) {
                $map[$elementId] = $taskId;
            }
        }
    } catch (\Throwable $e) {
    }

    $cache[$cacheKey] = $map;
    return $map;
}

function appendOfferHrdComment(int $offerId, string $message): void
{
    $oldValue = '';
    $rs = CIBlockElement::GetProperty(IBL_OFFERS, $offerId, ['sort' => 'asc'], ['ID' => HRD_COMMENTS_PROPERTY_ID]);
    while ($property = $rs->Fetch()) {
        $value = $property['VALUE'] ?? '';
        if (is_array($value) && isset($value['TEXT'])) $value = $value['TEXT'];
        if (trim((string)$value) !== '') $oldValue = trim((string)$value);
    }
    $newValue = $oldValue !== '' ? $oldValue . "\n" . $message : $message;
    CIBlockElement::SetPropertyValuesEx($offerId, IBL_OFFERS, [HRD_COMMENTS_PROPERTY_ID => $newValue]);
}

function getElementPropertyInt(int $iblockId, int $elementId, int $propertyId): int
{
    if ($elementId <= 0) return 0;
    $property = CIBlockElement::GetProperty(
        $iblockId,
        $elementId,
        ['sort' => 'asc'],
        ['ID' => $propertyId]
    )->Fetch();
    return (int)($property['VALUE'] ?? 0);
}

function getOfferRequestId(int $requestId, int $candidateId): int
{
    if ($requestId > 0) return $requestId;
    return $candidateId > 0
        ? getElementPropertyInt(IBL_CANDIDATES, $candidateId, CANDIDATE_REQUEST_PROPERTY_ID)
        : 0;
}

function terminateOfferWorkflows(int $offerId): array
{
    $errors = [];
    $documentType = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_' . IBL_OFFERS];
    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $offerId];
    foreach ((array)CBPDocument::GetDocumentStates($documentType, $documentId) as $state) {
        $workflowId = (string)($state['ID'] ?? '');
        if ($workflowId === '') continue;
        $workflowErrors = [];
        try {
            CBPDocument::TerminateWorkflow($workflowId, $documentId, $workflowErrors);
        } catch (\Throwable $e) {
            $workflowErrors[] = ['message' => $e->getMessage()];
        }
        foreach ($workflowErrors as $error) {
            $errors[] = is_array($error) ? (string)($error['message'] ?? 'Ошибка остановки бизнес-процесса.') : (string)$error;
        }
    }
    return array_values(array_filter($errors));
}

function startRequestRepeatWorkflow(int $requestId, int $recruiterId): bool
{
    if ($requestId <= 0 || $recruiterId <= 0) return false;
    $errors = [];
    try {
        $workflowId = CBPDocument::StartWorkflow(
            REQUEST_REPEAT_WORKFLOW_TEMPLATE_ID,
            ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $requestId],
            ['par_Repeat' => 'Y', 'par_Recruiter' => 'user_' . $recruiterId],
            $errors
        );
        return (string)$workflowId !== '' && empty($errors);
    } catch (\Throwable $e) {
        return false;
    }
}

function getExclusiveRecruiterTaskId(int $offerId, int $recruiterId): int
{
    if ($offerId <= 0 || $recruiterId <= 0) return 0;

    $documentType = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_' . IBL_OFFERS];
    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $offerId];
    try {
        foreach ((array)CBPDocument::GetDocumentStates($documentType, $documentId) as $state) {
            $workflowId = (string)($state['ID'] ?? '');
            if ($workflowId === '') continue;

            $taskUsers = [];
            $tasks = CBPTaskService::GetList(
                ['ID' => 'ASC'],
                ['WORKFLOW_ID' => $workflowId, 'STATUS' => CBPTaskStatus::Running],
                false,
                false,
                ['ID', 'USER_ID']
            );
            while ($task = $tasks->Fetch()) {
                $taskId = (int)($task['ID'] ?? 0);
                $userId = (int)($task['USER_ID'] ?? 0);
                if ($taskId > 0 && $userId > 0) $taskUsers[$taskId][$userId] = true;
            }
            foreach ($taskUsers as $taskId => $users) {
                if (count($users) === 1 && isset($users[$recruiterId])) return (int)$taskId;
            }
        }
    } catch (\Throwable $e) {
        return 0;
    }
    return 0;
}

function delegateOfferTask(int $taskId, int $fromUserId, int $toUserId): array
{
    if ($taskId <= 0) return [true, ''];
    try {
        CBPTaskService::delegateTask($taskId, $fromUserId, $toUserId);
        return [true, ''];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}

function startOfferRightsWorkflow(int $offerId): array
{
    $errors = [];
    try {
        $workflowId = CBPDocument::StartWorkflow(
            OFFER_RIGHTS_WORKFLOW_TEMPLATE_ID,
            ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $offerId],
            [],
            $errors
        );
        return [(string)$workflowId !== '', $errors];
    } catch (\Throwable $e) {
        return [false, [['message' => $e->getMessage()]]];
    }
}

function approveCurrentOfferTaskAsAssignee(int $offerId, string $comment): array
{
    $documentType = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_' . IBL_OFFERS];
    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $offerId];
    $states = CBPDocument::GetDocumentStates($documentType, $documentId);
    foreach ((array)$states as $state) {
        $workflowId = (string)($state['ID'] ?? '');
        if ($workflowId === '') continue;
        $tasks = CBPTaskService::GetList(
            ['ID' => 'ASC'],
            ['WORKFLOW_ID' => $workflowId, 'STATUS' => CBPTaskStatus::Running],
            false,
            false,
            ['ID', 'USER_ID', 'STATUS']
        );
        while ($task = $tasks->Fetch()) {
            $taskId = (int)($task['ID'] ?? 0);
            $assigneeId = (int)($task['USER_ID'] ?? 0);
            if ($taskId <= 0 || $assigneeId <= 0) continue;

            $actionCode = 'Approve';
            $controls = method_exists('CBPDocument', 'GetTaskControls') ? (array)CBPDocument::GetTaskControls($taskId) : [];
            foreach ($controls as $control) {
                $id = (string)($control['CONTROL_ID'] ?? $control['ID'] ?? '');
                $label = mb_strtolower((string)($control['NAME'] ?? $control['TEXT'] ?? $control['LABEL'] ?? ''));
                if (stripos($id, 'approve') !== false || strpos($label, 'соглас') !== false || strpos($label, 'утверж') !== false) {
                    $actionCode = $id !== '' ? $id : 'Approve';
                    break;
                }
            }
            $errors = [];
            $fields = [
                'approve' => $actionCode,
                $actionCode => 'Y',
                'ACTION' => $actionCode,
                'APPROVE' => 'Y',
                'status' => 'Y',
                'comment' => $comment,
                'task_comment' => $comment,
                'USER_ID' => $assigneeId,
                'REAL_USER_ID' => $assigneeId,
            ];
            global $USER;
            $previousUserId = is_object($USER) ? (int)$USER->GetID() : 0;
            try {
                if (is_object($USER) && $previousUserId !== $assigneeId) $USER->Authorize($assigneeId);
                CBPDocument::PostTaskForm($taskId, $assigneeId, $fields, $errors, '', $assigneeId);
            } finally {
                if (is_object($USER) && $previousUserId > 0 && (int)$USER->GetID() !== $previousUserId) $USER->Authorize($previousUserId);
            }
            $check = CBPTaskService::GetList([], ['ID' => $taskId], false, false, ['ID', 'STATUS'])->Fetch();
            if (empty($errors) && (!$check || (int)$check['STATUS'] !== (int)CBPTaskStatus::Running)) {
                return [true, ''];
            }
            if (!empty($errors)) return [false, implode('; ', array_map('strval', $errors))];
        }
    }
    return [false, 'Активное задание утверждения для оффера не найдено или не завершилось.'];
}

function getBizprocTaskUrl(int $taskId, ?int $userId = null): string
{
    $uid = $userId ?: (int)$GLOBALS['USER']->GetID();
    if (class_exists('\\CBPTaskService')) {
        if (method_exists('\\CBPTaskService', 'GetTaskUrl')) {
            try { return (string)\CBPTaskService::GetTaskUrl($taskId, $uid); } catch (\Throwable $e) {}
        }
        if (method_exists('\\CBPTaskService', 'GetTaskURL')) {
            try { return (string)\CBPTaskService::GetTaskURL($taskId, $uid); } catch (\Throwable $e) {}
        }
    }
    return '/company/personal/bizproc/' . $taskId . '/';
}

$request = Context::getCurrent()->getRequest();
$q = trim((string)$request->get('q'));
$fRecruiter = (int)$request->get('f_recruiter');
$fStatus = (int)$request->get('f_status');
$inWorkOnly = (string)$request->get('in_work') === 'Y';
$sort = strtoupper((string)$request->get('sort') ?: 'ID');
$dir = strtoupper((string)$request->get('dir') ?: 'DESC');
$pageSize = 20;

$sortable = ['ID', 'CANDIDATE_FIO', 'DATE_CREATE', 'STATUS'];
if (!in_array($sort, $sortable, true)) $sort = 'ID';
if (!in_array($dir, ['ASC', 'DESC'], true)) $dir = 'DESC';

$currentUserId = (int)$USER->GetID();
$currentUserTagLower = mb_strtolower('user_' . $currentUserId);
$isAdmin = $USER->IsAdmin();
$cbUsers = getGlobalVarUserList(CB_GLOBAL_VAR_ID);
$recruitHeads = getGlobalVarUserList(RECRUIT_HEAD_GLOBAL_VAR_ID);
$isCbManager = in_array($currentUserTagLower, $cbUsers, true);
$isRecruitHead = in_array($currentUserTagLower, $recruitHeads, true);
$canApproveAsHrd = $isRecruitHead || $currentUserId === COMMENTS_ADMIN_USER_ID;
$currentUserTasksMap = getCurrentUserRunningTaskMapForOffers($currentUserId, IBL_OFFERS);

if ($request->isPost() && (string)$request->getPost('action') === 'cancel_approval') {
    $offerId = (int)$request->getPost('offer_id');
    $comment = trim((string)$request->getPost('comment'));
    $requestAction = (string)$request->getPost('request_action');
    $result = 'error';

    if (!check_bitrix_sessid()) {
        $result = 'session_error';
    } elseif ($comment === '') {
        $result = 'comment_required';
    } else {
        $offer = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => IBL_OFFERS, 'ID' => $offerId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
            false,
            ['nTopCount' => 1],
            ['ID', 'PREVIEW_TEXT', PROP_RECRUITER, PROP_REQUEST_ID, PROP_CANDIDATE_ID]
        )->Fetch();
        $recruiterId = $offer ? userIdFromValue($offer[PROP_RECRUITER . '_VALUE'] ?? '') : 0;
        $canCancelApproval = $offer && ($isRecruitHead || $currentUserId === COMMENTS_ADMIN_USER_ID || $recruiterId === $currentUserId);
        $linkedRequestId = $offer ? getOfferRequestId(
            (int)($offer[PROP_REQUEST_ID . '_VALUE'] ?? 0),
            (int)($offer[PROP_CANDIDATE_ID . '_VALUE'] ?? 0)
        ) : 0;

        if (!$canCancelApproval) {
            $result = 'denied';
        } elseif ($linkedRequestId > 0 && !in_array($requestAction, ['continue', 'stop'], true)) {
            $result = 'request_action_required';
        } else {
            $workflowErrors = terminateOfferWorkflows($offerId);
            $actorName = getUserDisplayNameById($currentUserId) ?: ('Пользователь #' . $currentUserId);
            $date = date('d.m.Y H:i');
            $requestDecision = $requestAction === 'continue'
                ? 'Продолжить работу по заявке на подбор.'
                : ($requestAction === 'stop' ? 'Прервать процесс подбора.' : 'Связанная заявка на подбор отсутствует.');
            $historyLine = $date . ': ' . $actorName . ' отменил согласование оффера. ' . $requestDecision . ' Комментарий: ' . $comment;
            $history = decodeStatusHistoryHtml((string)($offer['PREVIEW_TEXT'] ?? ''));
            $element = new CIBlockElement();
            $historyUpdated = $element->Update($offerId, [
                'PREVIEW_TEXT' => ($history !== '' ? $history . "\n" : '') . $historyLine,
                'PREVIEW_TEXT_TYPE' => 'text',
            ]);
            CIBlockElement::SetPropertyValuesEx($offerId, IBL_OFFERS, [1189 => OFFER_CANCELLED_STATUS_ENUM_ID]);
            appendOfferHrdComment($offerId, $historyLine);

            $requestUpdated = true;
            if ($linkedRequestId > 0 && $requestAction === 'continue') {
                $requestUpdated = startRequestRepeatWorkflow($linkedRequestId, $recruiterId);
            } elseif ($linkedRequestId > 0 && $requestAction === 'stop') {
                CIBlockElement::SetPropertyValuesEx($linkedRequestId, IBL_REQUESTS, [REQUEST_STATUS_PROPERTY_ID => REQUEST_CANCELLED_STATUS_ENUM_ID]);
            }

            $result = $historyUpdated && $requestUpdated && empty($workflowErrors) ? 'cancelled' : 'partial_error';
        }
    }
    LocalRedirect(buildUrl(['approval_cancel' => $result], []));
}

if ($request->isPost() && (string)$request->getPost('action') === 'delegate_offer') {
    $offerId = (int)$request->getPost('offer_id');
    $toUserId = (int)$request->getPost('delegate_to_user_id');
    $comment = trim((string)$request->getPost('comment'));
    $result = 'error';

    if (!check_bitrix_sessid()) {
        $result = 'session_error';
    } elseif ($comment === '') {
        $result = 'comment_required';
    } else {
        $offer = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => IBL_OFFERS, 'ID' => $offerId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', PROP_RECRUITER, 'PREVIEW_TEXT']
        )->Fetch();
        $oldRecruiterId = $offer ? userIdFromValue($offer[PROP_RECRUITER . '_VALUE'] ?? '') : 0;
        $canDelegateOffer = $offer && ($isRecruitHead || $currentUserId === COMMENTS_ADMIN_USER_ID || $oldRecruiterId === $currentUserId);
        $targetUser = $toUserId > 0 ? CUser::GetByID($toUserId)->Fetch() : false;

        if (!$canDelegateOffer) {
            $result = 'denied';
        } elseif (!$targetUser || (string)($targetUser['ACTIVE'] ?? '') !== 'Y') {
            $result = 'invalid_user';
        } elseif ($oldRecruiterId <= 0 || $oldRecruiterId === $toUserId) {
            $result = 'same_user';
        } else {
            $taskId = getExclusiveRecruiterTaskId($offerId, $oldRecruiterId);
            [$taskDelegated, $taskError] = delegateOfferTask($taskId, $oldRecruiterId, $toUserId);
            if (!$taskDelegated) {
                $result = 'task_error';
            } else {
                $actorName = getUserDisplayNameById($currentUserId) ?: ('Пользователь #' . $currentUserId);
                $oldRecruiterName = getUserDisplayNameById($oldRecruiterId) ?: ('Пользователь #' . $oldRecruiterId);
                $newRecruiterName = getUserDisplayNameById($toUserId) ?: ('Пользователь #' . $toUserId);
                $date = date('d.m.Y H:i');
                $historyLine = $date . ': ' . $actorName . ' изменил рекрутера: ' . $oldRecruiterName . ' → ' . $newRecruiterName;
                $hrComment = $date . ' ' . $actorName . ': Оффер передан от ' . $oldRecruiterName . ' сотруднику ' . $newRecruiterName . '. Комментарий: ' . $comment;

                $history = decodeStatusHistoryHtml((string)($offer['PREVIEW_TEXT'] ?? ''));
                $element = new CIBlockElement();
                $historyUpdated = $element->Update($offerId, [
                    'PREVIEW_TEXT' => ($history !== '' ? $history . "\n" : '') . $historyLine,
                    'PREVIEW_TEXT_TYPE' => 'text',
                ]);
                CIBlockElement::SetPropertyValuesEx($offerId, IBL_OFFERS, [1190 => $toUserId]);
                appendOfferHrdComment($offerId, $hrComment);

                [$workflowStarted] = startOfferRightsWorkflow($offerId);
                if (!$historyUpdated) {
                    $result = 'history_error';
                } else {
                    $result = $workflowStarted ? 'delegated' : 'workflow_error';
                }
            }
        }
    }
    LocalRedirect(buildUrl(['offer_delegate' => $result], []));
}

if ($request->isPost() && (string)$request->getPost('action') === 'approve_as_hrd') {
    $offerId = (int)$request->getPost('offer_id');
    $approvalComment = trim((string)$request->getPost('comment'));
    $result = 'error';
    if (!check_bitrix_sessid()) {
        $result = 'session_error';
    } elseif (!$canApproveAsHrd) {
        $result = 'denied';
    } elseif ($approvalComment === '') {
        $result = 'comment_required';
    } else {
        $offer = CIBlockElement::GetList([], ['IBLOCK_ID' => IBL_OFFERS, 'ID' => $offerId, 'ACTIVE' => 'Y'], false, ['nTopCount' => 1], ['ID', PROP_STATUS])->Fetch();
        if (!$offer || (int)($offer[PROP_STATUS . '_ENUM_ID'] ?? 0) !== HRD_APPROVAL_STATUS_ENUM_ID) {
            $result = 'invalid_status';
        } else {
            [$approved, $approvalError] = approveCurrentOfferTaskAsAssignee($offerId, $approvalComment);
            if ($approved) {
                $userRow = CUser::GetByID($currentUserId)->Fetch() ?: [];
                $approverName = $userRow ? formatUserName($userRow) : ('Пользователь #' . $currentUserId);
                appendOfferHrdComment($offerId, date('d.m.Y H:i') . ' Оффер согласован ' . $approverName . ' за HRD. Комментарий: ' . $approvalComment);
                $result = 'approved';
            }
        }
    }
    LocalRedirect(buildUrl(['hrd_approval' => $result], []));
}

if ($request->isPost() && (string)$request->getPost('action') === 'generate_pdf') {
    $offerId = (int)$request->getPost('offer_id');
    $result = 'error';

    if (!check_bitrix_sessid()) {
        $result = 'session_error';
    } elseif ($offerId > 0) {
        $offer = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => IBL_OFFERS, 'ID' => $offerId, 'ACTIVE' => 'Y'],
            false,
            ['nTopCount' => 1],
            ['ID', PROP_RECRUITER, PROP_STATUS]
        )->Fetch();
        $offerRecruiterId = $offer ? userIdFromValue($offer[PROP_RECRUITER . '_VALUE'] ?? '') : 0;
        $offerStatusId = $offer ? (int)($offer[PROP_STATUS . '_ENUM_ID'] ?? 0) : 0;
        $hasPdfStatus = in_array($offerStatusId, PDF_ALLOWED_STATUS_ENUM_IDS, true);
        $hasPdfPermission = $offer && (
            $currentUserId === PDF_WORKFLOW_EXTRA_USER_ID
            || ($currentUserId > 0 && $offerRecruiterId > 0 && $currentUserId === $offerRecruiterId)
        );

        if (!$hasPdfPermission) {
            $result = 'denied';
        } elseif (!$hasPdfStatus) {
            $result = 'invalid_status';
        } else {
            try {
                $errors = [];
                $workflowId = CBPDocument::StartWorkflow(
                    PDF_WORKFLOW_TEMPLATE_ID,
                    ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $offerId],
                    [],
                    $errors
                );
                $result = (string)$workflowId !== '' ? 'started' : 'error';
            } catch (\Throwable $e) {
                $result = 'error';
            }
        }
    }

    LocalRedirect(buildUrl(['pdf_bp' => $result], []));
}

$pdfWorkflowResult = (string)$request->get('pdf_bp');
$hrdApprovalResult = (string)$request->get('hrd_approval');
$offerDelegateResult = (string)$request->get('offer_delegate');
$approvalCancelResult = (string)$request->get('approval_cancel');

$statusEnumOptions = [];
$rsEnum = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['IBLOCK_ID' => IBL_OFFERS, 'PROPERTY_ID' => 1189]);
while ($e = $rsEnum->Fetch()) {
    $statusEnumOptions[(int)$e['ID']] = (string)$e['VALUE'];
}

$filter = [
    'IBLOCK_ID' => IBL_OFFERS,
    'ACTIVE' => 'Y',
    'CHECK_PERMISSIONS' => 'Y',
];
if ($q !== '') $filter['%' . PROP_CANDIDATE_FIO] = $q;
if ($fRecruiter > 0) $filter[PROP_RECRUITER] = $fRecruiter;
if ($fStatus > 0) $filter[PROP_STATUS] = $fStatus;
if ($inWorkOnly) {
    $taskOfferIds = array_keys($currentUserTasksMap);
    $filter['ID'] = !empty($taskOfferIds) ? $taskOfferIds : 0;
}

$arOrder = ['ID' => 'DESC'];
if ($sort === 'ID') $arOrder = ['ID' => $dir];
if ($sort === 'DATE_CREATE') $arOrder = ['DATE_CREATE' => $dir];
if ($sort === 'CANDIDATE_FIO') $arOrder = [PROP_CANDIDATE_FIO => $dir, 'ID' => 'DESC'];
if ($sort === 'STATUS') $arOrder = [PROP_STATUS => $dir, 'ID' => 'DESC'];

$arSelect = [
    'ID', 'DATE_CREATE',
    PROP_CANDIDATE_FIO,
    PROP_POSITION,
    PROP_ORGANIZATION,
    PROP_RECRUITER,
    PROP_STATUS,
    PROP_OFFER_PDF,
    PROP_REQUEST_ID,
    PROP_CANDIDATE_ID,
    'PREVIEW_TEXT',
];

$res = CIBlockElement::GetList($arOrder, $filter, false, ['nPageSize' => $pageSize, 'bShowAll' => false], $arSelect);

$items = [];
$userIds = [];
$detailPropertyIds = [
    1157, 1158, 1161, 2753, 1996, 1163, 1164, 1168, 1169,
    1165, 1184, 1998, 1186, 1170, 1172, 1235, 1234,
    1159, 1174, 2001, 2002, 1177, 3147, 1767,
    1327, 1326, 1328, 1329, 2070, 3130, 2857,
];
while ($ob = $res->GetNextElement()) {
    $f = $ob->GetFields();
    $id = (int)$f['ID'];
    $p = loadOfferPropertyValues($id, $detailPropertyIds);
    $recruiterId = userIdFromValue($f[PROP_RECRUITER . '_VALUE'] ?? '');

    $taskIdForCurrentUser = (int)($currentUserTasksMap[$id] ?? 0);

    $detailSections = [
        [
            'title' => 'Кандидат',
            'rows' => [
                ['label' => 'Полное ФИО кандидата', 'value' => getPropertyValue($p, 1157)],
                ['label' => 'Контактный телефон кандидата (в формате +7 ....)', 'value' => getPropertyValue($p, 1158)],
            ],
        ],
        [
            'title' => 'Позиция и структура',
            'rows' => [
                ['label' => 'Должность', 'value' => getPropertyValue($p, 1161)],
                ['label' => 'Юридическое лицо', 'value' => getPropertyValue($p, 2753)],
                ['label' => 'Дирекция', 'value' => getPropertyValue($p, 1996)],
                ['label' => 'Подразделение', 'value' => getPropertyValue($p, 1163)],
                ['label' => 'ФИО руководителя', 'value' => getPropertyValue($p, 1168)],
                ['label' => 'Должность руководителя', 'value' => getPropertyValue($p, 1169)],
            ],
        ],
        [
            'title' => 'Компенсация',
            'rows' => [
                ['label' => 'Оклад, руб. Гросс', 'value' => formatMoneyForDisplay(getPropertyValue($p, 1165))],
                ['label' => 'ИСН, руб. Гросс', 'value' => formatMoneyForDisplay(getPropertyValue($p, 1184))],
                ['label' => 'Премиальная часть', 'value' => getPropertyValue($p, 1998)],
                ['label' => 'Премиальная часть, % премии от оклада', 'value' => getPropertyValue($p, 1186)],
                ['label' => 'Премиальная часть, руб. Гросс', 'value' => formatMoneyForDisplay(getPropertyValue($p, 1170))],
                ['label' => 'Доход в месяц в среднем, руб. Гросс', 'value' => formatMoneyForDisplay(getPropertyValue($p, 1172))],
                ['label' => 'Районный коэффициент', 'value' => getPropertyValue($p, 1235)],
                ['label' => 'Северная надбавка %%', 'value' => getPropertyValue($p, 1234)],
            ],
        ],
        [
            'title' => 'Даты и условия',
            'rows' => [
                ['label' => 'Планируемая дата отправки оффера кандидату', 'value' => getPropertyValue($p, 1159)],
                ['label' => 'Планируемая дата выхода на работу', 'value' => getPropertyValue($p, 1174)],
                ['label' => 'Испытательный срок', 'value' => getPropertyValue($p, 2001)],
                ['label' => 'Договор с сотрудником', 'value' => getPropertyValue($p, 2002)],
                ['label' => 'Социальный пакет', 'value' => getPropertyValue($p, 1177)],
                ['label' => 'Компенсация аренды жилья', 'value' => formatMoneyForDisplay(getPropertyValue($p, 3147))],
                ['label' => 'Регион-локация кандидата', 'value' => getPropertyValue($p, 1767)],
            ],
        ],
        [
            'title' => 'Рабочее место',
            'rows' => [
                ['label' => 'Формат работы', 'value' => getPropertyValue($p, 1327)],
                ['label' => 'Адрес офиса', 'value' => getPropertyValue($p, 1326)],
                ['label' => 'График работы', 'value' => getPropertyValue($p, 1328)],
                ['label' => 'Начало рабочего дня', 'value' => getPropertyValue($p, 1329)],
                ['label' => 'Оборудование для работы', 'value' => getPropertyValue($p, 2070)],
                ['label' => 'Оборудование для работы (текст)', 'value' => getPropertyValue($p, 3130)],
            ],
        ],
        [
            'title' => 'Дополнительно',
            'rows' => [
                ['label' => 'Путь создания оффера', 'value' => getPropertyValue($p, 2857)],
            ],
        ],
    ];

    $items[] = [
        'ID' => $id,
        'DATE_CREATE' => (string)$f['DATE_CREATE'],
        'CANDIDATE_FIO' => getFieldValue($f, PROP_CANDIDATE_FIO),
        'POSITION' => getFieldValue($f, PROP_POSITION),
        'ORGANIZATION' => getPropertyValue($p, 2753) ?: getFieldValue($f, PROP_ORGANIZATION),
        'RECRUITER_ID' => $recruiterId,
        'REQUEST_ID' => getOfferRequestId(
            (int)($f[PROP_REQUEST_ID . '_VALUE'] ?? 0),
            (int)($f[PROP_CANDIDATE_ID . '_VALUE'] ?? 0)
        ),
        'STATUS' => getFieldValue($f, PROP_STATUS),
        'STATUS_ID' => (int)($f[PROP_STATUS . '_ENUM_ID'] ?? 0),
        'STATUS_HISTORY' => decodeStatusHistoryHtml((string)($f['PREVIEW_TEXT'] ?? '')),
        'OFFER_PDF_URL' => getUrlFromHtmlField($f, PROP_OFFER_PDF),
        'DETAILS_HTML' => renderOfferDetailsHtml($detailSections),
        'TASK_ID_FOR_CURRENT_USER' => $taskIdForCurrentUser,
        'VIEW_URL' => '/forms/staff_recruitment/offer/view_offer.php?id=' . $id,
        'EDIT_URL' => '/forms/staff_recruitment/offer/edit_offer.php?id=' . $id,
    ];

    if ($recruiterId > 0) $userIds[$recruiterId] = true;
}

$ids = array_keys($userIds);
$userMap = [];
if (!empty($ids)) {
    $rsU = \Bitrix\Main\UserTable::getList([
        'filter' => ['@ID' => $ids],
        'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'],
    ]);
    while ($u = $rsU->fetch()) {
        $u['ID'] = (int)$u['ID'];
        $userMap[$u['ID']] = $u;
    }
}

$recruiterOptions = [];
$rsRecruiters = CIBlockElement::GetList(
    [],
    ['IBLOCK_ID' => IBL_OFFERS, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
    false,
    false,
    ['ID', PROP_RECRUITER]
);
while ($el = $rsRecruiters->Fetch()) {
    $uid = userIdFromValue($el[PROP_RECRUITER . '_VALUE'] ?? '');
    if ($uid > 0) $recruiterOptions[$uid] = true;
}
$recruiterOptions = array_map('intval', array_keys($recruiterOptions));

$recruiterOptionUsers = [];
if (!empty($recruiterOptions)) {
    $rsRU = \Bitrix\Main\UserTable::getList([
        'filter' => ['@ID' => $recruiterOptions],
        'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'],
    ]);
    while ($u = $rsRU->fetch()) {
        $u['ID'] = (int)$u['ID'];
        $recruiterOptionUsers[$u['ID']] = $u;
    }
    uasort($recruiterOptionUsers, static function ($a, $b) {
        return strcmp(mb_strtolower(formatUserName($a), 'UTF-8'), mb_strtolower(formatUserName($b), 'UTF-8'));
    });
}

function navPageUrl(int $pageNum): string
{
    return buildUrl(['PAGEN_1' => $pageNum]);
}
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.offer-list-page { padding:16px 24px; }
.offer-list-page .table thead th { white-space:nowrap; vertical-align:middle; }
.offer-list-page .sort-link, .offer-list-page th a { color:#fff; text-decoration:none; }
.offer-list-page .sort-link:hover, .offer-list-page th a:hover { color:#fff; text-decoration:underline; }
.offer-list-page .filter-toolbar { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; padding:12px 14px; }
.offer-list-page .filter-item { flex:0 0 auto; min-width:180px; }
.offer-list-page .filter-item.search-item { width:320px; }
.offer-list-page .actions { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.offer-list-page .actions-select { min-width:150px; max-width:100%; }
.offer-list-page .muted { color:#6c757d; }
.offer-list-page .info-btn { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; border:0; background:#6c757d; color:#fff; font-size:12px; text-decoration:none; margin-left:6px; cursor:pointer; }
.offer-list-page .info-btn:hover { background:#5a6268; color:#fff; text-decoration:none; }
.offer-list-page .status-badge { display:inline-block; padding:5px 10px; border-radius:999px; font-size:12px; font-weight:600; color:#111827; }
.offer-list-page .pdf-link { display:inline-flex; align-items:center; justify-content:center; color:#dc3545; }
.offer-list-page .pdf-link:hover { color:#bd2130; }
.offer-list-page .pdf-icon { width:26px; height:32px; display:block; }
.offer-list-page .pagination { margin-top:12px; display:flex; gap:6px; flex-wrap:wrap; }
.offer-list-page .pagination a, .offer-list-page .pagination span { padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; }
.offer-list-page .pagination .active { background:#007bff; border-color:#007bff; color:#fff; }
.offer-list-page .modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; z-index:9998; opacity:1; }
.offer-list-page .modal-card { position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); width:min(900px, 92vw); max-height:82vh; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.25); display:none; z-index:9999; overflow:hidden; }
.offer-list-page .modal-head { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-bottom:1px solid #e5e5e5; }
.offer-list-page .modal-title { font-weight:600; }
.offer-list-page .modal-body { padding:16px; white-space:pre-line; overflow:auto; max-height:calc(82vh - 60px); }
.offer-list-page .offer-detail-section { margin-bottom:18px; }
.offer-list-page .offer-detail-title { font-weight:700; margin-bottom:8px; color:#343a40; }
.offer-list-page .offer-detail-grid { display:grid; grid-template-columns:minmax(240px, 38%) 1fr; gap:6px 14px; }
.offer-list-page .offer-detail-label { color:#6c757d; }
.offer-list-page .offer-detail-value { font-weight:500; white-space:pre-wrap; }
/* Entity Selector is appended to <body>, outside of the modal. Keep its popup above the custom modal. */
.popup-window.offer-delegate-selector-popup,
.popup-window.ui-entity-selector-dialog { z-index:21000 !important; }
</style>

<div class="container-fluid offer-list-page">
    <h2 class="mb-3">Заявки на оффер</h2>

    <?php if ($approvalCancelResult === 'cancelled'): ?><div class="alert alert-success">Согласование оффера отменено.</div>
    <?php elseif ($approvalCancelResult === 'partial_error'): ?><div class="alert alert-warning">Оффер отменен, но одну из связанных операций выполнить не удалось. Обратитесь к администратору.</div>
    <?php elseif ($approvalCancelResult === 'denied'): ?><div class="alert alert-danger">Недостаточно прав для отмены согласования этого оффера.</div>
    <?php elseif ($approvalCancelResult === 'comment_required'): ?><div class="alert alert-danger">Комментарий обязателен.</div>
    <?php elseif ($approvalCancelResult === 'request_action_required'): ?><div class="alert alert-danger">Выберите действие с заявкой на подбор.</div>
    <?php elseif ($approvalCancelResult === 'session_error'): ?><div class="alert alert-danger">Сессия истекла. Обновите страницу и повторите действие.</div>
    <?php elseif ($approvalCancelResult === 'error'): ?><div class="alert alert-danger">Не удалось отменить согласование оффера.</div><?php endif; ?>

    <?php if ($offerDelegateResult === 'delegated'): ?><div class="alert alert-success">Оффер делегирован, рекрутер и история обновлены, процесс установки прав запущен.</div>
    <?php elseif ($offerDelegateResult === 'workflow_error'): ?><div class="alert alert-warning">Оффер делегирован, но процесс установки прав запустить не удалось.</div>
    <?php elseif ($offerDelegateResult === 'denied'): ?><div class="alert alert-danger">Недостаточно прав для делегирования этого оффера.</div>
    <?php elseif ($offerDelegateResult === 'comment_required'): ?><div class="alert alert-danger">Комментарий при делегировании обязателен.</div>
    <?php elseif ($offerDelegateResult === 'invalid_user'): ?><div class="alert alert-danger">Выберите активного сотрудника для делегирования.</div>
    <?php elseif ($offerDelegateResult === 'same_user'): ?><div class="alert alert-danger">Новый рекрутер должен отличаться от текущего.</div>
    <?php elseif ($offerDelegateResult === 'task_error'): ?><div class="alert alert-danger">Не удалось делегировать текущее задание рекрутера. Оффер не был передан.</div>
    <?php elseif ($offerDelegateResult === 'history_error'): ?><div class="alert alert-warning">Оффер передан, но запись в историю добавить не удалось.</div>
    <?php elseif ($offerDelegateResult === 'session_error'): ?><div class="alert alert-danger">Сессия истекла. Обновите страницу и повторите действие.</div>
    <?php elseif ($offerDelegateResult === 'error'): ?><div class="alert alert-danger">Не удалось делегировать оффер.</div><?php endif; ?>

    <?php if ($hrdApprovalResult === 'approved'): ?><div class="alert alert-success">Оффер согласован за HRD.</div>
    <?php elseif ($hrdApprovalResult === 'denied'): ?><div class="alert alert-danger">Недостаточно прав для согласования за HRD.</div>
    <?php elseif ($hrdApprovalResult === 'invalid_status'): ?><div class="alert alert-danger">Действие доступно только в статусе «Согласование HRD».</div>
    <?php elseif ($hrdApprovalResult === 'comment_required'): ?><div class="alert alert-danger">Комментарий обязателен.</div>
    <?php elseif ($hrdApprovalResult === 'session_error'): ?><div class="alert alert-danger">Сессия истекла.</div>
    <?php elseif ($hrdApprovalResult === 'error'): ?><div class="alert alert-danger">Не удалось согласовать оффер за HRD.</div><?php endif; ?>

    <?php if ($pdfWorkflowResult === 'started'): ?>
        <div class="alert alert-success">Процесс формирования PDF запущен.</div>
    <?php elseif ($pdfWorkflowResult === 'denied'): ?>
        <div class="alert alert-danger">Недостаточно прав для формирования PDF этого оффера.</div>
    <?php elseif ($pdfWorkflowResult === 'invalid_status'): ?>
        <div class="alert alert-danger">Формирование PDF доступно только для офферов в статусах «Согласовано» и «Оффер сформирован».</div>
    <?php elseif ($pdfWorkflowResult === 'session_error'): ?>
        <div class="alert alert-danger">Сессия истекла. Обновите страницу и повторите действие.</div>
    <?php elseif ($pdfWorkflowResult === 'error'): ?>
        <div class="alert alert-danger">Не удалось запустить процесс формирования PDF.</div>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-center mb-3">
        <a href="/forms/staff_recruitment/offer/create_offer.php" class="btn btn-success mr-3 mb-2">Создать оффер</a>
    </div>

    <form method="get" action="" class="card mb-3">
        <div class="filter-toolbar">
            <div class="filter-item search-item">
                <label class="mb-1">Поиск по ФИО кандидата</label>
                <input type="text" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Введите ФИО">
            </div>

            <div class="filter-item">
                <label class="mb-1">Рекрутер</label>
                <select name="f_recruiter" class="form-control form-control-sm">
                <option value="0">Все рекрутеры</option>
                <?php foreach ($recruiterOptionUsers as $uid => $u): ?>
                    <option value="<?= (int)$uid ?>" <?= $fRecruiter === (int)$uid ? 'selected' : '' ?>>
                        <?= h(formatUserName($u)) ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label class="mb-1">Статус</label>
                <select name="f_status" class="form-control form-control-sm">
                <option value="0">Все статусы</option>
                <?php foreach ($statusEnumOptions as $sid => $sname): ?>
                    <option value="<?= (int)$sid ?>" <?= $fStatus === (int)$sid ? 'selected' : '' ?>><?= h($sname) ?></option>
                <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label class="d-flex align-items-center mt-4">
                    <input type="checkbox" name="in_work" value="Y" <?= $inWorkOnly ? 'checked' : '' ?>>
                    <span class="ml-2">В работе</span>
                </label>
            </div>

            <div class="ml-auto d-flex" style="gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm">Применить</button>
                <a href="<?= h(buildUrl([], ['q', 'f_recruiter', 'f_status', 'in_work', 'sort', 'dir', 'PAGEN_1'])) ?>" class="btn btn-secondary btn-sm">Сбросить</a>
            </div>
        </div>
        </form>

    <div class="mb-2 text-muted">Найдено: <?= (int)$res->NavRecordCount ?>, страница <?= (int)$res->NavPageNomer ?> из <?= (int)$res->NavPageCount ?></div>

    <div class="table-responsive">
    <table class="table table-sm table-bordered table-hover">
        <thead class="thead-dark">
        <tr>
            <th><?= sortLink('ID', 'ID', $sort, $dir) ?></th>
            <th><?= sortLink('CANDIDATE_FIO', 'Полное ФИО кандидата', $sort, $dir) ?></th>
            <th><?= sortLink('DATE_CREATE', 'Дата создания', $sort, $dir) ?></th>
            <th>Должность</th>
            <th>Юридическое лицо</th>
            <th>Рекрутер</th>
            <th><?= sortLink('STATUS', 'Статус + история', $sort, $dir) ?></th>
            <th>PDF</th>
            <th>Действия</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="9" class="muted">Ничего не найдено.</td></tr>
        <?php else: ?>
            <?php foreach ($items as $row): ?>
                <?php
                $recruiterId = (int)$row['RECRUITER_ID'];
                $isRecruiterForOffer = ($recruiterId > 0 && $recruiterId === $currentUserId);
                $canManage = $isAdmin || $isRecruiterForOffer || $isCbManager || $isRecruitHead;
                $hasPdfStatus = in_array((int)$row['STATUS_ID'], PDF_ALLOWED_STATUS_ENUM_IDS, true);
                $canApproveThisAsHrd = $canApproveAsHrd && (int)$row['STATUS_ID'] === HRD_APPROVAL_STATUS_ENUM_ID;
                $canGeneratePdf = $hasPdfStatus && ($isRecruiterForOffer || $currentUserId === PDF_WORKFLOW_EXTRA_USER_ID);
                $canDelegateOffer = $isRecruiterForOffer || $isRecruitHead || $currentUserId === COMMENTS_ADMIN_USER_ID;
                $canCancelApproval = $isRecruiterForOffer || $isRecruitHead || $currentUserId === COMMENTS_ADMIN_USER_ID;
                $taskId = (int)$row['TASK_ID_FOR_CURRENT_USER'];
                $taskUrl = $taskId > 0 ? getBizprocTaskUrl($taskId, $currentUserId) : '';
                ?>
                <tr>
                    <td><a href="#" class="js-offer-details" data-offer-id="<?= (int)$row['ID'] ?>">#<?= (int)$row['ID'] ?></a><div class="d-none" id="offer-details-<?= (int)$row['ID'] ?>"><?= $row['DETAILS_HTML'] ?></div></td>
                    <td><?= h($row['CANDIDATE_FIO'] ?: '—') ?></td>
                    <td><?= h($row['DATE_CREATE']) ?></td>
                    <td><?= h($row['POSITION'] ?: '—') ?></td>
                    <td><?= h($row['ORGANIZATION'] ?: '—') ?></td>
                    <td><?= h(isset($userMap[$recruiterId]) ? formatUserName($userMap[$recruiterId]) : '—') ?></td>
                    <td>
                        <div>
                            <?php $statusName = (string)($row['STATUS'] ?: '—'); ?>
                            <span class="status-badge" style="background:<?= h(getStatusBadgeColor($statusName)) ?>;"><?= h($statusName) ?></span>
                            <?php if (trim($row['STATUS_HISTORY']) !== ''): ?>
                                <?php $historyBase64 = base64_encode($row['STATUS_HISTORY']); ?>
                                <a href="#"
                                   class="info-btn js-status-info"
                                   title="Показать историю"
                                   data-history-b64="<?= h($historyBase64) ?>"
                                   data-offer-id="<?= (int)$row['ID'] ?>">i</a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-center align-middle">
                        <?php if ($row['OFFER_PDF_URL'] !== ''): ?>
                            <a class="pdf-link" href="<?= h($row['OFFER_PDF_URL']) ?>" target="_blank" rel="noopener" title="Открыть PDF оффера" aria-label="Открыть PDF оффера">
                                <svg class="pdf-icon" viewBox="0 0 26 32" aria-hidden="true" focusable="false">
                                    <path fill="currentColor" d="M3 0h13l7 7v22a3 3 0 0 1-3 3H3a3 3 0 0 1-3-3V3a3 3 0 0 1 3-3Z"/>
                                    <path fill="#fff" fill-opacity=".45" d="M16 0v7h7Z"/>
                                    <text x="13" y="23" fill="#fff" font-size="8" font-family="Arial, sans-serif" font-weight="700" text-anchor="middle">PDF</text>
                                </svg>
                            </a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="actions">
                            <?php if ($taskUrl !== ''): ?>
                                <a class="btn btn-info btn-sm" href="<?= h($taskUrl) ?>" target="_blank" rel="noopener">Перейти в задание</a>
                            <?php endif; ?>

                            <?php if ($canManage || $canGeneratePdf || $canApproveThisAsHrd || $canDelegateOffer || $canCancelApproval): ?>
                                <select class="form-control form-control-sm actions-select js-offer-action-select"
                                        aria-label="Действия с оффером"
                                        data-offer-id="<?= (int)$row['ID'] ?>"
                                        data-recruiter-id="<?= $recruiterId ?>"
                                        data-request-id="<?= (int)$row['REQUEST_ID'] ?>"
                                        data-sessid="<?= h(bitrix_sessid()) ?>"
                                        data-view-url="<?= h($row['VIEW_URL']) ?>"
                                        data-edit-url="<?= h($row['EDIT_URL']) ?>">
                                    <option value="">Действия…</option>
                                    <?php if ($canManage): ?>
                                        <option value="view">Просмотр</option>
                                        <option value="edit">Редактирование</option>
                                    <?php endif; ?>
                                    <?php if ($canDelegateOffer): ?><option value="delegate">Делегировать</option><?php endif; ?>
                                    <?php if ($canApproveThisAsHrd): ?><option value="approve_as_hrd">Согласовать за HRD</option><?php endif; ?>
                                    <?php if ($canGeneratePdf): ?>
                                        <option value="generate_pdf">Сформировать PDF</option>
                                    <?php endif; ?>
                                    <?php if ($canCancelApproval): ?><option value="cancel_approval">Отмена согласования</option><?php endif; ?>
                                </select>
                            <?php endif; ?>

                            <?php if (!$canManage && !$canGeneratePdf && !$canApproveThisAsHrd && !$canDelegateOffer && !$canCancelApproval && $taskUrl === ''): ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ((int)$res->NavPageCount > 1): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= (int)$res->NavPageCount; $p++): ?>
                <?php if ($p === (int)$res->NavPageNomer): ?>
                    <span class="active"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= h(navPageUrl($p)) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<div class="offer-list-page">
    <div id="offer-modal-backdrop" class="modal-backdrop"></div>
    <div id="offer-modal" class="modal-card" role="dialog" aria-modal="true" aria-labelledby="offer-modal-title">
        <div class="modal-head">
            <div id="offer-modal-title" class="modal-title">История статуса</div>
            <button type="button" class="btn btn-secondary btn-sm" id="offer-modal-close">Закрыть</button>
        </div>
        <div class="modal-body" id="offer-modal-content"></div>
    </div>
</div>

<script>
(function() {
  var backdrop = document.getElementById('offer-modal-backdrop');
  var modal = document.getElementById('offer-modal');
  var closeBtn = document.getElementById('offer-modal-close');
  var content = document.getElementById('offer-modal-content');
  var title = document.getElementById('offer-modal-title');
  var delegateSelector = null;

  function closeModal() {
    try { if (delegateSelector && delegateSelector.isOpen()) delegateSelector.hide(); } catch (err) {}
    backdrop.style.display = 'none';
    modal.style.display = 'none';
    content.textContent = '';
  }

  function openModal(modalTitle, html) {
    title.textContent = modalTitle;
    content.innerHTML = html || 'Данные отсутствуют.';
    backdrop.style.display = 'block';
    modal.style.display = 'block';
  }

  function decodeBase64Utf8(encoded) {
    if (!encoded) return '';
    try {
      var binary = window.atob(encoded);
      var bytes = new Uint8Array(binary.length);
      for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
      return new TextDecoder('utf-8').decode(bytes);
    } catch (err) {
      try { return window.atob(encoded); } catch (fallbackErr) { return ''; }
    }
  }

  document.querySelectorAll('.js-offer-action-select').forEach(function(select) {
    select.addEventListener('change', function() {
      var action = select.value || '';
      var url = '';

      if (action === 'view') {
        url = select.getAttribute('data-view-url') || '';
      } else if (action === 'edit') {
        url = select.getAttribute('data-edit-url') || '';
      } else if (action === 'approve_as_hrd') {
        var offerId = select.getAttribute('data-offer-id') || '0';
        var sessid = select.getAttribute('data-sessid') || '';
        openModal('Согласовать за HRD', '<p>Данное действие согласует оффер за HRD.</p><form method="post" id="approve-as-hrd-form"><input type="hidden" name="action" value="approve_as_hrd"><input type="hidden" name="offer_id" value="' + offerId + '"><input type="hidden" name="sessid" value="' + sessid + '"><label for="approve-as-hrd-comment">Комментарий <span class="text-danger">*</span></label><textarea id="approve-as-hrd-comment" name="comment" class="form-control" rows="4" required></textarea><div class="mt-3"><button type="submit" class="btn btn-primary">Согласовать за HRD</button> <button type="button" class="btn btn-secondary" id="approve-as-hrd-cancel">Отмена</button></div></form>');
        var cancel = document.getElementById('approve-as-hrd-cancel');
        if (cancel) cancel.addEventListener('click', closeModal);
        select.value = '';
        return;
      } else if (action === 'delegate') {
        var delegateOfferId = select.getAttribute('data-offer-id') || '0';
        var delegateSessid = select.getAttribute('data-sessid') || '';
        var oldRecruiterId = select.getAttribute('data-recruiter-id') || '0';
        openModal('Делегировать оффер', '<form method="post" id="delegate-offer-form"><input type="hidden" name="action" value="delegate_offer"><input type="hidden" name="offer_id" value="' + delegateOfferId + '"><input type="hidden" name="sessid" value="' + delegateSessid + '"><input type="hidden" name="delegate_to_user_id" id="delegate-offer-user-id" value=""><div class="form-group"><label>Сотрудник <span class="text-danger">*</span></label><div><button type="button" class="btn btn-outline-primary btn-sm" id="delegate-offer-pick-user">Выбрать сотрудника</button> <span class="text-muted" id="delegate-offer-selected-user">Сотрудник не выбран</span></div></div><div class="form-group"><label for="delegate-offer-comment">Комментарии <span class="text-danger">*</span></label><textarea id="delegate-offer-comment" name="comment" class="form-control" rows="4" required></textarea></div><button type="submit" class="btn btn-primary">Делегировать оффер</button> <button type="button" class="btn btn-secondary" id="delegate-offer-cancel">Отмена</button></form>');

        var pickButton = document.getElementById('delegate-offer-pick-user');
        var selectedUser = document.getElementById('delegate-offer-selected-user');
        var userInput = document.getElementById('delegate-offer-user-id');
        var delegateForm = document.getElementById('delegate-offer-form');
        document.getElementById('delegate-offer-cancel').addEventListener('click', closeModal);
        pickButton.addEventListener('click', function() {
          try { if (delegateSelector) delegateSelector.destroy(); } catch (err) {}
          delegateSelector = new BX.UI.EntitySelector.Dialog({
            targetNode: pickButton,
            context: 'delegate-offer',
            multiple: false,
            dropdownMode: true,
            enableSearch: true,
            zIndex: 21000,
            popupOptions: { zIndex: 21000, className: 'offer-delegate-selector-popup' },
            entities: [{ id: 'user', options: { inviteEmployeeLink: false } }],
            events: {
              'Item:onSelect': function(event) {
                var item = event.getData().item;
                var rawId = item ? item.getId() : '';
                var userId = parseInt(String(rawId).replace(/[^\d]/g, ''), 10) || 0;
                if (!userId || String(userId) === String(oldRecruiterId)) {
                  alert(userId ? 'Выберите сотрудника, отличного от текущего рекрутера.' : 'Не удалось определить сотрудника.');
                  return;
                }
                userInput.value = String(userId);
                selectedUser.textContent = item.getTitle() || ('ID ' + userId);
                selectedUser.classList.remove('text-muted');
                delegateSelector.hide();
              }
            }
          });
          delegateSelector.show();
          var selectorPopup = delegateSelector.getPopup();
          if (selectorPopup) {
            if (typeof selectorPopup.setZindex === 'function') selectorPopup.setZindex(21000);
            else if (typeof selectorPopup.setZIndex === 'function') selectorPopup.setZIndex(21000);
            var popupContainer = selectorPopup.getPopupContainer ? selectorPopup.getPopupContainer() : null;
            if (popupContainer) {
              popupContainer.classList.add('offer-delegate-selector-popup');
              popupContainer.style.setProperty('z-index', '21000', 'important');
            }
            setTimeout(function() {
              try { selectorPopup.adjustPosition(); } catch (err) {}
            }, 0);
          }
        });
        delegateForm.addEventListener('submit', function(event) {
          if (!userInput.value || !document.getElementById('delegate-offer-comment').value.trim()) {
            event.preventDefault();
            alert(!userInput.value ? 'Выберите сотрудника для делегирования.' : 'Поле «Комментарии» обязательно.');
          }
        });
        select.value = '';
        return;
      } else if (action === 'cancel_approval') {
        var cancelOfferId = select.getAttribute('data-offer-id') || '0';
        var cancelSessid = select.getAttribute('data-sessid') || '';
        var requestId = parseInt(select.getAttribute('data-request-id') || '0', 10) || 0;
        var requestChoice = requestId > 0
          ? '<div class="form-group"><div class="font-weight-bold mb-2">Что сделать с заявкой на подбор?</div><label class="d-block"><input type="radio" name="request_action" value="continue" required> Продолжить работу по заявке на подбор</label><label class="d-block"><input type="radio" name="request_action" value="stop" required> Прервать процесс подбора</label></div>'
          : '';
        openModal('Отмена согласования', '<form method="post" id="cancel-approval-form"><input type="hidden" name="action" value="cancel_approval"><input type="hidden" name="offer_id" value="' + cancelOfferId + '"><input type="hidden" name="sessid" value="' + cancelSessid + '">' + requestChoice + '<div class="form-group"><label for="cancel-approval-comment">Комментарий <span class="text-danger">*</span></label><textarea id="cancel-approval-comment" name="comment" class="form-control" rows="4" required></textarea></div><button type="submit" class="btn btn-danger">Отменить согласование</button> <button type="button" class="btn btn-secondary" id="cancel-approval-close">Отмена</button></form>');
        document.getElementById('cancel-approval-close').addEventListener('click', closeModal);
        document.getElementById('cancel-approval-form').addEventListener('submit', function(event) {
          var comment = document.getElementById('cancel-approval-comment');
          var choice = this.querySelector('input[name="request_action"]:checked');
          if (!comment.value.trim() || (requestId > 0 && !choice)) {
            event.preventDefault();
            alert(!comment.value.trim() ? 'Поле «Комментарий» обязательно.' : 'Выберите действие с заявкой на подбор.');
          }
        });
        select.value = '';
        return;
      } else if (action === 'generate_pdf') {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = window.location.href;

        [
          ['action', 'generate_pdf'],
          ['offer_id', select.getAttribute('data-offer-id') || '0'],
          ['sessid', select.getAttribute('data-sessid') || '']
        ].forEach(function(field) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = field[0];
          input.value = field[1];
          form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        return;
      }

      if (url) window.open(url, '_blank', 'noopener');
      select.value = '';
    });
  });

  document.addEventListener('click', function(e) {
    var historyBtn = e.target.closest('.js-status-info');
    if (historyBtn) {
      e.preventDefault();
      openModal(
        'История статуса (оффер #' + (historyBtn.getAttribute('data-offer-id') || '') + ')',
        decodeBase64Utf8(historyBtn.getAttribute('data-history-b64') || '') || 'История отсутствует.'
      );
      return;
    }

    var detailsBtn = e.target.closest('.js-offer-details');
    if (detailsBtn) {
      e.preventDefault();
      var offerId = detailsBtn.getAttribute('data-offer-id') || '';
      var details = document.getElementById('offer-details-' + offerId);
      openModal('Информация по офферу #' + offerId, details ? details.innerHTML : 'Данные отсутствуют.');
    }
  });

  backdrop.addEventListener('click', closeModal);
  closeBtn.addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display === 'block') closeModal();
  });
})();
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
