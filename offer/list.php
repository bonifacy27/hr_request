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
CJSCore::Init(['popup']);

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

function extractElementIdFromDocumentId($documentId, int $iblockId): int
{
    if (is_array($documentId)) {
        $candidate = $documentId[2] ?? '';
        if (is_numeric($candidate)) return (int)$candidate;
        $documentId = (string)$candidate;
    }

    $raw = trim((string)$documentId);
    if ($raw === '') return 0;

    if (preg_match('/(?:lists|iblock)_' . (int)$iblockId . '_(\d+)/', $raw, $m)) {
        return (int)$m[1];
    }
    if (is_numeric($raw)) {
        return (int)$raw;
    }
    return 0;
}

function getCurrentUserRunningTaskMapForOffers(int $userId, int $iblockId): array
{
    static $cache = [];
    $cacheKey = $iblockId . ':' . $userId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $map = [];
    if ($userId <= 0) {
        $cache[$cacheKey] = $map;
        return $map;
    }

    try {
        $rs = \CBPTaskService::GetList(
            ['ID' => 'ASC'],
            ['USER_ID' => $userId, 'STATUS' => \CBPTaskStatus::Running],
            false,
            false,
            ['ID', 'DOCUMENT_ID']
        );
        while ($t = $rs->GetNext()) {
            $taskId = (int)($t['ID'] ?? 0);
            if ($taskId <= 0) continue;

            $elementId = extractElementIdFromDocumentId($t['DOCUMENT_ID'] ?? '', $iblockId);
            if ($elementId <= 0 && !empty($t['DOCUMENT_ID'])) {
                $docRaw = is_array($t['DOCUMENT_ID']) ? json_encode($t['DOCUMENT_ID'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$t['DOCUMENT_ID'];
                if (preg_match('/(?:lists|iblock)_' . (int)$iblockId . '_(\d+)/', $docRaw, $m)) {
                    $elementId = (int)$m[1];
                }
            }

            if ($elementId > 0 && !isset($map[$elementId])) {
                $map[$elementId] = $taskId;
            }
        }
    } catch (\Throwable $e) {
    }

    $cache[$cacheKey] = $map;
    return $map;
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
$pageSize = 20;

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
if ($sort === 'CANDIDATE_FIO') $arOrder = [PROP_CANDIDATE_FIO => $dir, 'ID' => 'DESC'];
if ($sort === 'STATUS') $arOrder = [PROP_STATUS => $dir, 'ID' => 'DESC'];

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
$currentUserTasksMap = getCurrentUserRunningTaskMapForOffers($currentUserId, IBL_OFFERS);

while ($ob = $res->GetNextElement()) {
    $f = $ob->GetFields();
    $id = (int)$f['ID'];
    $recruiterId = userIdFromValue($f[PROP_RECRUITER . '_VALUE'] ?? '');

    $taskIdForCurrentUser = (int)($currentUserTasksMap[$id] ?? 0);

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
.offer-list-page .info-btn { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; border:1px solid #94a3b8; color:#334155; font-size:12px; text-decoration:none; margin-left:6px; cursor:pointer; }
.offer-list-page .pagination { margin-top:12px; display:flex; gap:6px; flex-wrap:wrap; }
.offer-list-page .pagination a, .offer-list-page .pagination span { padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; }
.offer-list-page .pagination .active { background:#2563eb; border-color:#2563eb; color:#fff; }
.offer-list-page .modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; z-index:9998; }
.offer-list-page .modal-card { position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); width:min(680px, 92vw); max-height:80vh; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(2,6,23,.25); display:none; z-index:9999; }
.offer-list-page .modal-head { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-bottom:1px solid #e2e8f0; }
.offer-list-page .modal-title { font-weight:600; }
.offer-list-page .modal-body { padding:16px; white-space:pre-line; overflow:auto; max-height:calc(80vh - 60px); }
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
                        <div>
                            <strong><?= h($row['STATUS'] ?: '—') ?></strong>
                            <?php if (trim($row['STATUS_HISTORY']) !== ''): ?>
                                <a href="#"
                                   class="info-btn js-status-info"
                                   title="Показать историю"
                                   data-history="<?= h($row['STATUS_HISTORY']) ?>"
                                   data-offer-id="<?= (int)$row['ID'] ?>">i</a>
                            <?php endif; ?>
                        </div>
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

<div class="offer-list-page">
    <div id="status-history-backdrop" class="modal-backdrop"></div>
    <div id="status-history-modal" class="modal-card" role="dialog" aria-modal="true" aria-labelledby="status-history-title">
        <div class="modal-head">
            <div id="status-history-title" class="modal-title">История статуса</div>
            <button type="button" class="btn" id="status-history-close">Закрыть</button>
        </div>
        <div class="modal-body" id="status-history-content"></div>
    </div>
</div>

<script>
(function() {
  var backdrop = document.getElementById('status-history-backdrop');
  var modal = document.getElementById('status-history-modal');
  var closeBtn = document.getElementById('status-history-close');
  var content = document.getElementById('status-history-content');
  var title = document.getElementById('status-history-title');

  function closeModal() {
    backdrop.style.display = 'none';
    modal.style.display = 'none';
    content.textContent = '';
  }

  function openModal(offerId, history) {
    title.textContent = 'История статуса (оффер #' + offerId + ')';
    content.textContent = history || 'История отсутствует.';
    backdrop.style.display = 'block';
    modal.style.display = 'block';
  }

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.js-status-info');
    if (!btn) return;
    e.preventDefault();
    openModal(btn.getAttribute('data-offer-id') || '', btn.getAttribute('data-history') || '');
  });

  backdrop.addEventListener('click', closeModal);
  closeBtn.addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display === 'block') closeModal();
  });
})();
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
