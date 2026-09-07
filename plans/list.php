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
const KPI_TASK_IBLOCK_ID = 363;
const PROP_MANAGER = 2775;
const PROP_EMPLOYMENT_DATE = 2776;
const PROP_TRIAL_END_DATE = 2802;
const PROP_RECRUITER = 2796;
const PROP_PVD_TASKS = 2761;
const PROP_KPI_TASKS = 2769;
const PROP_PVD_STATUS = 2767;
const PROP_KPI_STATUS = 2805;
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

function loadTasks(array $ids, $iblockId, $statusPropertyId)
{
    $result = [];
    if (!$ids) {
        return $result;
    }
    $tasks = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => (int)$iblockId, 'ID' => $ids, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME', 'PROPERTY_' . (int)$statusPropertyId]
    );
    while ($task = $tasks->Fetch()) {
        $statusId = (int)($task['PROPERTY_' . (int)$statusPropertyId . '_VALUE'] ?? 0);
        $status = '';
        if ($statusId > 0) {
            $statusElement = CIBlockElement::GetByID($statusId)->Fetch();
            $status = $statusElement ? (string)$statusElement['NAME'] : '';
        }
        $result[] = ['ID' => (int)$task['ID'], 'NAME' => (string)$task['NAME'], 'STATUS' => $status];
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

function renderTaskTable(array $tasks, $iblockId)
{
    if (!$tasks) {
        return '<span class="text-muted">Нет задач</span>';
    }
    $html = '<table class="plan-task-table"><tbody>';
    foreach ($tasks as $task) {
        $url = '/workgroups/group/206/lists/' . (int)$iblockId . '/element/0/' . (int)$task['ID'] . '/';
        $html .= '<tr><td><a href="' . h($url) . '" target="_blank" rel="noopener">' . h($task['NAME']) . '</a></td>';
        $html .= '<td>' . h($task['STATUS'] !== '' ? $task['STATUS'] : '—') . '</td></tr>';
    }
    return $html . '</tbody></table>';
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
    $plan['PVD_TASKS'] = loadTasks($pvdIds, PVD_TASK_IBLOCK_ID, PROP_PVD_STATUS);
    $plan['KPI_TASKS'] = loadTasks($kpiIds, KPI_TASK_IBLOCK_ID, PROP_KPI_STATUS);
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
                <th>Задачи ПВД</th><th>Задачи KPI</th><th>Действия</th>
            </tr></thead>
            <tbody>
            <?php if (!$plans): ?>
                <tr><td colspan="7" class="text-muted">Планы не найдены.</td></tr>
            <?php else: foreach ($plans as $plan): ?>
                <?php
                $planId = (int)$plan['ID'];
                $taskId = (int)$plan['BP_TASK_ID'];
                $reportUrl = '/adaptation/onboarding_plan_report.php?PLAN_ID=' . $planId;
                ?>
                <tr>
                    <td><?= h($plan['NAME']) ?></td>
                    <td><?= h($userNames[(int)$plan['MANAGER_ID']] ?? '—') ?></td>
                    <td><?= h(($plan['PROPERTY_' . PROP_EMPLOYMENT_DATE . '_VALUE'] ?: '—') . '–' . ($plan['PROPERTY_' . PROP_TRIAL_END_DATE . '_VALUE'] ?: '—')) ?></td>
                    <td><?= h($userNames[(int)$plan['RECRUITER_ID']] ?? '—') ?></td>
                    <td><?= renderTaskTable($plan['PVD_TASKS'], PVD_TASK_IBLOCK_ID) ?></td>
                    <td><?= renderTaskTable($plan['KPI_TASKS'], KPI_TASK_IBLOCK_ID) ?></td>
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
</div>
<script>
document.addEventListener('change', function (event) {
    if (event.target.classList.contains('js-plan-action') && event.target.value) {
        window.location.href = event.target.value;
    }
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
