<?php
/**
 * Сводный дашборд подбора и адаптации персонала.
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Подбор и адаптация персонала');

if (!Loader::includeModule('iblock')) {
    ShowError('Модуль iblock не установлен.');
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

function dashboardIsClosed(string $status): bool
{
    $status = mb_strtolower(trim($status));
    foreach (['закрыт', 'заверш', 'отклон', 'отмен', 'не принят', 'прерван'] as $marker) {
        if (mb_strpos($status, $marker) !== false) {
            return true;
        }
    }
    return false;
}

function dashboardListUrl(string $url, string $statusParam, int $statusId, string $from, string $to): string
{
    $query = ['dashboard_from' => $from, 'dashboard_to' => $to];
    if ($statusId > 0 && $statusParam !== '') {
        $query[$statusParam] = $statusId;
    }
    return $url . '?' . http_build_query($query);
}

$today = new DateTimeImmutable('today');
$defaultFrom = $today->modify('first day of this month');
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
    'staffing' => ['title' => 'Заявки на подбор', 'short' => 'Заявки', 'iblock' => 201, 'url' => '/forms/staff_recruitment/staffing/list.php', 'status' => 1042, 'status_type' => 'enum', 'status_param' => 'f_status', 'deadline' => 0, 'stale' => 30, 'accent' => '#2563eb'],
    'candidates' => ['title' => 'Анкеты кандидатов', 'short' => 'Кандидаты', 'iblock' => 207, 'url' => '/forms/staff_recruitment/check_candidate/list.php', 'status' => 1092, 'status_type' => 'enum', 'status_param' => 'status', 'deadline' => 0, 'stale' => 14, 'accent' => '#7c3aed'],
    'offers' => ['title' => 'Офферы', 'short' => 'Офферы', 'iblock' => 218, 'url' => '/forms/staff_recruitment/offer/list.php', 'status' => 1189, 'status_type' => 'enum', 'status_param' => 'f_status', 'deadline' => 1159, 'stale' => 14, 'accent' => '#ea580c'],
    'employees' => ['title' => 'Карточки новых сотрудников', 'short' => 'Сотрудники', 'iblock' => 196, 'url' => '/forms/staff_recruitment/adaptation/list.php', 'status' => 2930, 'status_type' => 'linked', 'status_iblock' => 374, 'status_param' => 'status', 'deadline' => 964, 'stale' => 30, 'accent' => '#059669'],
    'plans' => ['title' => 'Планы ввода в должность', 'short' => 'Планы', 'iblock' => 359, 'url' => '/forms/staff_recruitment/plans/list.php', 'status' => 0, 'status_type' => 'derived', 'status_param' => '', 'deadline' => 2802, 'employment' => 2776, 'stale' => 30, 'accent' => '#0891b2'],
];

$grandTotal = $grandWork = $grandOverdue = 0;
foreach ($sections as $key => &$section) {
    $section['items'] = [];
    $section['statuses'] = [];
    $section['status_ids'] = [];
    $statusMap = $section['status_type'] === 'enum' ? dashboardEnumMap($section['status']) : [];
    $linkedIds = [];
    $elements = CIBlockElement::GetList(
        ['DATE_CREATE' => 'DESC'],
        ['IBLOCK_ID' => $section['iblock'], 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y', '>=DATE_CREATE' => $bitrixFrom, '<=DATE_CREATE' => $bitrixTo],
        false,
        false,
        ['ID', 'NAME', 'DATE_CREATE']
    );
    while ($element = $elements->Fetch()) {
        $statusId = $section['status'] ? (int)dashboardPropertyValue($section['iblock'], (int)$element['ID'], $section['status']) : 0;
        if ($section['status_type'] === 'linked' && $statusId) {
            $linkedIds[] = $statusId;
        }
        $deadlineRaw = $section['deadline'] ? dashboardPropertyValue($section['iblock'], (int)$element['ID'], $section['deadline']) : '';
        $section['items'][] = ['id' => (int)$element['ID'], 'created' => (string)$element['DATE_CREATE'], 'status_id' => $statusId, 'deadline' => (string)$deadlineRaw];
    }
    if ($section['status_type'] === 'linked') {
        $statusMap = dashboardLinkedNames($linkedIds, (int)$section['status_iblock']);
    }
    foreach ($section['items'] as &$item) {
        $created = new DateTimeImmutable($item['created']);
        $deadline = $item['deadline'] !== '' ? new DateTimeImmutable($item['deadline']) : null;
        if ($section['status_type'] === 'derived') {
            $status = $deadline && $deadline < $today ? 'Срок завершён' : ($deadline ? 'В процессе' : 'Планируется');
        } else {
            $status = $statusMap[$item['status_id']] ?? 'Без статуса';
        }
        $closed = dashboardIsClosed($status) || ($section['status_type'] === 'derived' && $status === 'Срок завершён');
        $overdue = !$closed && (($deadline && $deadline < $today) || (!$deadline && $created < $today->modify('-' . (int)$section['stale'] . ' days')));
        $item['status'] = $status;
        $item['closed'] = $closed;
        $item['overdue'] = $overdue;
        $section['statuses'][$status] = ($section['statuses'][$status] ?? 0) + 1;
        if ($item['status_id']) $section['status_ids'][$status] = $item['status_id'];
    }
    unset($item);
    arsort($section['statuses']);
    $section['total'] = count($section['items']);
    $section['work'] = count(array_filter($section['items'], static fn($item) => !$item['closed']));
    $section['overdue'] = count(array_filter($section['items'], static fn($item) => $item['overdue']));
    $grandTotal += $section['total'];
    $grandWork += $section['work'];
    $grandOverdue += $section['overdue'];
}
unset($section);
?>

<style>
.hr-dashboard{--ink:#182230;--muted:#667085;--line:#e4e7ec;max-width:1440px;margin:0 auto 48px;color:var(--ink)}
.hr-hero{position:relative;overflow:hidden;padding:30px 34px;border-radius:24px;background:linear-gradient(125deg,#172554 0%,#1d4ed8 56%,#0ea5e9 100%);color:#fff;box-shadow:0 18px 45px rgba(29,78,216,.19)}
.hr-hero:after{content:"";position:absolute;width:310px;height:310px;right:-80px;top:-150px;border-radius:50%;background:rgba(255,255,255,.12)}
.hr-eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:11px;font-weight:700;opacity:.75}.hr-hero h1{margin:6px 0 8px;font-size:30px;color:#fff}.hr-hero p{margin:0;max-width:720px;line-height:1.55;opacity:.82}
.hr-filter{position:relative;z-index:1;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:24px}.hr-field label{display:block;margin:0 0 6px;font-size:12px;font-weight:600;opacity:.82}.hr-field input{height:40px;padding:0 12px;border:1px solid rgba(255,255,255,.34);border-radius:10px;background:rgba(255,255,255,.14);color:#fff;color-scheme:dark}.hr-button{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 18px;border:0;border-radius:10px;background:#fff;color:#1d4ed8;font-weight:700;text-decoration:none;cursor:pointer}.hr-button:hover{color:#1e40af;text-decoration:none}
.hr-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:18px 0}.hr-kpi{padding:19px 22px;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(16,24,40,.04)}.hr-kpi span{display:block;color:var(--muted);font-size:13px}.hr-kpi strong{display:block;margin-top:5px;font-size:28px}.hr-kpi.danger strong{color:#dc2626}
.hr-section-head{display:flex;align-items:end;justify-content:space-between;margin:30px 2px 13px}.hr-section-head h2{margin:0;font-size:21px}.hr-section-head span{color:var(--muted);font-size:13px}
.hr-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.hr-card{border:1px solid var(--line);border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 6px 22px rgba(16,24,40,.045)}.hr-card-top{height:4px}.hr-card-body{padding:20px}.hr-card-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.hr-card-title h3{margin:0;font-size:17px}.hr-open{color:#2563eb;text-decoration:none;font-weight:600;font-size:13px;white-space:nowrap}.hr-open:hover{text-decoration:underline}.hr-card-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:18px 0}.hr-mini{padding:11px;border-radius:11px;background:#f8fafc}.hr-mini span{display:block;color:var(--muted);font-size:11px}.hr-mini strong{display:block;margin-top:3px;font-size:20px}.hr-mini.overdue strong{color:#dc2626}
.hr-status-title{margin-bottom:8px;color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}.hr-statuses{display:flex;flex-wrap:wrap;gap:7px}.hr-status{display:inline-flex;gap:6px;padding:6px 9px;border-radius:999px;background:#f2f4f7;color:#344054;text-decoration:none;font-size:12px}.hr-status:hover{background:#e8efff;color:#1d4ed8;text-decoration:none}.hr-status b{font-weight:700}.hr-empty{color:var(--muted);font-size:13px}
@media(max-width:800px){.hr-grid{grid-template-columns:1fr}.hr-kpis{grid-template-columns:1fr}.hr-hero{padding:24px 20px}.hr-hero h1{font-size:25px}}@media(min-width:1200px){.hr-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
</style>

<div class="hr-dashboard">
    <section class="hr-hero">
        <div class="hr-eyebrow">HR-процессы в одном окне</div>
        <h1>Подбор и адаптация</h1>
        <p>Контролируйте путь от заявки на подбор до завершения плана ввода в должность. Показатели учитывают доступные вам элементы, созданные в выбранном периоде.</p>
        <form class="hr-filter" method="get">
            <div class="hr-field"><label for="date-from">Дата создания с</label><input id="date-from" type="date" name="date_from" value="<?=dashboardH($from)?>"></div>
            <div class="hr-field"><label for="date-to">по</label><input id="date-to" type="date" name="date_to" value="<?=dashboardH($to)?>"></div>
            <button class="hr-button" type="submit">Применить</button>
            <a class="hr-button" href="?date_from=<?=$today->modify('-30 days')->format('Y-m-d')?>&amp;date_to=<?=$today->format('Y-m-d')?>">30 дней</a>
        </form>
    </section>

    <div class="hr-kpis">
        <div class="hr-kpi"><span>Всего объектов за период</span><strong><?=$grandTotal?></strong></div>
        <div class="hr-kpi"><span>Сейчас в работе</span><strong><?=$grandWork?></strong></div>
        <div class="hr-kpi danger"><span>Требуют внимания</span><strong><?=$grandOverdue?></strong></div>
    </div>

    <div class="hr-section-head"><h2>Воронка процессов</h2><span><?=dashboardH($dateFrom->format('d.m.Y'))?> — <?=dashboardH($dateTo->format('d.m.Y'))?></span></div>
    <div class="hr-grid">
        <?php foreach ($sections as $section): ?>
            <article class="hr-card">
                <div class="hr-card-top" style="background:<?=dashboardH($section['accent'])?>"></div>
                <div class="hr-card-body">
                    <div class="hr-card-title">
                        <h3><?=dashboardH($section['title'])?></h3>
                        <a class="hr-open" href="<?=dashboardH(dashboardListUrl($section['url'], '', 0, $from, $to))?>">Открыть список →</a>
                    </div>
                    <div class="hr-card-metrics">
                        <div class="hr-mini"><span>Всего</span><strong><?=$section['total']?></strong></div>
                        <div class="hr-mini"><span>В работе</span><strong><?=$section['work']?></strong></div>
                        <div class="hr-mini overdue"><span>Просрочено</span><strong><?=$section['overdue']?></strong></div>
                    </div>
                    <div class="hr-status-title">По статусам</div>
                    <div class="hr-statuses">
                        <?php if (!$section['statuses']): ?><span class="hr-empty">Нет данных за период</span><?php endif; ?>
                        <?php foreach (array_slice($section['statuses'], 0, 6, true) as $status => $count): ?>
                            <a class="hr-status" href="<?=dashboardH(dashboardListUrl($section['url'], $section['status_param'], (int)($section['status_ids'][$status] ?? 0), $from, $to))?>"><span><?=dashboardH($status)?></span><b><?=$count?></b></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
