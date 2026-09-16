<?php
/**
 * Задачи, связанные с планом ввода в должность.
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Context;
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Задачи плана ввода в должность');

if (!Loader::includeModule('iblock') || !Loader::includeModule('bizproc') || !Loader::includeModule('intranet')) {
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
const TASKS_PROP_RECRUITER = 2796;
const TASKS_PROP_EMPLOYMENT_DATE = 2776;
const TASKS_PROP_TRIAL_END_DATE = 2802;
const TASKS_PROP_PVD = 2761;
const TASKS_PROP_KPI = 2769;
const TASKS_PROP_PVD_STATUS = 2767;
const TASKS_PROP_KPI_STATUS = 2805;
const TASKS_PROP_STATUS_COLOR = 3168;
const TASKS_PROP_PVD_RESPONSIBLE = 2827;
const TASKS_PROP_KPI_RESPONSIBLE = 2828;
const TASKS_ALLOWED_REASSIGN_STATUS_IDS = [3396791, 3507933, 3347533, 3365494];

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

function tasksSubordinateUsers(array $headIds)
{
    $users = [];
    foreach (array_unique(array_filter(array_map('intval', $headIds))) as $headId) {
        $employees = CIntranetUtils::GetSubordinateEmployees($headId, true);
        if (!$employees) {
            continue;
        }
        while ($employee = $employees->Fetch()) {
            $userId = (int)($employee['ID'] ?? 0);
            if ($userId > 0 && (string)($employee['ACTIVE'] ?? 'Y') !== 'N') {
                $users[$userId] = tasksUserName($userId);
            }
        }
    }
    asort($users, SORT_NATURAL | SORT_FLAG_CASE);
    return $users;
}

function tasksIsAdministrator($userId)
{
    return in_array(1, array_map('intval', CUser::GetUserGroup((int)$userId)), true);
}

function tasksCanReassign($userId, $managerId, $recruiterId, $responsibleId)
{
    return tasksIsAdministrator($userId)
        || (int)$userId === (int)$managerId
        || (int)$userId === (int)$recruiterId
        || (int)$userId === (int)$responsibleId;
}

function tasksDelegateRunningAssignments($elementId, $iblockId, $fromUserId, $toUserId)
{
    if ($fromUserId <= 0 || $fromUserId === $toUserId) {
        return;
    }
    $handled = [];
    foreach (tasksDocumentIds($elementId, $iblockId) as $documentId) {
        $tasks = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            ['DOCUMENT_ID' => $documentId, 'USER_ID' => (int)$fromUserId, 'STATUS' => CBPTaskStatus::Running],
            false,
            false,
            ['ID']
        );
        while ($task = $tasks->Fetch()) {
            $taskId = (int)$task['ID'];
            if ($taskId > 0 && empty($handled[$taskId])) {
                CBPTaskService::DelegateTask($taskId, (int)$fromUserId, (int)$toUserId);
                $handled[$taskId] = true;
            }
        }
    }
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

function tasksLoadRows(array $ids, $iblockId, $statusPropertyId, $responsiblePropertyId, array $fields, $currentUserId, $managerId, $recruiterId)
{
    if (!$ids) {
        return [];
    }
    $select = ['ID', 'NAME', 'PROPERTY_' . (int)$statusPropertyId, 'PROPERTY_' . (int)$responsiblePropertyId];
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
        $responsibleId = tasksUserId($element['PROPERTY_' . (int)$responsiblePropertyId . '_VALUE'] ?? '');
        $canReassign = in_array($statusId, TASKS_ALLOWED_REASSIGN_STATUS_IDS, true)
            && tasksCanReassign($currentUserId, $managerId, $recruiterId, $responsibleId);
        $result[] = [
            'ID' => (int)$element['ID'],
            'NAME' => (string)$element['NAME'],
            'STATUS' => $statusName,
            'STATUS_COLOR' => $statusColor,
            'VALUES' => $values,
            'EXECUTORS' => $workflow['EXECUTORS'],
            'TASK_ID' => (int)$workflow['TASK_ID'],
            'CURRENT_USER_TASK_ID' => (int)$workflow['CURRENT_USER_TASK_ID'],
            'RESPONSIBLE_ID' => $responsibleId,
            'RESPONSIBLE' => tasksUserName($responsibleId),
            'CAN_REASSIGN' => $canReassign,
            'REASSIGN_USERS' => $canReassign ? tasksSubordinateUsers([$managerId, $responsibleId]) : [],
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
        $needsUserAction = (int)$row['CURRENT_USER_TASK_ID'] > 0;
        echo '<tr' . ($needsUserAction ? ' class="task-needs-action"' : '') . '>';
        echo '<td><a href="/workgroups/group/206/lists/' . (int)$row['IBLOCK_ID'] . '/element/0/' . (int)$row['ID'] . '/" target="_blank" rel="noopener">' . (int)$row['ID'] . '</a></td>';
        echo '<td><strong>' . tasksH($row['NAME']) . '</strong>' . ($needsUserAction ? '<span class="action-note">Требуется ваше действие</span>' : '') . '</td>';
        echo '<td><span class="status-pill" style="background-color:' . tasksH($row['STATUS_COLOR']) . '">' . tasksH($row['STATUS']) . '</span></td>';
        echo '<td>' . tasksH($row['RESPONSIBLE']) . '</td>';
        foreach (array_keys($fields) as $propertyId) {
            $value = trim((string)$row['VALUES'][$fields[$propertyId]]);
            echo '<td>' . tasksH($value !== '' ? $value : '—') . '</td>';
        }
        echo '<td>' . tasksH($row['EXECUTORS'] ? implode(', ', $row['EXECUTORS']) : '—') . '</td><td class="task-actions">';
        if ($needsUserAction) {
            echo '<a class="btn btn-info btn-sm" href="' . tasksH(tasksBizprocUrl($row['CURRENT_USER_TASK_ID'], $currentUserId)) . '" target="_blank" rel="noopener">Перейти в задание</a>';
        }
        if (!empty($row['CAN_REASSIGN'])) {
            $usersJson = base64_encode(json_encode($row['REASSIGN_USERS'], JSON_UNESCAPED_UNICODE));
            echo '<button type="button" class="btn btn-outline-primary btn-sm js-reassign-task" data-task-id="' . (int)$row['ID'] . '" data-iblock-id="' . (int)$row['IBLOCK_ID'] . '" data-task-name="' . tasksH($row['NAME']) . '" data-users="' . tasksH($usersJson) . '">Сменить ответственного</button>';
        }
        if (!$needsUserAction && empty($row['CAN_REASSIGN'])) {
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
    ['ID', 'NAME', 'PROPERTY_' . TASKS_PROP_MANAGER, 'PROPERTY_' . TASKS_PROP_RECRUITER,
        'PROPERTY_' . TASKS_PROP_EMPLOYMENT_DATE, 'PROPERTY_' . TASKS_PROP_TRIAL_END_DATE]
)->Fetch() : false;

if (!$plan) {
    ShowError($planId > 0 ? 'План не найден или недоступен.' : 'Не указан план ввода в должность.');
    echo '<p><a class="ui-btn ui-btn-light-border" href="/forms/staff_recruitment/plans/list.php">Вернуться к списку планов</a></p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$currentUserId = (int)$USER->GetID();
$managerId = tasksUserId($plan['PROPERTY_' . TASKS_PROP_MANAGER . '_VALUE'] ?? '');
$recruiterId = tasksUserId($plan['PROPERTY_' . TASKS_PROP_RECRUITER . '_VALUE'] ?? '');
$request = Context::getCurrent()->getRequest();
$actionResult = (string)$request->get('reassign_result');

if ($request->isPost() && (string)$request->getPost('action') === 'reassign_task') {
    $taskId = (int)$request->getPost('task_id');
    $iblockId = (int)$request->getPost('iblock_id');
    $newResponsibleId = (int)$request->getPost('new_responsible_id');
    $result = 'error';
    if (check_bitrix_sessid()
        && in_array($iblockId, [TASKS_PVD_IBLOCK_ID, TASKS_KPI_IBLOCK_ID], true)
        && $newResponsibleId > 0
    ) {
        $linkedIds = $iblockId === TASKS_PVD_IBLOCK_ID
            ? tasksLinkedIds($planId, TASKS_PROP_PVD)
            : tasksLinkedIds($planId, TASKS_PROP_KPI);
        $statusPropertyId = $iblockId === TASKS_PVD_IBLOCK_ID ? TASKS_PROP_PVD_STATUS : TASKS_PROP_KPI_STATUS;
        $responsiblePropertyId = $iblockId === TASKS_PVD_IBLOCK_ID ? TASKS_PROP_PVD_RESPONSIBLE : TASKS_PROP_KPI_RESPONSIBLE;
        $task = in_array($taskId, $linkedIds, true) ? CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $taskId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
            false,
            ['nTopCount' => 1],
            ['ID', 'PROPERTY_' . $statusPropertyId, 'PROPERTY_' . $responsiblePropertyId]
        )->Fetch() : false;
        if ($task) {
            $statusId = (int)($task['PROPERTY_' . $statusPropertyId . '_VALUE'] ?? 0);
            $oldResponsibleId = tasksUserId($task['PROPERTY_' . $responsiblePropertyId . '_VALUE'] ?? '');
            $allowedUsers = tasksSubordinateUsers([$managerId, $oldResponsibleId]);
            if (in_array($statusId, TASKS_ALLOWED_REASSIGN_STATUS_IDS, true)
                && tasksCanReassign($currentUserId, $managerId, $recruiterId, $oldResponsibleId)
                && isset($allowedUsers[$newResponsibleId])
            ) {
                try {
                    CIBlockElement::SetPropertyValuesEx($taskId, $iblockId, [$responsiblePropertyId => $newResponsibleId]);
                    tasksDelegateRunningAssignments($taskId, $iblockId, $oldResponsibleId, $newResponsibleId);
                    $result = 'success';
                } catch (Throwable $exception) {
                    $result = 'error';
                }
            } else {
                $result = 'forbidden';
            }
        }
    }
    LocalRedirect('/forms/staff_recruitment/plans/tasks.php?PLAN_ID=' . $planId . '&reassign_result=' . $result);
}

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
$pvdRows = tasksLoadRows(tasksLinkedIds($planId, TASKS_PROP_PVD), TASKS_PVD_IBLOCK_ID, TASKS_PROP_PVD_STATUS, TASKS_PROP_PVD_RESPONSIBLE, $pvdFields, $currentUserId, $managerId, $recruiterId);
$kpiRows = tasksLoadRows(tasksLinkedIds($planId, TASKS_PROP_KPI), TASKS_KPI_IBLOCK_ID, TASKS_PROP_KPI_STATUS, TASKS_PROP_KPI_RESPONSIBLE, $kpiFields, $currentUserId, $managerId, $recruiterId);
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
.plan-tasks-page .task-actions .btn { display:block; width:100%; margin-bottom:6px; }
.reassign-backdrop { position:fixed; inset:0; z-index:10000; display:none; background:rgba(0,0,0,.45); }
.reassign-modal { position:fixed; top:50%; left:50%; z-index:10001; display:none; width:min(520px,92vw); transform:translate(-50%,-50%); padding:20px; border-radius:8px; background:#fff; box-shadow:0 12px 35px rgba(0,0,0,.3); }
.reassign-modal-actions { display:flex; gap:8px; margin-top:18px; }
</style>
<div class="container-fluid plan-tasks-page">
    <p><a href="/forms/staff_recruitment/plans/list.php">&larr; Вернуться к списку планов</a></p>
    <h2><?= tasksH($plan['NAME']) ?></h2>
    <?php if ($actionResult === 'success'): ?><div class="alert alert-success">Ответственный изменен, активное задание бизнес-процесса передано новому сотруднику.</div><?php endif; ?>
    <?php if ($actionResult === 'forbidden'): ?><div class="alert alert-danger">Недостаточно прав, недопустимый статус задачи или выбранный сотрудник.</div><?php endif; ?>
    <?php if ($actionResult === 'error'): ?><div class="alert alert-danger">Не удалось сменить ответственного за задачу.</div><?php endif; ?>
    <table class="table table-sm table-bordered plan-summary">
        <tr><th>Руководитель</th><td><?= tasksH(tasksUserName(tasksUserId($plan['PROPERTY_' . TASKS_PROP_MANAGER . '_VALUE'] ?? ''))) ?></td></tr>
        <tr><th>Испытательный срок</th><td><?= tasksH(($plan['PROPERTY_' . TASKS_PROP_EMPLOYMENT_DATE . '_VALUE'] ?: '—') . '–' . ($plan['PROPERTY_' . TASKS_PROP_TRIAL_END_DATE . '_VALUE'] ?: '—')) ?></td></tr>
        <tr><th>Всего задач</th><td><?= count($pvdRows) + count($kpiRows) ?></td></tr>
    </table>
    <?php tasksRenderTable($pvdRows, $pvdFields, 'Задачи ПВД', $currentUserId); ?>
    <?php tasksRenderTable($kpiRows, $kpiFields, 'Задачи KPI', $currentUserId); ?>
</div>
<div class="reassign-backdrop js-reassign-close"></div>
<div class="reassign-modal" role="dialog" aria-modal="true" aria-labelledby="reassign-title">
    <h4 id="reassign-title">Сменить ответственного за задачу</h4>
    <p class="text-muted" id="reassign-task-name"></p>
    <form method="post" id="reassign-form">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="reassign_task">
        <input type="hidden" name="task_id" id="reassign-task-id" value="">
        <input type="hidden" name="iblock_id" id="reassign-iblock-id" value="">
        <div class="form-group">
            <label for="reassign-user">Новый ответственный</label>
            <select class="form-control" name="new_responsible_id" id="reassign-user" required></select>
            <small class="form-text text-muted">Доступны подчиненные руководителя плана и текущего ответственного.</small>
        </div>
        <div class="reassign-modal-actions">
            <button type="submit" class="btn btn-primary">Передать задачу</button>
            <button type="button" class="btn btn-secondary js-reassign-close">Отмена</button>
        </div>
    </form>
</div>
<script>
(function () {
    var modal = document.querySelector('.reassign-modal');
    var backdrop = document.querySelector('.reassign-backdrop');
    var userSelect = document.getElementById('reassign-user');
    function closeModal() {
        modal.style.display = 'none';
        backdrop.style.display = 'none';
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-reassign-task');
        if (button) {
            var binary = window.atob(button.getAttribute('data-users') || 'e30=');
            var bytes = Uint8Array.from(binary, function (character) { return character.charCodeAt(0); });
            var users = JSON.parse(new TextDecoder('utf-8').decode(bytes));
            userSelect.innerHTML = '<option value="">Выберите сотрудника</option>';
            Object.keys(users).forEach(function (userId) {
                var option = document.createElement('option');
                option.value = userId;
                option.textContent = users[userId];
                userSelect.appendChild(option);
            });
            document.getElementById('reassign-task-id').value = button.getAttribute('data-task-id');
            document.getElementById('reassign-iblock-id').value = button.getAttribute('data-iblock-id');
            document.getElementById('reassign-task-name').textContent = button.getAttribute('data-task-name');
            modal.style.display = 'block';
            backdrop.style.display = 'block';
        }
        if (event.target.closest('.js-reassign-close')) {
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
