<?php
/**
 * Задачи, связанные с планом ввода в должность.
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Context;
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Задачи плана ввода в должность');

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

const TASKS_PLAN_IBLOCK_ID = 359;
const TASKS_PVD_IBLOCK_ID = 360;
const TASKS_STATUS_IBLOCK_ID = 361;
const TASKS_KPI_IBLOCK_ID = 363;
const TASKS_PROP_MANAGER = 2775;
const TASKS_PROP_EMPLOYMENT_DATE = 2776;
const TASKS_PROP_TRIAL_END_DATE = 2802;
const TASKS_PROP_PVD = 2761;
const TASKS_PROP_KPI = 2769;
const TASKS_PROP_PVD_STATUS = 2767;
const TASKS_PROP_KPI_STATUS = 2805;
const TASKS_PROP_STATUS_COLOR = 3168;

function tasksH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tasksUserId($value)
{
    if (is_array($value)) {
        $value = reset($value);
    }
    return preg_match('/(\d+)/', (string)$value, $matches) ? (int)$matches[1] : 0;
}

function tasksUserName($userId)
{
    $user = CUser::GetByID((int)$userId)->Fetch();
    if (!$user) {
        return '—';
    }
    $name = trim((string)CUser::FormatName(CSite::GetNameFormat(false), $user, true, false));
    return $name !== '' ? $name : (string)$user['LOGIN'];
}

function tasksLinkedIds($planId, $propertyId)
{
    $result = [];
    $properties = CIBlockElement::GetProperty(
        TASKS_PLAN_IBLOCK_ID,
        (int)$planId,
        ['sort' => 'asc', 'id' => 'asc'],
        ['ID' => (int)$propertyId]
    );
    while ($property = $properties->Fetch()) {
        $id = (int)($property['VALUE'] ?? 0);
        if ($id > 0) {
            $result[$id] = $id;
        }
    }
    return array_values($result);
}

function tasksDocumentIds($elementId, $iblockId)
{
    return [
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
        ['lists', 'BizprocDocument', 'lists_' . (int)$iblockId . '_' . (int)$elementId],
        ['lists', 'lists_' . (int)$iblockId . '_group_206', (int)$elementId],
        ['lists', 'lists_' . (int)$iblockId, (int)$elementId],
        ['iblock', 'CIBlockDocument', 'iblock_' . (int)$iblockId . '_' . (int)$elementId],
    ];
}

function tasksWorkflowInfo($elementId, $iblockId, $currentUserId)
{
    $userIds = [];
    $taskId = 0;
    $currentUserTaskId = 0;
    foreach (tasksDocumentIds($elementId, $iblockId) as $documentId) {
        $tasks = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            ['DOCUMENT_ID' => $documentId, 'STATUS' => CBPTaskStatus::Running],
            false,
            false,
            ['ID', 'USER_ID']
        );
        while ($task = $tasks->Fetch()) {
            if ($taskId === 0) {
                $taskId = (int)$task['ID'];
            }
            $userId = (int)($task['USER_ID'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
            if ($userId === (int)$currentUserId && $currentUserTaskId === 0) {
                $currentUserTaskId = (int)$task['ID'];
            }
        }
    }
    $names = [];
    foreach ($userIds as $userId) {
        $names[] = tasksUserName($userId);
    }
    return [
        'EXECUTORS' => array_values(array_unique($names)),
        'TASK_ID' => $taskId,
        'CURRENT_USER_TASK_ID' => $currentUserTaskId,
    ];
}

function tasksBizprocUrl($taskId, $userId)
{
    if (method_exists('CBPTaskService', 'GetTaskUrl')) {
        return (string)CBPTaskService::GetTaskUrl((int)$taskId, (int)$userId);
    }
    if (method_exists('CBPTaskService', 'GetTaskURL')) {
        return (string)CBPTaskService::GetTaskURL((int)$taskId, (int)$userId);
    }
    return '/company/personal/bizproc/' . (int)$taskId . '/';
}

function tasksLoadRows(array $ids, $iblockId, $statusPropertyId, array $fields, $currentUserId)
{
    if (!$ids) {
        return [];
    }
    $select = ['ID', 'NAME', 'PROPERTY_' . (int)$statusPropertyId, 'PROPERTY_OTVETSTVENNYY'];
    foreach ($fields as $propertyId => $label) {
        $select[] = 'PROPERTY_' . (int)$propertyId;
    }
    $result = [];
    $elements = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => (int)$iblockId, 'ID' => $ids, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
        false,
        false,
        $select
    );
    while ($element = $elements->Fetch()) {
        $statusId = (int)($element['PROPERTY_' . (int)$statusPropertyId . '_VALUE'] ?? 0);
        $statusName = '—';
        $statusColor = '#ffffff';
        if ($statusId > 0) {
            $status = CIBlockElement::GetList([], ['IBLOCK_ID' => TASKS_STATUS_IBLOCK_ID, 'ID' => $statusId], false, ['nTopCount' => 1], ['NAME', 'PROPERTY_' . TASKS_PROP_STATUS_COLOR])->Fetch();
            if ($status) {
                $statusName = (string)$status['NAME'];
                $color = trim((string)($status['PROPERTY_' . TASKS_PROP_STATUS_COLOR . '_VALUE'] ?? ''));
                if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
                    $statusColor = $color;
                }
            }
        }
        $values = [];
        foreach ($fields as $propertyId => $label) {
            $values[$label] = (string)($element['PROPERTY_' . (int)$propertyId . '_VALUE'] ?? '');
        }
        $workflow = tasksWorkflowInfo((int)$element['ID'], (int)$iblockId, (int)$currentUserId);
        $result[] = [
            'ID' => (int)$element['ID'],
            'NAME' => (string)$element['NAME'],
            'STATUS' => $statusName,
            'STATUS_COLOR' => $statusColor,
            'VALUES' => $values,
            'EXECUTORS' => $workflow['EXECUTORS'],
            'TASK_ID' => (int)$workflow['TASK_ID'],
            'CURRENT_USER_TASK_ID' => (int)$workflow['CURRENT_USER_TASK_ID'],
            'RESPONSIBLE' => tasksUserName(tasksUserId($element['PROPERTY_OTVETSTVENNYY_VALUE'] ?? '')),
            'IBLOCK_ID' => (int)$iblockId,
        ];
    }
    return $result;
}

function tasksRenderTable(array $rows, array $fields, $title, $currentUserId)
{
    echo '<h3>' . tasksH($title) . '</h3>';
    if (!$rows) {
        echo '<div class="alert alert-light border">Нет задач.</div>';
        return;
    }
    echo '<div class="table-responsive"><table class="table table-sm table-bordered task-table"><thead class="thead-light"><tr>';
    echo '<th>ID</th><th>Название</th><th>Статус</th><th>Ответственный</th>';
    foreach ($fields as $label) {
        echo '<th>' . tasksH($label) . '</th>';
    }
    echo '<th>Текущий исполнитель</th><th>Действия</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $hasRunningTask = (int)$row['TASK_ID'] > 0;
        $needsUserAction = (int)$row['CURRENT_USER_TASK_ID'] > 0;
        echo '<tr' . ($hasRunningTask ? ' class="task-needs-action"' : '') . '>';
        echo '<td><a href="/workgroups/group/206/lists/' . (int)$row['IBLOCK_ID'] . '/element/0/' . (int)$row['ID'] . '/" target="_blank" rel="noopener">' . (int)$row['ID'] . '</a></td>';
        echo '<td><strong>' . tasksH($row['NAME']) . '</strong>' . ($hasRunningTask ? '<span class="action-note">' . ($needsUserAction ? 'Требуется ваше действие' : 'Требуется действие') . '</span>' : '') . '</td>';
        echo '<td><span class="status-pill" style="background-color:' . tasksH($row['STATUS_COLOR']) . '">' . tasksH($row['STATUS']) . '</span></td>';
        echo '<td>' . tasksH($row['RESPONSIBLE']) . '</td>';
        foreach (array_keys($fields) as $propertyId) {
            $value = trim((string)$row['VALUES'][$fields[$propertyId]]);
            echo '<td>' . tasksH($value !== '' ? $value : '—') . '</td>';
        }
        echo '<td>' . tasksH($row['EXECUTORS'] ? implode(', ', $row['EXECUTORS']) : '—') . '</td><td class="task-actions">';
        if ($hasRunningTask) {
            echo '<a class="btn btn-info btn-sm" href="' . tasksH(tasksBizprocUrl($row['TASK_ID'], $currentUserId)) . '" target="_blank" rel="noopener">Перейти в задание</a>';
        } else {
            echo '<span class="text-muted">Нет доступных действий</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

$planId = max(0, (int)Context::getCurrent()->getRequest()->get('PLAN_ID'));
$plan = $planId > 0 ? CIBlockElement::GetList(
    [],
    ['IBLOCK_ID' => TASKS_PLAN_IBLOCK_ID, 'ID' => $planId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
    false,
    ['nTopCount' => 1],
    ['ID', 'NAME', 'PROPERTY_' . TASKS_PROP_MANAGER, 'PROPERTY_' . TASKS_PROP_EMPLOYMENT_DATE, 'PROPERTY_' . TASKS_PROP_TRIAL_END_DATE]
)->Fetch() : false;

if (!$plan) {
    ShowError($planId > 0 ? 'План не найден или недоступен.' : 'Не указан план ввода в должность.');
    echo '<p><a class="ui-btn ui-btn-light-border" href="/forms/staff_recruitment/plans/list.php">Вернуться к списку планов</a></p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$currentUserId = (int)$USER->GetID();
$pvdFields = [
    2836 => 'Требуется контроль',
    2837 => 'Дата постановки',
    2760 => 'Планируемый результат',
    2762 => 'Фактический результат',
    2807 => 'Планируемый срок',
    2806 => 'Фактический срок',
];
$kpiFields = [
    2785 => 'Планируемый результат',
    2791 => 'Фактический результат',
    2784 => 'Планируемый срок',
    2789 => 'Фактический срок',
    2803 => 'Вес (%)',
    2804 => 'Выполнение (%)',
];
$pvdRows = tasksLoadRows(tasksLinkedIds($planId, TASKS_PROP_PVD), TASKS_PVD_IBLOCK_ID, TASKS_PROP_PVD_STATUS, $pvdFields, $currentUserId);
$kpiRows = tasksLoadRows(tasksLinkedIds($planId, TASKS_PROP_KPI), TASKS_KPI_IBLOCK_ID, TASKS_PROP_KPI_STATUS, $kpiFields, $currentUserId);
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.plan-tasks-page { padding:16px 24px; }
.plan-tasks-page .plan-summary { max-width:900px; margin-bottom:24px; }
.plan-tasks-page .task-table { font-size:13px; }
.plan-tasks-page .task-table th { white-space:nowrap; }
.plan-tasks-page .task-table td { min-width:110px; vertical-align:top; white-space:pre-wrap; }
.plan-tasks-page .task-table td:nth-child(2) { min-width:220px; }
.plan-tasks-page .task-needs-action > td { background:#fff3cd; }
.plan-tasks-page .action-note { display:block; margin-top:5px; color:#856404; font-size:12px; font-weight:700; }
.plan-tasks-page .status-pill { display:inline-block; padding:4px 8px; border:1px solid rgba(0,0,0,.15); border-radius:12px; white-space:nowrap; }
.plan-tasks-page .task-actions { min-width:170px !important; }
</style>
<div class="container-fluid plan-tasks-page">
    <p><a href="/forms/staff_recruitment/plans/list.php">&larr; Вернуться к списку планов</a></p>
    <h2><?= tasksH($plan['NAME']) ?></h2>
    <table class="table table-sm table-bordered plan-summary">
        <tr><th>Руководитель</th><td><?= tasksH(tasksUserName(tasksUserId($plan['PROPERTY_' . TASKS_PROP_MANAGER . '_VALUE'] ?? ''))) ?></td></tr>
        <tr><th>Испытательный срок</th><td><?= tasksH(($plan['PROPERTY_' . TASKS_PROP_EMPLOYMENT_DATE . '_VALUE'] ?: '—') . '–' . ($plan['PROPERTY_' . TASKS_PROP_TRIAL_END_DATE . '_VALUE'] ?: '—')) ?></td></tr>
        <tr><th>Всего задач</th><td><?= count($pvdRows) + count($kpiRows) ?></td></tr>
    </table>
    <?php tasksRenderTable($pvdRows, $pvdFields, 'Задачи ПВД', $currentUserId); ?>
    <?php tasksRenderTable($kpiRows, $kpiFields, 'Задачи KPI', $currentUserId); ?>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
