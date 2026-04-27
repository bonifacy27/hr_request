<?php
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Анкеты новых сотрудников');

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

const ANKETA_IBLOCK_ID = 196;
const STATUS_IBLOCK_ID = 374;
const VIEW_URL = 'view.php?id=';
const CREATE_URL = 'create_anketa.php';
const PER_PAGE = 20;

const PROP_FAMILIYA = 951;
const PROP_IMYA = 952;
const PROP_OTCHESTVO = 953;
const PROP_ORGANIZATSIYA = 1835;
const PROP_DOLZHNOST = 958;
const PROP_DATA_PRIEMA = 963;
const PROP_DATA_OKONCHANIYA_IS = 964;
const PROP_RECRUITER = 961;
const PROP_RUKOVODITEL = 959;
const PROP_STATUS = 2930;
const PROP_HISTORY = 2861;

const STATUS_COLOR_PROP_ID = 3138;

function h($value)
{
    return htmlspecialcharsbx((string)$value);
}

function fullName($last, $first, $middle = '')
{
    return trim(implode(' ', array_filter([(string)$last, (string)$first, (string)$middle])));
}

function shortUserName(array $user)
{
    $name = trim((string)($user['NAME'] ?? ''));
    $lastName = trim((string)($user['LAST_NAME'] ?? ''));
    $login = trim((string)($user['LOGIN'] ?? ''));

    $fio = trim($lastName . ' ' . $name);
    if ($fio !== '') {
        return $fio;
    }

    return $login;
}

function getPropertyValue(array $properties, $propertyId, $valueKey = 'VALUE')
{
    $propertyId = (int)$propertyId;

    foreach ($properties as $property) {
        if (!is_array($property)) {
            continue;
        }
        if ((int)($property['ID'] ?? 0) !== $propertyId) {
            continue;
        }

        return $property[$valueKey] ?? '';
    }

    return '';
}

function getPropertyValues(array $properties, $propertyId, $valueKey = 'VALUE')
{
    $propertyId = (int)$propertyId;

    foreach ($properties as $property) {
        if (!is_array($property)) {
            continue;
        }
        if ((int)($property['ID'] ?? 0) !== $propertyId) {
            continue;
        }

        $value = $property[$valueKey] ?? [];
        if (is_array($value)) {
            return array_values(array_filter(array_map('intval', $value)));
        }

        $single = (int)$value;
        return $single > 0 ? [$single] : [];
    }

    return [];
}

function getUserNamesMap(array $userIds)
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) {
        return [];
    }

    $map = [];
    $rsUsers = CUser::GetList(
        $by = 'ID',
        $order = 'ASC',
        ['ID' => implode(' | ', $userIds)],
        ['FIELDS' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME']]
    );

    while ($user = $rsUsers->Fetch()) {
        $map[(int)$user['ID']] = shortUserName($user);
    }

    return $map;
}

function getElementNamesMap(array $elementIds)
{
    $elementIds = array_values(array_unique(array_filter(array_map('intval', $elementIds))));
    if (!$elementIds) {
        return [];
    }

    $map = [];
    $rs = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['ID' => $elementIds, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($item = $rs->Fetch()) {
        $map[(int)$item['ID']] = (string)$item['NAME'];
    }

    return $map;
}

function getStatusMetaMap(array $statusElementIds)
{
    $statusElementIds = array_values(array_unique(array_filter(array_map('intval', $statusElementIds))));
    if (!$statusElementIds) {
        return [];
    }

    $map = [];
    $rs = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'ID' => 'ASC'],
        ['IBLOCK_ID' => STATUS_IBLOCK_ID, 'ID' => $statusElementIds, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'NAME', 'IBLOCK_ID']
    );

    while ($item = $rs->Fetch()) {
        $statusId = (int)$item['ID'];
        $color = '';

        $rsColor = CIBlockElement::GetProperty(
            (int)$item['IBLOCK_ID'],
            $statusId,
            ['SORT' => 'ASC'],
            ['ID' => STATUS_COLOR_PROP_ID]
        );
        if ($prop = $rsColor->Fetch()) {
            $color = trim((string)($prop['VALUE'] ?? ''));
        }

        if ($color === '') {
            $rsColorByCode = CIBlockElement::GetProperty(
                (int)$item['IBLOCK_ID'],
                $statusId,
                ['SORT' => 'ASC'],
                ['CODE' => 'COLOR']
            );
            if ($propByCode = $rsColorByCode->Fetch()) {
                $color = trim((string)($propByCode['VALUE'] ?? ''));
            }
        }

        $map[$statusId] = [
            'NAME' => (string)$item['NAME'],
            'COLOR' => $color,
        ];
    }

    return $map;
}

function docIdCandidates($elementId)
{
    $elementId = (int)$elementId;
    if ($elementId <= 0) {
        return [];
    }

    return [
        ['lists', 'BizprocDocument', 'lists_' . ANKETA_IBLOCK_ID . '_' . $elementId],
        ['iblock', 'CIBlockDocument', 'iblock_' . ANKETA_IBLOCK_ID . '_' . $elementId],
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
    ];
}

function loadMyTasksMap(array $elementIds, $userId)
{
    $map = [];
    if (!class_exists('CBPTaskService')) {
        return $map;
    }

    foreach ($elementIds as $elementId) {
        foreach (docIdCandidates($elementId) as $docId) {
            $rs = CBPTaskService::GetList(
                ['ID' => 'DESC'],
                [
                    'DOCUMENT_ID' => $docId,
                    'USER_ID' => (int)$userId,
                    'USER_STATUS' => CBPTaskUserStatus::Waiting,
                ],
                false,
                false,
                ['ID']
            );

            if ($task = $rs->GetNext()) {
                $map[(int)$elementId] = (int)$task['ID'];
                break;
            }
        }
    }

    return $map;
}

function loadExecutorsMap(array $elementIds)
{
    $map = [];
    if (!class_exists('CBPTaskService')) {
        return $map;
    }

    $allUserIds = [];

    foreach ($elementIds as $elementId) {
        foreach (docIdCandidates($elementId) as $docId) {
            $rs = CBPTaskService::GetList(
                ['ID' => 'DESC'],
                [
                    'DOCUMENT_ID' => $docId,
                    'STATUS' => CBPTaskStatus::Running,
                ],
                false,
                false,
                ['ID', 'USER_ID']
            );

            $found = false;
            while ($task = $rs->GetNext()) {
                $uid = (int)$task['USER_ID'];
                if ($uid <= 0) {
                    continue;
                }
                $map[(int)$elementId][$uid] = $uid;
                $allUserIds[$uid] = $uid;
                $found = true;
            }

            if ($found) {
                break;
            }
        }
    }

    $userNames = getUserNamesMap($allUserIds);

    foreach ($map as $elementId => $userMap) {
        $names = [];
        foreach (array_keys($userMap) as $uid) {
            if (!empty($userNames[$uid])) {
                $names[] = $userNames[$uid];
            }
        }
        $map[$elementId] = $names;
    }

    return $map;
}

function buildQueryUrl(array $override = [])
{
    $params = $_GET;
    foreach ($override as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'list.php' . ($params ? ('?' . http_build_query($params)) : '');
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (int)($_GET['status'] ?? 0);
$orgFilter = (int)($_GET['org'] ?? 0);
$inWorkOnly = (string)($_GET['in_work'] ?? '') === 'Y';

$allowedSorts = [
    'id' => 'ID',
    'fio' => 'EMPLOYEE_FIO',
    'organization' => 'ORGANIZATION_NAME',
    'position' => 'POSITION',
    'hire_date' => 'HIRE_DATE_TS',
    'probation_end' => 'PROBATION_END_TS',
    'recruiter' => 'RECRUITER_FIO',
    'manager' => 'MANAGER_FIO',
    'status' => 'STATUS_NAME',
];
$sort = (string)($_GET['sort'] ?? 'id');
if (!isset($allowedSorts[$sort])) {
    $sort = 'id';
}
$order = mb_strtolower((string)($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$page = max(1, (int)($_GET['page'] ?? 1));

$filter = [
    'IBLOCK_ID' => ANKETA_IBLOCK_ID,
    'ACTIVE' => 'Y',
    'CHECK_PERMISSIONS' => 'Y',
    'MIN_PERMISSION' => 'R',
];

$rows = [];
$userIds = [];
$organizationIds = [];
$statusIds = [];

$rs = CIBlockElement::GetList(
    ['ID' => 'DESC'],
    $filter,
    false,
    false,
    ['ID', 'IBLOCK_ID', 'DATE_CREATE']
);

while ($ob = $rs->GetNextElement()) {
    $fields = $ob->GetFields();
    $properties = $ob->GetProperties();

    $id = (int)$fields['ID'];
    $recruiterId = (int)getPropertyValue($properties, PROP_RECRUITER, 'VALUE');
    $managerId = (int)getPropertyValue($properties, PROP_RUKOVODITEL, 'VALUE');
    $organizationId = (int)getPropertyValue($properties, PROP_ORGANIZATSIYA, 'VALUE');

    $statusElementIds = getPropertyValues($properties, PROP_STATUS, 'VALUE');
    $statusId = $statusElementIds ? (int)$statusElementIds[0] : 0;

    if ($recruiterId > 0) {
        $userIds[$recruiterId] = $recruiterId;
    }
    if ($managerId > 0) {
        $userIds[$managerId] = $managerId;
    }
    if ($organizationId > 0) {
        $organizationIds[$organizationId] = $organizationId;
    }
    if ($statusId > 0) {
        $statusIds[$statusId] = $statusId;
    }

    $hireDate = (string)getPropertyValue($properties, PROP_DATA_PRIEMA, 'VALUE');
    $probationEndDate = (string)getPropertyValue($properties, PROP_DATA_OKONCHANIYA_IS, 'VALUE');

    $rows[] = [
        'ID' => $id,
        'FAMILIYA' => (string)getPropertyValue($properties, PROP_FAMILIYA, 'VALUE'),
        'IMYA' => (string)getPropertyValue($properties, PROP_IMYA, 'VALUE'),
        'OTCHESTVO' => (string)getPropertyValue($properties, PROP_OTCHESTVO, 'VALUE'),
        'ORGANIZATION_ID' => $organizationId,
        'POSITION' => (string)getPropertyValue($properties, PROP_DOLZHNOST, 'VALUE'),
        'HIRE_DATE' => $hireDate,
        'HIRE_DATE_TS' => strtotime($hireDate) ?: 0,
        'PROBATION_END' => $probationEndDate,
        'PROBATION_END_TS' => strtotime($probationEndDate) ?: 0,
        'RECRUITER_ID' => $recruiterId,
        'MANAGER_ID' => $managerId,
        'STATUS_ID' => $statusId,
        'HISTORY' => (string)getPropertyValue($properties, PROP_HISTORY, 'VALUE'),
    ];
}

$userMap = getUserNamesMap($userIds);
$organizationMap = getElementNamesMap($organizationIds);
$statusMetaMap = getStatusMetaMap($statusIds);
$statusFilterMap = getStatusMetaMap(array_keys($statusIds));
$orgFilterMap = $organizationMap;
asort($orgFilterMap, SORT_NATURAL | SORT_FLAG_CASE);

foreach ($rows as &$row) {
    $row['EMPLOYEE_FIO'] = fullName($row['FAMILIYA'], $row['IMYA'], $row['OTCHESTVO']);
    $row['RECRUITER_FIO'] = $row['RECRUITER_ID'] > 0 ? (string)($userMap[$row['RECRUITER_ID']] ?? '') : '';
    $row['MANAGER_FIO'] = $row['MANAGER_ID'] > 0 ? (string)($userMap[$row['MANAGER_ID']] ?? '') : '';
    $row['ORGANIZATION_NAME'] = $row['ORGANIZATION_ID'] > 0 ? (string)($organizationMap[$row['ORGANIZATION_ID']] ?? '') : '';
    $row['STATUS_NAME'] = $row['STATUS_ID'] > 0 ? (string)($statusMetaMap[$row['STATUS_ID']]['NAME'] ?? '') : '';
    $row['STATUS_COLOR'] = $row['STATUS_ID'] > 0 ? trim((string)($statusMetaMap[$row['STATUS_ID']]['COLOR'] ?? '')) : '';
}
unset($row);

if ($statusFilter > 0) {
    $rows = array_values(array_filter($rows, static function ($row) use ($statusFilter) {
        return (int)$row['STATUS_ID'] === $statusFilter;
    }));
}

if ($orgFilter > 0) {
    $rows = array_values(array_filter($rows, static function ($row) use ($orgFilter) {
        return (int)$row['ORGANIZATION_ID'] === $orgFilter;
    }));
}

if ($search !== '') {
    $needle = mb_strtolower($search);
    $rows = array_values(array_filter($rows, static function ($row) use ($needle) {
        return mb_strpos(mb_strtolower((string)$row['EMPLOYEE_FIO']), $needle) !== false
            || mb_strpos(mb_strtolower((string)$row['RECRUITER_FIO']), $needle) !== false
            || mb_strpos(mb_strtolower((string)$row['MANAGER_FIO']), $needle) !== false;
    }));
}

$elementIds = array_column($rows, 'ID');
$myTasksMap = loadMyTasksMap($elementIds, (int)$USER->GetID());
if ($inWorkOnly) {
    $rows = array_values(array_filter($rows, static function ($row) use ($myTasksMap) {
        return isset($myTasksMap[(int)$row['ID']]);
    }));
    $elementIds = array_column($rows, 'ID');
}

$executorsMap = loadExecutorsMap($elementIds);

$sortField = $allowedSorts[$sort];
usort($rows, static function ($a, $b) use ($sortField, $order) {
    $av = $a[$sortField] ?? '';
    $bv = $b[$sortField] ?? '';

    if (is_numeric($av) && is_numeric($bv)) {
        $cmp = $av <=> $bv;
    } else {
        $cmp = strnatcasecmp((string)$av, (string)$bv);
    }

    if ($cmp === 0) {
        $cmp = ((int)$a['ID']) <=> ((int)$b['ID']);
    }

    return $order === 'asc' ? $cmp : -$cmp;
});

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / PER_PAGE));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * PER_PAGE;
$rowsPage = array_slice($rows, $offset, PER_PAGE);

function sortLink($label, $sortKey, $currentSort, $currentOrder)
{
    $isActive = $currentSort === $sortKey;
    $nextOrder = $isActive && $currentOrder === 'asc' ? 'desc' : 'asc';
    $caret = '';

    if ($isActive) {
        $caret = $currentOrder === 'asc' ? '▲' : '▼';
    }

    $url = h(buildQueryUrl(['sort' => $sortKey, 'order' => $nextOrder, 'page' => 1]));
    return '<a class="sort-link" href="' . $url . '">' . h($label) . ($caret !== '' ? ' <span class="sort-caret">' . $caret . '</span>' : '') . '</a>';
}
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.page-wrap { padding: 16px 24px; }
.table thead th { white-space: nowrap; vertical-align: middle; }
.main-table td { vertical-align: top; }
.sort-link { color: #fff; text-decoration: none; }
.sort-link:hover { color: #fff; text-decoration: underline; }
.sort-caret { margin-left: 4px; font-weight: 700; }
.nowrap { white-space: nowrap; }

.number-link {
    font-weight: 700;
    color: #0d6efd;
    text-decoration: none;
    border: 0;
    background: transparent;
    padding: 0;
    cursor: pointer;
}
.number-link:hover { text-decoration: underline; }

.status-pill {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 999px;
    color: #fff;
    font-size: 12px;
    line-height: 1.2;
    white-space: nowrap;
    font-weight: 600;
}

.history-btn {
    border: 0;
    background: #6c757d;
    color: #fff;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    line-height: 22px;
    padding: 0;
    font-size: 12px;
    font-weight: bold;
    margin-left: 6px;
    cursor: pointer;
}
.history-btn:hover { background: #5a6268; }

.history-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.history-modal {
    background: #fff;
    border-radius: 10px;
    max-width: 900px;
    width: 92%;
    max-height: 82vh;
    box-shadow: 0 10px 30px rgba(0,0,0,.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.history-modal-header {
    padding: 12px 16px;
    border-bottom: 1px solid #e5e5e5;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.history-modal-title {
    font-size: 16px;
    font-weight: 600;
}

.status-open-btn {
    border: 0;
    background: transparent;
    padding: 0;
    cursor: pointer;
}

.history-modal-body {
    padding: 16px;
    overflow-y: auto;
}

.history-modal-close {
    border: 0;
    background: transparent;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
}

.filter-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: nowrap;
    overflow-x: auto;
    padding: 12px 14px;
}

.filter-item {
    flex: 0 0 auto;
    min-width: 0;
}

.filter-item.search-item { width: 340px; }
.filter-item .form-control { min-width: 0; }

.filter-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
    flex: 0 0 auto;
}

.actions-cell {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}
</style>

<div class="container-fluid page-wrap">
    <h2 class="mb-3">Анкеты новых сотрудников</h2>

    <div class="d-flex flex-wrap align-items-center mb-3">
        <a href="<?=h(CREATE_URL)?>" class="btn btn-success mr-3 mb-2">Создать анкету</a>
    </div>

    <form method="get" class="card mb-3">
        <div class="filter-toolbar">
            <div class="filter-item search-item">
                <input type="text" name="q" value="<?=h($search)?>" class="form-control form-control-sm" placeholder="Поиск: ФИО сотрудника / рекрутера / руководителя">
            </div>

            <div class="filter-item">
                <select name="org" class="form-control form-control-sm">
                    <option value="0">ЮЛ: все</option>
                    <?php foreach ($orgFilterMap as $orgId => $orgName): ?>
                        <option value="<?=$orgId?>" <?=$orgFilter === (int)$orgId ? 'selected' : ''?>><?=h($orgName)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <select name="status" class="form-control form-control-sm">
                    <option value="0">Статус: все</option>
                    <?php foreach ($statusFilterMap as $statusId => $statusMeta): ?>
                        <option value="<?=$statusId?>" <?=$statusFilter === (int)$statusId ? 'selected' : ''?>><?=h($statusMeta['NAME'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label class="mb-0" style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="in_work" value="Y" <?=$inWorkOnly ? 'checked' : ''?>>
                    <span>В работе</span>
                </label>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Применить</button>
                <a href="list.php" class="btn btn-secondary btn-sm">Сбросить</a>
            </div>
        </div>
    </form>

    <div class="mb-2 text-muted">Найдено: <?=$totalRows?>, страница <?=$page?> из <?=$totalPages?></div>

    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover main-table">
            <thead class="thead-dark">
            <tr>
                <th><?=sortLink('ID', 'id', $sort, $order)?></th>
                <th><?=sortLink('ФИО сотрудника', 'fio', $sort, $order)?></th>
                <th><?=sortLink('ЮЛ', 'organization', $sort, $order)?></th>
                <th><?=sortLink('Должность', 'position', $sort, $order)?></th>
                <th><?=sortLink('Дата приема', 'hire_date', $sort, $order)?></th>
                <th><?=sortLink('Дата окончания ИС', 'probation_end', $sort, $order)?></th>
                <th><?=sortLink('Рекрутер', 'recruiter', $sort, $order)?></th>
                <th><?=sortLink('Руководитель', 'manager', $sort, $order)?></th>
                <th><?=sortLink('Статус + история', 'status', $sort, $order)?></th>
                <th>Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rowsPage): ?>
                <tr><td colspan="10" class="text-center text-muted">Ничего не найдено</td></tr>
            <?php endif; ?>

            <?php foreach ($rowsPage as $row):
                $id = (int)$row['ID'];
                $statusName = $row['STATUS_NAME'] !== '' ? $row['STATUS_NAME'] : 'Без статуса';
                $badgeColor = $row['STATUS_COLOR'] !== '' ? $row['STATUS_COLOR'] : '#6c757d';
                $executors = $executorsMap[$id] ?? [];
                $executorsHtml = $executors ? '<ul class="mb-0"><li>' . implode('</li><li>', array_map('h', $executors)) . '</li></ul>' : '<div class="text-muted">Активные исполнители не найдены.</div>';
                $historyText = trim((string)$row['HISTORY']);
                $historyHtml = $historyText !== '' ? nl2br(h($historyText)) : '<div class="text-muted">История отсутствует.</div>';
                $taskId = (int)($myTasksMap[$id] ?? 0);
                $taskUrl = $taskId > 0 ? '/company/personal/bizproc/' . $taskId . '/?back_url=' . rawurlencode($APPLICATION->GetCurPageParam()) : '';
                ?>
                <tr>
                    <td class="nowrap"><button type="button" class="number-link js-request-btn" data-request="<?=h($row['EMPLOYEE_FIO'])?>">#<?=$id?></button></td>
                    <td><?=h($row['EMPLOYEE_FIO'])?></td>
                    <td><?=h($row['ORGANIZATION_NAME'])?></td>
                    <td><?=h($row['POSITION'])?></td>
                    <td class="nowrap"><?=h($row['HIRE_DATE'])?></td>
                    <td class="nowrap"><?=h($row['PROBATION_END'])?></td>
                    <td><?=h($row['RECRUITER_FIO'])?></td>
                    <td><?=h($row['MANAGER_FIO'])?></td>
                    <td>
                        <button type="button" class="status-open-btn js-executors-btn" data-executors="<?=h($executorsHtml)?>" data-id="<?=$id?>">
                            <span class="status-pill" style="background:<?=$badgeColor?>;"><?=h($statusName)?></span>
                        </button>
                        <button type="button" class="history-btn js-history-btn" data-history="<?=h($historyHtml)?>" data-id="<?=$id?>" title="Показать историю">i</button>
                    </td>
                    <td class="nowrap">
                        <div class="actions-cell">
                            <a href="<?=h(VIEW_URL . $id)?>" target="_blank">Открыть</a>
                            <?php if ($taskId > 0): ?>
                                <a class="btn btn-outline-primary btn-sm" href="<?=h($taskUrl)?>" target="_blank">Задание БП</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination pagination-sm">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?=$p === $page ? 'active' : ''?>">
                        <a class="page-link" href="<?=h(buildQueryUrl(['page' => $p]))?>"><?=$p?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<div id="history-modal-backdrop" class="history-modal-backdrop">
    <div class="history-modal">
        <div class="history-modal-header">
            <div class="history-modal-title" id="history-modal-title">Информация</div>
            <button type="button" class="history-modal-close js-history-close">&times;</button>
        </div>
        <div class="history-modal-body" id="history-modal-body"></div>
    </div>
</div>

<script>
(function() {
    var backdrop = document.getElementById('history-modal-backdrop');
    var bodyEl = document.getElementById('history-modal-body');
    var titleEl = document.getElementById('history-modal-title');

    if (!backdrop || !bodyEl || !titleEl) {
        return;
    }

    function openModal(title, html) {
        titleEl.textContent = title;
        bodyEl.innerHTML = html;
        backdrop.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        backdrop.style.display = 'none';
        bodyEl.innerHTML = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function(e) {
        var historyBtn = e.target.closest ? e.target.closest('.js-history-btn') : null;
        if (historyBtn) {
            openModal('История (анкета #' + historyBtn.getAttribute('data-id') + ')', historyBtn.getAttribute('data-history') || '');
            return;
        }

        var executorsBtn = e.target.closest ? e.target.closest('.js-executors-btn') : null;
        if (executorsBtn) {
            openModal('Текущие исполнители (анкета #' + executorsBtn.getAttribute('data-id') + ')', executorsBtn.getAttribute('data-executors') || '');
            return;
        }

        var requestBtn = e.target.closest ? e.target.closest('.js-request-btn') : null;
        if (requestBtn) {
            openModal('Сведения об анкете #' + requestBtn.textContent.replace('#', ''), '<strong>Сотрудник:</strong> ' + (requestBtn.getAttribute('data-request') || ''));
            return;
        }

        if (e.target === backdrop || (e.target.closest && e.target.closest('.js-history-close'))) {
            closeModal();
        }
    });
})();
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
