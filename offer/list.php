<?php
/**
 * list.php — список заявок на оффер (ИБ 218)
 * URL: /forms/staff_recruiting/offer/list.php
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\Context;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Заявки на оффер');

if (!Loader::includeModule('main')) { ShowError('Модуль main не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
if (!Loader::includeModule('iblock')) { ShowError('Модуль iblock не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
if (!Loader::includeModule('bizproc')) { ShowError('Модуль bizproc не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }

const IBL_OFFERS = 218;
const PROP_CANDIDATE_FIO = 'PROPERTY_1157';
const PROP_PLANNED_SEND_DATE = 'PROPERTY_1159';
const PROP_POSITION = 'PROPERTY_1161';
const PROP_ORGANIZATION = 'PROPERTY_2753';
const PROP_RECRUITER = 'PROPERTY_1190';
const PROP_STATUS = 'PROPERTY_1189';
const CB_GLOBAL_VAR_ID = 'Variable1722502594854';
const RECRUIT_HEAD_GLOBAL_VAR_ID = 'Variable1722503621093';

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buildUrl(array $paramsToSet = [], array $paramsToUnset = []): string
{
    $query = $_GET;
    foreach ($paramsToUnset as $k) {
        unset($query[$k]);
    }
    foreach ($paramsToSet as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return $path . (empty($query) ? '' : '?' . http_build_query($query));
}

function sortLink(string $key, string $title, string $currentSort, string $currentDir): string
{
    $dir = ($currentSort === $key && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $url = buildUrl(['sort' => $key, 'dir' => $dir, 'PAGEN_1' => 1]);
    $arrow = '';
    if ($currentSort === $key) {
        $arrow = $currentDir === 'ASC' ? ' ▲' : ' ▼';
    }
    return '<a href="' . h($url) . '">' . h($title) . $arrow . '</a>';
}

function userIdFromValue($raw): int
{
    $value = trim((string)$raw);
    if ($value === '') return 0;
    if (stripos($value, 'user_') === 0) return (int)substr($value, 5);
    if (preg_match('/(\d+)/', $value, $m)) return (int)$m[1];
    return (int)$value;
}

function formatUserName(array $u): string
{
    $tmpl = \CSite::GetNameFormat(false);
    return trim(\CUser::FormatName($tmpl, $u, true, false)) ?: ($u['LOGIN'] ?? ('user#' . (int)$u['ID']));
}

function getGlobalVarUserList(string $varId): array
{
    $users = [];
    try {
        $conn = \Bitrix\Main\Application::getConnection();
        $sqlVarId = $conn->getSqlHelper()->forSql($varId);
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

function getDocumentIdCandidates(int $iblockId, int $elementId): array
{
    return [
        ['lists', 'BizprocDocument', "lists_{$iblockId}_{$elementId}"],
        ['iblock', 'CIBlockDocument', "iblock_{$iblockId}_{$elementId}"],
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $elementId],
    ];
}

function getRunningTasks(int $elementId, int $iblockId): array
{
    $tasks = [];
    foreach (getDocumentIdCandidates($iblockId, $elementId) as $docIdCandidate) {
        try {
            $rs = \CBPTaskService::GetList(
                ['ID' => 'ASC'],
                ['DOCUMENT_ID' => $docIdCandidate, 'STATUS' => \CBPTaskStatus::Running],
                false,
                false,
                ['ID', 'USER_ID', 'NAME']
            );
            while ($t = $rs->GetNext()) {
                $tid = (int)($t['ID'] ?? 0);
                if ($tid <= 0) continue;
                $tasks[$tid] = [
                    'ID' => $tid,
                    'USER_ID' => (int)($t['USER_ID'] ?? 0),
                    'NAME' => (string)($t['NAME'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
        }
    }
    return array_values($tasks);
}

function getBizprocTaskUrl(int $taskId, ?int $userId = null): string
{
    $uid = $userId ?: (int)$GLOBALS['USER']->GetID();
    if (class_exists('\\CBPTaskService')) {
        if (method_exists('\\CBPTaskService', 'GetTaskUrl')) {
            try { return (string)\CBPTaskService::GetTaskUrl($taskId, $uid); } catch (\Throwable $e) {}
        }
        if (method_exists('\\CBPTaskService', 'GetTaskURL')) {
            try { return (string)\CBPTaskService::GetTaskURL($taskId, $uid); } catch (\Throwable $e) {}
        }
    }
    return '/company/personal/bizproc/' . $taskId . '/';
}

$request = Context::getCurrent()->getRequest();
$q = trim((string)$request->get('q'));
$fRecruiter = (int)$request->get('f_recruiter');
$fStatus = (int)$request->get('f_status');
$sort = strtoupper((string)$request->get('sort') ?: 'ID');
$dir = strtoupper((string)$request->get('dir') ?: 'DESC');
$pageSize = 50;

$sortable = ['ID', 'CANDIDATE_FIO', 'DATE_CREATE', 'STATUS'];
if (!in_array($sort, $sortable, true)) $sort = 'ID';
if (!in_array($dir, ['ASC', 'DESC'], true)) $dir = 'DESC';

$currentUserId = (int)$USER->GetID();
$currentUserTagLower = mb_strtolower('user_' . $currentUserId);
$isAdmin = $USER->IsAdmin();
$cbUsers = getGlobalVarUserList(CB_GLOBAL_VAR_ID);
$recruitHeads = getGlobalVarUserList(RECRUIT_HEAD_GLOBAL_VAR_ID);
$isCbManager = in_array($currentUserTagLower, $cbUsers, true);
$isRecruitHead = in_array($currentUserTagLower, $recruitHeads, true);

$statusEnumOptions = [];
$rsEnum = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['IBLOCK_ID' => IBL_OFFERS, 'PROPERTY_ID' => 1189]);
while ($e = $rsEnum->Fetch()) {
    $statusEnumOptions[(int)$e['ID']] = (string)$e['VALUE'];
}

$filter = [
    'IBLOCK_ID' => IBL_OFFERS,
    'ACTIVE' => 'Y',
    'CHECK_PERMISSIONS' => 'Y',
];
if ($q !== '') $filter['%' . PROP_CANDIDATE_FIO] = $q;
if ($fRecruiter > 0) $filter[PROP_RECRUITER] = $fRecruiter;
if ($fStatus > 0) $filter[PROP_STATUS] = $fStatus;

$arOrder = ['ID' => 'DESC'];
if ($sort === 'ID') $arOrder = ['ID' => $dir];
if ($sort === 'DATE_CREATE') $arOrder = ['DATE_CREATE' => $dir];

$arSelect = [
    'ID', 'DATE_CREATE',
    PROP_CANDIDATE_FIO,
    PROP_PLANNED_SEND_DATE,
    PROP_POSITION,
    PROP_ORGANIZATION,
    PROP_RECRUITER,
    PROP_STATUS,
    'PREVIEW_TEXT',
];

$res = CIBlockElement::GetList($arOrder, $filter, false, ['nPageSize' => $pageSize, 'bShowAll' => false], $arSelect);

$items = [];
$userIds = [];

while ($ob = $res->GetNextElement()) {
    $f = $ob->GetFields();
    $id = (int)$f['ID'];
    $recruiterId = userIdFromValue($f[PROP_RECRUITER . '_VALUE'] ?? '');

    $tasks = getRunningTasks($id, IBL_OFFERS);
    $taskIdForCurrentUser = 0;
    foreach ($tasks as $t) {
        if ((int)$t['USER_ID'] === $currentUserId && $taskIdForCurrentUser <= 0) {
            $taskIdForCurrentUser = (int)$t['ID'];
        }
    }

    $items[] = [
        'ID' => $id,
        'DATE_CREATE' => (string)$f['DATE_CREATE'],
        'CANDIDATE_FIO' => (string)($f[PROP_CANDIDATE_FIO . '_VALUE'] ?? ''),
        'PLANNED_SEND_DATE' => (string)($f[PROP_PLANNED_SEND_DATE . '_VALUE'] ?? ''),
        'POSITION' => (string)($f[PROP_POSITION . '_VALUE'] ?? ''),
        'ORGANIZATION' => (string)($f[PROP_ORGANIZATION . '_VALUE'] ?? ''),
        'RECRUITER_ID' => $recruiterId,
        'STATUS' => (string)($f[PROP_STATUS . '_VALUE'] ?? ''),
        'STATUS_HISTORY' => (string)($f['PREVIEW_TEXT'] ?? ''),
        'TASK_ID_FOR_CURRENT_USER' => $taskIdForCurrentUser,
        'VIEW_URL' => '/forms/staff_recruiting/offer/view_offer.php?id=' . $id,
        'EDIT_URL' => '/forms/staff_recruiting/offer/edit_offer.php?id=' . $id,
    ];

    if ($recruiterId > 0) $userIds[$recruiterId] = true;
}

if (in_array($sort, ['CANDIDATE_FIO', 'STATUS'], true)) {
    usort($items, static function (array $a, array $b) use ($sort, $dir) {
        $mul = ($dir === 'ASC') ? 1 : -1;
        return $mul * strcmp(
            mb_strtolower((string)($a[$sort] ?? ''), 'UTF-8'),
            mb_strtolower((string)($b[$sort] ?? ''), 'UTF-8')
        );
    });
}

$ids = array_keys($userIds);
$userMap = [];
if (!empty($ids)) {
    $rsU = \Bitrix\Main\UserTable::getList([
        'filter' => ['@ID' => $ids],
        'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'],
    ]);
    while ($u = $rsU->fetch()) {
        $u['ID'] = (int)$u['ID'];
        $userMap[$u['ID']] = $u;
    }
}

$recruiterOptions = [];
$rsRecruiters = CIBlockElement::GetList(
    [],
    ['IBLOCK_ID' => IBL_OFFERS, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
    false,
    false,
    ['ID', PROP_RECRUITER]
);
while ($el = $rsRecruiters->Fetch()) {
    $uid = userIdFromValue($el[PROP_RECRUITER . '_VALUE'] ?? '');
    if ($uid > 0) $recruiterOptions[$uid] = true;
}
$recruiterOptions = array_map('intval', array_keys($recruiterOptions));

$recruiterOptionUsers = [];
if (!empty($recruiterOptions)) {
    $rsRU = \Bitrix\Main\UserTable::getList([
        'filter' => ['@ID' => $recruiterOptions],
        'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'],
    ]);
    while ($u = $rsRU->fetch()) {
        $u['ID'] = (int)$u['ID'];
        $recruiterOptionUsers[$u['ID']] = $u;
    }
    uasort($recruiterOptionUsers, static function ($a, $b) {
        return strcmp(mb_strtolower(formatUserName($a), 'UTF-8'), mb_strtolower(formatUserName($b), 'UTF-8'));
    });
}

function navPageUrl(int $pageNum): string
{
    return buildUrl(['PAGEN_1' => $pageNum]);
}
?>
<style>
.offer-list-page .toolbar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:12px; }
.offer-list-page .toolbar .btn-primary { background:#2563eb; border-color:#2563eb; color:#fff; padding:7px 12px; border-radius:6px; text-decoration:none; }
.offer-list-page .toolbar form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0; }
.offer-list-page table { width:100%; border-collapse:collapse; }
.offer-list-page th, .offer-list-page td { border:1px solid #d1d5db; padding:8px; vertical-align:top; }
.offer-list-page th { background:#f8fafc; }
.offer-list-page .actions { display:flex; flex-wrap:wrap; gap:6px; }
.offer-list-page .btn { display:inline-block; padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; }
.offer-list-page .btn-info { background:#0ea5e9; border-color:#0ea5e9; color:#fff; }
.offer-list-page .muted { color:#6b7280; }
.offer-list-page .pagination { margin-top:12px; display:flex; gap:6px; flex-wrap:wrap; }
.offer-list-page .pagination a, .offer-list-page .pagination span { padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; }
.offer-list-page .pagination .active { background:#2563eb; border-color:#2563eb; color:#fff; }
</style>

<div class="offer-list-page">
    <div class="toolbar">
        <a href="/forms/staff_recruiting/offer/create_offer.php" class="btn-primary">Создать оффер</a>

        <form method="get" action="">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Поиск по ФИО кандидата">

            <select name="f_recruiter">
                <option value="0">Все рекрутеры</option>
                <?php foreach ($recruiterOptionUsers as $uid => $u): ?>
                    <option value="<?= (int)$uid ?>" <?= $fRecruiter === (int)$uid ? 'selected' : '' ?>>
                        <?= h(formatUserName($u)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="f_status">
                <option value="0">Все статусы</option>
                <?php foreach ($statusEnumOptions as $sid => $sname): ?>
                    <option value="<?= (int)$sid ?>" <?= $fStatus === (int)$sid ? 'selected' : '' ?>><?= h($sname) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn">Применить</button>
            <a href="<?= h(buildUrl([], ['q', 'f_recruiter', 'f_status', 'sort', 'dir', 'PAGEN_1'])) ?>" class="btn">Сбросить</a>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th><?= sortLink('ID', 'ID', $sort, $dir) ?></th>
            <th><?= sortLink('CANDIDATE_FIO', 'Полное ФИО кандидата', $sort, $dir) ?></th>
            <th>Планируемая дата отправки оффера кандидату</th>
            <th>Должность</th>
            <th>Юридическое лицо</th>
            <th>Рекрутер</th>
            <th><?= sortLink('STATUS', 'Статус + история', $sort, $dir) ?></th>
            <th><?= sortLink('DATE_CREATE', 'Дата создания', $sort, $dir) ?></th>
            <th>Действия</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="9" class="muted">Ничего не найдено.</td></tr>
        <?php else: ?>
            <?php foreach ($items as $row): ?>
                <?php
                $recruiterId = (int)$row['RECRUITER_ID'];
                $isRecruiterForOffer = ($recruiterId > 0 && $recruiterId === $currentUserId);
                $canManage = $isAdmin || $isRecruiterForOffer || $isCbManager || $isRecruitHead;
                $taskId = (int)$row['TASK_ID_FOR_CURRENT_USER'];
                $taskUrl = $taskId > 0 ? getBizprocTaskUrl($taskId, $currentUserId) : '';
                ?>
                <tr>
                    <td><?= (int)$row['ID'] ?></td>
                    <td><?= h($row['CANDIDATE_FIO'] ?: '—') ?></td>
                    <td><?= h($row['PLANNED_SEND_DATE'] ?: '—') ?></td>
                    <td><?= h($row['POSITION'] ?: '—') ?></td>
                    <td><?= h($row['ORGANIZATION'] ?: '—') ?></td>
                    <td><?= h(isset($userMap[$recruiterId]) ? formatUserName($userMap[$recruiterId]) : '—') ?></td>
                    <td>
                        <div><strong><?= h($row['STATUS'] ?: '—') ?></strong></div>
                        <?php if (trim($row['STATUS_HISTORY']) !== ''): ?>
                            <div class="muted" style="white-space:pre-line; margin-top:4px;"><?= h($row['STATUS_HISTORY']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($row['DATE_CREATE']) ?></td>
                    <td>
                        <div class="actions">
                            <?php if ($canManage): ?>
                                <a class="btn" href="<?= h($row['VIEW_URL']) ?>" target="_blank" rel="noopener">Просмотр</a>
                                <a class="btn" href="<?= h($row['EDIT_URL']) ?>" target="_blank" rel="noopener">Редактирование</a>
                            <?php endif; ?>
                            <?php if ($taskUrl !== ''): ?>
                                <a class="btn btn-info" href="<?= h($taskUrl) ?>" target="_blank" rel="noopener">Перейти в задание</a>
                            <?php endif; ?>
                            <?php if (!$canManage && $taskUrl === ''): ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ((int)$res->NavPageCount > 1): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= (int)$res->NavPageCount; $p++): ?>
                <?php if ($p === (int)$res->NavPageNomer): ?>
                    <span class="active"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= h(navPageUrl($p)) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
