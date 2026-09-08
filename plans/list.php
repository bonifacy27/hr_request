<?php
/**
 * Список планов ввода в должность (список 359 рабочей группы 206).
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Context;
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Планы ввода в должность');

if (!Loader::includeModule('iblock') || !Loader::includeModule('bizproc')) {
    ShowError('Не удалось подключить модули iblock/bizproc.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    ShowError('Требуется авторизация.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

const PLAN_IBLOCK_ID = 359;
const PVD_TASK_IBLOCK_ID = 360;
const TASK_STATUS_IBLOCK_ID = 361;
const KPI_TASK_IBLOCK_ID = 363;
const PROP_MANAGER = 2775;
const PROP_EMPLOYMENT_DATE = 2776;
const PROP_TRIAL_END_DATE = 2802;
const PROP_RECRUITER = 2796;
const PROP_PVD_TASKS = 2761;
const PROP_KPI_TASKS = 2769;
const PROP_PVD_STATUS = 2767;
const PROP_PVD_TASK_TYPE = 2764;
const PROP_KPI_STATUS = 2805;
const PROP_STATUS_COLOR = 3168;
const PROP_EMPLOYEE_CARD = 2801;
const EMPLOYEE_CARD_IBLOCK_ID = 196;
const PROP_PVD_CREATED_AT = 3064;
const EMPLOYEE_CARD_VIEW_URL = '/forms/staff_recruitment/adaptation/view.php?id=';
const PVD_REVIEW_TASK_TYPE_ID = 3347538;
const COMPLETED_TASK_STATUS_ID = 3347534;
const PAGE_SIZE = 20;

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function userIdFromPlanValue($value)
{
    if (is_array($value)) {
        $value = reset($value);
    }
    $value = trim((string)$value);
    if (stripos($value, 'user_') === 0) {
        return (int)substr($value, 5);
    }
    return preg_match('/(\d+)/', $value, $matches) ? (int)$matches[1] : 0;
}

function formatUserName(array $user)
{
    $name = trim((string)CUser::FormatName(CSite::GetNameFormat(false), $user, true, false));
    return $name !== '' ? $name : (string)($user['LOGIN'] ?? '');
}

function loadUserNames(array $userIds)
{
    $result = [];
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) {
        return $result;
    }

    $users = Bitrix\Main\UserTable::getList([
        'filter' => ['@ID' => $userIds],
        'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'],
    ]);
    while ($user = $users->fetch()) {
        $result[(int)$user['ID']] = formatUserName($user);
    }
    return $result;
}

function loadLinkedIds($planId, $propertyId)
{
    $ids = [];
    $properties = CIBlockElement::GetProperty(
        PLAN_IBLOCK_ID,
        (int)$planId,
        ['sort' => 'asc', 'id' => 'asc'],
        ['ID' => (int)$propertyId]
    );
    while ($property = $properties->Fetch()) {
        $id = (int)($property['VALUE'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function currentTaskExecutors($elementId, $iblockId)
{
    $userIds = [];
    $documents = [
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
        ['lists', 'BizprocDocument', 'lists_' . (int)$iblockId . '_' . (int)$elementId],
        ['lists', 'lists_' . (int)$iblockId . '_group_206', (int)$elementId],
        ['lists', 'lists_' . (int)$iblockId, (int)$elementId],
        ['iblock', 'CIBlockDocument', 'iblock_' . (int)$iblockId . '_' . (int)$elementId],
    ];

    foreach ($documents as $documentId) {
        $tasks = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            ['DOCUMENT_ID' => $documentId, 'STATUS' => CBPTaskStatus::Running],
            false,
            false,
            ['USER_ID']
        );
        while ($task = $tasks->Fetch()) {
            $userId = (int)($task['USER_ID'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }
    }

    $names = [];
    foreach ($userIds as $userId) {
        $user = CUser::GetByID($userId)->Fetch();
        if ($user) {
            $names[] = formatUserName($user);
        }
    }
    return array_values(array_filter(array_unique($names)));
}

function loadTasks(array $ids, $iblockId, $statusPropertyId, array $detailFields, $typePropertyId = 0)
{
    $result = [];
    if (!$ids) {
        return $result;
    }

    $select = ['ID', 'NAME', 'PROPERTY_' . (int)$statusPropertyId];
    if ($typePropertyId > 0) {
        $select[] = 'PROPERTY_' . (int)$typePropertyId;
    }
    foreach ($detailFields as $propertyId => $label) {
        $select[] = 'PROPERTY_' . (int)$propertyId;
    }
    $tasks = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => (int)$iblockId, 'ID' => $ids, 'ACTIVE' => 'Y'],
        false,
        false,
        $select
    );
    while ($task = $tasks->Fetch()) {
        $statusId = (int)($task['PROPERTY_' . (int)$statusPropertyId . '_VALUE'] ?? 0);
        $status = '';
        $statusColor = '#FFFFFF';
        if ($statusId > 0) {
            $statusElement = CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => TASK_STATUS_IBLOCK_ID, 'ID' => $statusId],
                false,
                ['nTopCount' => 1],
                ['ID', 'NAME', 'PROPERTY_' . PROP_STATUS_COLOR]
            )->Fetch();
            if ($statusElement) {
                $status = (string)$statusElement['NAME'];
                $candidateColor = trim((string)($statusElement['PROPERTY_' . PROP_STATUS_COLOR . '_VALUE'] ?? ''));
                if (preg_match('/^#[0-9a-f]{6}$/i', $candidateColor)) {
                    $statusColor = $candidateColor;
                }
            }
        }

        $details = ['Название' => (string)$task['NAME']];
        foreach ($detailFields as $propertyId => $label) {
            $details[$label] = (string)($task['PROPERTY_' . (int)$propertyId . '_VALUE'] ?? '');
        }
        $details['Статус задачи'] = $status;
        $result[] = [
            'ID' => (int)$task['ID'],
            'NAME' => (string)$task['NAME'],
            'STATUS' => $status,
            'STATUS_ID' => $statusId,
            'TYPE_ID' => $typePropertyId > 0
                ? (int)($task['PROPERTY_' . (int)$typePropertyId . '_VALUE'] ?? 0)
                : 0,
            'STATUS_COLOR' => $statusColor,
            'EXECUTORS' => currentTaskExecutors((int)$task['ID'], (int)$iblockId),
            'DETAILS' => $details,
        ];
    }
    return $result;
}

function loadPvdCreatedAt($employeeCardId)
{
    if ((int)$employeeCardId <= 0) {
        return '';
    }
    $property = CIBlockElement::GetProperty(
        EMPLOYEE_CARD_IBLOCK_ID,
        (int)$employeeCardId,
        ['sort' => 'asc', 'id' => 'asc'],
        ['ID' => PROP_PVD_CREATED_AT]
    )->Fetch();
    return trim((string)($property['VALUE'] ?? ''));
}

function isLessThanDayBeforeEmployment($dateValue)
{
    $dateValue = trim((string)$dateValue);
    if ($dateValue === '') {
        return false;
    }
    $timestamp = MakeTimeStamp($dateValue);
    if (!$timestamp) {
        $timestamp = strtotime($dateValue);
    }
    return $timestamp !== false && ($timestamp - time()) < 86400;
}
function currentPlanTaskId($planId, $userId)
{
    $documents = [
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$planId],
        ['lists', 'BizprocDocument', 'lists_' . PLAN_IBLOCK_ID . '_' . (int)$planId],
        ['lists', 'lists_' . PLAN_IBLOCK_ID . '_group_206', (int)$planId],
        ['lists', 'lists_' . PLAN_IBLOCK_ID, (int)$planId],
        ['iblock', 'CIBlockDocument', 'iblock_' . PLAN_IBLOCK_ID . '_' . (int)$planId],
    ];
    foreach ($documents as $documentId) {
        $tasks = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            ['DOCUMENT_ID' => $documentId, 'USER_ID' => (int)$userId, 'STATUS' => CBPTaskStatus::Running],
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        if ($task = $tasks->Fetch()) {
            return (int)$task['ID'];
        }
    }
    return 0;
}

function bizprocTaskUrl($taskId, $userId)
{
    if (method_exists('CBPTaskService', 'GetTaskUrl')) {
        return (string)CBPTaskService::GetTaskUrl((int)$taskId, (int)$userId);
    }
    if (method_exists('CBPTaskService', 'GetTaskURL')) {
        return (string)CBPTaskService::GetTaskURL((int)$taskId, (int)$userId);
    }
    return '/company/personal/bizproc/' . (int)$taskId . '/';
}

function buildUrl(array $set = [], array $remove = [])
{
    $query = $_GET;
    foreach ($remove as $key) {
        unset($query[$key]);
    }
    foreach ($set as $key => $value) {
        $query[$key] = $value;
    }
    return strtok($_SERVER['REQUEST_URI'], '?') . ($query ? '?' . http_build_query($query) : '');
}

function renderTaskTable(array $tasks, $type, $title)
{
    static $sectionSequence = 0;
    $sectionSequence++;
    $safeType = preg_replace('/[^a-z0-9_-]/i', '', (string)$type);
    $sectionId = 'task-section-' . $safeType . '-' . $sectionSequence;
    $html = '<section class="task-section">';
    $html .= '<button type="button" class="task-section-toggle js-task-section-toggle" aria-expanded="false" aria-controls="' . h($sectionId) . '">';
    $html .= '<span>' . h($title) . ' <span class="task-count">(' . count($tasks) . ')</span></span><span class="task-chevron" aria-hidden="true">&#9660;</span></button>';
    $html .= '<div id="' . h($sectionId) . '" class="task-section-body">';
    if (!$tasks) {
        return $html . '<span class="text-muted">Нет задач</span></div></section>';
    }

    $html .= '<table class="plan-task-table"><tbody>';
    foreach ($tasks as $task) {
        $templateId = 'task-details-' . $safeType . '-' . $sectionSequence . '-' . (int)$task['ID'];
        $html .= '<tr><td><button type="button" class="task-name js-task-details" data-template="' . h($templateId) . '">' . h($task['NAME']) . '</button></td>';
        $html .= '<td><span class="task-status" style="background-color:' . h($task['STATUS_COLOR']) . '">' . h($task['STATUS'] !== '' ? $task['STATUS'] : '—') . '</span></td></tr>';
        $html .= '<tr class="task-details-template"><td colspan="2"><div id="' . h($templateId) . '">';
        $html .= '<div class="task-card-head"><strong>' . h($task['NAME']) . '</strong>';
        $html .= '<span class="task-status" style="background-color:' . h($task['STATUS_COLOR']) . '">' . h($task['STATUS'] !== '' ? $task['STATUS'] : '—') . '</span></div>';
        $html .= '<div class="task-card-section"><h5>Сведения о задаче</h5><dl class="task-details-list">';
        foreach ($task['DETAILS'] as $label => $value) {
            if ($label === 'Название' || $label === 'Статус задачи') {
                continue;
            }
            $html .= '<dt>' . h($label) . '</dt><dd>' . h(trim((string)$value) !== '' ? $value : '—') . '</dd>';
        }
        $html .= '</dl></div><div class="task-card-section"><h5>Бизнес-процесс</h5><dl class="task-details-list">';
        $html .= '<dt>Текущий исполнитель</dt><dd>' . h($task['EXECUTORS'] ? implode(', ', $task['EXECUTORS']) : '—') . '</dd>';
        $html .= '</dl></div></div></td></tr>';
    }
    return $html . '</tbody></table></div></section>';
}
$request = Context::getCurrent()->getRequest();
$search = trim((string)$request->get('q'));
$managerFilter = max(0, (int)$request->get('manager'));
$recruiterFilter = max(0, (int)$request->get('recruiter'));
$missingPvdFilter = (string)$request->get('pvd_missing') === 'Y';
$sortField = (string)$request->get('sort') === 'name' ? 'name' : 'employment';
$sortDirection = strtoupper((string)$request->get('order')) === 'ASC' ? 'ASC' : 'DESC';
$sort = $sortField === 'name'
    ? ['NAME' => $sortDirection, 'ID' => 'DESC']
    : ['PROPERTY_' . PROP_EMPLOYMENT_DATE => $sortDirection, 'ID' => 'DESC'];

$managerIds = [];
$recruiterIds = [];
$filteredPlanIds = [];
$filterCandidates = CIBlockElement::GetList(
    ['ID' => 'DESC'],
    ['IBLOCK_ID' => PLAN_IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
    false,
    false,
    ['ID', 'PROPERTY_' . PROP_MANAGER, 'PROPERTY_' . PROP_RECRUITER,
        'PROPERTY_' . PROP_EMPLOYMENT_DATE]
);
while ($candidate = $filterCandidates->Fetch()) {
    $candidateManagerId = userIdFromPlanValue($candidate['PROPERTY_' . PROP_MANAGER . '_VALUE'] ?? '');
    $candidateRecruiterId = userIdFromPlanValue($candidate['PROPERTY_' . PROP_RECRUITER . '_VALUE'] ?? '');
    if ($candidateManagerId > 0) {
        $managerIds[$candidateManagerId] = $candidateManagerId;
    }
    if ($candidateRecruiterId > 0) {
        $recruiterIds[$candidateRecruiterId] = $candidateRecruiterId;
    }
    if ($managerFilter > 0 && $candidateManagerId !== $managerFilter) {
        continue;
    }
    if ($recruiterFilter > 0 && $candidateRecruiterId !== $recruiterFilter) {
        continue;
    }
    if ($missingPvdFilter && (
        loadLinkedIds((int)$candidate['ID'], PROP_PVD_TASKS)
        || loadLinkedIds((int)$candidate['ID'], PROP_KPI_TASKS)
        || !isLessThanDayBeforeEmployment($candidate['PROPERTY_' . PROP_EMPLOYMENT_DATE . '_VALUE'] ?? '')
    )) {
        continue;
    }
    $filteredPlanIds[] = (int)$candidate['ID'];
}

$filter = ['IBLOCK_ID' => PLAN_IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'];
if ($search !== '') {
    $filter['%NAME'] = $search;
}
if ($managerFilter > 0 || $recruiterFilter > 0 || $missingPvdFilter) {
    $filter['ID'] = $filteredPlanIds ?: [-1];
}

$plansResult = CIBlockElement::GetList(
    $sort,
    $filter,
    false,
    ['nPageSize' => PAGE_SIZE, 'bShowAll' => false],
    ['ID', 'NAME', 'PROPERTY_' . PROP_MANAGER, 'PROPERTY_' . PROP_EMPLOYMENT_DATE,
        'PROPERTY_' . PROP_TRIAL_END_DATE, 'PROPERTY_' . PROP_RECRUITER,
        'PROPERTY_' . PROP_EMPLOYEE_CARD]
);

$plans = [];
$userIds = array_merge(array_values($managerIds), array_values($recruiterIds));
$currentUserId = (int)$USER->GetID();
while ($plan = $plansResult->Fetch()) {
    $managerId = userIdFromPlanValue($plan['PROPERTY_' . PROP_MANAGER . '_VALUE'] ?? '');
    $recruiterId = userIdFromPlanValue($plan['PROPERTY_' . PROP_RECRUITER . '_VALUE'] ?? '');
    $userIds[] = $managerId;
    $userIds[] = $recruiterId;
    $pvdIds = loadLinkedIds((int)$plan['ID'], PROP_PVD_TASKS);
    $kpiIds = loadLinkedIds((int)$plan['ID'], PROP_KPI_TASKS);
    $plan['MANAGER_ID'] = $managerId;
    $plan['RECRUITER_ID'] = $recruiterId;
    $plan['PVD_TASKS'] = loadTasks($pvdIds, PVD_TASK_IBLOCK_ID, PROP_PVD_STATUS, [
        2837 => 'Дата постановки задачи',
        2760 => 'Планируемый результат',
        2762 => 'Фактический результат',
        2807 => 'Планируемый срок исполнения',
        2806 => 'Фактический срок исполнения',
    ], PROP_PVD_TASK_TYPE);
    $plan['KPI_TASKS'] = loadTasks($kpiIds, KPI_TASK_IBLOCK_ID, PROP_KPI_STATUS, [
        2785 => 'Планируемый результат',
        2791 => 'Фактический результат',
        2784 => 'Планируемый срок',
        2789 => 'Фактический срок',
        2803 => 'Вес (%)',
        2804 => 'Процент выполнения (%)',
    ]);
    $plan['BP_TASK_ID'] = currentPlanTaskId((int)$plan['ID'], $currentUserId);
    $employeeCardId = (int)($plan['PROPERTY_' . PROP_EMPLOYEE_CARD . '_VALUE'] ?? 0);
    $plan['EMPLOYEE_CARD_ID'] = $employeeCardId;
    $plan['PVD_CREATED_AT'] = loadPvdCreatedAt($employeeCardId);
    $plan['PVD_IS_MISSING'] = !$plan['PVD_TASKS'] && !$plan['KPI_TASKS']
        && isLessThanDayBeforeEmployment($plan['PROPERTY_' . PROP_EMPLOYMENT_DATE . '_VALUE'] ?? '');
    $plan['PVD_REVIEW_IS_PENDING'] = false;
    foreach ($plan['PVD_TASKS'] as $pvdTask) {
        if ((int)$pvdTask['TYPE_ID'] === PVD_REVIEW_TASK_TYPE_ID
            && (int)$pvdTask['STATUS_ID'] !== COMPLETED_TASK_STATUS_ID) {
            $plan['PVD_REVIEW_IS_PENDING'] = true;
            break;
        }
    }
    $plans[] = $plan;
}
$userNames = loadUserNames($userIds);
$managerNames = array_intersect_key($userNames, $managerIds);
$recruiterNames = array_intersect_key($userNames, $recruiterIds);
asort($managerNames, SORT_NATURAL | SORT_FLAG_CASE);
asort($recruiterNames, SORT_NATURAL | SORT_FLAG_CASE);
$nameSortOrder = $sortField === 'name' && $sortDirection === 'ASC' ? 'DESC' : 'ASC';
$employmentSortOrder = $sortField === 'employment' && $sortDirection === 'DESC' ? 'ASC' : 'DESC';
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.plans-list-page { padding:16px 24px; }
.plans-list-page .table > thead > tr > th { white-space:nowrap; vertical-align:middle; }
.plans-list-page .table > tbody > tr > td { vertical-align:top; }
.plans-list-page .filter-toolbar { display:flex; align-items:flex-end; gap:12px; padding:12px 14px; flex-wrap:wrap; }
.plans-list-page .fio-column { width:20%; max-width:20%; overflow-wrap:anywhere; }
.plans-list-page .sort-link { color:inherit; text-decoration:none; }
.plans-list-page .sort-link:hover { color:inherit; text-decoration:underline; }
.plans-list-page .plan-task-table { width:100%; min-width:260px; border-collapse:collapse; font-size:12px; }
.plans-list-page .plan-task-table td { padding:4px 6px; border-bottom:1px solid #dee2e6; }
.plans-list-page .plan-task-table td:last-child { width:35%; white-space:nowrap; }
.plans-list-page .task-section + .task-section { margin-top:8px; }
.plans-list-page .task-section-toggle { display:flex; align-items:center; justify-content:space-between; width:100%; padding:7px 9px; border:1px solid #d7dce1; border-radius:5px; background:#f5f7f9; font-size:13px; font-weight:700; text-align:left; cursor:pointer; }
.plans-list-page .task-section-toggle:hover { background:#e9ecef; }
.plans-list-page .task-count { color:#6c757d; font-weight:400; }
.plans-list-page .task-chevron { margin-left:12px; transition:transform .2s ease; }
.plans-list-page .task-section-toggle[aria-expanded="true"] .task-chevron { transform:rotate(180deg); }
.plans-list-page .task-section-body { display:none; padding-top:6px; }
.plans-list-page .task-section-body.is-open { display:block; }
.plans-list-page .task-name { padding:0; border:0; background:none; color:#007bff; text-align:left; cursor:pointer; }
.plans-list-page .task-name:hover { text-decoration:underline; }
.plans-list-page .task-status { display:inline-block; padding:3px 7px; border:1px solid rgba(0,0,0,.12); border-radius:10px; color:#111; }
.plans-list-page .task-details-template { display:none; }
.plans-list-page .task-modal-backdrop { position:fixed; inset:0; z-index:9998; display:none; background:rgba(0,0,0,.45); }
.plans-list-page .task-modal { position:fixed; top:50%; left:50%; z-index:9999; display:none; width:min(700px,92vw); max-height:85vh; transform:translate(-50%,-50%); overflow:hidden; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.3); }
.plans-list-page .task-modal-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #dee2e6; }
.plans-list-page .task-modal-body { max-height:calc(85vh - 58px); padding:16px; overflow:auto; }
.plans-list-page .task-modal.is-employee-card { width:min(1100px,96vw); height:90vh; max-height:90vh; }
.plans-list-page .task-modal.is-employee-card .task-modal-body { height:calc(90vh - 58px); max-height:none; padding:0; overflow:hidden; }
.plans-list-page .employee-card-frame { display:block; width:100%; height:100%; border:0; background:#fff; }
.plans-list-page .task-modal-close { border:0; background:none; font-size:26px; line-height:1; cursor:pointer; }
.plans-list-page .task-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:14px; border:1px solid #dfe3e8; border-radius:8px; background:#f8f9fa; font-size:16px; }
.plans-list-page .task-card-head .task-status { flex:0 0 auto; font-size:12px; }
.plans-list-page .task-card-section { margin-top:14px; padding:14px; border:1px solid #e3e6e9; border-radius:8px; }
.plans-list-page .task-card-section h5 { margin:0 0 12px; padding-bottom:8px; border-bottom:1px solid #e9ecef; font-size:14px; font-weight:700; }
.plans-list-page .task-details-list { display:grid; grid-template-columns:minmax(190px,35%) 1fr; gap:9px 16px; margin:0; }
.plans-list-page .task-details-list dt, .plans-list-page .task-details-list dd { margin:0; white-space:pre-wrap; }
.plans-list-page .task-details-list dt { color:#6c757d; font-weight:500; }
.plans-list-page .task-details-list dd { font-weight:500; }
.plans-list-page .actions { min-width:190px; }
.plans-list-page .actions .btn { display:block; width:100%; margin-bottom:7px; }
.plans-list-page .pagination { margin-top:12px; display:flex; gap:6px; flex-wrap:wrap; }
.plans-list-page .pagination a, .plans-list-page .pagination span { padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; }
.plans-list-page .pagination .active { background:#007bff; border-color:#007bff; color:#fff; }
.plans-list-page tr.plan-attention > td { background:#fff3cd; }
.plans-list-page tr.plan-critical > td { background:#f8d7da; }
.plans-list-page .plan-notice { display:block; margin-top:6px; padding:5px 7px; border-radius:4px; background:rgba(255,255,255,.72); color:#721c24; font-size:12px; font-weight:600; line-height:1.35; }
.plans-list-page .pvd-document { min-width:90px; text-align:center; }
.plans-list-page .pvd-date { display:block; margin-bottom:4px; color:#6c757d; font-size:10px; line-height:1.2; }
.plans-list-page .pdf-link { display:inline-flex; align-items:center; justify-content:center; width:38px; height:42px; border-radius:4px; background:#c82333; color:#fff; font-size:11px; font-weight:700; text-decoration:none; box-shadow:0 1px 2px rgba(0,0,0,.2); }
.plans-list-page .pdf-link:hover { background:#a71d2a; color:#fff; text-decoration:none; }
</style>

<div class="container-fluid plans-list-page">
    <h2 class="mb-3">Планы ввода в должность</h2>
    <form method="get" class="card mb-3">
        <div class="filter-toolbar">
            <input type="hidden" name="sort" value="<?= h($sortField) ?>">
            <input type="hidden" name="order" value="<?= h($sortDirection) ?>">
            <div style="width:360px;max-width:100%;">
                <label class="mb-1" for="plans-search">Поиск по ФИО</label>
                <input id="plans-search" type="text" name="q" value="<?= h($search) ?>" class="form-control form-control-sm" placeholder="Введите ФИО">
            </div>
            <div style="min-width:220px;">
                <label class="mb-1" for="plans-manager">Руководитель</label>
                <select id="plans-manager" name="manager" class="form-control form-control-sm">
                    <option value="">Все руководители</option>
                    <?php foreach ($managerNames as $userId => $userName): ?>
                        <option value="<?= (int)$userId ?>"<?= (int)$userId === $managerFilter ? ' selected' : '' ?>><?= h($userName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:220px;">
                <label class="mb-1" for="plans-recruiter">Рекрутер</label>
                <select id="plans-recruiter" name="recruiter" class="form-control form-control-sm">
                    <option value="">Все рекрутеры</option>
                    <?php foreach ($recruiterNames as $userId => $userName): ?>
                        <option value="<?= (int)$userId ?>"<?= (int)$userId === $recruiterFilter ? ' selected' : '' ?>><?= h($userName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-check mb-1">
                <input id="plans-pvd-missing" type="checkbox" name="pvd_missing" value="Y" class="form-check-input"<?= $missingPvdFilter ? ' checked' : '' ?>>
                <label class="form-check-label" for="plans-pvd-missing">ПВД не заполнен</label>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Применить</button>
            <a href="<?= h(buildUrl([], ['q', 'manager', 'recruiter', 'pvd_missing', 'PAGEN_1'])) ?>" class="btn btn-secondary btn-sm">Сбросить</a>
        </div>
    </form>

    <div class="mb-2 text-muted">Найдено: <?= (int)$plansResult->NavRecordCount ?>, страница <?= (int)$plansResult->NavPageNomer ?> из <?= (int)$plansResult->NavPageCount ?></div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover">
            <thead class="thead-dark"><tr>
                <th class="fio-column"><a class="sort-link" href="<?= h(buildUrl(['sort' => 'name', 'order' => $nameSortOrder], ['PAGEN_1'])) ?>">ФИО<?= $sortField === 'name' ? ($sortDirection === 'ASC' ? ' ↑' : ' ↓') : '' ?></a></th>
                <th>Руководитель</th>
                <th><a class="sort-link" href="<?= h(buildUrl(['sort' => 'employment', 'order' => $employmentSortOrder], ['PAGEN_1'])) ?>">ИС<?= $sortField === 'employment' ? ($sortDirection === 'ASC' ? ' ↑' : ' ↓') : '' ?></a></th>
                <th>Рекрутер</th>
                <th>Задачи</th><th>ПВД</th><th>Действия</th>
            </tr></thead>
            <tbody>
            <?php if (!$plans): ?>
                <tr><td colspan="7" class="text-muted">Планы не найдены.</td></tr>
            <?php else: foreach ($plans as $plan): ?>
                <?php
                $planId = (int)$plan['ID'];
                $taskId = (int)$plan['BP_TASK_ID'];
                $reportUrl = '/forms/staff_recruitment/onboarding_plan_report.php?PLAN_ID=' . $planId;
                $rowClass = $plan['PVD_IS_MISSING'] ? 'plan-critical' : ($plan['PVD_REVIEW_IS_PENDING'] ? 'plan-attention' : '');
                ?>
                <tr class="<?= h($rowClass) ?>">
                    <td class="fio-column">
                        <?php if ((int)$plan['EMPLOYEE_CARD_ID'] > 0): ?>
                            <button type="button" class="task-name js-employee-card" data-url="<?= h(EMPLOYEE_CARD_VIEW_URL . (int)$plan['EMPLOYEE_CARD_ID']) ?>" data-name="<?= h($plan['NAME']) ?>"><?= h($plan['NAME']) ?></button>
                        <?php else: ?>
                            <?= h($plan['NAME']) ?>
                        <?php endif; ?>
                        <?php if ($plan['PVD_IS_MISSING']): ?>
                            <span class="plan-notice">ПВД не заполнен.</span>
                        <?php endif; ?>
                        <?php if ($plan['PVD_REVIEW_IS_PENDING']): ?>
                            <span class="plan-notice">С планом ввода в должность вам необходимо ознакомить сотрудника в первые 3 р.д. с даты выхода сотрудника.</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($userNames[(int)$plan['MANAGER_ID']] ?? '—') ?></td>
                    <td><?= h(($plan['PROPERTY_' . PROP_EMPLOYMENT_DATE . '_VALUE'] ?: '—') . '–' . ($plan['PROPERTY_' . PROP_TRIAL_END_DATE . '_VALUE'] ?: '—')) ?></td>
                    <td><?= h($userNames[(int)$plan['RECRUITER_ID']] ?? '—') ?></td>
                    <td>
                        <?= renderTaskTable($plan['PVD_TASKS'], 'pvd', 'Задачи ПВД') ?>
                        <?= renderTaskTable($plan['KPI_TASKS'], 'kpi', 'Задачи KPI') ?>
                    </td>
                    <td class="pvd-document">
                        <?php if ($plan['PVD_TASKS'] || $plan['KPI_TASKS']): ?>
                            <?php if ($plan['PVD_CREATED_AT'] !== ''): ?>
                                <small class="pvd-date">Дата формирования:<br><?= h($plan['PVD_CREATED_AT']) ?></small>
                            <?php endif; ?>
                            <a class="pdf-link" href="/pub/apps/plans/plan.php?id_plan=<?= $planId ?>" target="_blank" rel="noopener" title="Сформировать PDF плана ввода в должность" aria-label="Сформировать PDF плана ввода в должность">PDF</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="actions">
                        <?php if ($taskId > 0): ?>
                            <a class="btn btn-info btn-sm" href="<?= h(bizprocTaskUrl($taskId, $currentUserId)) ?>" target="_blank" rel="noopener">Перейти в задание</a>
                        <?php endif; ?>
                        <select class="form-control form-control-sm js-plan-action" aria-label="Действия с планом">
                            <option value="">Действия…</option>
                            <option value="<?= h($reportUrl) ?>">Посмотреть план</option>
                        </select>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ((int)$plansResult->NavPageCount > 1): ?>
        <nav class="pagination" aria-label="Страницы">
            <?php for ($page = 1; $page <= (int)$plansResult->NavPageCount; $page++): ?>
                <?php if ($page === (int)$plansResult->NavPageNomer): ?><span class="active"><?= $page ?></span>
                <?php else: ?><a href="<?= h(buildUrl(['PAGEN_1' => $page])) ?>"><?= $page ?></a><?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
    <div class="task-modal-backdrop js-task-modal-close"></div>
    <div class="task-modal" role="dialog" aria-modal="true" aria-labelledby="task-modal-title">
        <div class="task-modal-head">
            <strong id="task-modal-title">Описание задачи</strong>
            <button type="button" class="task-modal-close js-task-modal-close" aria-label="Закрыть">&times;</button>
        </div>
        <div class="task-modal-body"></div>
    </div>
</div>
<script>
document.addEventListener('change', function (event) {
    if (event.target.classList.contains('js-plan-action') && event.target.value) {
        window.location.href = event.target.value;
    }
});
(function () {
    var modal = document.querySelector('.task-modal');
    var backdrop = document.querySelector('.task-modal-backdrop');
    var body = modal.querySelector('.task-modal-body');
    var title = modal.querySelector('#task-modal-title');

    function closeModal() {
        modal.style.display = 'none';
        backdrop.style.display = 'none';
        modal.classList.remove('is-employee-card');
        body.innerHTML = '';
    }

    document.addEventListener('click', function (event) {
        var sectionToggle = event.target.closest('.js-task-section-toggle');
        if (sectionToggle) {
            var sectionBody = document.getElementById(sectionToggle.getAttribute('aria-controls'));
            var willOpen = sectionToggle.getAttribute('aria-expanded') !== 'true';
            sectionToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (sectionBody) {
                sectionBody.classList.toggle('is-open', willOpen);
            }
        }

        var trigger = event.target.closest('.js-task-details');
        if (trigger) {
            var template = document.getElementById(trigger.getAttribute('data-template'));
            if (template) {
                title.textContent = 'Описание задачи: ' + trigger.textContent.trim();
                body.innerHTML = template.innerHTML;
                backdrop.style.display = 'block';
                modal.style.display = 'block';
            }
        }
        var employeeTrigger = event.target.closest('.js-employee-card');
        if (employeeTrigger) {
            modal.classList.add('is-employee-card');
            title.textContent = 'Карточка сотрудника: ' + employeeTrigger.getAttribute('data-name');
            var frame = document.createElement('iframe');
            frame.className = 'employee-card-frame';
            frame.src = employeeTrigger.getAttribute('data-url');
            frame.title = title.textContent;
            body.innerHTML = '';
            body.appendChild(frame);
            backdrop.style.display = 'block';
            modal.style.display = 'block';
        }
        if (event.target.closest('.js-task-modal-close')) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
}());
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
