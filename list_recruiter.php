<?php
/**
 * list_recruiter.php — список "Заявок на подбор" (ИБ 201) для рекрутера
 * Версия: 2.3.6 (2026-03-30)
 *
 * v2.3.5:
 * - Фильтры Инициатор/Руководитель/Рекрутер: выпадашки строятся по ВСЕМУ списку (без учёта пагинации),
 *   и показывают только реально встречающихся пользователей в соответствующем поле.
 * - Сортировка и отображение пользователей в фильтрах: "Фамилия Имя".
 *
 * v2.3.6:
 * - "Действия" сделаны компактными: вынесены в выпадающий список.
 * - Быстрое действие "Перейти в задание" оставлено отдельной кнопкой (если доступно).
 * - Добавлены действия: "Редактировать" (для разрешённых статусов) и "Дублировать заявку".
 * - Рядом со статусом добавлена кнопка "Инфо" с модальным окном истории заявки (PROPERTY_1043).
 *
 * v2.3.4:
 * - После делегирования: запускаем БП (шаблон 1291) по заявке.
 *
 * v2.3.3:
 * - Добавлены фильтры (AND) по:
 *   1) Инициатор (CREATED_BY)
 *   2) Руководитель (PROPERTY_1034)
 *   3) Рекрутер (PROPERTY_1035)
 *   4) Статус заявки (PROPERTY_1042, enum)
 * - Фильтры совместимы между собой и с поиском q.
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Json;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
$APPLICATION->SetTitle("Заявки на подбор");

if (!Loader::includeModule('main'))  { ShowError('Модуль main не установлен');  require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); return; }
if (!Loader::includeModule('iblock')){ ShowError('Модуль iblock не установлен');require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); return; }
if (!Loader::includeModule('bizproc')){ShowError('Модуль bizproc не установлен');require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); return; }
if (!Loader::includeModule('lists')) { ShowError('Модуль lists не установлен'); require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); return; }

CJSCore::Init(['ajax', 'popup', 'ui.entity-selector', 'ui.buttons', 'ui.notification']);

// === Лог ===
define('LOG_FILE', $_SERVER['DOCUMENT_ROOT'] . '/upload/logs/list_recruiter.log');
function logFilter($data)
{
    try {
        $line = '[' . date('c') . "] " . Json::encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {}
}

// === Конфигурация ===
$IBLOCK_ID = 201;

// ID/коды свойств
$PROP_DOLZHNOST   = 'PROPERTY_1011';
$PROP_STATUS      = 'PROPERTY_1042'; // enum list
$PROP_MANAGER     = 'PROPERTY_1034';
$PROP_REASON      = 'PROPERTY_1609';
$PROP_RECRUITER   = 'PROPERTY_1035';
$PROP_KOMMENTARII = 'PROPERTY_1043';

$BP_TEMPLATE_AFTER_DELEGATE = 1291; // <=== запускать после делегирования

$createElementUrl      = '/forms/staff_recruitment/create_request.php';
$editElementUrlPattern = '/forms/staff_recruitment/edit_request.php?id=#ID#';
$copyElementUrlPattern = '/forms/staff_recruitment/create_request_copy.php?id=#ID#';
$elementViewUrlPattern = '/bizproc/processes/201/element/0/#ID#/';

$statusColorMap = [
    'Новая'               => '#2563eb',
    'Согласование C&B'    => '#eab308',
    'В работе рекрутера'  => '#0ea5e9',
    'Проверка кандидатов' => '#16a34a',
    'Закрыта'             => '#64748b',
    'Отклонена'           => '#ef4444',
];

$nonEditableStatuses = [
    'Новая',
    'Проверка',
    'Согласование руководителя',
    'Ошибка',
    'Отклонена',
    'Отмена',
    'Закрыта',
];

// === Helpers ===
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function formatUserName(array $u): string {
    $tmpl = \CSite::GetNameFormat(false);
    return trim(\CUser::FormatName($tmpl, $u, true, false)) ?: ($u['LOGIN'] ?? ('user#' . $u['ID']));
}

function getUserNameById(int $userId): string
{
    if ($userId <= 0) return '—';
    try {
        $u = \Bitrix\Main\UserTable::getById($userId)->fetch();
        if (!$u) return 'user#'.$userId;
        $u['ID'] = (int)$u['ID'];
        return formatUserName($u);
    } catch (\Throwable $e) {
        return 'user#'.$userId;
    }
}

function renderUserPlain(?array $u): string {
    if (!$u) return '<span class="text-muted">—</span>';
    $name = formatUserName($u);
    return $name !== '' ? h($name) : '<span class="text-muted">—</span>';
}

function renderUserListPlain(array $ids, array $userMap): string {
    if (empty($ids)) return '<span class="text-muted">—</span>';
    $names = [];
    foreach ($ids as $id) {
        $u = $userMap[(int)$id] ?? null;
        if ($u) $names[] = formatUserName($u);
    }
    return $names ? h(implode(', ', $names)) : '<span class="text-muted">—</span>';
}

function buildUrl(array $paramsToSet = [], array $paramsToUnset = []) {
    $query = $_GET;
    foreach ($paramsToUnset as $k) unset($query[$k]);
    foreach ($paramsToSet as $k => $v) {
        if ($v === null) unset($query[$k]);
        else $query[$k] = $v;
    }
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return $path . (empty($query) ? '' : '?' . http_build_query($query));
}

function sortLink($key, $title, $currentSort, $currentDir) {
    $dir   = ($currentSort === $key && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $url   = buildUrl(['sort' => $key, 'dir' => $dir, 'PAGEN_1' => 1], ['page']);
    $arrow = '';
    if ($currentSort === $key) $arrow = $currentDir === 'ASC' ? ' ▲' : ' ▼';
    return '<a href="' . h($url) . '" class="sort-link">' . h($title) . $arrow . '</a>';
}

function appendComment(string $existing, string $line): string
{
    $existing = trim($existing);
    $line = trim($line);
    if ($line === '') return $existing;
    if ($existing === '') return $line;
    return $existing . "\n" . $line;
}

function getElementPropertyString(int $iblockId, int $elementId, int $propertyId): string
{
    $val = '';
    $rs = CIBlockElement::GetProperty($iblockId, $elementId, ['sort'=>'asc'], ['ID'=>$propertyId]);
    while ($p = $rs->Fetch()) {
        if ((string)$p['VALUE'] !== '') {
            $val = (string)$p['VALUE'];
            break;
        }
    }
    return (string)$val;
}

// === Bizproc helpers ===
function getDocumentIdCandidates(int $iblockId, int $elementId): array
{
    return [
        ['lists',  'BizprocDocument', "lists_{$iblockId}_{$elementId}"],
        ['iblock', 'CIBlockDocument', "iblock_{$iblockId}_{$elementId}"],
        ['lists',  'Bitrix\Lists\BizprocDocumentLists', $elementId],
    ];
}

function getRunningTasks(int $elementId, int $iblockId = 201): array
{
    $tasks = [];
    foreach (getDocumentIdCandidates($iblockId, $elementId) as $docIdCandidate) {
        try {
            $rs = \CBPTaskService::GetList(
                ['ID' => 'ASC'],
                ['DOCUMENT_ID' => $docIdCandidate, 'STATUS' => \CBPTaskStatus::Running],
                false,
                false,
                ['ID','USER_ID','NAME','DOCUMENT_ID','WORKFLOW_ID']
            );
            while ($t = $rs->GetNext()) {
                $tid = (int)($t['ID'] ?? 0);
                if ($tid <= 0) continue;
                $tasks[] = [
                    'ID'          => $tid,
                    'USER_ID'     => (int)($t['USER_ID'] ?? 0),
                    'DOCUMENT_ID' => $docIdCandidate,
                    'WORKFLOW_ID' => (string)($t['WORKFLOW_ID'] ?? ''),
                    'NAME'        => (string)($t['NAME'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {}
    }
    $uniq = [];
    foreach ($tasks as $t) $uniq[$t['ID']] = $t;
    return array_values($uniq);
}

function getBizprocTaskUrl(int $taskId, ?int $userId = null): string
{
    $userId = $userId ?: (int)$GLOBALS['USER']->GetID();
    if (class_exists('\CBPTaskService')) {
        if (method_exists('\CBPTaskService', 'GetTaskUrl')) {
            try { return (string)\CBPTaskService::GetTaskUrl($taskId, $userId); } catch (\Throwable $e) {}
        }
        if (method_exists('\CBPTaskService', 'GetTaskURL')) {
            try { return (string)\CBPTaskService::GetTaskURL($taskId, $userId); } catch (\Throwable $e) {}
        }
    }
    return '/company/personal/bizproc/' . $taskId . '/';
}

function terminateAllWorkflowsByElement(int $elementId, int $iblockId = 201): array
{
    $terminated = [];
    $errorsOut  = [];

    $tasks = getRunningTasks($elementId, $iblockId);
    $workflowIds = [];
    foreach ($tasks as $t) {
        $wf = trim((string)($t['WORKFLOW_ID'] ?? ''));
        if ($wf !== '') $workflowIds[$wf] = true;
    }
    $workflowIds = array_keys($workflowIds);

    if (empty($workflowIds)) {
        return ['TERMINATED' => [], 'ERRORS' => ['Не найден WORKFLOW_ID у running-задач.']];
    }

    foreach ($workflowIds as $wfId) {
        try {
            $arState = \CBPStateService::GetWorkflowState($wfId);
            if (empty($arState) || empty($arState['DOCUMENT_ID'])) {
                $errorsOut[] = "Не удалось получить DOCUMENT_ID для workflow {$wfId}";
                continue;
            }
            $arErrorsTmp = [];
            \CBPDocument::TerminateWorkflow($wfId, $arState['DOCUMENT_ID'], $arErrorsTmp);
            $terminated[$wfId] = true;

            if (!empty($arErrorsTmp) && is_array($arErrorsTmp)) {
                foreach ($arErrorsTmp as $er) {
                    if (is_array($er) && !empty($er['message'])) $errorsOut[] = (string)$er['message'];
                }
            }
        } catch (\Throwable $e) {
            $errorsOut[] = $e->getMessage();
        }
    }

    return ['TERMINATED' => array_keys($terminated), 'ERRORS' => $errorsOut];
}

function delegateBizprocTask(int $taskId, int $fromUserId, int $toUserId): array
{
    try {
        if ($taskId <= 0) return ['OK' => false, 'ERROR' => 'Некорректный TASK_ID'];
        if ($fromUserId <= 0 || $toUserId <= 0) return ['OK' => false, 'ERROR' => 'Некорректный пользователь делегирования'];
        if ($fromUserId === $toUserId) return ['OK' => false, 'ERROR' => 'Нельзя делегировать самому себе'];

        \CBPTaskService::delegateTask($taskId, $fromUserId, $toUserId);
        return ['OK' => true, 'ERROR' => ''];
    } catch (\Throwable $e) {
        return ['OK' => false, 'ERROR' => $e->getMessage()];
    }
}

/**
 * v2.3.5:
 * - Фильтры Инициатор/Руководитель/Рекрутер: выпадашки строятся по ВСЕМУ списку (без учёта пагинации),
 *   и показывают только реально встречающихся пользователей в соответствующем поле.
 * - Сортировка и отображение пользователей в фильтрах: "Фамилия Имя".
 *
 * v2.3.4: запуск БП по шаблону после делегирования.
 * Для списков (lists) корректно использовать:
 *   $documentType = ['lists','Bitrix\Lists\BizprocDocumentLists',"iblock_{$iblockId}"];
 *   $documentId   = ['lists','Bitrix\Lists\BizprocDocumentLists',$elementId];
 */
function startWorkflowTemplateForElement(int $templateId, int $iblockId, int $elementId): array
{
    $errors = [];
    try {
        $documentType = ['lists', 'Bitrix\Lists\BizprocDocumentLists', "iblock_".$iblockId];
        $documentId   = ['lists', 'Bitrix\Lists\BizprocDocumentLists', $elementId];
        $workflowId = \CBPDocument::StartWorkflow(
            $templateId,
            $documentId,
            [],          // параметры шаблона (если нужны — добавим)
            $errors
        );

        return [
            'OK' => (string)$workflowId !== '',
            'WORKFLOW_ID' => (string)$workflowId,
            'ERRORS' => $errors
        ];
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
        return ['OK'=>false,'WORKFLOW_ID'=>'','ERRORS'=>$errors];
    }
}

// === STATUS (enum) options for cancel modal AND filter ===
$statusEnumOptions = [];
try {
    $rsEnum = CIBlockPropertyEnum::GetList(['SORT'=>'ASC','VALUE'=>'ASC'], ['IBLOCK_ID'=>$IBLOCK_ID, 'PROPERTY_ID'=>1042]);
    while ($e = $rsEnum->Fetch()) $statusEnumOptions[(int)$e['ID']] = (string)$e['VALUE'];
} catch (\Throwable $e) {}

// ===== Params =====
$request   = Context::getCurrent()->getRequest();
$q         = trim((string)$request->get('q'));
$sort      = strtoupper((string)$request->get('sort') ?: 'ID');
$dir       = strtoupper((string)$request->get('dir') ?: 'DESC');
$pageSize  = 50;

$sortable = ['ID', 'DATE_CREATE', 'DOLZHNOST', 'STATUS'];
if (!in_array($sort, $sortable, true)) $sort = 'ID';
if (!in_array($dir, ['ASC', 'DESC'], true)) $dir = 'DESC';

$currentUserId = (int)$USER->GetID();

// ===== FILTER PARAMS (AND) =====
$fInitiator = (int)$request->get('f_initiator');
$fRecruiter = (int)$request->get('f_recruiter');
$fManager   = (int)$request->get('f_manager');
$fStatus    = (int)$request->get('f_status'); // enum id

// === POST actions ===
$flashMessage = '';
$flashType = 'success';

if ($request->isPost()) {
    $action = (string)$request->getPost('action');

    if (!check_bitrix_sessid()) {
        $flashMessage = 'Ошибка: сессия истекла. Обновите страницу и повторите.';
        $flashType = 'danger';
    } else {

        // Делегирование
        if ($action === 'delegate_task') {
            $elementId = (int)$request->getPost('element_id');
            $taskId    = (int)$request->getPost('task_id');
            $toUserId  = (int)$request->getPost('delegate_to_user_id');

            logFilter(['action'=>'delegate_task','elementId'=>$elementId,'taskId'=>$taskId,'fromUser'=>$currentUserId,'toUser'=>$toUserId]);

            $res = delegateBizprocTask($taskId, $currentUserId, $toUserId);

            if ($res['OK']) {
                // старый рекрутер
                $oldRecruiterId = 0;
                $prop = CIBlockElement::GetProperty($IBLOCK_ID, $elementId, [], ['ID'=>1035])->Fetch();
                $oldRecruiterId = (int)($prop['VALUE'] ?? 0);

                $fromName = getUserNameById($currentUserId);
                $oldRecruiterName = $oldRecruiterId > 0 ? getUserNameById($oldRecruiterId) : $fromName;
                $toName   = getUserNameById($toUserId);

                // комментарий
                $dt = date('d.m.Y H:i');
                $line = $dt . ': ' . $fromName . ' делегировал заявку ' . $oldRecruiterName . ' -> ' . $toName;

                $existing = getElementPropertyString($IBLOCK_ID, $elementId, 1043);
                $newComments = appendComment($existing, $line);

                // обновляем рекрутера и комментарии
                try {
                    CIBlockElement::SetPropertyValuesEx($elementId, $IBLOCK_ID, [
                        '1035' => $toUserId,
                        '1043' => $newComments,
                    ]);
                } catch (\Throwable $e) {
                    logFilter(['action'=>'delegate_task_update_props_error','elementId'=>$elementId,'error'=>$e->getMessage()]);
                }

                // v2.3.4: запуск БП 1291
                $bpStart = startWorkflowTemplateForElement($BP_TEMPLATE_AFTER_DELEGATE, $IBLOCK_ID, $elementId);
                logFilter([
                    'action' => 'delegate_task_start_bp',
                    'elementId' => $elementId,
                    'templateId' => $BP_TEMPLATE_AFTER_DELEGATE,
                    'ok' => $bpStart['OK'],
                    'workflowId' => $bpStart['WORKFLOW_ID'],
                    'errors' => $bpStart['ERRORS']
                ]);

                if ($bpStart['OK']) {
                    $flashMessage = 'Задание делегировано. Рекрутер обновлён, комментарий добавлен. БП запущен.';
                    $flashType = 'success';
                } else {
                    $flashMessage = 'Задание делегировано. Рекрутер обновлён, комментарий добавлен. Но БП не запустился.';
                    $flashType = 'warning';
                }

            } else {
                $flashMessage = 'Не удалось делегировать задание. ' . $res['ERROR'];
                $flashType = 'danger';
            }

            LocalRedirect(buildUrl(['msg'=>$flashType,'text'=>$flashMessage], []));
        }

        // Отмена заявки
        if ($action === 'cancel_request') {
            $elementId = (int)$request->getPost('id');
            $statusEnumId = (int)$request->getPost('cancel_status_enum_id');
            $cancelComment = trim((string)$request->getPost('cancel_comment'));

            if ($elementId <= 0) {
                $flashMessage = 'Некорректный ID заявки.';
                $flashType = 'danger';
                LocalRedirect(buildUrl(['msg'=>$flashType,'text'=>$flashMessage], []));
            }
            if ($statusEnumId <= 0) {
                $flashMessage = 'Не выбран статус.';
                $flashType = 'danger';
                LocalRedirect(buildUrl(['msg'=>$flashType,'text'=>$flashMessage], []));
            }
            if ($cancelComment === '') {
                $flashMessage = 'Не заполнен комментарий.';
                $flashType = 'danger';
                LocalRedirect(buildUrl(['msg'=>$flashType,'text'=>$flashMessage], []));
            }

            $result = terminateAllWorkflowsByElement($elementId, $IBLOCK_ID);

            $fromName = getUserNameById($currentUserId);
            $dt = date('d.m.Y H:i');
            $line = $dt . ': ' . $fromName . " отменил заявку на подбор с комментарием: '" . $cancelComment . "'";

            $existing = getElementPropertyString($IBLOCK_ID, $elementId, 1043);
            $newComments = appendComment($existing, $line);

            try {
                CIBlockElement::SetPropertyValuesEx($elementId, $IBLOCK_ID, [
                    '1042' => $statusEnumId,
                    '1043' => $newComments,
                ]);
            } catch (\Throwable $e) {
                logFilter(['action'=>'cancel_request_update_props_error','elementId'=>$elementId,'error'=>$e->getMessage()]);
            }

            if (!empty($result['TERMINATED'])) {
                $flashMessage = 'Заявка отменена: бизнес-процесс прерван, статус и комментарий записаны.';
                $flashType = 'success';
            } else {
                $flashMessage = 'Статус и комментарий записаны. Но бизнес-процесс мог не прерваться.';
                $flashType = 'warning';
            }

            LocalRedirect(buildUrl(['msg'=>$flashType,'text'=>$flashMessage], []));
        }
    }
}

// Flash from redirect
if ($flashMessage === '') {
    $msg = (string)$request->get('msg');
    $text = (string)$request->get('text');
    if ($text !== '') {
        $flashMessage = $text;
        $flashType = in_array($msg, ['success','warning','danger','info'], true) ? $msg : 'info';
    }
}

// ===== Поиск userIds по q (для OR-блока) =====
$searchUserIds = [];
if ($q !== '') {
    try {
        $rsUsers = \Bitrix\Main\UserTable::getList([
            'filter' => [
                'LOGIC' => 'OR',
                '%NAME' => $q,
                '%LAST_NAME' => $q,
                '%SECOND_NAME' => $q,
                '%LOGIN' => $q,
            ],
            'select' => ['ID'],
            'limit'  => 300,
        ]);
        while ($u = $rsUsers->fetch()) $searchUserIds[] = (int)$u['ID'];
    } catch (\Throwable $e) {}
}

// ===== SQL order =====
$arOrder = ['ID' => 'DESC'];
if ($sort === 'ID') $arOrder = ['ID' => $dir];
if ($sort === 'DATE_CREATE') $arOrder = ['DATE_CREATE' => $dir];

// ===== FILTER BASE (AND filters here) =====
$filter = [
    'IBLOCK_ID'=>$IBLOCK_ID,
    'ACTIVE'=>'Y',
    'CHECK_PERMISSIONS'=>'Y'
];

// AND-фильтры
if ($fInitiator > 0) $filter['CREATED_BY'] = $fInitiator;
if ($fManager   > 0) $filter[$PROP_MANAGER] = $fManager;
if ($fRecruiter > 0) $filter[$PROP_RECRUITER] = $fRecruiter;
if ($fStatus    > 0) $filter[$PROP_STATUS] = $fStatus; // enum id

// OR-блок поиска (внутри AND)
if ($q !== '') {
    $or = [
        'LOGIC' => 'OR',
        '%NAME' => $q,
        '%'.$PROP_DOLZHNOST => $q,
        '%'.$PROP_REASON => $q,
        '%'.$PROP_STATUS => $q,
    ];
    if (!empty($searchUserIds)) {
        $or['CREATED_BY'] = $searchUserIds;
        $or[$PROP_MANAGER] = $searchUserIds;
        $or[$PROP_RECRUITER] = $searchUserIds;
    }
    $filter[] = $or;
}

// ===== SELECT =====
$arSelect = [
    'ID','IBLOCK_ID','NAME','DATE_CREATE','CREATED_BY',
    $PROP_DOLZHNOST,
    $PROP_STATUS,
    $PROP_REASON,
    $PROP_MANAGER,
    $PROP_RECRUITER,
    $PROP_KOMMENTARII,
];

// ===== Nav =====
$navParams = [
    'nPageSize' => $pageSize,
    'bShowAll'  => false,
];

$res = CIBlockElement::GetList($arOrder, $filter, false, $navParams, $arSelect);

// ===== Collect data =====
$items = [];
$userIds = [];

while ($ob = $res->GetNextElement()) {
    $f = $ob->GetFields();
    $id          = (int)$f['ID'];
    $creatorId   = (int)$f['CREATED_BY'];
    $managerId   = (int)$f["{$PROP_MANAGER}_VALUE"];
    $recruiterId = (int)$f["{$PROP_RECRUITER}_VALUE"];

    $tasks = getRunningTasks($id, $IBLOCK_ID);

    $assigneeIds = [];
    foreach ($tasks as $t) {
        $uid = (int)($t['USER_ID'] ?? 0);
        if ($uid > 0) $assigneeIds[$uid] = true;
    }
    $assigneeIds = array_values(array_map('intval', array_keys($assigneeIds)));

    $taskIdForLink = 0;
    $taskUserForLink = 0;
    $taskIdForDelegate = 0;
    $hasCurrentUserTask = false;

    if (!empty($tasks)) {
        foreach ($tasks as $t) {
            if ((int)$t['USER_ID'] === (int)$GLOBALS['USER']->GetID() && (int)$GLOBALS['USER']->GetID() > 0) {
                $taskIdForLink = (int)$t['ID'];
                $taskUserForLink = (int)$t['USER_ID'];
                $taskIdForDelegate = (int)$t['ID'];
                $hasCurrentUserTask = true;
                break;
            }
        }
    }

    $delegateVisible = ($recruiterId > 0 && in_array($recruiterId, $assigneeIds, true));

    $items[] = [
        'ID'=>$id,
        'NAME'=>(string)$f['NAME'],
        'DATE_CREATE'=>(string)$f['DATE_CREATE'],
        'DOLZHNOST'=>(string)$f["{$PROP_DOLZHNOST}_VALUE"],
        'STATUS'=>(string)$f["{$PROP_STATUS}_VALUE"],
        'CREATED_BY'=>$creatorId,
        'MANAGER_ID'=>$managerId,
        'RECRUITER_ID'=>$recruiterId,
        'ASSIGNEES'=>$assigneeIds,
        'REASON'=>(string)$f["{$PROP_REASON}_VALUE"],
        'KOMMENTARII'=>is_array($f["{$PROP_KOMMENTARII}_VALUE"] ?? null)
            ? implode("\n", array_map('strval', (array)$f["{$PROP_KOMMENTARII}_VALUE"]))
            : (string)($f["{$PROP_KOMMENTARII}_VALUE"] ?? ''),
        'VIEW_URL'=>str_replace('#ID#', $id, $elementViewUrlPattern),
        'EDIT_URL'=>str_replace('#ID#', $id, $editElementUrlPattern),
        'COPY_URL'=>str_replace('#ID#', $id, $copyElementUrlPattern),
        'HAS_TASKS'=>!empty($tasks),
        'HAS_CURRENT_USER_TASK'=>$hasCurrentUserTask,
        'TASK_ID_FOR_LINK'=>$taskIdForLink,
        'TASK_USER_FOR_LINK'=>$taskUserForLink,
        'TASK_ID_DELEGATE'=>$taskIdForDelegate,
        'DELEGATE_VISIBLE'=>$delegateVisible,
    ];

    foreach ([$creatorId,$managerId,$recruiterId] as $uid) if ($uid>0) $userIds[$uid]=true;
    foreach ($assigneeIds as $aid) if ((int)$aid>0) $userIds[(int)$aid]=true;
}

// Users map
$userMap = [];
$ids = array_keys($userIds);
if ($ids) {
    $rsU = \Bitrix\Main\UserTable::getList([
        'filter'=>['@ID'=>$ids],
        'select'=>['ID','NAME','LAST_NAME','SECOND_NAME','LOGIN'],
    ]);
    while ($u = $rsU->fetch()) { $u['ID']=(int)$u['ID']; $userMap[$u['ID']]=$u; }
}

// Локальная сортировка по DOLZHNOST/STATUS (только в рамках страницы)
if (in_array($sort, ['DOLZHNOST','STATUS'], true)) {
    usort($items, function($a,$b) use($sort,$dir) {
        $mul = ($dir==='ASC') ? 1 : -1;
        $av = mb_strtolower((string)($a[$sort] ?? ''), 'UTF-8');
        $bv = mb_strtolower((string)($b[$sort] ?? ''), 'UTF-8');
        return $mul * strcmp($av, $bv);
    });
}

// Navigation
$totalCount  = (int)$res->NavRecordCount;
$pageCount   = (int)$res->NavPageCount;
$currentPage = (int)$res->NavPageNomer;

function navPageUrl(int $pageNum): string { return buildUrl(['PAGEN_1' => $pageNum], []); }

// ===== Filter dropdown data (users) =====
// Требования:
// 1) В выпадашках (Инициатор / Руководитель / Рекрутер) показываем только те значения,
//    которые реально встречаются во ВСЁМ списке (после применения остальных фильтров и поиска),
//    а не только на текущей странице.
// 2) Сортировка и отображение: "Фамилия Имя" (потом отчество, если есть).

function formatUserNameLastFirst(array $u): string {
    $last = trim((string)($u['LAST_NAME'] ?? ''));
    $name = trim((string)($u['NAME'] ?? ''));
    $second = trim((string)($u['SECOND_NAME'] ?? ''));
    $parts = [];
    if ($last !== '') $parts[] = $last;
    if ($name !== '') $parts[] = $name;
    if ($second !== '') $parts[] = $second;
    $out = trim(implode(' ', $parts));
    return $out !== '' ? $out : ($u['LOGIN'] ?? ('user#' . (int)$u['ID']));
}

function fetchUsersMapByIds(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) return [];

    $map = [];
    try {
        $rs = \Bitrix\Main\UserTable::getList([
            'filter' => ['@ID' => $ids],
            'select' => ['ID','NAME','LAST_NAME','SECOND_NAME','LOGIN'],
        ]);
        while ($u = $rs->fetch()) {
            $u['ID'] = (int)$u['ID'];
            $map[$u['ID']] = $u;
        }
    } catch (\Throwable $e) {}

    // сортировка по Фамилии, затем Имени, затем Отчеству
    uasort($map, function($a, $b) {
        $aLast = mb_strtolower((string)($a['LAST_NAME'] ?? ''), 'UTF-8');
        $bLast = mb_strtolower((string)($b['LAST_NAME'] ?? ''), 'UTF-8');
        if ($aLast !== $bLast) return $aLast <=> $bLast;

        $aName = mb_strtolower((string)($a['NAME'] ?? ''), 'UTF-8');
        $bName = mb_strtolower((string)($b['NAME'] ?? ''), 'UTF-8');
        if ($aName !== $bName) return $aName <=> $bName;

        $aSec = mb_strtolower((string)($a['SECOND_NAME'] ?? ''), 'UTF-8');
        $bSec = mb_strtolower((string)($b['SECOND_NAME'] ?? ''), 'UTF-8');
        if ($aSec !== $bSec) return $aSec <=> $bSec;

        return ((int)$a['ID']) <=> ((int)$b['ID']);
    });

    return $map;
}

/**
 * Собираем уникальные ID пользователей из всех элементов по заданному полю/свойству.
 * Важно: фильтр уже включает поиск (OR-блок) и остальные AND-фильтры, кроме целевого поля.
 */
function collectDistinctUserIds(array $facetFilter, string $fieldCode, int $max = 5000): array
{
    $ids = [];

    // Подстраховка от "бесконечного" списка: лимитируем количество элементов,
    // но достаточно большим значением для реальных списков заявок на подбор.
    $rs = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $facetFilter,
        false,
        ['nTopCount' => $max],
        ['ID', $fieldCode]
    );

    while ($row = $rs->Fetch()) {
        if ($fieldCode === 'CREATED_BY') {
            $v = (int)($row['CREATED_BY'] ?? 0);
        } else {
            // PROPERTY_XXXX => ожидаем ключ PROPERTY_XXXX_VALUE
            $v = (int)($row[$fieldCode . '_VALUE'] ?? 0);
        }
        if ($v > 0) $ids[$v] = true;
    }

    return array_keys($ids);
}

// Базовый фильтр для "фасетных" списков — такой же, как для выдачи, НО без целевого фильтра.
$facetBase = $filter;

// 1) Инициатор: убираем фильтр по CREATED_BY
$facetInitiator = $facetBase;
unset($facetInitiator['CREATED_BY']);

// 2) Руководитель: убираем фильтр по свойству руководителя
$facetManager = $facetBase;
unset($facetManager[$PROP_MANAGER]);

// 3) Рекрутер: убираем фильтр по свойству рекрутера
$facetRecruiter = $facetBase;
unset($facetRecruiter[$PROP_RECRUITER]);

$initiatorIds = collectDistinctUserIds($facetInitiator, 'CREATED_BY');
$managerIds   = collectDistinctUserIds($facetManager, $PROP_MANAGER);
$recruiterIds = collectDistinctUserIds($facetRecruiter, $PROP_RECRUITER);

// Гарантируем, что выбранные значения не пропадут из выпадашки
foreach ([$fInitiator, $fManager, $fRecruiter] as $uid) {
    if ($uid > 0) {
        $initiatorIds[] = $uid;
        $managerIds[] = $uid;
        $recruiterIds[] = $uid;
    }
}

$initiatorUsers = fetchUsersMapByIds($initiatorIds);
$managerUsers   = fetchUsersMapByIds($managerIds);
$recruiterUsers = fetchUsersMapByIds($recruiterIds);

?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
  .page-wrap { padding: 16px 24px; }
  .table thead th { white-space: nowrap; }
  .sort-link { color: #fff; text-decoration: none; }
  .sort-link:hover { text-decoration: underline; }
  .actions-wrap { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
  .actions-compact { min-width: 190px; max-width: 220px; }
  .status-wrap { display:inline-flex; align-items:center; gap:6px; }
  .btn-info-icon {
    width: 22px; height: 22px; border-radius: 50%;
    display:inline-flex; align-items:center; justify-content:center;
    padding: 0; font-size: 12px; line-height: 1;
  }
  .history-box {
    max-height: 360px; overflow:auto; white-space:pre-wrap; word-break:break-word;
    border: 1px solid #e9ecef; background:#f8f9fa; border-radius:6px; padding:10px 12px; margin-top:8px;
  }

  .pagination-custom { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:12px; }
  .pagination-custom a, .pagination-custom span {
    padding: 6px 10px; border: 1px solid #dee2e6; border-radius: 4px;
    background: #fff; text-decoration: none; color: #212529; font-size: 13px;
  }
  .pagination-custom .active { background: #343a40; color: #fff; border-color: #343a40; }

  .popup-form-wrap { padding: 12px 14px; }
  .popup-form-title { font-size: 14px; font-weight: 600; margin-bottom: 10px; }
  .popup-form-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .popup-form-hint { font-size: 12px; color: #6c757d; margin-top: 8px; }
  .popup-form-field { margin-top: 10px; }
  .popup-form-field label { font-size: 12px; font-weight: 600; margin-bottom: 6px; display:block; }
  .popup-form-required { color: #dc3545; }
</style>

<div class="page-wrap">
  <h1 class="mb-3">Заявки на подбор</h1>
  <p class="text-muted small mb-3">
    Источник: инфоблок <?= (int)$IBLOCK_ID ?>.
    Всего записей (с учетом фильтров/поиска): <?= (int)$totalCount ?>.
    Пагинация: 50 / страница.
    Версия скрипта: 2.3.6.
  </p>

  <?php if ($flashMessage !== ''): ?>
    <div class="alert alert-<?= h($flashType) ?>"><?= h($flashMessage) ?></div>
  <?php endif; ?>

  <div class="d-flex align-items-center mb-3">
    <a href="<?= h($createElementUrl) ?>" class="btn btn-success mr-3" target="_blank" rel="noopener">Создать новую заявку</a>

    <form method="get" class="form-inline" style="gap:8px; flex-wrap:wrap;">
      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <input type="hidden" name="dir"  value="<?= h(strtolower($dir)) ?>">

      <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Поиск по всем заявкам">

      <select name="f_initiator" class="form-control">
        <option value="0">Инициатор: все</option>
        <?php foreach ($initiatorUsers as $uid => $u): ?>
          <?php $name = formatUserNameLastFirst($u); ?>
          <option value="<?= (int)$uid ?>" <?= ($fInitiator===(int)$uid ? 'selected' : '') ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="f_manager" class="form-control">
        <option value="0">Руководитель: все</option>
        <?php foreach ($managerUsers as $uid => $u): ?>
          <?php $name = formatUserNameLastFirst($u); ?>
          <option value="<?= (int)$uid ?>" <?= ($fManager===(int)$uid ? 'selected' : '') ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="f_recruiter" class="form-control">
        <option value="0">Рекрутер: все</option>
        <?php foreach ($recruiterUsers as $uid => $u): ?>
          <?php $name = formatUserNameLastFirst($u); ?>
          <option value="<?= (int)$uid ?>" <?= ($fRecruiter===(int)$uid ? 'selected' : '') ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="f_status" class="form-control">
        <option value="0">Статус: все</option>
        <?php foreach ($statusEnumOptions as $eid => $val): ?>
          <option value="<?= (int)$eid ?>" <?= ($fStatus===(int)$eid ? 'selected' : '') ?>><?= h($val) ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn btn-primary">Найти</button>
      <a href="<?= h($APPLICATION->GetCurPage()) ?>" class="btn btn-secondary">Сброс</a>
    </form>
  </div>

  <?php if ($pageCount > 1): ?>
    <div class="pagination-custom mb-2">
      <?php if ($currentPage > 1): ?>
        <a href="<?= h(navPageUrl(1)) ?>">&laquo; 1</a>
        <a href="<?= h(navPageUrl($currentPage - 1)) ?>">&lsaquo;</a>
      <?php endif; ?>

      <?php
        $start = max(1, $currentPage - 3);
        $end   = min($pageCount, $currentPage + 3);
        for ($p = $start; $p <= $end; $p++):
      ?>
        <?php if ($p === $currentPage): ?>
          <span class="active"><?= (int)$p ?></span>
        <?php else: ?>
          <a href="<?= h(navPageUrl($p)) ?>"><?= (int)$p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($currentPage < $pageCount): ?>
        <a href="<?= h(navPageUrl($currentPage + 1)) ?>">&rsaquo;</a>
        <a href="<?= h(navPageUrl($pageCount)) ?>"><?= (int)$pageCount ?> &raquo;</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($items)): ?>
    <div class="alert alert-info">Нет доступных заявок по заданным условиям.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-bordered table-hover">
        <thead class="thead-dark">
          <tr>
            <th><?= sortLink('ID','ID',$sort,$dir) ?></th>
            <th><?= sortLink('DOLZHNOST','Должность',$sort,$dir) ?></th>
            <th>Инициатор</th>
            <th>Руководитель</th>
            <th>Рекрутер</th>
            <th>Текущие исполнители</th>
            <th><?= sortLink('DATE_CREATE','Дата заявки',$sort,$dir) ?></th>
            <th><?= sortLink('STATUS','Статус заявки',$sort,$dir) ?></th>
            <th>Причина</th>
            <th>Открыть</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $row):
            $status    = $row['STATUS'] !== '' ? $row['STATUS'] : '—';
            $chipColor = $statusColorMap[$status] ?? '#6c757d';

            $creator   = $userMap[(int)$row['CREATED_BY']]   ?? null;
            $manager   = $userMap[(int)$row['MANAGER_ID']]   ?? null;
            $recruiter = $userMap[(int)$row['RECRUITER_ID']] ?? null;

            $hasCurrentUserTask = (bool)($row['HAS_CURRENT_USER_TASK'] ?? false);

            $taskIdForLink = (int)$row['TASK_ID_FOR_LINK'];
            $taskUrl  = ($hasCurrentUserTask && $taskIdForLink > 0) ? getBizprocTaskUrl($taskIdForLink, (int)$row['TASK_USER_FOR_LINK']) : '';

            $delegateVisible = (bool)$row['DELEGATE_VISIBLE'];
            $taskIdForDelegate = (int)$row['TASK_ID_DELEGATE'];
            $canDelegate = ($delegateVisible && $taskIdForDelegate > 0);
            $canEdit = !in_array($status, $nonEditableStatuses, true);
        ?>
          <tr>
            <td><?= (int)$row['ID'] ?></td>
            <td><?= $row['DOLZHNOST'] !== '' ? h($row['DOLZHNOST']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= renderUserPlain($creator) ?></td>
            <td><?= renderUserPlain($manager) ?></td>
            <td><?= renderUserPlain($recruiter) ?></td>
            <td><?= renderUserListPlain((array)$row['ASSIGNEES'], $userMap) ?></td>
            <td><span class="text-muted"><?= h($row['DATE_CREATE']) ?></span></td>
            <td>
              <span class="status-wrap">
                <span class="badge" style="background:<?= h($chipColor) ?>;color:#fff;"><?= h($status) ?></span>
                <button type="button"
                        class="btn btn-sm btn-outline-secondary btn-info-icon js-history-btn"
                        data-element-id="<?= (int)$row['ID'] ?>"
                        data-comments="<?= h((string)$row['KOMMENTARII']) ?>"
                        title="История заявки">
                  i
                </button>
              </span>
            </td>
            <td style="max-width:420px; white-space:normal; word-break:break-word;">
              <?= $row['REASON'] !== '' ? nl2br(h($row['REASON'])) : '<span class="text-muted">—</span>' ?>
            </td>
            <td>
              <a class="btn btn-sm btn-outline-primary" href="<?= h($row['VIEW_URL']) ?>" target="_blank" rel="noopener">Открыть</a>
            </td>
            <td>
              <div class="actions-wrap">
                <?php if ($taskUrl !== ''): ?>
                  <a class="btn btn-sm btn-info" href="<?= h($taskUrl) ?>" target="_blank" rel="noopener">Перейти в задание</a>
                <?php endif; ?>

                <select class="form-control form-control-sm actions-compact js-action-select"
                        data-element-id="<?= (int)$row['ID'] ?>"
                        data-task-id="<?= (int)$taskIdForDelegate ?>"
                        data-can-delegate="<?= $canDelegate ? '1' : '0' ?>"
                        data-edit-url="<?= h($row['EDIT_URL']) ?>"
                        data-copy-url="<?= h($row['COPY_URL']) ?>">
                  <option value="">Действия…</option>
                  <?php if ($delegateVisible): ?>
                    <option value="delegate">Делегировать</option>
                  <?php endif; ?>
                  <option value="cancel">Отменить заявку</option>
                  <?php if ($canEdit): ?>
                    <option value="edit">Редактировать</option>
                  <?php endif; ?>
                  <option value="copy">Дублировать заявку</option>
                </select>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pageCount > 1): ?>
      <div class="pagination-custom">
        <?php if ($currentPage > 1): ?>
          <a href="<?= h(navPageUrl(1)) ?>">&laquo; 1</a>
          <a href="<?= h(navPageUrl($currentPage - 1)) ?>">&lsaquo;</a>
        <?php endif; ?>

        <?php
          $start = max(1, $currentPage - 3);
          $end   = min($pageCount, $currentPage + 3);
          for ($p = $start; $p <= $end; $p++):
        ?>
          <?php if ($p === $currentPage): ?>
            <span class="active"><?= (int)$p ?></span>
          <?php else: ?>
            <a href="<?= h(navPageUrl($p)) ?>"><?= (int)$p ?></a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($currentPage < $pageCount): ?>
          <a href="<?= h(navPageUrl($currentPage + 1)) ?>">&rsaquo;</a>
          <a href="<?= h(navPageUrl($pageCount)) ?>"><?= (int)$pageCount ?> &raquo;</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<!-- ===== Шаблоны попапов делегирования/отмены (без изменений) ===== -->

<div id="delegate-popup-template" style="display:none;">
  <div class="popup-form-wrap">
    <div class="popup-form-title">Делегировать задание</div>
    <form method="post" id="delegate-form-popup">
      <?= bitrix_sessid_post(); ?>
      <input type="hidden" name="action" value="delegate_task">
      <input type="hidden" name="element_id" id="delegate-element-id" value="">
      <input type="hidden" name="task_id" id="delegate-task-id" value="">
      <input type="hidden" name="delegate_to_user_id" id="delegate-to-user-id" value="">
      <div><b>Кому делегировать</b></div>
      <div class="popup-form-row" style="margin-top:8px;">
        <button type="button" class="btn btn-outline-primary btn-sm" id="delegate-pick-user">Выбрать сотрудника</button>
        <span class="text-muted" id="delegate-selected-user">Сотрудник не выбран</span>
      </div>
      <div class="popup-form-hint">Делегировать можно только вашу текущую задачу рекрутера по заявке.</div>
    </form>
  </div>
</div>

<div id="history-popup-template" style="display:none;">
  <div class="popup-form-wrap">
    <div class="popup-form-title">История заявки #<span id="history-element-id"></span></div>
    <div id="history-content" class="history-box"></div>
  </div>
</div>

<div id="cancel-popup-template" style="display:none;">
  <div class="popup-form-wrap">
    <div class="popup-form-title">Отменить заявку на подбор</div>
    <form method="post" id="cancel-form-popup">
      <?= bitrix_sessid_post(); ?>
      <input type="hidden" name="action" value="cancel_request">
      <input type="hidden" name="id" id="cancel-element-id" value="">
      <input type="hidden" name="cancel_status_enum_id" id="cancel-status-enum-id" value="">
      <input type="hidden" name="cancel_comment" id="cancel-comment-hidden" value="">
      <div class="popup-form-field">
        <label>Статус <span class="popup-form-required">*</span></label>
        <select class="form-control form-control-sm" id="cancel-status-select">
          <option value="">— Выберите статус —</option>
          <?php foreach ($statusEnumOptions as $enumId => $val): ?>
            <option value="<?= (int)$enumId ?>"><?= h($val) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="popup-form-field">
        <label>Комментарий <span class="popup-form-required">*</span></label>
        <textarea class="form-control" id="cancel-comment-text" rows="3" placeholder="Укажите причину отмены"></textarea>
      </div>
      <div class="popup-form-hint">
        После отмены будет прерван бизнес-процесс, в заявку запишется выбранный статус и комментарий (добавится к существующим).
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  function notify(text) {
    if (BX && BX.UI && BX.UI.Notification) BX.UI.Notification.Center.notify({content: text});
    else alert(text);
  }

  var delegatePopup = null;
  var selectorDialog = null;

  function ensureDelegatePopup() {
    if (delegatePopup) return delegatePopup;

    var tpl = document.getElementById('delegate-popup-template');
    var content = tpl ? tpl.innerHTML : '<div style="padding:12px">Ошибка шаблона</div>';

    delegatePopup = BX.PopupWindowManager.create('delegate_bp_popup', null, {
      content: content,
      closeIcon: { right: '12px', top: '10px' },
      autoHide: false,
      overlay: { opacity: 30 },
      draggable: true,
      closeByEsc: true,
      titleBar: 'Делегировать',
      zIndex: 20000,
      buttons: [
        new BX.PopupWindowButton({
          text: 'Отмена',
          className: 'popup-window-button-link-cancel',
          events: { click: function(){ delegatePopup.close(); } }
        }),
        new BX.PopupWindowButton({
          text: 'Делегировать',
          className: 'popup-window-button-accept',
          events: {
            click: function() {
              var toUser = delegatePopup.contentContainer.querySelector('#delegate-to-user-id');
              if (!toUser || !toUser.value) { notify('Выберите сотрудника для делегирования.'); return; }
              var form = delegatePopup.contentContainer.querySelector('#delegate-form-popup');
              if (form) form.submit();
            }
          }
        })
      ],
      events: {
        onPopupClose: function() {
          try { if (selectorDialog && selectorDialog.isOpen()) selectorDialog.hide(); } catch(e) {}
        }
      }
    });

    return delegatePopup;
  }

  function ensureSelector(targetNode, onPick) {
    if (selectorDialog) {
      try { selectorDialog.destroy(); } catch(e) {}
      selectorDialog = null;
    }

    selectorDialog = new BX.UI.EntitySelector.Dialog({
      targetNode: targetNode,
      context: 'delegate-bp-task',
      multiple: false,
      dropdownMode: true,
      enableSearch: true,
      zIndex: 21000,
      popupOptions: { zIndex: 21000 },
      entities: [{ id: 'user', options: { inviteEmployeeLink: false } }],
      events: {
        'Item:onSelect': function(event) {
          var item = event.getData().item;
          if (!item) return;

          var entityId = item.getEntityId();
          var rawId = item.getId();
          var numericId = 0;

          if (typeof rawId === 'number') numericId = rawId;
          if (typeof rawId === 'string') numericId = parseInt(rawId.replace(/[^\d]/g, ''), 10) || 0;

          if (entityId !== 'user' || !numericId) return;

          onPick(numericId, item.getTitle() || ('ID ' + numericId));
          try { selectorDialog.hide(); } catch(e) {}
        }
      }
    });

    return selectorDialog;
  }

  function openDelegatePopup(elementId, taskId) {
    var p = ensureDelegatePopup();
    p.show();

    var elElementId = p.contentContainer.querySelector('#delegate-element-id');
    var elTaskId    = p.contentContainer.querySelector('#delegate-task-id');
    var elToUserId  = p.contentContainer.querySelector('#delegate-to-user-id');
    var elPickBtn   = p.contentContainer.querySelector('#delegate-pick-user');
    var elSelected  = p.contentContainer.querySelector('#delegate-selected-user');

    if (!elElementId || !elTaskId || !elToUserId || !elPickBtn || !elSelected) { notify('Ошибка окна делегирования.'); return; }

    elElementId.value = String(elementId || '');
    elTaskId.value    = String(taskId || '');
    elToUserId.value  = '';
    elSelected.textContent = 'Сотрудник не выбран';
    elSelected.classList.add('text-muted');

    var newBtn = elPickBtn.cloneNode(true);
    elPickBtn.parentNode.replaceChild(newBtn, elPickBtn);

    newBtn.addEventListener('click', function() {
      var d = ensureSelector(newBtn, function(userId, title) {
        elToUserId.value = String(userId);
        elSelected.textContent = title;
        elSelected.classList.remove('text-muted');
      });

      d.show();
      setTimeout(function(){ try { d.getPopup().adjustPosition(); } catch(e) {} }, 0);
    });
  }

  var cancelPopup = null;
  var historyPopup = null;

  function ensureCancelPopup() {
    if (cancelPopup) return cancelPopup;

    var tpl = document.getElementById('cancel-popup-template');
    var content = tpl ? tpl.innerHTML : '<div style="padding:12px">Ошибка шаблона</div>';

    cancelPopup = BX.PopupWindowManager.create('cancel_bp_popup', null, {
      content: content,
      closeIcon: { right: '12px', top: '10px' },
      autoHide: false,
      overlay: { opacity: 30 },
      draggable: true,
      closeByEsc: true,
      titleBar: 'Отмена заявки',
      zIndex: 20000,
      buttons: [
        new BX.PopupWindowButton({
          text: 'Закрыть',
          className: 'popup-window-button-link-cancel',
          events: { click: function(){ cancelPopup.close(); } }
        }),
        new BX.PopupWindowButton({
          text: 'Отменить заявку',
          className: 'popup-window-button-accept',
          events: {
            click: function() {
              var elStatusSel = cancelPopup.contentContainer.querySelector('#cancel-status-select');
              var elComment   = cancelPopup.contentContainer.querySelector('#cancel-comment-text');
              var elStatusHid = cancelPopup.contentContainer.querySelector('#cancel-status-enum-id');
              var elCommHid   = cancelPopup.contentContainer.querySelector('#cancel-comment-hidden');
              var form        = cancelPopup.contentContainer.querySelector('#cancel-form-popup');

              if (!elStatusSel || !elComment || !elStatusHid || !elCommHid || !form) { notify('Ошибка окна отмены.'); return; }

              var statusVal = (elStatusSel.value || '').trim();
              var commVal   = (elComment.value || '').trim();

              if (!statusVal) { notify('Выберите статус.'); return; }
              if (!commVal)   { notify('Введите комментарий.'); return; }

              elStatusHid.value = statusVal;
              elCommHid.value   = commVal;

              form.submit();
            }
          }
        })
      ]
    });

    return cancelPopup;
  }

  function openCancelPopup(elementId) {
    var p = ensureCancelPopup();
    p.show();

    var elId = p.contentContainer.querySelector('#cancel-element-id');
    var elStatusSel = p.contentContainer.querySelector('#cancel-status-select');
    var elComment = p.contentContainer.querySelector('#cancel-comment-text');
    var elStatusHid = p.contentContainer.querySelector('#cancel-status-enum-id');
    var elCommHid = p.contentContainer.querySelector('#cancel-comment-hidden');

    if (!elId || !elStatusSel || !elComment || !elStatusHid || !elCommHid) { notify('Ошибка окна отмены.'); return; }

    elId.value = String(elementId || '');
    elStatusSel.value = '';
    elComment.value = '';
    elStatusHid.value = '';
    elCommHid.value = '';
  }

  function ensureHistoryPopup() {
    if (historyPopup) return historyPopup;
    var tpl = document.getElementById('history-popup-template');
    var content = tpl ? tpl.innerHTML : '<div style="padding:12px">Ошибка шаблона</div>';
    historyPopup = BX.PopupWindowManager.create('history_bp_popup', null, {
      content: content,
      closeIcon: { right: '12px', top: '10px' },
      autoHide: true,
      overlay: { opacity: 30 },
      draggable: true,
      closeByEsc: true,
      titleBar: 'История заявки',
      zIndex: 20000,
      buttons: [
        new BX.PopupWindowButton({
          text: 'Закрыть',
          className: 'popup-window-button-link-cancel',
          events: { click: function(){ historyPopup.close(); } }
        })
      ]
    });
    return historyPopup;
  }

  function openHistoryPopup(elementId, comments) {
    var p = ensureHistoryPopup();
    p.show();
    var idNode = p.contentContainer.querySelector('#history-element-id');
    var contentNode = p.contentContainer.querySelector('#history-content');
    if (idNode) idNode.textContent = String(elementId || '');
    if (contentNode) {
      var txt = (comments || '').trim();
      contentNode.textContent = txt !== '' ? txt : 'История отсутствует.';
    }
  }

  document.querySelectorAll('.js-action-select').forEach(function(select) {
    select.addEventListener('change', function() {
      var action = select.value || '';
      if (!action) return;

      var elementId = parseInt(select.getAttribute('data-element-id') || '0', 10);
      var taskId = parseInt(select.getAttribute('data-task-id') || '0', 10);
      var canDelegate = (select.getAttribute('data-can-delegate') || '0') === '1';
      var editUrl = select.getAttribute('data-edit-url') || '';
      var copyUrl = select.getAttribute('data-copy-url') || '';

      if (!elementId) {
        notify('Не удалось определить ID заявки.');
        select.value = '';
        return;
      }

      if (action === 'delegate') {
        if (!canDelegate || !taskId) notify('Делегировать можно только свою текущую задачу рекрутера по заявке.');
        else openDelegatePopup(elementId, taskId);
      } else if (action === 'cancel') {
        openCancelPopup(elementId);
      } else if (action === 'edit') {
        if (editUrl) window.open(editUrl, '_blank', 'noopener');
      } else if (action === 'copy') {
        if (copyUrl) window.open(copyUrl, '_blank', 'noopener');
      }

      select.value = '';
    });
  });

  document.querySelectorAll('.js-history-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var elementId = parseInt(btn.getAttribute('data-element-id') || '0', 10);
      var comments = btn.getAttribute('data-comments') || '';
      if (!elementId) { notify('Не удалось определить ID заявки.'); return; }
      openHistoryPopup(elementId, comments);
    });
  });
})();
</script>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
