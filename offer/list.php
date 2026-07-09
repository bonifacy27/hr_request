<?php
/**
 * list.php — список заявок на оффер (ИБ 218)
 * URL: /forms/staff_recruitment/offer/list.php
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

function decodeStatusHistoryHtml(string $raw): string
{
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('/[ÐÑ]/u', $decoded)) {
        $decoded = mb_convert_encoding($decoded, 'ISO-8859-1', 'UTF-8');
    }
    $decoded = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $decoded);
    $decoded = preg_replace('/<br\\s*\\/?>/iu', "<br>", $decoded);
    $decoded = strip_tags($decoded, '<br>');
    return trim($decoded);
}

function getStatusBadgeColor(string $status): string
{
    $map = [
        'Согласование рук. ОПП' => '#fb923c',
        'Согласование C&B' => '#fb923c',
        'Согласование рук. ОМиОР' => '#fb923c',
        'Согласование HRD' => '#fb923c',
        'Согласование рук-ля' => '#fb923c',
        'Доработка' => '#fde047',
        'Согласовано' => '#86efac',
        'Оффер сформирован' => '#60a5fa',
        'Оффер принят' => '#22c55e',
        'Оффер не принят' => '#ef4444',
        'Черновик' => '#9ca3af',
    ];
    return $map[$status] ?? '#cbd5e1';
}

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
        'ORGANIZATION' => html_entity_decode((string)($f[PROP_ORGANIZATION . '_VALUE'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'RECRUITER_ID' => $recruiterId,
        'STATUS' => (string)($f[PROP_STATUS . '_VALUE'] ?? ''),
        'STATUS_HISTORY' => decodeStatusHistoryHtml((string)($f['PREVIEW_TEXT'] ?? '')),
        'TASK_ID_FOR_CURRENT_USER' => $taskIdForCurrentUser,
        'VIEW_URL' => '/forms/staff_recruitment/offer/view_offer.php?id=' . $id,
        'EDIT_URL' => '/forms/staff_recruitment/offer/edit_offer.php?id=' . $id,
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
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
.offer-list-page { padding:16px 24px; }
.offer-list-page .table thead th { white-space:nowrap; vertical-align:middle; }
.offer-list-page .sort-link, .offer-list-page th a { color:#fff; text-decoration:none; }
.offer-list-page .sort-link:hover, .offer-list-page th a:hover { color:#fff; text-decoration:underline; }
.offer-list-page .filter-toolbar { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; padding:12px 14px; }
.offer-list-page .filter-item { flex:0 0 auto; min-width:180px; }
.offer-list-page .filter-item.search-item { width:320px; }
.offer-list-page .actions { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.offer-list-page .muted { color:#6c757d; }
.offer-list-page .info-btn { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; border:0; background:#6c757d; color:#fff; font-size:12px; text-decoration:none; margin-left:6px; cursor:pointer; }
.offer-list-page .info-btn:hover { background:#5a6268; color:#fff; text-decoration:none; }
.offer-list-page .status-badge { display:inline-block; padding:5px 10px; border-radius:999px; font-size:12px; font-weight:600; color:#111827; }
.offer-list-page .pagination { margin-top:12px; display:flex; gap:6px; flex-wrap:wrap; }
.offer-list-page .pagination a, .offer-list-page .pagination span { padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; }
.offer-list-page .pagination .active { background:#007bff; border-color:#007bff; color:#fff; }
.offer-list-page .modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; z-index:9998; opacity:1; }
.offer-list-page .modal-card { position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); width:min(900px, 92vw); max-height:82vh; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.25); display:none; z-index:9999; overflow:hidden; }
.offer-list-page .modal-head { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-bottom:1px solid #e5e5e5; }
.offer-list-page .modal-title { font-weight:600; }
.offer-list-page .modal-body { padding:16px; white-space:pre-line; overflow:auto; max-height:calc(82vh - 60px); }
</style>

<div class="container-fluid offer-list-page">
    <h2 class="mb-3">Заявки на оффер</h2>

    <div class="d-flex flex-wrap align-items-center mb-3">
        <a href="/forms/staff_recruitment/offer/create_offer.php" class="btn btn-success mr-3 mb-2">Создать оффер</a>
    </div>

    <form method="get" action="" class="card mb-3">
        <div class="filter-toolbar">
            <div class="filter-item search-item">
                <label class="mb-1">Поиск по ФИО кандидата</label>
                <input type="text" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Введите ФИО">
            </div>

            <div class="filter-item">
                <label class="mb-1">Рекрутер</label>
                <select name="f_recruiter" class="form-control form-control-sm">
                <option value="0">Все рекрутеры</option>
                <?php foreach ($recruiterOptionUsers as $uid => $u): ?>
                    <option value="<?= (int)$uid ?>" <?= $fRecruiter === (int)$uid ? 'selected' : '' ?>>
                        <?= h(formatUserName($u)) ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label class="mb-1">Статус</label>
                <select name="f_status" class="form-control form-control-sm">
                <option value="0">Все статусы</option>
                <?php foreach ($statusEnumOptions as $sid => $sname): ?>
                    <option value="<?= (int)$sid ?>" <?= $fStatus === (int)$sid ? 'selected' : '' ?>><?= h($sname) ?></option>
                <?php endforeach; ?>
                </select>
            </div>

            <div class="ml-auto d-flex" style="gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm">Применить</button>
                <a href="<?= h(buildUrl([], ['q', 'f_recruiter', 'f_status', 'sort', 'dir', 'PAGEN_1'])) ?>" class="btn btn-secondary btn-sm">Сбросить</a>
            </div>
        </div>
        </form>

    <div class="mb-2 text-muted">Найдено: <?= (int)$res->NavRecordCount ?>, страница <?= (int)$res->NavPageNomer ?> из <?= (int)$res->NavPageCount ?></div>

    <div class="table-responsive">
    <table class="table table-sm table-bordered table-hover">
        <thead class="thead-dark">
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
                            <?php $statusName = (string)($row['STATUS'] ?: '—'); ?>
                            <span class="status-badge" style="background:<?= h(getStatusBadgeColor($statusName)) ?>;"><?= h($statusName) ?></span>
                            <?php if (trim($row['STATUS_HISTORY']) !== ''): ?>
                                <?php $historyBase64 = base64_encode($row['STATUS_HISTORY']); ?>
                                <a href="#"
                                   class="info-btn js-status-info"
                                   title="Показать историю"
                                   data-history-b64="<?= h($historyBase64) ?>"
                                   data-offer-id="<?= (int)$row['ID'] ?>">i</a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= h($row['DATE_CREATE']) ?></td>
                    <td>
                        <div class="actions">
                            <?php if ($canManage): ?>
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h($row['VIEW_URL']) ?>" target="_blank" rel="noopener">Просмотр</a>
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h($row['EDIT_URL']) ?>" target="_blank" rel="noopener">Редактирование</a>
                            <?php endif; ?>
                            <?php if ($taskUrl !== ''): ?>
                                <a class="btn btn-info btn-sm" href="<?= h($taskUrl) ?>" target="_blank" rel="noopener">Перейти в задание</a>
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
    </div>

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
            <button type="button" class="btn btn-secondary btn-sm" id="status-history-close">Закрыть</button>
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
    content.innerHTML = history || 'История отсутствует.';
    backdrop.style.display = 'block';
    modal.style.display = 'block';
  }

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.js-status-info');
    if (!btn) return;
    e.preventDefault();
    var encoded = btn.getAttribute('data-history-b64') || '';
    var decoded = '';
    try { decoded = encoded ? window.atob(encoded) : ''; } catch (err) {}
    openModal(btn.getAttribute('data-offer-id') || '', decoded);
  });

  backdrop.addEventListener('click', closeModal);
  closeBtn.addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display === 'block') closeModal();
  });
})();
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
