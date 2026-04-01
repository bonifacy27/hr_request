<?php
/**
 * /forms/staff_recruitment/check_links.php
 *
 * Контроль и настройка связок между сущностями подбора:
 * - заявка на подбор (ИБ 201)
 * - анкеты кандидата (ИБ 207)
 * - офферы (ИБ 218)
 * - карточки сотрудников (ИБ 196)
 *
 * Скрипт:
 * 1) собирает таблицу связей по ID заявки;
 * 2) показывает предупреждения по «битым» связям;
 * 3) по запуску (apply=Y) заполняет в заявке поля:
 *    - PROPERTY_3127 (ANKETA_KANDIDATA, множественное)
 *    - PROPERTY_3128 (ID_OFFERA, множественное)
 *    - PROPERTY_3129 (ID_KARTOCHKI_SOTRUDNIKA, множественное)
 */

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

global $APPLICATION;
$APPLICATION->SetTitle('Проверка связок: заявка → анкеты → офферы → карточки');

const CL_IBLOCK_REQUEST   = 201;
const CL_IBLOCK_CANDIDATE = 207;
const CL_IBLOCK_OFFER     = 218;
const CL_IBLOCK_EMPLOYEE  = 196;

const CL_PROP_CAND_REQ_ID    = 1596; // 207.ID_ZAYAVKI_NA_PODBOR
const CL_PROP_OFFER_REQ_ID   = 1601; // 218.ID_ZAYAVKI_NA_PODBOR
const CL_PROP_OFFER_CAND_ID  = 1603; // 218.ID_ANKETY_KANDIDATA
const CL_PROP_EMP_REQ_ID     = 1619; // 196.ID_ZAYAVKI_NA_PODBOR
const CL_PROP_EMP_CAND_ID    = 1621; // 196.ID_ANKETY_KANDIDATA
const CL_PROP_EMP_OFFER_ID   = 2085; // 196.ID_ZAYAVKI_NA_OFFER

const CL_PROP_REQ_CAND_MULTI = 3127; // 201.ANKETA_KANDIDATA
const CL_PROP_REQ_OFFER_MULTI = 3128; // 201.ID_OFFERA
const CL_PROP_REQ_EMP_MULTI   = 3129; // 201.ID_KARTOCHKI_SOTRUDNIKA

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

function cl_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cl_normalize_id($value): int
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }

    if (preg_match('/\d+/', $value, $m)) {
        return (int)$m[0];
    }

    return 0;
}

function cl_unique_sorted(array $ids): array
{
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, static function ($v) {
        return $v > 0;
    });
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function cl_get_prop_value(int $iblockId, int $elementId, int $propertyId): string
{
    $rs = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['ID' => $propertyId]);
    while ($row = $rs->Fetch()) {
        $v = trim((string)($row['VALUE'] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

function cl_collect_candidates(): array
{
    $byReq = [];
    $rows = [];

    $rs = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => CL_IBLOCK_CANDIDATE, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($item = $rs->GetNext()) {
        $candId = (int)$item['ID'];
        $reqId = cl_normalize_id(cl_get_prop_value(CL_IBLOCK_CANDIDATE, $candId, CL_PROP_CAND_REQ_ID));

        if ($reqId > 0) {
            $byReq[$reqId][] = $candId;
        }

        $rows[$candId] = [
            'id' => $candId,
            'req_id' => $reqId,
            'name' => (string)$item['NAME'],
        ];
    }

    foreach ($byReq as $reqId => $list) {
        $byReq[$reqId] = cl_unique_sorted($list);
    }

    return [$byReq, $rows];
}

function cl_collect_offers(): array
{
    $byReq = [];
    $rows = [];

    $rs = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => CL_IBLOCK_OFFER, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($item = $rs->GetNext()) {
        $offerId = (int)$item['ID'];
        $reqId = cl_normalize_id(cl_get_prop_value(CL_IBLOCK_OFFER, $offerId, CL_PROP_OFFER_REQ_ID));
        $candId = cl_normalize_id(cl_get_prop_value(CL_IBLOCK_OFFER, $offerId, CL_PROP_OFFER_CAND_ID));

        if ($reqId > 0) {
            $byReq[$reqId][] = $offerId;
        }

        $rows[$offerId] = [
            'id' => $offerId,
            'req_id' => $reqId,
            'cand_id' => $candId,
            'name' => (string)$item['NAME'],
        ];
    }

    foreach ($byReq as $reqId => $list) {
        $byReq[$reqId] = cl_unique_sorted($list);
    }

    return [$byReq, $rows];
}

function cl_collect_employees(): array
{
    $byReq = [];
    $rows = [];

    $rs = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => CL_IBLOCK_EMPLOYEE, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($item = $rs->GetNext()) {
        $employeeId = (int)$item['ID'];
        $reqId = cl_normalize_id(cl_get_prop_value(CL_IBLOCK_EMPLOYEE, $employeeId, CL_PROP_EMP_REQ_ID));
        $candId = cl_normalize_id(cl_get_prop_value(CL_IBLOCK_EMPLOYEE, $employeeId, CL_PROP_EMP_CAND_ID));
        $offerId = cl_normalize_id(cl_get_prop_value(CL_IBLOCK_EMPLOYEE, $employeeId, CL_PROP_EMP_OFFER_ID));

        if ($reqId > 0) {
            $byReq[$reqId][] = $employeeId;
        }

        $rows[$employeeId] = [
            'id' => $employeeId,
            'req_id' => $reqId,
            'cand_id' => $candId,
            'offer_id' => $offerId,
            'name' => (string)$item['NAME'],
        ];
    }

    foreach ($byReq as $reqId => $list) {
        $byReq[$reqId] = cl_unique_sorted($list);
    }

    return [$byReq, $rows];
}

function cl_collect_request_ids_from_iblock(): array
{
    $ids = [];

    $rs = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => CL_IBLOCK_REQUEST, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID']
    );

    while ($item = $rs->GetNext()) {
        $ids[] = (int)$item['ID'];
    }

    return cl_unique_sorted($ids);
}

[$candByReq, $candRows] = cl_collect_candidates();
[$offerByReq, $offerRows] = cl_collect_offers();
[$empByReq, $empRows] = cl_collect_employees();

$requestIds = cl_collect_request_ids_from_iblock();
$requestIds = array_merge($requestIds, array_keys($candByReq), array_keys($offerByReq), array_keys($empByReq));
$requestIds = cl_unique_sorted($requestIds);

$apply = (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && check_bitrix_sessid()
    && (string)($_POST['apply'] ?? '') === 'Y'
);

$updated = 0;
$errors = 0;
$errorItems = [];

$rows = [];

foreach ($requestIds as $reqId) {
    $candIds = $candByReq[$reqId] ?? [];
    $offerIds = $offerByReq[$reqId] ?? [];
    $empIds = $empByReq[$reqId] ?? [];

    $warnings = [];

    foreach ($offerIds as $offerId) {
        $offer = $offerRows[$offerId] ?? null;
        if (!$offer) {
            continue;
        }

        $candId = (int)($offer['cand_id'] ?? 0);
        if ($candId > 0 && !in_array($candId, $candIds, true)) {
            $warnings[] = 'Оффер #' . $offerId . ' ссылается на анкету #' . $candId . ', которой нет у заявки.';
        }
    }

    foreach ($empIds as $empId) {
        $emp = $empRows[$empId] ?? null;
        if (!$emp) {
            continue;
        }

        $candId = (int)($emp['cand_id'] ?? 0);
        $offerId = (int)($emp['offer_id'] ?? 0);

        if ($candId > 0 && !in_array($candId, $candIds, true)) {
            $warnings[] = 'Карточка #' . $empId . ' ссылается на анкету #' . $candId . ', которой нет у заявки.';
        }

        if ($offerId > 0 && !in_array($offerId, $offerIds, true)) {
            $warnings[] = 'Карточка #' . $empId . ' ссылается на оффер #' . $offerId . ', которого нет у заявки.';
        }
    }

    if ($apply) {
        $ok = CIBlockElement::SetPropertyValuesEx(
            $reqId,
            CL_IBLOCK_REQUEST,
            [
                'PROPERTY_' . CL_PROP_REQ_CAND_MULTI => $candIds,
                'PROPERTY_' . CL_PROP_REQ_OFFER_MULTI => $offerIds,
                'PROPERTY_' . CL_PROP_REQ_EMP_MULTI => $empIds,
            ]
        );

        if ($ok === false) {
            $errors++;
            $errorItems[] = $reqId;
        } else {
            $updated++;
        }
    }

    $rows[] = [
        'req_id' => $reqId,
        'cand_ids' => $candIds,
        'offer_ids' => $offerIds,
        'emp_ids' => $empIds,
        'warnings' => $warnings,
    ];
}

?>
<style>
    .cl-wrap { margin: 16px 0 32px; }
    .cl-card { border: 1px solid #ddd; border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; background: #fff; }
    .cl-meta { color: #555; margin-top: 8px; }
    .cl-actions { margin: 12px 0 16px; }
    .cl-table { width: 100%; border-collapse: collapse; }
    .cl-table th, .cl-table td { border: 1px solid #e2e8f0; padding: 8px; vertical-align: top; }
    .cl-table th { background: #f8fafc; text-align: left; }
    .cl-warn { color: #b45309; }
    .cl-ok { color: #166534; }
    .cl-err { color: #b91c1c; }
    .cl-small { font-size: 12px; color: #6b7280; }
</style>

<div class="cl-wrap">
    <div class="cl-card">
        <b>Собранные данные:</b>
        <div class="cl-meta">
            Заявок: <?=count($requestIds)?>,
            анкет: <?=count($candRows)?>,
            офферов: <?=count($offerRows)?>,
            карточек сотрудников: <?=count($empRows)?>.
        </div>

        <?php if ($apply): ?>
            <div class="cl-meta cl-ok" style="margin-top:10px;">
                Обновление выполнено. Успешно: <?=$updated?>.
            </div>
            <?php if ($errors > 0): ?>
                <div class="cl-meta cl-err">
                    Ошибки обновления: <?=$errors?> (заявки: <?=cl_h(implode(', ', $errorItems))?>).
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="cl-actions">
                <form method="post">
                    <?=bitrix_sessid_post()?>
                    <input type="hidden" name="apply" value="Y">
                    <button type="submit" class="ui-btn ui-btn-success">Проставить связи в заявках (PROPERTY_3127/3128/3129)</button>
                </form>
            </div>
            <div class="cl-small">
                Кнопка заполнит в каждой заявке множественные поля ID анкет/офферов/карточек на основе текущих ссылок в ИБ 207/218/196.
            </div>
        <?php endif; ?>
    </div>

    <table class="cl-table">
        <thead>
        <tr>
            <th>ID заявки</th>
            <th>Анкеты кандидатов (207)</th>
            <th>Офферы (218)</th>
            <th>Карточки сотрудников (196)</th>
            <th>Контроль связки</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td>#<?= (int)$row['req_id'] ?></td>
                <td><?= cl_h(implode(', ', $row['cand_ids'])) ?: '—' ?></td>
                <td><?= cl_h(implode(', ', $row['offer_ids'])) ?: '—' ?></td>
                <td><?= cl_h(implode(', ', $row['emp_ids'])) ?: '—' ?></td>
                <td>
                    <?php if (empty($row['warnings'])): ?>
                        <span class="cl-ok">OK</span>
                    <?php else: ?>
                        <?php foreach ($row['warnings'] as $warn): ?>
                            <div class="cl-warn">• <?=cl_h($warn)?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
