<?php
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Анкеты кандидатов');

if (!Loader::includeModule('iblock') || !Loader::includeModule('bizproc') || !Loader::includeModule('main')) {
    ShowError('Не удалось подключить модули iblock/bizproc/main.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

CJSCore::Init(['popup', 'ui.entity-selector', 'ui.notification']);

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    ShowError('Требуется авторизация.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

const CANDIDATE_IBLOCK_ID = 207;
const VIEW_URL = 'view.php?id=';
const CREATE_URL = 'create_anketa.php';
const PER_PAGE = 20;

const PROP_LASTNAME = 1083;
const PROP_FIRSTNAME = 1084;
const PROP_MIDDLENAME = 1085;
const PROP_STATUS = 1092;
const PROP_TYPE = 1093;
const PROP_HISTORY = 1276;
const PROP_RECRUITER = 1323;

function h($value)
{
    return htmlspecialcharsbx((string)$value);
}


const RECRUIT_HEAD_GLOBAL_VAR_ID = 'Variable1722503621093';

function formatUserNameById(int $userId): string
{
    if ($userId <= 0) {
        return '—';
    }

    $rsUsers = CUser::GetList(
        $by = 'ID',
        $order = 'ASC',
        ['ID' => (string)$userId],
        ['FIELDS' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME']]
    );

    if ($user = $rsUsers->Fetch()) {
        $name = trim(($user['LAST_NAME'] ?? '') . ' ' . ($user['NAME'] ?? '') . ' ' . ($user['SECOND_NAME'] ?? ''));
        return $name !== '' ? $name : (string)($user['LOGIN'] ?? ('user#' . $userId));
    }

    return 'user#' . $userId;
}

function getGlobalVarUserList($varId): array
{
    $users = [];
    try {
        $conn = \Bitrix\Main\Application::getConnection();
        $sqlVarId = $conn->getSqlHelper()->forSql((string)$varId);
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

function appendHistoryLine(string $history, string $line): string
{
    $history = trim($history);
    $line = trim($line);
    if ($line === '') {
        return $history;
    }
    return $history === '' ? $line : ($history . "\n" . $line);
}

function findActiveTaskIdForUser(int $elementId, int $userId): int
{
    if ($elementId <= 0 || $userId <= 0 || !class_exists('CBPTaskService')) {
        return 0;
    }

    foreach (docIdCandidates($elementId) as $docId) {
        $rs = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            [
                'DOCUMENT_ID' => $docId,
                'USER_ID' => $userId,
                'USER_STATUS' => CBPTaskUserStatus::Waiting,
            ],
            false,
            false,
            ['ID']
        );

        if ($task = $rs->GetNext()) {
            return (int)$task['ID'];
        }
    }

    return 0;
}

function fullName($last, $first, $middle)
{
    return trim(implode(' ', array_filter([(string)$last, (string)$first, (string)$middle])));
}

function propertyValueById(array $properties, $propertyId, $valueKey = 'VALUE')
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

function getEnumMap($propertyId)
{
    $map = [];
    $rs = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => (int)$propertyId]);
    while ($enum = $rs->Fetch()) {
        $map[(int)$enum['ID']] = (string)$enum['VALUE'];
    }
    return $map;
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
        ['FIELDS' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME']]
    );

    while ($user = $rsUsers->Fetch()) {
        $id = (int)$user['ID'];
        $name = trim($user['LAST_NAME'] . ' ' . $user['NAME'] . ' ' . $user['SECOND_NAME']);
        $map[$id] = $name !== '' ? $name : (string)$user['LOGIN'];
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
        ['lists', 'BizprocDocument', 'lists_' . CANDIDATE_IBLOCK_ID . '_' . $elementId],
        ['iblock', 'CIBlockDocument', 'iblock_' . CANDIDATE_IBLOCK_ID . '_' . $elementId],
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

$currentUserId = (int)$USER->GetID();
$currentUserTagLower = mb_strtolower('user_' . $currentUserId);
$recruitHeads = getGlobalVarUserList(RECRUIT_HEAD_GLOBAL_VAR_ID);
$isRecruitHead = in_array($currentUserTagLower, $recruitHeads, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'change_recruiter') {
        $elementId = (int)($_POST['element_id'] ?? 0);
        $newRecruiterId = (int)($_POST['new_recruiter_id'] ?? 0);
        $comment = trim((string)($_POST['change_comment'] ?? ''));

        $el = CIBlockElement::GetList([], ['IBLOCK_ID' => CANDIDATE_IBLOCK_ID, 'ID' => $elementId], false, false, ['ID'])->GetNextElement();
        $props = $el ? $el->GetProperties() : [];
        $oldRecruiterId = (int)propertyValueById($props, PROP_RECRUITER, 'VALUE');

        $canChange = $isRecruitHead || ($oldRecruiterId > 0 && $oldRecruiterId === $currentUserId);

        if ($elementId <= 0 || $newRecruiterId <= 0 || $comment === '') {
            LocalRedirect(buildQueryUrl(['msg' => 'danger', 'text' => 'Заполните все обязательные поля.']));
        }
        if (!$canChange) {
            LocalRedirect(buildQueryUrl(['msg' => 'danger', 'text' => 'Недостаточно прав для смены рекрутера.']));
        }

        $now = date('d.m.Y H:i');
        $actorName = formatUserNameById($currentUserId);
        $oldName = formatUserNameById($oldRecruiterId);
        $newName = formatUserNameById($newRecruiterId);

        $history = (string)propertyValueById($props, PROP_HISTORY, 'VALUE');
        $line = $now . ': ' . $actorName . ' сменил рекрутера ' . $oldName . ' на ' . $newName . '. Комментарий: ' . $comment;
        $newHistory = appendHistoryLine($history, $line);

        $taskId = findActiveTaskIdForUser($elementId, $oldRecruiterId);
        if ($taskId > 0 && $oldRecruiterId !== $newRecruiterId) {
            try {
                CBPTaskService::DelegateTask($taskId, $oldRecruiterId, $newRecruiterId);
                $newHistory = appendHistoryLine($newHistory, $now . ': Задание БП #' . $taskId . ' делегировано с ' . $oldName . ' на ' . $newName . '.');
            } catch (\Throwable $e) {
                $newHistory = appendHistoryLine($newHistory, $now . ': Не удалось делегировать задание БП #' . $taskId . ' — ' . $e->getMessage());
            }
        }

        CIBlockElement::SetPropertyValuesEx($elementId, CANDIDATE_IBLOCK_ID, [
            (string)PROP_RECRUITER => $newRecruiterId,
            (string)PROP_HISTORY => $newHistory,
        ]);

        LocalRedirect(buildQueryUrl(['msg' => 'success', 'text' => 'Рекрутер успешно изменён.']));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !check_bitrix_sessid()) {
    LocalRedirect(buildQueryUrl(['msg' => 'danger', 'text' => 'Сессия истекла. Обновите страницу.']));
}

$msgType = trim((string)($_GET['msg'] ?? ''));
$msgText = trim((string)($_GET['text'] ?? ''));

$typeEnumMap = getEnumMap(PROP_TYPE);
$statusEnumMap = getEnumMap(PROP_STATUS);

$search = trim((string)($_GET['q'] ?? ''));
$typeFilter = (int)($_GET['type'] ?? 0);
$statusFilter = (int)($_GET['status'] ?? 0);
$inWorkOnly = (string)($_GET['in_work'] ?? '') === 'Y';

$allowedSorts = [
    'id' => 'ID',
    'fio' => 'CANDIDATE_FIO',
    'date_create' => 'DATE_CREATE_TS',
    'type' => 'TYPE_NAME',
    'recruiter' => 'RECRUITER_FIO',
    'status' => 'STATUS_NAME',
];
$sort = (string)($_GET['sort'] ?? 'date_create');
if (!isset($allowedSorts[$sort])) {
    $sort = 'date_create';
}
$order = mb_strtolower((string)($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$page = max(1, (int)($_GET['page'] ?? 1));

$filter = [
    'IBLOCK_ID' => CANDIDATE_IBLOCK_ID,
    'ACTIVE' => 'Y',
    'CHECK_PERMISSIONS' => 'Y',
    'MIN_PERMISSION' => 'R',
];
if ($typeFilter > 0) {
    $filter['PROPERTY_' . PROP_TYPE] = $typeFilter;
}
if ($statusFilter > 0) {
    $filter['PROPERTY_' . PROP_STATUS] = $statusFilter;
}

$rows = [];
$recruiterIds = [];
$rs = CIBlockElement::GetList(
    ['DATE_CREATE' => 'DESC', 'ID' => 'DESC'],
    $filter,
    false,
    false,
    ['ID', 'IBLOCK_ID', 'DATE_CREATE']
);

while ($ob = $rs->GetNextElement()) {
    $f = $ob->GetFields();
    $p = $ob->GetProperties();

    $id = (int)$f['ID'];
    $rid = (int)propertyValueById($p, PROP_RECRUITER, 'VALUE');
    if ($rid > 0) {
        $recruiterIds[$rid] = $rid;
    }

    $dateCreate = (string)$f['DATE_CREATE'];
    $rows[] = [
        'ID' => $id,
        'DATE_CREATE' => $dateCreate,
        'DATE_CREATE_TS' => strtotime($dateCreate) ?: 0,
        'LASTNAME' => (string)propertyValueById($p, PROP_LASTNAME, 'VALUE'),
        'FIRSTNAME' => (string)propertyValueById($p, PROP_FIRSTNAME, 'VALUE'),
        'MIDDLENAME' => (string)propertyValueById($p, PROP_MIDDLENAME, 'VALUE'),
        'TYPE_ID' => (int)propertyValueById($p, PROP_TYPE, 'VALUE_ENUM_ID'),
        'STATUS_ID' => (int)propertyValueById($p, PROP_STATUS, 'VALUE_ENUM_ID'),
        'HISTORY' => (string)propertyValueById($p, PROP_HISTORY, 'VALUE'),
        'RECRUITER_ID' => $rid,
    ];
}

$recruiterMap = getUserNamesMap($recruiterIds);

foreach ($rows as &$row) {
    $row['CANDIDATE_FIO'] = fullName($row['LASTNAME'], $row['FIRSTNAME'], $row['MIDDLENAME']);
    $row['RECRUITER_FIO'] = $row['RECRUITER_ID'] > 0 ? (string)($recruiterMap[$row['RECRUITER_ID']] ?? '') : '';
    $row['TYPE_NAME'] = (string)($typeEnumMap[$row['TYPE_ID']] ?? '');
    $row['STATUS_NAME'] = (string)($statusEnumMap[$row['STATUS_ID']] ?? '');
}
unset($row);

if ($search !== '') {
    $needle = mb_strtolower($search);
    $rows = array_values(array_filter($rows, static function ($row) use ($needle) {
        return mb_strpos(mb_strtolower((string)$row['CANDIDATE_FIO']), $needle) !== false
            || mb_strpos(mb_strtolower((string)$row['RECRUITER_FIO']), $needle) !== false;
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

$statusColorMap = [
    'Черновик' => '#6c757d',
    'Первичная ссылка' => '#b8860b',
    'Ожидание анкеты и документов' => '#facc15',
    'Вторичная ссылка' => '#f97316',
    'Ожидание доп. файлов' => '#f97316',
    'Доработка' => '#7c3aed',
    'Проверка документов' => '#2563eb',
    'Согласовано СБ' => '#16a34a',
    'Отклонена' => '#dc3545',
    'Предварительная проверка' => '#7dd3fc',
    'Ожидание анкеты' => '#facc15',
    'Сформирован оффер' => '#16a34a',
    'Согласовано СБ с ограничениями' => '#86efac',
    'Повторная ссылка' => '#c4b5fd',
    'Ожидание документов' => '#b8860b',
    'На проверке рекрутером' => '#38bdf8',
    'На согласовании СБ' => '#1e3a8a',
    'Согласовано СБ, документы получены' => '#166534',
];

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
.page-wrap { padding:16px 24px; }
.table thead th { white-space:nowrap; vertical-align:middle; }
.sort-link { color:#fff; text-decoration:none; }
.sort-link:hover { color:#fff; text-decoration:underline; }
.sort-caret { margin-left:4px; font-weight:700; }
.status-pill { display:inline-block; padding:5px 10px; border-radius:999px; color:#fff; font-size:12px; font-weight:600; }
.history-btn { border:0; background:#6c757d; color:#fff; border-radius:50%; width:22px; height:22px; line-height:22px; padding:0; font-size:12px; margin-left:6px; }
.history-btn:hover { background:#5a6268; }
.status-open-btn { border:0; background:transparent; padding:0; }
.actions-cell { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.history-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:9999; }
.history-modal { background:#fff; border-radius:10px; max-width:900px; width:92%; max-height:82vh; box-shadow:0 10px 30px rgba(0,0,0,.25); display:flex; flex-direction:column; overflow:hidden; }
.history-modal-header { padding:12px 16px; border-bottom:1px solid #e5e5e5; display:flex; justify-content:space-between; align-items:center; }
.history-modal-body { padding:16px; overflow-y:auto; }
.history-modal-close { border:0; background:transparent; font-size:24px; line-height:1; cursor:pointer; }
.filter-toolbar { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; padding:12px 14px; }
.filter-item { flex:0 0 auto; min-width:180px; }
.filter-item.search-item { width:320px; }
.recruiter-change-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
</style>

<div class="container-fluid page-wrap">
    <h2 class="mb-3">Анкеты кандидатов</h2>

    <?php if ($msgText !== ''): ?>
        <div class="alert alert-<?=h($msgType === 'success' ? 'success' : 'danger')?>">
            <?=h($msgText)?>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-center mb-3">
        <a href="<?=h(CREATE_URL)?>" class="btn btn-success mr-3 mb-2">Создать анкету</a>
    </div>

    <form method="get" class="card mb-3">
        <div class="filter-toolbar">
            <div class="filter-item search-item">
                <label class="mb-1">Поиск (ФИО кандидата / рекрутера)</label>
                <input type="text" name="q" value="<?=h($search)?>" class="form-control form-control-sm" placeholder="Введите ФИО">
            </div>

            <div class="filter-item">
                <label class="mb-1">Тип анкеты</label>
                <select name="type" class="form-control form-control-sm">
                    <option value="0">Все</option>
                    <?php foreach ($typeEnumMap as $enumId => $enumName): ?>
                        <option value="<?=$enumId?>" <?=$typeFilter === (int)$enumId ? 'selected' : ''?>><?=h($enumName)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label class="mb-1">Статус</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="0">Все</option>
                    <?php foreach ($statusEnumMap as $enumId => $enumName): ?>
                        <option value="<?=$enumId?>" <?=$statusFilter === (int)$enumId ? 'selected' : ''?>><?=h($enumName)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label class="d-flex align-items-center mt-4">
                    <input type="checkbox" name="in_work" value="Y" <?=$inWorkOnly ? 'checked' : ''?>>
                    <span class="ml-2">В работе</span>
                </label>
            </div>

            <div class="ml-auto d-flex" style="gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm">Применить</button>
                <a href="list.php" class="btn btn-secondary btn-sm">Сбросить</a>
            </div>
        </div>
    </form>

    <div class="mb-2 text-muted">Найдено: <?=$totalRows?>, страница <?=$page?> из <?=$totalPages?></div>

    <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover">
            <thead class="thead-dark">
            <tr>
                <th><?=sortLink('ID', 'id', $sort, $order)?></th>
                <th><?=sortLink('ФИО кандидата', 'fio', $sort, $order)?></th>
                <th><?=sortLink('Дата создания', 'date_create', $sort, $order)?></th>
                <th><?=sortLink('Тип анкеты', 'type', $sort, $order)?></th>
                <th><?=sortLink('Рекрутер', 'recruiter', $sort, $order)?></th>
                <th><?=sortLink('Статус + История', 'status', $sort, $order)?></th>
                <th>Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rowsPage): ?>
                <tr><td colspan="7" class="text-center text-muted">Ничего не найдено</td></tr>
            <?php endif; ?>

            <?php foreach ($rowsPage as $row):
                $id = (int)$row['ID'];
                $statusName = $row['STATUS_NAME'] !== '' ? $row['STATUS_NAME'] : 'Без статуса';
                $badgeColor = $statusColorMap[$statusName] ?? '#6c757d';
                $executors = $executorsMap[$id] ?? [];
                $executorsText = $executors ? implode("\n", $executors) : 'Активных исполнителей нет';
                $taskId = (int)($myTasksMap[$id] ?? 0);
                $taskUrl = $taskId > 0 ? '/company/personal/bizproc/' . $taskId . '/?back_url=' . rawurlencode($APPLICATION->GetCurPageParam()) : '';
                ?>
                <tr>
                    <td><?= $id ?></td>
                    <td><?=h($row['CANDIDATE_FIO'])?></td>
                    <td><?=h($row['DATE_CREATE'])?></td>
                    <td><?=h($row['TYPE_NAME'])?></td>
                    <td><?=h($row['RECRUITER_FIO'])?></td>
                    <td>
                        <button type="button" class="status-open-btn js-executors-btn" data-executors="<?=h(nl2br($executorsText))?>" data-id="<?=$id?>">
                            <span class="status-pill" style="background:<?=$badgeColor?>;<?=in_array($badgeColor, ['#facc15','#7dd3fc','#86efac','#c4b5fd','#38bdf8'], true) ? 'color:#111;' : ''?>"><?=h($statusName)?></span>
                        </button>
                        <button type="button" class="history-btn js-history-btn" data-history="<?=h(nl2br($row['HISTORY'] !== '' ? $row['HISTORY'] : 'История отсутствует'))?>" data-id="<?=$id?>">i</button>
                    </td>
                    <td>
                        <div class="actions-cell">
                            <a href="<?=h(VIEW_URL . $id)?>" target="_blank">Открыть</a>
                            <?php
                                $canChangeRecruiter = $isRecruitHead || ($row['RECRUITER_ID'] > 0 && (int)$row['RECRUITER_ID'] === $currentUserId);
                            ?>
                            <?php if ($canChangeRecruiter): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm js-change-recruiter-btn" data-id="<?=$id?>" data-current-recruiter-id="<?= (int)$row['RECRUITER_ID'] ?>">Сменить рекрутера</button>
                            <?php endif; ?>
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


<div id="change-recruiter-modal-template" style="display:none;">
  <div class="popup-form-wrap">
    <div class="popup-form-title">Сменить рекрутера</div>
    <form method="post" id="change-recruiter-form">
      <?= bitrix_sessid_post(); ?>
      <input type="hidden" name="action" value="change_recruiter">
      <input type="hidden" name="element_id" id="change-element-id" value="">
      <input type="hidden" name="new_recruiter_id" id="change-new-recruiter-id" value="">
      <div class="popup-form-field">
        <label>Новый рекрутер <span style="color:#dc3545;">*</span></label>
        <div class="recruiter-change-row">
          <button type="button" class="btn btn-outline-primary btn-sm" id="change-pick-recruiter">Выбрать сотрудника</button>
          <span class="text-muted" id="change-selected-recruiter">Сотрудник не выбран</span>
        </div>
      </div>
      <div class="popup-form-field" style="margin-top:12px;">
        <label>Комментарий <span style="color:#dc3545;">*</span></label>
        <textarea class="form-control" name="change_comment" id="change-comment" rows="3" placeholder="Укажите причину смены"></textarea>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
    var backdrop = document.getElementById('history-modal-backdrop');
    var changePopup = null;
    var changeSelector = null;

    function notify(text){ if (BX && BX.UI && BX.UI.Notification) BX.UI.Notification.Center.notify({content:text}); else alert(text); }

    function ensureChangePopup(){ if(changePopup) return changePopup; var tpl=document.getElementById('change-recruiter-modal-template'); var content=tpl?tpl.innerHTML:'<div>Ошибка шаблона</div>'; changePopup=BX.PopupWindowManager.create('change_recruiter_popup', null, {content:content,closeIcon:{right:'12px',top:'10px'},autoHide:false,overlay:{opacity:30},draggable:true,closeByEsc:true,titleBar:'Сменить рекрутера',zIndex:20000,buttons:[new BX.PopupWindowButton({text:'Отмена',className:'popup-window-button-link-cancel',events:{click:function(){changePopup.close();}}}),new BX.PopupWindowButton({text:'Сменить рекрутера',className:'popup-window-button-accept',events:{click:function(){var uid=changePopup.contentContainer.querySelector('#change-new-recruiter-id'); var c=changePopup.contentContainer.querySelector('#change-comment'); var f=changePopup.contentContainer.querySelector('#change-recruiter-form'); if(!uid||!uid.value){notify('Выберите нового рекрутера.');return;} if(!c||!c.value.trim()){notify('Комментарий обязателен.');return;} if(f) f.submit();}}})]}); return changePopup;}

    function ensureChangeSelector(targetNode,onPick){ if(changeSelector){try{changeSelector.destroy();}catch(e){} changeSelector=null;} changeSelector=new BX.UI.EntitySelector.Dialog({targetNode:targetNode,context:'change-recruiter',multiple:false,dropdownMode:true,enableSearch:true,zIndex:21000,popupOptions:{zIndex:21000},entities:[{id:'user',options:{inviteEmployeeLink:false}}],events:{'Item:onSelect':function(event){var item=event.getData().item; if(!item) return; var rawId=item.getId(); var uid=(typeof rawId==='number')?rawId:parseInt(String(rawId).replace(/[^\d]/g,''),10)||0; if(item.getEntityId()!=='user'||!uid) return; onPick(uid,item.getTitle()||('ID '+uid)); try{changeSelector.hide();}catch(e){}}}}); return changeSelector;}

    function openChangeRecruiterPopup(elementId,currentRecruiterId){ var p=ensureChangePopup(); p.show(); var elId=p.contentContainer.querySelector('#change-element-id'); var elUid=p.contentContainer.querySelector('#change-new-recruiter-id'); var elPick=p.contentContainer.querySelector('#change-pick-recruiter'); var elSel=p.contentContainer.querySelector('#change-selected-recruiter'); var elComment=p.contentContainer.querySelector('#change-comment'); if(!elId||!elUid||!elPick||!elSel||!elComment){notify('Ошибка окна смены рекрутера.');return;} elId.value=String(elementId||''); elUid.value=''; elSel.textContent='Сотрудник не выбран'; elSel.classList.add('text-muted'); elComment.value=''; var newBtn=elPick.cloneNode(true); elPick.parentNode.replaceChild(newBtn,elPick); newBtn.addEventListener('click',function(){ var d=ensureChangeSelector(newBtn,function(uid,title){ if(currentRecruiterId>0 && uid===currentRecruiterId){notify('Выбран текущий рекрутер. Укажите другого сотрудника.'); return;} elUid.value=String(uid); elSel.textContent=title; elSel.classList.remove('text-muted');}); d.show(); }); }
    var bodyEl = document.getElementById('history-modal-body');
    var titleEl = document.getElementById('history-modal-title');

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

        var changeBtn = e.target.closest ? e.target.closest('.js-change-recruiter-btn') : null;
        if (changeBtn) {
            var elementId = parseInt(changeBtn.getAttribute('data-id') || '0', 10);
            var currentRid = parseInt(changeBtn.getAttribute('data-current-recruiter-id') || '0', 10);
            if (!elementId) { notify('Не удалось определить ID анкеты.'); return; }
            openChangeRecruiterPopup(elementId, currentRid);
            return;
        }

        if (e.target === backdrop || (e.target.closest && e.target.closest('.js-history-close'))) {
            closeModal();
        }
    });
})();
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
