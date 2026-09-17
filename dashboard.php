<?php
/**
 * Сводный дашборд подбора и адаптации персонала.
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Подбор и адаптация персонала');

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

function dashboardH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dashboardDate(string $value, DateTimeImmutable $fallback): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ?: $fallback;
}

function dashboardPropertyValue(int $iblockId, int $elementId, int $propertyId)
{
    $property = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['ID' => $propertyId])->Fetch();
    return $property['VALUE'] ?? '';
}

function dashboardEnumMap(int $propertyId): array
{
    $map = [];
    $items = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $propertyId]);
    while ($item = $items->Fetch()) {
        $map[(int)$item['ID']] = (string)$item['VALUE'];
    }
    return $map;
}

function dashboardLinkedNames(array $ids, int $iblockId): array
{
    $map = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids) {
        $items = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '@ID' => $ids], false, false, ['ID', 'NAME']);
        while ($item = $items->Fetch()) {
            $map[(int)$item['ID']] = (string)$item['NAME'];
        }
    }
    return $map;
}

function dashboardLinkedPropertyIds(int $iblockId, int $elementId, int $propertyId): array
{
    $ids = [];
    $properties = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['ID' => $propertyId]);
    while ($property = $properties->Fetch()) {
        $id = (int)($property['VALUE'] ?? 0);
        if ($id > 0) $ids[$id] = $id;
    }
    return $ids;
}

function dashboardListUrl(string $url, string $statusParam, int $statusId, string $from, string $to): string
{
    $query = ['dashboard_from' => $from, 'dashboard_to' => $to];
    if ($statusId > 0 && $statusParam !== '') {
        $query[$statusParam] = $statusId;
    }
    return $url . '?' . http_build_query($query);
}

function dashboardTaskUserIds($rawUserId): array
{
    $ids = [];
    if (!is_array($rawUserId) && preg_match_all('/\[(\d+)\]/', (string)$rawUserId, $matches)) {
        foreach ($matches[1] as $id) {
            $ids[(int)$id] = true;
        }
    }
    $parts = is_array($rawUserId) ? $rawUserId : preg_split('/[;,\s]+/', (string)$rawUserId);
    foreach ((array)$parts as $part) {
        $part = trim((string)$part);
        if (preg_match('/^user_(\d+)$/i', $part, $matches)) {
            $ids[(int)$matches[1]] = true;
        } elseif (ctype_digit($part)) {
            $ids[(int)$part] = true;
        }
    }
    return array_keys($ids);
}

function dashboardElementHasCurrentUserTask(int $userId, int $iblockId, int $elementId): bool
{
    if ($userId <= 0 || $iblockId <= 0 || $elementId <= 0 || !class_exists('CBPTaskService')) {
        return false;
    }
    $documentIds = [
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
        ['lists', 'BizprocDocument', "lists_{$iblockId}_{$elementId}"],
        ['lists', 'lists_' . $iblockId . '_group_206', $elementId],
        ['lists', 'lists_' . $iblockId, $elementId],
        ['iblock', 'CIBlockDocument', "iblock_{$iblockId}_{$elementId}"],
    ];
    foreach ($documentIds as $documentId) {
        try {
            $tasks = CBPTaskService::GetList(
                ['ID' => 'DESC'],
                ['DOCUMENT_ID' => $documentId, 'STATUS' => CBPTaskStatus::Running],
                false,
                false,
                ['ID', 'USER_ID']
            );
        } catch (Throwable $exception) {
            continue;
        }
        while ($task = $tasks->GetNext()) {
            if (in_array($userId, dashboardTaskUserIds($task['USER_ID'] ?? ''), true)) {
                return true;
            }
        }
    }
    return false;
}

function dashboardCurrentUserTaskCount(int $userId, int $iblockId, array $elementIds): int
{
    $count = 0;
    foreach (array_unique(array_map('intval', $elementIds)) as $elementId) {
        if (dashboardElementHasCurrentUserTask($userId, $iblockId, $elementId)) {
            ++$count;
        }
    }
    return $count;
}

function dashboardTaskStatusCounts(array $ids, int $iblockId, int $statusPropertyId): array
{
    $counts = [];
    $statusIds = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return $counts;
    }
    $tasks = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, '@ID' => $ids, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
        false,
        false,
        ['ID', 'PROPERTY_' . $statusPropertyId]
    );
    while ($task = $tasks->Fetch()) {
        $statusId = (int)($task['PROPERTY_' . $statusPropertyId . '_VALUE'] ?? 0);
        $statusIds[] = $statusId;
        $counts[$statusId] = ($counts[$statusId] ?? 0) + 1;
    }
    $statusNames = dashboardLinkedNames($statusIds, 361);
    $result = [];
    foreach ($counts as $statusId => $count) {
        $name = $statusNames[$statusId] ?? 'Без статуса';
        $result[$name] = ($result[$name] ?? 0) + $count;
    }
    return $result;
}

$today = new DateTimeImmutable('today');
$defaultFrom = $today->modify('-1 year');
$dateFrom = dashboardDate((string)($_GET['date_from'] ?? ''), $defaultFrom);
$dateTo = dashboardDate((string)($_GET['date_to'] ?? ''), $today);
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$from = $dateFrom->format('Y-m-d');
$to = $dateTo->format('Y-m-d');
$bitrixFrom = $dateFrom->format('d.m.Y') . ' 00:00:00';
$bitrixTo = $dateTo->format('d.m.Y') . ' 23:59:59';

$sections = [
    'staffing' => [
        'title' => 'Заявки на подбор', 'iblock' => 201, 'url' => '/forms/staff_recruitment/staffing/list.php',
        'status' => 1042, 'status_type' => 'enum', 'status_param' => 'f_status', 'accent' => '#2563eb', 'background' => '#f2f7ff',
        'groups' => [
            'На согласовании' => ['Согласование руководителя', 'Согласование C&B', 'Согласование руководителя ОПИА'],
            'В работе' => ['В работе рекрутера', 'Передана в FW', 'Поиск кандидатов', 'Проверка кандидата', 'Указание заказчиков FW', 'Бриф с нанимающим руководителем'],
            'Закрыта' => ['Закрыта', 'Кандидат одобрен', 'Оффер принят'],
            'Отклонены' => ['Заявка не актуальна', 'Ошибка', 'Отклонена', 'Отмена'],
        ],
    ],
    'candidates' => [
        'title' => 'Анкеты кандидатов', 'iblock' => 207, 'url' => '/forms/staff_recruitment/check_candidate/list.php',
        'status' => 1092, 'status_type' => 'enum', 'status_param' => 'status', 'type_property' => 1093,
        'type_value' => 'Профессиональный подбор', 'accent' => '#7c3aed', 'background' => '#f7f3ff',
        'groups' => [
            'В работе' => ['Первичная ссылка', 'Ожидание анкеты', 'Вторичная ссылка', 'Документы получены'],
            'Отклонены' => ['Отклонена'],
            'Одобрено' => ['Согласовано СБ, документы получены', 'Согласовано СБ с ограничениями'],
        ],
    ],
    'offers' => [
        'title' => 'Офферы', 'iblock' => 218, 'url' => '/forms/staff_recruitment/offer/list.php',
        'status' => 1189, 'status_type' => 'enum', 'status_param' => 'f_status', 'accent' => '#ea580c', 'background' => '#fff7ed',
        'groups' => [
            'В работе' => ['Согласование рук. ОПП', 'Согласование C&B', 'Согласование рук. ОМиОР', 'Согласование HRD', 'Согласование рук-ля', 'Доработка', 'Согласовано', 'Оффер сформирован'],
            'Принято' => ['Оффер принят'],
            'Отклонено' => ['Оффер не принят'],
        ],
    ],
    'employees' => [
        'title' => 'Карточки новых сотрудников', 'iblock' => 196, 'url' => '/forms/staff_recruitment/adaptation/list.php',
        'status' => 2930, 'status_type' => 'linked', 'status_iblock' => 374, 'status_param' => 'status', 'accent' => '#059669', 'background' => '#effbf6',
        'groups' => [
            'На адаптации' => ['(Адаптация) Старт адаптации', '(Адаптация) Начало ИС', '(Адаптация) Окончание ИС - 10 рд'],
            'Завершено' => ['(Адаптация) Окончание ИС', '(Адаптация) Финиш адаптации'],
            'Отмена' => ['(Адаптация) Отмена адаптации'],
        ],
    ],
    'plans' => [
        'title' => 'Планы ввода в должность', 'iblock' => 359, 'url' => '/forms/staff_recruitment/plans/list.php',
        'status' => 0, 'status_type' => 'tasks', 'status_param' => '', 'accent' => '#0891b2', 'background' => '#effaff',
        'pvd_tasks_property' => 2761, 'kpi_tasks_property' => 2769,
    ],
];

$currentUserId = (int)$USER->GetID();
$currentUserGroups = array_map('intval', (array)CUser::GetUserGroup($currentUserId));
if (!array_intersect([1, 9, 81, 82], $currentUserGroups)) {
    unset($sections['candidates']);
}
if (!array_intersect([1, 9, 13, 81, 82], $currentUserGroups)) {
    unset($sections['employees']);
}
foreach ($sections as $key => &$section) {
    $section['items'] = [];
    $section['metrics'] = [];
    $section['statuses'] = [];
    $section['status_ids'] = [];
    $statusMap = $section['status_type'] === 'enum' ? dashboardEnumMap($section['status']) : [];
    $linkedIds = [];
    $elementFilter = ['IBLOCK_ID' => $section['iblock'], 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y', '>=DATE_CREATE' => $bitrixFrom, '<=DATE_CREATE' => $bitrixTo];
    if (!empty($section['type_property'])) {
        $typeMap = dashboardEnumMap((int)$section['type_property']);
        $typeId = (int)array_search($section['type_value'], $typeMap, true);
        $elementFilter['PROPERTY_' . (int)$section['type_property']] = $typeId > 0 ? $typeId : -1;
    }
    $elements = CIBlockElement::GetList(
        ['DATE_CREATE' => 'DESC'],
        $elementFilter,
        false,
        false,
        ['ID', 'NAME', 'DATE_CREATE']
    );
    while ($element = $elements->Fetch()) {
        $statusId = $section['status'] ? (int)dashboardPropertyValue($section['iblock'], (int)$element['ID'], $section['status']) : 0;
        if ($section['status_type'] === 'linked' && $statusId) {
            $linkedIds[] = $statusId;
        }
        $section['items'][] = ['id' => (int)$element['ID'], 'status_id' => $statusId];
    }
    $section['my_work_count'] = $section['status_type'] === 'tasks' ? 0 : dashboardCurrentUserTaskCount(
        $currentUserId,
        (int)$section['iblock'],
        array_column($section['items'], 'id')
    );
    if ($section['status_type'] === 'linked') {
        $statusMap = dashboardLinkedNames($linkedIds, (int)$section['status_iblock']);
    }
    $section['total'] = count($section['items']);
    if ($section['status_type'] === 'tasks') {
        $pvdTaskIds = $kpiTaskIds = [];
        $plansInWork = 0;
        foreach ($section['items'] as $item) {
            $planPvdTaskIds = dashboardLinkedPropertyIds((int)$section['iblock'], $item['id'], (int)$section['pvd_tasks_property']);
            $planKpiTaskIds = dashboardLinkedPropertyIds((int)$section['iblock'], $item['id'], (int)$section['kpi_tasks_property']);
            $pvdTaskIds += $planPvdTaskIds;
            $kpiTaskIds += $planKpiTaskIds;
            $hasCurrentUserWork = dashboardElementHasCurrentUserTask($currentUserId, (int)$section['iblock'], $item['id']);
            foreach ([360 => $planPvdTaskIds, 363 => $planKpiTaskIds] as $taskIblockId => $taskIds) {
                foreach ($taskIds as $taskId) {
                    if (dashboardElementHasCurrentUserTask($currentUserId, $taskIblockId, $taskId)) {
                        $hasCurrentUserWork = true;
                        break 2;
                    }
                }
            }
            if ($hasCurrentUserWork) {
                ++$plansInWork;
            }
        }
        $section['my_work_count'] = $plansInWork;
        $section['statuses'] = dashboardTaskStatusCounts($pvdTaskIds, 360, 2767);
        foreach (dashboardTaskStatusCounts($kpiTaskIds, 363, 2805) as $statusName => $count) {
            $section['statuses'][$statusName] = ($section['statuses'][$statusName] ?? 0) + $count;
        }
        arsort($section['statuses']);
        $completedCount = static function (array $ids, int $iblockId, int $statusProperty): int {
            if (!$ids) return 0;
            return (int)CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, '@ID' => array_values($ids), 'PROPERTY_' . $statusProperty => 3347534, 'CHECK_PERMISSIONS' => 'Y'], []);
        };
        $section['metrics'] = [
            'Всего задач ПВД' => count($pvdTaskIds),
            'Выполнено задач ПВД' => $completedCount($pvdTaskIds, 360, 2767),
            'Всего задач KPI' => count($kpiTaskIds),
            'Выполнено задач KPI' => $completedCount($kpiTaskIds, 363, 2805),
        ];
    } else {
        foreach ($section['items'] as $item) {
            $statusName = $statusMap[$item['status_id']] ?? 'Без статуса';
            $section['statuses'][$statusName] = ($section['statuses'][$statusName] ?? 0) + 1;
            if ($item['status_id'] > 0) {
                $section['status_ids'][$statusName] = $item['status_id'];
            }
        }
        arsort($section['statuses']);
        foreach ($section['groups'] as $label => $statuses) {
            $section['metrics'][$label] = count(array_filter($section['items'], static function ($item) use ($statuses, $statusMap) {
                return in_array($statusMap[$item['status_id']] ?? '', $statuses, true);
            }));
        }
    }
}
unset($section);
?>

<style>
.hr-dashboard{--ink:#182230;--muted:#667085;--line:#e4e7ec;max-width:1440px;margin:0 auto 48px;color:var(--ink)}
.hr-hero{position:relative;overflow:hidden;padding:30px 34px;border-radius:24px;background:linear-gradient(125deg,#172554 0%,#1d4ed8 56%,#0ea5e9 100%);color:#fff;box-shadow:0 18px 45px rgba(29,78,216,.19)}
.hr-hero:after{content:"";position:absolute;width:310px;height:310px;right:-80px;top:-150px;border-radius:50%;background:rgba(255,255,255,.12)}
.hr-eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:11px;font-weight:700;opacity:.75}.hr-hero h1{margin:6px 0 8px;font-size:30px;color:#fff}.hr-hero p{margin:0;max-width:720px;line-height:1.55;opacity:.82}
.hr-filter{position:relative;z-index:1;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:24px}.hr-field label{display:block;margin:0 0 6px;font-size:12px;font-weight:600;opacity:.82}.hr-field input{height:40px;padding:0 12px;border:1px solid rgba(255,255,255,.34);border-radius:10px;background:rgba(255,255,255,.14);color:#fff;color-scheme:dark}.hr-button{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 18px;border:0;border-radius:10px;background:#fff;color:#1d4ed8;font-weight:700;text-decoration:none;cursor:pointer}.hr-button:hover{color:#1e40af;text-decoration:none}
.hr-section-head{display:flex;align-items:end;justify-content:space-between;margin:30px 2px 13px}.hr-section-head h2{margin:0;font-size:21px}.hr-section-head span{color:var(--muted);font-size:13px}
.hr-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.hr-card{border:1px solid rgba(16,24,40,.08);border-radius:18px;overflow:hidden;box-shadow:0 6px 22px rgba(16,24,40,.045)}.hr-card-top{height:4px}.hr-card-body{padding:20px}.hr-card-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.hr-card-title h3{margin:0;font-size:17px}.hr-open{color:#2563eb;text-decoration:none;font-weight:600;font-size:13px;white-space:nowrap}.hr-open:hover{text-decoration:underline}.hr-card-metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:18px}.hr-mini{padding:12px;border:1px solid rgba(255,255,255,.7);border-radius:11px;background:rgba(255,255,255,.68)}.hr-mini span{display:block;color:var(--muted);font-size:11px;line-height:1.3}.hr-mini strong{display:block;margin-top:4px;font-size:21px}.hr-mini.total{grid-column:1/-1}.hr-mini.total strong{font-size:26px}.hr-mini.my-work{border:2px solid currentColor;background:#fff;box-shadow:0 4px 12px rgba(16,24,40,.08)}.hr-mini.my-work span{color:var(--ink);font-weight:700}.hr-mini.my-work strong{color:#dc2626}
.hr-status-title{margin:18px 0 9px;color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}.hr-statuses{display:flex;flex-wrap:wrap;gap:7px}.hr-status{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border:1px solid rgba(16,24,40,.06);border-radius:999px;background:rgba(255,255,255,.72);color:#344054;text-decoration:none;font-size:12px;line-height:1.2}.hr-status:hover{border-color:#93b4ff;background:#fff;color:#1d4ed8;text-decoration:none}.hr-status b{font-weight:700}.hr-empty{color:var(--muted);font-size:13px}
@media(max-width:800px){.hr-grid{grid-template-columns:1fr}.hr-hero{padding:24px 20px}.hr-hero h1{font-size:25px}}@media(min-width:1200px){.hr-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
</style>

<div class="hr-dashboard">
    <section class="hr-hero">
        <div class="hr-eyebrow">HR-процессы в одном окне</div>
        <h1>Подбор и адаптация</h1>
        <p>Контролируйте свои процессы подбора персонала от заявки на подбор до адаптации новых сотрудников. Показатели учитывают доступные вам заявки, созданные в выбранном периоде.</p>
        <form class="hr-filter" method="get">
            <div class="hr-field"><label for="date-from">Дата создания с</label><input id="date-from" type="date" name="date_from" value="<?=dashboardH($from)?>"></div>
            <div class="hr-field"><label for="date-to">по</label><input id="date-to" type="date" name="date_to" value="<?=dashboardH($to)?>"></div>
            <button class="hr-button" type="submit">Применить</button>
            <a class="hr-button" href="?date_from=<?=$today->modify('-30 days')->format('Y-m-d')?>&amp;date_to=<?=$today->format('Y-m-d')?>">30 дней</a>
        </form>
    </section>

    <div class="hr-section-head"><h2>Воронка процессов</h2><span><?=dashboardH($dateFrom->format('d.m.Y'))?> — <?=dashboardH($dateTo->format('d.m.Y'))?></span></div>
    <div class="hr-grid">
        <?php foreach ($sections as $section): ?>
            <article class="hr-card" style="background:<?=dashboardH($section['background'])?>">
                <div class="hr-card-top" style="background:<?=dashboardH($section['accent'])?>"></div>
                <div class="hr-card-body">
                    <div class="hr-card-title">
                        <h3><?=dashboardH($section['title'])?></h3>
                        <a class="hr-open" href="<?=dashboardH(dashboardListUrl($section['url'], '', 0, $from, $to))?>">Открыть список →</a>
                    </div>
                    <div class="hr-card-metrics">
                        <div class="hr-mini total"><span>Всего</span><strong><?=$section['total']?></strong></div>
                        <?php if ($section['my_work_count'] > 0): ?>
                            <div class="hr-mini my-work"><span>У меня в работе</span><strong><?=$section['my_work_count']?></strong></div>
                        <?php endif; ?>
                        <?php foreach ($section['metrics'] as $label => $count): ?>
                            <div class="hr-mini"><span><?=dashboardH($label)?></span><strong><?=$count?></strong></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="hr-status-title"><?=$section['status_type'] === 'tasks' ? 'По статусам задач' : 'По статусам'?></div>
                    <div class="hr-statuses">
                        <?php if (!$section['statuses']): ?><span class="hr-empty">Нет данных за период</span><?php endif; ?>
                        <?php foreach ($section['statuses'] as $status => $count): ?>
                            <?php if ($section['status_param'] !== ''): ?>
                                <a class="hr-status" href="<?=dashboardH(dashboardListUrl($section['url'], $section['status_param'], (int)($section['status_ids'][$status] ?? 0), $from, $to))?>"><span><?=dashboardH($status)?></span><b><?=$count?></b></a>
                            <?php else: ?>
                                <span class="hr-status"><span><?=dashboardH($status)?></span><b><?=$count?></b></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
