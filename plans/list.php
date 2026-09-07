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
const PROP_KPI_STATUS = 2805;
const PROP_STATUS_COLOR = 3168;
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

function loadTasks(array $ids, $iblockId, $statusPropertyId, array $detailFields)
{
    $result = [];
    if (!$ids) {
        return $result;
    }

    $select = ['ID', 'NAME', 'PROPERTY_' . (int)$statusPropertyId];
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
            'STATUS_COLOR' => $statusColor,
            'DETAILS' => $details,
        ];
    }
    return $result;
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
    $html = '<section class="task-section"><h4>' . h($title) . '</h4>';
    if (!$tasks) {
        return $html . '<span class="text-muted">Нет задач</span></section>';
    }

    $html .= '<table class="plan-task-table"><tbody>';
    foreach ($tasks as $task) {
        $templateId = 'task-details-' . preg_replace('/[^a-z0-9_-]/i', '', (string)$type) . '-' . (int)$task['ID'];
        $html .= '<tr><td><button type="button" class="task-name js-task-details" data-template="' . h($templateId) . '">' . h($task['NAME']) . '</button></td>';
        $html .= '<td><span class="task-status" style="background-color:' . h($task['STATUS_COLOR']) . '">' . h($task['STATUS'] !== '' ? $task['STATUS'] : '—') . '</span></td></tr>';
        $html .= '<tr class="task-details-template"><td colspan="2"><div id="' . h($templateId) . '"><dl class="task-details-list">';
        foreach ($task['DETAILS'] as $label => $value) {
            $html .= '<dt>' . h($label) . '</dt><dd>' . h(trim((string)$value) !== '' ? $value : '—') . '</dd>';
        }
        $html .= '</dl></div></td></tr>';
    }
    return $html . '</tbody></table></section>';
}
$request = Context::getCurrent()->getRequest();
$search = trim((string)$request->get('q'));
$filter = ['IBLOCK_ID' => PLAN_IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'];
if ($search !== '') {
    $filter['%NAME'] = $search;
}

$plansResult = CIBlockElement::GetList(
    ['ID' => 'DESC'],
    $filter,
    false,
    ['nPageSize' => PAGE_SIZE, 'bShowAll' => false],
    ['ID', 'NAME', 'PROPERTY_' . PROP_MANAGER, 'PROPERTY_' . PROP_EMPLOYMENT_DATE,
        'PROPERTY_' . PROP_TRIAL_END_DATE, 'PROPERTY_' . PROP_RECRUITER]
);

$plans = [];
$userIds = [];
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
    ]);
    $plan['KPI_TASKS'] = loadTasks($kpiIds, KPI_TASK_IBLOCK_ID, PROP_KPI_STATUS, [
        2785 => 'Планируемый результат',
        2791 => 'Фактический результат',
        2784 => 'Планируемый срок',
        2789 => 'Фактический срок',
        2803 => 'Вес (%)',
        2804 => 'Процент выполнения (%)',
    ]);
    $plan['BP_TASK_ID'] = currentPlanTaskId((int)$plan['ID'], $currentUserId);
    $plans[] = $plan;
}
$userNames = loadUserNames($userIds);
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.plans-list-page { padding:16px 24px; }
.plans-list-page .table > thead > tr > th { white-space:nowrap; vertical-align:middle; }
.plans-list-page .table > tbody > tr > td { vertical-align:top; }
.plans-list-page .filter-toolbar { display:flex; align-items:flex-end; gap:12px; padding:12px 14px; }
.plans-list-page .plan-task-table { width:100%; min-width:260px; border-collapse:collapse; font-size:12px; }
.plans-list-page .plan-task-table td { padding:4px 6px; border-bottom:1px solid #dee2e6; }
.plans-list-page .plan-task-table td:last-child { width:35%; white-space:nowrap; }
.plans-list-page .task-section + .task-section { margin-top:14px; }
.plans-list-page .task-section h4 { margin:0 0 6px; font-size:13px; font-weight:700; }
.plans-list-page .task-name { padding:0; border:0; background:none; color:#007bff; text-align:left; cursor:pointer; }
.plans-list-page .task-name:hover { text-decoration:underline; }
.plans-list-page .task-status { display:inline-block; padding:3px 7px; border:1px solid rgba(0,0,0,.12); border-radius:10px; color:#111; }
.plans-list-page .task-details-template { display:none; }
.plans-list-page .task-modal-backdrop { position:fixed; inset:0; z-index:9998; display:none; background:rgba(0,0,0,.45); }
.plans-list-page .task-modal { position:fixed; top:50%; left:50%; z-index:9999; display:none; width:min(700px,92vw); max-height:85vh; transform:translate(-50%,-50%); overflow:hidden; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.3); }
.plans-list-page .task-modal-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #dee2e6; }
.plans-list-page .task-modal-body { max-height:calc(85vh - 58px); padding:16px; overflow:auto; }
.plans-list-page .task-modal-close { border:0; background:none; font-size:26px; line-height:1; cursor:pointer; }
.plans-list-page .task-details-list { display:grid; grid-template-columns:minmax(190px,35%) 1fr; gap:8px 14px; margin:0; }
.plans-list-page .task-details-list dt, .plans-list-page .task-details-list dd { margin:0; white-space:pre-wrap; }
.plans-list-page .actions { min-width:190px; }
.plans-list-page .actions .btn { display:block; width:100%; margin-bottom:7px; }
.plans-list-page .pagination { margin-top:12px; display:flex; gap:6px; flex-wrap:wrap; }
.plans-list-page .pagination a, .plans-list-page .pagination span { padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; }
.plans-list-page .pagination .active { background:#007bff; border-color:#007bff; color:#fff; }
</style>

<div class="container-fluid plans-list-page">
    <h2 class="mb-3">Планы ввода в должность</h2>
    <form method="get" class="card mb-3">
        <div class="filter-toolbar">
            <div style="width:360px;max-width:100%;">
                <label class="mb-1" for="plans-search">Поиск по ФИО</label>
                <input id="plans-search" type="text" name="q" value="<?= h($search) ?>" class="form-control form-control-sm" placeholder="Введите ФИО">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Применить</button>
            <a href="<?= h(buildUrl([], ['q', 'PAGEN_1'])) ?>" class="btn btn-secondary btn-sm">Сбросить</a>
        </div>
    </form>

    <div class="mb-2 text-muted">Найдено: <?= (int)$plansResult->NavRecordCount ?>, страница <?= (int)$plansResult->NavPageNomer ?> из <?= (int)$plansResult->NavPageCount ?></div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover">
            <thead class="thead-dark"><tr>
                <th>ФИО</th><th>Руководитель</th><th>ИС</th><th>Рекрутер</th>
                <th>Задачи</th><th>Действия</th>
            </tr></thead>
            <tbody>
            <?php if (!$plans): ?>
                <tr><td colspan="6" class="text-muted">Планы не найдены.</td></tr>
            <?php else: foreach ($plans as $plan): ?>
                <?php
                $planId = (int)$plan['ID'];
                $taskId = (int)$plan['BP_TASK_ID'];
                $reportUrl = '/forms/staff_recruitment/onboarding_plan_report.php?PLAN_ID=' . $planId;
                ?>
                <tr>
                    <td><?= h($plan['NAME']) ?></td>
                    <td><?= h($userNames[(int)$plan['MANAGER_ID']] ?? '—') ?></td>
                    <td><?= h(($plan['PROPERTY_' . PROP_EMPLOYMENT_DATE . '_VALUE'] ?: '—') . '–' . ($plan['PROPERTY_' . PROP_TRIAL_END_DATE . '_VALUE'] ?: '—')) ?></td>
                    <td><?= h($userNames[(int)$plan['RECRUITER_ID']] ?? '—') ?></td>
                    <td>
                        <?= renderTaskTable($plan['PVD_TASKS'], 'pvd', 'Задачи ПВД') ?>
                        <?= renderTaskTable($plan['KPI_TASKS'], 'kpi', 'Задачи KPI') ?>
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

    function closeModal() {
        modal.style.display = 'none';
        backdrop.style.display = 'none';
        body.innerHTML = '';
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('.js-task-details');
        if (trigger) {
            var template = document.getElementById(trigger.getAttribute('data-template'));
            if (template) {
                body.innerHTML = template.innerHTML;
                backdrop.style.display = 'block';
                modal.style.display = 'block';
            }
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
