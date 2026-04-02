<?php
/**
 * candidate_to_offer.php
 * Создание черновика оффера из анкеты кандидата и заявки на подбор
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\Context;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание черновика оффера');

if (!Loader::includeModule('main'))   { ShowError('Модуль main не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
if (!Loader::includeModule('iblock')) { ShowError('Модуль iblock не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
if (!Loader::includeModule('bizproc')){ ShowError('Модуль bizproc не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }
if (!Loader::includeModule('lists'))  { ShowError('Модуль lists не установлен'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }

const IBL_CANDIDATES = 207;
const IBL_REQUESTS   = 201;
const IBL_OFFERS     = 218;
const PROP_REQ_OFFERS_MULTI = 3128; // ID_OFFERA, множественное число
const PROP_OFFER_CANDIDATE_ID = 1603; // ссылка на анкету кандидата в оффере

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function userIdFromValue($raw): int
{
    $value = trim((string)$raw);
    if ($value === '') {
        return 0;
    }
    if (stripos($value, 'user_') === 0) {
        return (int)substr($value, 5);
    }
    return (int)$value;
}

function getCandidateById(int $candidateId): ?array
{
    $select = [
        'ID',
        'NAME',
        'PROPERTY_1083', // FAMILIYA
        'PROPERTY_1084', // IMYA
        'PROPERTY_1085', // OTCHESTVO
        'PROPERTY_1089', // E_MAIL
        'PROPERTY_1596', // ID_ZAYAVKI_NA_PODBOR
        'PROPERTY_1594', // ID_KANDIDATA_FRIENDWORK
        'PROPERTY_1323', // REKRUTER
    ];

    $rs = CIBlockElement::GetList([], [
        'IBLOCK_ID' => IBL_CANDIDATES,
        'ACTIVE' => 'Y',
        'ID' => $candidateId,
        'CHECK_PERMISSIONS' => 'Y',
    ], false, ['nTopCount' => 1], $select);

    if (!($row = $rs->GetNext())) {
        return null;
    }

    $lastName = trim((string)($row['PROPERTY_1083_VALUE'] ?? ''));
    $firstName = trim((string)($row['PROPERTY_1084_VALUE'] ?? ''));
    $middleName = trim((string)($row['PROPERTY_1085_VALUE'] ?? ''));

    return [
        'ID' => (int)$row['ID'],
        'FIO' => trim($lastName . ' ' . $firstName . ' ' . $middleName),
        'LAST_NAME' => $lastName,
        'FIRST_NAME' => $firstName,
        'MIDDLE_NAME' => $middleName,
        'EMAIL' => trim((string)($row['PROPERTY_1089_VALUE'] ?? '')),
        'REQUEST_ID' => (int)($row['PROPERTY_1596_VALUE'] ?? 0),
        'FW_CANDIDATE_ID' => trim((string)($row['PROPERTY_1594_VALUE'] ?? '')),
        'RECRUITER_RAW' => (string)($row['PROPERTY_1323_VALUE'] ?? ''),
        'RECRUITER_ID' => userIdFromValue($row['PROPERTY_1323_VALUE'] ?? ''),
    ];
}

function getRequestById(int $requestId): ?array
{
    $row = CIBlockElement::GetList([], [
        'IBLOCK_ID' => IBL_REQUESTS,
        'ACTIVE' => 'Y',
        'ID' => $requestId,
        'CHECK_PERMISSIONS' => 'Y',
    ], false, ['nTopCount' => 1], ['ID', 'IBLOCK_ID', 'NAME'])->GetNext();
    if (!$row) {
        return null;
    }

    $propCodes = [
        'RUKOVODYASHCHAYA_DOLZHNOST',
        'DOLZHNOST',
        'DIREKTSIYA',
        'PODRAZDELENIE',
        'NEPOSREDSTVENNYY_RUKOVODITEL',
        'DOLZHNOST_RUKOVODITELYA',
        'OKLAD',
        'ISN_RUB_GROSS',
        'PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA',
        'PROTSENT_PREMII_',
        'FORMAT_RABOTY_PRIVYAZKA',
        'OFIS_PRIVYAZKA',
        'GRAFIK_RABOTY_PRIVYAZKA',
        'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA',
        'NACHALO_RABOCHEGO_DNYA_TEKST',
        'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA',
        'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA',
        'YURIDICHESKOE_LITSO',
    ];
    $props = [];
    CIBlockElement::GetPropertyValuesArray($props, IBL_REQUESTS, ['ID' => (int)$row['ID']], ['CODE' => $propCodes]);
    $p = $props[(int)$row['ID']] ?? [];

    $raw = static function(array $allProps, string $code): string {
        $v = $allProps[$code]['VALUE'] ?? '';
        if (is_array($v)) $v = reset($v);
        return trim((string)$v);
    };
    $enum = static function(array $allProps, string $code): string {
        $v = $allProps[$code]['VALUE_ENUM'] ?? '';
        if (is_array($v)) $v = reset($v);
        return trim((string)$v);
    };
    $disp = static function(array $allProps, string $code) use ($raw, $enum): string {
        $e = $enum($allProps, $code);
        if ($e !== '') return $e;
        return $raw($allProps, $code);
    };
    $resolveElementName = static function(string $value): string {
        $id = (int)$value;
        if ($id <= 0) return $value;
        $row = CIBlockElement::GetByID($id)->GetNext();
        if ($row && !empty($row['NAME'])) return (string)$row['NAME'];
        return $value;
    };
    $resolveUserName = static function(string $value): string {
        $id = (int)$value;
        if ($id <= 0) return $value;
        $u = CUser::GetByID($id)->Fetch();
        if (!$u) return $value;
        $name = trim((string)CUser::FormatName(CSite::GetNameFormat(false), $u, true, false));
        return $name !== '' ? $name : $value;
    };

    return [
        'ID' => (int)$row['ID'],
        // RAW значения для заполнения оффера
        'CHIEF_POSITION_FLAG' => $raw($p, 'RUKOVODYASHCHAYA_DOLZHNOST'),
        'WORK_POSITION' => $raw($p, 'DOLZHNOST'),
        'DIRECTION' => $raw($p, 'DIREKTSIYA'),
        'DEPARTMENT' => $raw($p, 'PODRAZDELENIE'),
        'CHIEF' => $raw($p, 'NEPOSREDSTVENNYY_RUKOVODITEL'),
        'CHIEF_POSITION' => $raw($p, 'DOLZHNOST_RUKOVODITELYA'),
        'PROFIT' => $raw($p, 'OKLAD'),
        'ISN' => $raw($p, 'ISN_RUB_GROSS'),
        'BONUS_TYPE' => $raw($p, 'PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA'),
        'BONUS_PERCENT' => $raw($p, 'PROTSENT_PREMII_'),
        'WORK_FORMAT_LINK' => $raw($p, 'FORMAT_RABOTY_PRIVYAZKA'),
        'OFFICE_LINK' => $raw($p, 'OFIS_PRIVYAZKA'),
        'WORK_SCHEDULE_LINK' => $raw($p, 'GRAFIK_RABOTY_PRIVYAZKA'),
        'WORK_BEGIN_HOUR_LINK' => $raw($p, 'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA'),
        'WORK_BEGIN_HOUR_TEXT' => $raw($p, 'NACHALO_RABOCHEGO_DNYA_TEKST'),
        'DOGOVOR_TYPE_LINK' => $raw($p, 'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA'),
        'EQUIPMENT_LINK' => $raw($p, 'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA'),
        'ORGANISATION' => $raw($p, 'YURIDICHESKOE_LITSO'),
        // DISPLAY значения для предпросмотра
        'WORK_POSITION_DISPLAY' => $disp($p, 'DOLZHNOST'),
        'DIRECTION_DISPLAY' => $disp($p, 'DIREKTSIYA'),
        'DEPARTMENT_DISPLAY' => $disp($p, 'PODRAZDELENIE'),
        'CHIEF_DISPLAY' => $resolveUserName($disp($p, 'NEPOSREDSTVENNYY_RUKOVODITEL')),
        'CHIEF_POSITION_DISPLAY' => $disp($p, 'DOLZHNOST_RUKOVODITELYA'),
        'PROFIT_DISPLAY' => $disp($p, 'OKLAD'),
        'ISN_DISPLAY' => $disp($p, 'ISN_RUB_GROSS'),
        'BONUS_TYPE_DISPLAY' => $disp($p, 'PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA'),
        'BONUS_PERCENT_DISPLAY' => $disp($p, 'PROTSENT_PREMII_'),
        'WORK_FORMAT_LINK_DISPLAY' => $resolveElementName($disp($p, 'FORMAT_RABOTY_PRIVYAZKA')),
        'OFFICE_LINK_DISPLAY' => $resolveElementName($disp($p, 'OFIS_PRIVYAZKA')),
        'WORK_SCHEDULE_LINK_DISPLAY' => $resolveElementName($disp($p, 'GRAFIK_RABOTY_PRIVYAZKA')),
        'WORK_BEGIN_HOUR_LINK_DISPLAY' => $resolveElementName($disp($p, 'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA')),
        'WORK_BEGIN_HOUR_TEXT_DISPLAY' => $disp($p, 'NACHALO_RABOCHEGO_DNYA_TEKST'),
        'DOGOVOR_TYPE_LINK_DISPLAY' => $resolveElementName($disp($p, 'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA')),
        'EQUIPMENT_LINK_DISPLAY' => $resolveElementName($disp($p, 'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA')),
        'ORGANISATION_DISPLAY' => $resolveElementName($disp($p, 'YURIDICHESKOE_LITSO')),
    ];
}

function normalizeChiefPosition(string $flag): int
{
    if ($flag === 'Y') return 1159;
    if ($flag === 'N') return 1160;
    return 1160;
}

function findUserRunningTaskForDocument(int $iblockId, int $elementId, int $userId): ?array
{
    $candidates = [
        ['lists',  'BizprocDocument', "lists_{$iblockId}_{$elementId}"],
        ['iblock', 'CIBlockDocument', "iblock_{$iblockId}_{$elementId}"],
        ['lists',  'Bitrix\\Lists\\BizprocDocumentLists', $elementId],
    ];

    foreach ($candidates as $docId) {
        try {
            $rs = CBPTaskService::GetList(
                ['ID' => 'ASC'],
                ['DOCUMENT_ID' => $docId, 'STATUS' => CBPTaskStatus::Running, 'USER_ID' => $userId],
                false,
                false,
                ['ID','NAME','WORKFLOW_ID','USER_ID','ACTIVITY','ACTIVITY_NAME']
            );
            if ($task = $rs->GetNext()) {
                return [
                    'ID' => (int)$task['ID'],
                    'NAME' => (string)$task['NAME'],
                    'WORKFLOW_ID' => (string)$task['WORKFLOW_ID'],
                    'ACTIVITY_NAME' => (string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? ''),
                    'DOCUMENT_ID' => $docId,
                ];
            }
        } catch (\Throwable $e) {
        }
    }

    return null;
}

function taskIsRunning(int $taskId): bool
{
    if ($taskId <= 0) {
        return false;
    }
    try {
        $rs = CBPTaskService::GetList(
            ['ID' => 'ASC'],
            ['ID' => $taskId, 'STATUS' => CBPTaskStatus::Running],
            false,
            false,
            ['ID']
        );
        return (bool)$rs->GetNext();
    } catch (\Throwable $e) {
        return false;
    }
}

function getRunningTaskByIdForUser(int $taskId, int $userId): ?array
{
    if ($taskId <= 0 || $userId <= 0) {
        return null;
    }
    try {
        $rs = CBPTaskService::GetList(
            ['ID' => 'ASC'],
            ['ID' => $taskId, 'STATUS' => CBPTaskStatus::Running, 'USER_ID' => $userId],
            false,
            false,
            ['ID','NAME','WORKFLOW_ID','USER_ID','ACTIVITY','ACTIVITY_NAME']
        );
        $task = $rs->GetNext();
        if (!$task) {
            return null;
        }
        return [
            'ID' => (int)$task['ID'],
            'NAME' => (string)$task['NAME'],
            'WORKFLOW_ID' => (string)$task['WORKFLOW_ID'],
            'ACTIVITY_NAME' => (string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? ''),
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

function flattenBizprocErrors(array $errors): string
{
    $parts = [];
    foreach ($errors as $e) {
        if (is_array($e)) {
            $msg = trim((string)($e['message'] ?? $e['MESSAGE'] ?? ''));
            $code = trim((string)($e['code'] ?? $e['CODE'] ?? ''));
            if ($msg !== '' && $code !== '') $parts[] = $code . ': ' . $msg;
            elseif ($msg !== '') $parts[] = $msg;
            elseif ($code !== '') $parts[] = $code;
        } else {
            $s = trim((string)$e);
            if ($s !== '') $parts[] = $s;
        }
    }
    return implode('; ', array_values(array_unique($parts)));
}

function appendOfferToRequest(int $requestId, int $offerId): void
{
    $values = [];
    $rs = CIBlockElement::GetProperty(IBL_REQUESTS, $requestId, ['sort' => 'asc'], ['ID' => PROP_REQ_OFFERS_MULTI]);
    while ($p = $rs->Fetch()) {
        $v = (int)($p['VALUE'] ?? 0);
        if ($v > 0) $values[] = $v;
    }
    $values[] = $offerId;
    $values = array_values(array_unique(array_map('intval', $values)));

    CIBlockElement::SetPropertyValuesEx($requestId, IBL_REQUESTS, [
        PROP_REQ_OFFERS_MULTI => $values,
    ]);
}

function findOfferByCandidateId(int $candidateId): int
{
    if ($candidateId <= 0) {
        return 0;
    }
    $row = CIBlockElement::GetList([], [
        'IBLOCK_ID' => IBL_OFFERS,
        'PROPERTY_' . PROP_OFFER_CANDIDATE_ID => $candidateId,
        'CHECK_PERMISSIONS' => 'Y',
    ], false, ['nTopCount' => 1], ['ID'])->Fetch();
    return (int)($row['ID'] ?? 0);
}

function completeBizprocTask(array $task, int $userId, string $action = 'approve', string $comment = ''): array
{
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0) {
        return ['OK' => false, 'ERROR' => 'Некорректный ID задачи БП.'];
    }

    $errors = [];
    $aliases = [
        'yes' => 'approve',
        'ok' => 'approve',
        'no' => 'nonapprove',
        'cancel' => 'nonapprove',
        'reject' => 'nonapprove',
        'decline' => 'nonapprove',
    ];
    $code = strtolower(trim((string)$action));
    if ($code === '') $code = 'approve';
    if (isset($aliases[$code])) $code = $aliases[$code];

    // Попытка 1: ACTION + именованный флаг
    try {
        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $fields1 = [
                'USER_ID' => $userId,
                'REAL_USER_ID' => $userId,
                'COMMENT' => $comment,
                'ACTION' => $code,
                $code => 'Y',
            ];
            $tmpErr = [];
            CBPDocument::PostTaskForm($taskId, $userId, $fields1, $tmpErr);
            if (!empty($tmpErr)) {
                $errors = array_merge($errors, $tmpErr);
            }
            if (!taskIsRunning($taskId)) {
                return ['OK' => true, 'ERROR' => ''];
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    // Попытка 2: только именованный флаг
    try {
        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $fields2 = [
                'USER_ID' => $userId,
                'REAL_USER_ID' => $userId,
                'COMMENT' => $comment,
                $code => 'Y',
            ];
            $tmpErr2 = [];
            CBPDocument::PostTaskForm($taskId, $userId, $fields2, $tmpErr2);
            if (!empty($tmpErr2)) {
                $errors = array_merge($errors, $tmpErr2);
            }
            if (!taskIsRunning($taskId)) {
                return ['OK' => true, 'ERROR' => ''];
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    // Попытка 3: внешний эвент (APPROVE / RESULT)
    try {
        $workflowId = (string)($task['WORKFLOW_ID'] ?? '');
        $activity = (string)($task['ACTIVITY_NAME'] ?? $task['NAME'] ?? '');
        if ($workflowId !== '' && $activity !== '' && method_exists('CBPDocument', 'SendExternalEvent')) {
            $isYes = in_array($code, ['approve','accepted','accept','ok','yes','y','agree'], true);
            $isNo  = in_array($code, ['cancel','rejected','reject','no','n','disagree','decline','deny','refuse','nonapprove'], true);
            $payloads = [];
            if ($isYes || $isNo) {
                $appr = $isYes ? 'Y' : 'N';
                $payloads[] = ['APPROVE' => $appr, 'COMMENT' => $comment, 'USER_ID' => $userId, 'REAL_USER_ID' => $userId];
                $payloads[] = ['RESULT' => $appr, 'COMMENT' => $comment, 'USER_ID' => $userId, 'REAL_USER_ID' => $userId];
            } else {
                $payloads[] = ['COMMENT' => $comment, 'USER_ID' => $userId, 'REAL_USER_ID' => $userId];
            }

            foreach ($payloads as $payload) {
                $extErr = [];
                CBPDocument::SendExternalEvent($workflowId, $activity, $payload, $extErr);
                if (!empty($extErr)) {
                    $errors = array_merge($errors, $extErr);
                }
                if (!taskIsRunning($taskId)) {
                    return ['OK' => true, 'ERROR' => ''];
                }
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    // Последний fallback: старый DoTask
    try {
        if (method_exists('CBPTaskService', 'DoTask')) {
            CBPTaskService::DoTask($taskId, $userId, [
                'ACTION' => $code,
                $code => 'Y',
                'COMMENT' => $comment,
            ]);
            if (!taskIsRunning($taskId)) {
                return ['OK' => true, 'ERROR' => ''];
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    $flat = flattenBizprocErrors($errors);
    if ($flat === '') {
        $flat = 'Задание осталось активным после всех попыток завершения.';
    }
    return ['OK' => false, 'ERROR' => $flat];
}

function buildOfferPropertyMap(array $candidate, array $request): array
{
    $isChiefPosition = normalizeChiefPosition((string)$request['CHIEF_POSITION_FLAG']);

    $equipment = (string)$request['EQUIPMENT_LINK'];
    if ($equipment === '') $equipment = '3263612';

    $dogovorType = (string)$request['DOGOVOR_TYPE_LINK'];
    if ($dogovorType === '') $dogovorType = '3263600';

    $organisation = (string)$request['ORGANISATION'];
    if ($organisation === '') $organisation = '3197820';

    return [
        1157 => (string)$candidate['FIO'],
        1618 => $isChiefPosition,
        1161 => (string)$request['WORK_POSITION'],
        1996 => (string)$request['DIRECTION'],
        1163 => (string)$request['DEPARTMENT'],
        1164 => (string)$request['CHIEF'],
        1169 => (string)$request['CHIEF_POSITION'],
        1165 => (string)$request['PROFIT'],
        1184 => (string)$request['ISN'],
        1998 => (string)$request['BONUS_TYPE'],
        1186 => (string)$request['BONUS_PERCENT'],
        1177 => 'ДМС по истечению испытательного срока',
        1327 => (string)$request['WORK_FORMAT_LINK'],
        1326 => (string)$request['OFFICE_LINK'],
        1328 => (string)$request['WORK_SCHEDULE_LINK'],
        1329 => (string)$request['WORK_BEGIN_HOUR_LINK'],
        2070 => $equipment,
        2002 => $dogovorType,
        2753 => $organisation,
        1190 => (int)$candidate['RECRUITER_ID'],
        1601 => (int)$candidate['REQUEST_ID'],
        1603 => (int)$candidate['ID'],
        1602 => (string)$candidate['FW_CANDIDATE_ID'],
        2857 => 'Из анкеты кандидата ' . (int)$candidate['ID'],
    ];
}

$request = Context::getCurrent()->getRequest();
$currentUserId = (int)$USER->GetID();
$candidateId = (int)$request->get('id');

$errors = [];
$warnings = [];
$success = '';
$offerIdCreated = 0;
$taskUrl = '';

if ($candidateId <= 0) {
    $errors[] = 'Не передан корректный id анкеты кандидата.';
}

$candidate = $candidateId > 0 ? getCandidateById($candidateId) : null;
if ($candidateId > 0 && !$candidate) {
    $errors[] = 'Анкета кандидата не найдена или недоступна.';
}

$requestItem = null;
if ($candidate) {
    if ((int)$candidate['REQUEST_ID'] <= 0) {
        $errors[] = 'В анкете кандидата не заполнен ID заявки на подбор (PROPERTY_1596).';
    } else {
        $requestItem = getRequestById((int)$candidate['REQUEST_ID']);
        if (!$requestItem) {
            $errors[] = 'Заявка на подбор из анкеты не найдена или недоступна.';
        }
    }
}

$task = null;
if ($candidateId > 0 && $currentUserId > 0) {
    $task = findUserRunningTaskForDocument(IBL_CANDIDATES, $candidateId, $currentUserId);
    if (!$task) {
        $warnings[] = 'Не найдена активная задача БП по этой анкете для текущего пользователя. Автозавершение может быть недоступно.';
    } else {
        $taskUrl = '/company/personal/bizproc/' . (int)$task['ID'] . '/';
    }
}

if ($request->isPost() && check_bitrix_sessid()) {
    $action = (string)$request->getPost('action');

    if ($action === 'create_offer' && empty($errors) && $candidate && $requestItem) {
        $existingOfferId = findOfferByCandidateId((int)$candidate['ID']);
        if ($existingOfferId > 0) {
            $offerIdCreated = $existingOfferId;
            appendOfferToRequest((int)$candidate['REQUEST_ID'], $offerIdCreated);
            $warnings[] = 'По этой анкете уже существует оффер #' . $offerIdCreated . '. Повторное создание не выполнено.';
        }

        if ($candidate['FIO'] === '') {
            $errors[] = 'Не заполнено ФИО кандидата (PROPERTY_1083/1084/1085).';
        }
        if ((string)$requestItem['WORK_POSITION'] === '') {
            $warnings[] = 'В заявке не заполнена должность.';
        }
        if ((string)$requestItem['DEPARTMENT'] === '') {
            $warnings[] = 'В заявке не заполнено подразделение.';
        }
        if ((string)$requestItem['CHIEF'] === '') {
            $warnings[] = 'В заявке не заполнен непосредственный руководитель.';
        }
        if ((string)$requestItem['PROFIT'] === '') {
            $warnings[] = 'В заявке не заполнен оклад.';
        }

        if (empty($errors) && $offerIdCreated <= 0) {
            $offerProps = buildOfferPropertyMap($candidate, $requestItem);
            $el = new CIBlockElement();
            $offerId = $el->Add([
                'IBLOCK_ID' => IBL_OFFERS,
                'PROPERTY_VALUES' => $offerProps,
                'NAME' => 'Заявка на оффер после проверки СБ',
                'ACTIVE' => 'Y',
            ]);

            if ($offerId) {
                $offerIdCreated = (int)$offerId;
                appendOfferToRequest((int)$candidate['REQUEST_ID'], $offerIdCreated);
                $success = 'Черновик оффера успешно создан (ID: ' . $offerIdCreated . ').';
            } else {
                $errors[] = 'Не удалось создать оффер: ' . ($el->LAST_ERROR ?: 'неизвестная ошибка');
            }
        }

        // Автоматически завершаем задачу БП после успешного создания/нахождения оффера
        if (empty($errors) && $offerIdCreated > 0) {
            $taskId = (int)($task['ID'] ?? 0);
            if ($taskId <= 0) {
                $warnings[] = 'Оффер создан, но не удалось определить ID задачи БП для автозавершения.';
            } else {
                $actualTask = getRunningTaskByIdForUser($taskId, $currentUserId);
                if (!$actualTask) {
                    $warnings[] = 'Оффер создан, но задача БП не найдена среди активных задач текущего пользователя.';
                } else {
                    $done = completeBizprocTask($actualTask, $currentUserId, 'approve', 'Создан черновик оффера #' . $offerIdCreated);
                    if ($done['OK']) {
                        $success = 'Черновик оффера создан (ID: ' . $offerIdCreated . '), задание БП успешно завершено.';
                    } else {
                        $warnings[] = 'Оффер создан (ID: ' . $offerIdCreated . '), но задачу БП завершить не удалось: ' . $done['ERROR'];
                    }
                }
            }
        }
    }
}

?>
<div class="ui-ctl-wrapper" style="max-width: 1024px;">
    <h2 style="margin-bottom:16px;">Создание черновика оффера из анкеты кандидата</h2>

    <?php if (!empty($success)): ?>
        <div class="ui-alert ui-alert-success" style="margin-bottom:16px;"><?=h($success)?></div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
        <div class="ui-alert ui-alert-danger" style="margin-bottom:8px;"><?=h($e)?></div>
    <?php endforeach; ?>

    <?php foreach ($warnings as $w): ?>
        <div class="ui-alert ui-alert-warning" style="margin-bottom:8px;"><?=h($w)?></div>
    <?php endforeach; ?>

    <?php if ($candidate): ?>
        <h3>Данные анкеты кандидата</h3>
        <table class="ui-table" style="width:100%; margin-bottom:18px;">
            <tbody>
            <tr><td><b>ID анкеты</b></td><td><?=h($candidate['ID'])?></td></tr>
            <tr><td><b>ФИО</b></td><td><?=h($candidate['FIO'])?></td></tr>
            <tr><td><b>E-mail</b></td><td><?=h($candidate['EMAIL'])?></td></tr>
            <tr><td><b>ID заявки на подбор</b></td><td><?=h($candidate['REQUEST_ID'])?></td></tr>
            <tr><td><b>ID кандидата Friendwork</b></td><td><?=h($candidate['FW_CANDIDATE_ID'])?></td></tr>
            <tr><td><b>Рекрутер (user ID)</b></td><td><?=h($candidate['RECRUITER_ID'])?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($requestItem): ?>
        <h3>Данные заявки на подбор (для оффера)</h3>
        <table class="ui-table" style="width:100%; margin-bottom:18px;">
            <tbody>
            <tr><td><b>ID заявки</b></td><td><?=h($requestItem['ID'])?></td></tr>
            <tr><td><b>Должность</b></td><td><?=h($requestItem['WORK_POSITION_DISPLAY'])?></td></tr>
            <tr><td><b>Дирекция</b></td><td><?=h($requestItem['DIRECTION_DISPLAY'])?></td></tr>
            <tr><td><b>Подразделение</b></td><td><?=h($requestItem['DEPARTMENT_DISPLAY'])?></td></tr>
            <tr><td><b>Руководитель</b></td><td><?=h($requestItem['CHIEF_DISPLAY'])?></td></tr>
            <tr><td><b>Должность руководителя</b></td><td><?=h($requestItem['CHIEF_POSITION_DISPLAY'])?></td></tr>
            <tr><td><b>Оклад</b></td><td><?=h($requestItem['PROFIT_DISPLAY'])?></td></tr>
            <tr><td><b>ИСН</b></td><td><?=h($requestItem['ISN_DISPLAY'])?></td></tr>
            <tr><td><b>Тип премии</b></td><td><?=h($requestItem['BONUS_TYPE_DISPLAY'])?></td></tr>
            <tr><td><b>Процент премии</b></td><td><?=h($requestItem['BONUS_PERCENT_DISPLAY'])?></td></tr>
            <tr><td><b>Формат работы</b></td><td><?=h($requestItem['WORK_FORMAT_LINK_DISPLAY'])?></td></tr>
            <tr><td><b>Офис</b></td><td><?=h($requestItem['OFFICE_LINK_DISPLAY'])?></td></tr>
            <tr><td><b>График работы</b></td><td><?=h($requestItem['WORK_SCHEDULE_LINK_DISPLAY'])?></td></tr>
            <tr><td><b>Начало рабочего дня (справочник)</b></td><td><?=h($requestItem['WORK_BEGIN_HOUR_LINK_DISPLAY'])?></td></tr>
            <tr><td><b>Начало рабочего дня (текст)</b></td><td><?=h($requestItem['WORK_BEGIN_HOUR_TEXT_DISPLAY'])?></td></tr>
            <tr><td><b>Тип договора</b></td><td><?=h($requestItem['DOGOVOR_TYPE_LINK_DISPLAY'])?></td></tr>
            <tr><td><b>Оборудование</b></td><td><?=h($requestItem['EQUIPMENT_LINK_DISPLAY'])?></td></tr>
            <tr><td><b>Юридическое лицо</b></td><td><?=h($requestItem['ORGANISATION_DISPLAY'])?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (empty($errors) && $candidate && $requestItem): ?>
        <form method="post" style="display:inline-block; margin-right:10px;">
            <?=bitrix_sessid_post()?>
            <input type="hidden" name="action" value="create_offer">
            <button type="submit" class="ui-btn ui-btn-success">Создать черновик оффера</button>
        </form>
    <?php endif; ?>

    <?php if ($taskUrl !== ''): ?>
        <a href="<?=h($taskUrl)?>" class="ui-btn ui-btn-light-border">Отмена</a>
    <?php endif; ?>

    <?php if (!empty($task)): ?>
        <div style="margin-top:14px;color:#6b7280;">
            Найдена активная задача БП: #<?=h($task['ID'])?> (<?=h($task['NAME'])?>)
        </div>
    <?php endif; ?>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
