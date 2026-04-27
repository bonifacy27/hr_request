<?php
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Анкеты кандидатов');

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

const CANDIDATE_IBLOCK_ID = 207;
const SOURCE_LIST_URL = 'https://ourtricolortv.nsc.ru/services/lists/207/view/0/?list_section_id=';
const VIEW_URL = 'view.php?id=';
const CREATE_URL = 'create_anketa.php';

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

function fullName($last, $first, $middle)
{
    return trim(implode(' ', array_filter([(string)$last, (string)$first, (string)$middle])));
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

$typeEnumMap = getEnumMap(PROP_TYPE);
$statusEnumMap = getEnumMap(PROP_STATUS);

$search = trim((string)($_GET['q'] ?? ''));
$typeFilter = (int)($_GET['type'] ?? 0);
$statusFilter = (int)($_GET['status'] ?? 0);
$inWorkOnly = (string)($_GET['in_work'] ?? '') === 'Y';

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

    $rows[] = [
        'ID' => $id,
        'DATE_CREATE' => (string)$f['DATE_CREATE'],
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
    $searchNeedle = mb_strtolower($search);
    $rows = array_values(array_filter($rows, static function ($row) use ($searchNeedle) {
        $candidate = mb_strtolower((string)$row['CANDIDATE_FIO']);
        $recruiter = mb_strtolower((string)$row['RECRUITER_FIO']);
        return mb_strpos($candidate, $searchNeedle) !== false || mb_strpos($recruiter, $searchNeedle) !== false;
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
    'Новая' => '#6c757d',
    'В работе' => '#0d6efd',
    'На согласовании' => '#fd7e14',
    'Согласована' => '#198754',
    'Отклонена' => '#dc3545',
    'Закрыта' => '#212529',
];

?>

<div style="margin-bottom:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <a class="ui-btn ui-btn-success" href="<?=h(CREATE_URL)?>">Создать анкету</a>
    <a class="ui-btn ui-btn-light-border" href="<?=h(SOURCE_LIST_URL)?>" target="_blank">Источник списка</a>
</div>

<form method="get" style="margin-bottom:18px; padding:12px; border:1px solid #dfe3e6; border-radius:8px; background:#fafbfc;">
    <div style="display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:10px;">
        <div>
            <label style="display:block; margin-bottom:4px;">Поиск (ФИО кандидата / рекрутера)</label>
            <input type="text" name="q" value="<?=h($search)?>" style="width:100%; padding:6px 8px;" placeholder="Введите ФИО">
        </div>

        <div>
            <label style="display:block; margin-bottom:4px;">Тип анкеты</label>
            <select name="type" style="width:100%; padding:6px 8px;">
                <option value="0">Все</option>
                <?php foreach ($typeEnumMap as $enumId => $enumName): ?>
                    <option value="<?=$enumId?>" <?=$typeFilter === (int)$enumId ? 'selected' : ''?>><?=h($enumName)?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display:block; margin-bottom:4px;">Статус</label>
            <select name="status" style="width:100%; padding:6px 8px;">
                <option value="0">Все</option>
                <?php foreach ($statusEnumMap as $enumId => $enumName): ?>
                    <option value="<?=$enumId?>" <?=$statusFilter === (int)$enumId ? 'selected' : ''?>><?=h($enumName)?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; align-items:flex-end; gap:8px;">
            <label style="display:flex; align-items:center; gap:6px; margin:0 0 6px;">
                <input type="checkbox" name="in_work" value="Y" <?=$inWorkOnly ? 'checked' : ''?>>
                В работе (мои задания БП)
            </label>
        </div>
    </div>

    <div style="margin-top:10px; display:flex; gap:8px;">
        <button type="submit" class="ui-btn ui-btn-primary">Применить</button>
        <a href="list.php" class="ui-btn ui-btn-link">Сбросить</a>
    </div>
</form>

<div style="margin-bottom:8px; color:#6b7280;">Найдено анкет: <?=count($rows)?></div>

<div style="overflow:auto;">
    <table class="ui-table ui-table-hover" style="width:100%; min-width:1200px;">
        <thead>
        <tr>
            <th>ID</th>
            <th>ФИО кандидата</th>
            <th>Дата создания</th>
            <th>Тип анкеты</th>
            <th>Рекрутер</th>
            <th>Статус + История</th>
            <th>Действия</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" style="text-align:center; color:#6b7280;">Ничего не найдено</td></tr>
        <?php endif; ?>

        <?php foreach ($rows as $row):
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
                    <span class="js-open-modal"
                          data-title="Текущие исполнители (анкета #<?=$id?>)"
                          data-content="<?=h(nl2br($executorsText))?>"
                          style="display:inline-block; padding:4px 10px; border-radius:999px; color:#fff; cursor:pointer; background:<?=$badgeColor?>;">
                        <?=h($statusName)?>
                    </span>
                    <span class="js-open-modal"
                          data-title="История (анкета #<?=$id?>)"
                          data-content="<?=h(nl2br($row['HISTORY'] !== '' ? $row['HISTORY'] : 'История отсутствует'))?>"
                          style="margin-left:8px; cursor:pointer; font-size:16px;"
                          title="История">📜</span>
                </td>
                <td style="white-space:nowrap;">
                    <a class="ui-btn ui-btn-link" href="<?=h(VIEW_URL . $id)?>">Открыть</a>
                    <?php if ($taskId > 0): ?>
                        <a class="ui-btn ui-btn-primary" href="<?=h($taskUrl)?>">Задание БП</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="candidate-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
    <div style="width:min(720px,95vw); margin:7vh auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 12px 30px rgba(0,0,0,.25);">
        <div style="padding:12px 14px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <strong id="candidate-modal-title">Детали</strong>
            <button type="button" id="candidate-modal-close" class="ui-btn ui-btn-xs ui-btn-light-border">Закрыть</button>
        </div>
        <div id="candidate-modal-content" style="padding:14px; max-height:65vh; overflow:auto; white-space:normal;"></div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('candidate-modal');
    var title = document.getElementById('candidate-modal-title');
    var content = document.getElementById('candidate-modal-content');
    var closeBtn = document.getElementById('candidate-modal-close');

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.js-open-modal');
        if (!trigger) return;

        title.textContent = trigger.getAttribute('data-title') || 'Детали';
        content.innerHTML = trigger.getAttribute('data-content') || '';
        modal.style.display = 'block';
    });

    function closeModal() {
        modal.style.display = 'none';
        content.innerHTML = '';
    }

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
})();
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
