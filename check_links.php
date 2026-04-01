<?php
/**
 * /forms/staff_recruitment/check_links.php
 *
 * Контроль и настройка связок между сущностями подбора:
 * - заявка на подбор (ИБ 201)
 * - анкеты кандидата (ИБ 207)
 * - офферы (ИБ 218)
 * - карточки сотрудников (ИБ 196)
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

const CL_PROP_REQ_CAND_MULTI  = 3127; // 201.ANKETA_KANDIDATA
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

function cl_get_prop_values(int $iblockId, int $elementId, int $propertyId): array
{
    $values = [];
    $rs = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['ID' => $propertyId]);
    while ($row = $rs->Fetch()) {
        $id = cl_normalize_id($row['VALUE'] ?? '');
        if ($id > 0) {
            $values[] = $id;
        }
    }
    return cl_unique_sorted($values);
}

function cl_update_request_links(int $requestId, array $candIds, array $offerIds, array $empIds): array
{
    $candIds = cl_unique_sorted($candIds);
    $offerIds = cl_unique_sorted($offerIds);
    $empIds = cl_unique_sorted($empIds);

    // Для SetPropertyValuesEx используем ID свойств (число),
    // т.к. в ИБ поля множественные, тип "Число".
    CIBlockElement::SetPropertyValuesEx(
        $requestId,
        CL_IBLOCK_REQUEST,
        [
            CL_PROP_REQ_CAND_MULTI => $candIds,
            CL_PROP_REQ_OFFER_MULTI => $offerIds,
            CL_PROP_REQ_EMP_MULTI => $empIds,
        ]
    );

    // Явно проверяем, что значения реально записались.
    $savedCand = cl_get_prop_values(CL_IBLOCK_REQUEST, $requestId, CL_PROP_REQ_CAND_MULTI);
    $savedOffer = cl_get_prop_values(CL_IBLOCK_REQUEST, $requestId, CL_PROP_REQ_OFFER_MULTI);
    $savedEmp = cl_get_prop_values(CL_IBLOCK_REQUEST, $requestId, CL_PROP_REQ_EMP_MULTI);

    $ok = ($savedCand === $candIds) && ($savedOffer === $offerIds) && ($savedEmp === $empIds);

    return [
        'ok' => $ok,
        'saved_cand' => $savedCand,
        'saved_offer' => $savedOffer,
        'saved_emp' => $savedEmp,
    ];
}

function cl_collect_entities(int $iblockId, int $propReqId, ?int $propCandId = null, ?int $propOfferId = null): array
{
    $byReq = [];
    $rows = [];

    $rs = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($item = $rs->GetNext()) {
        $id = (int)$item['ID'];
        $reqId = cl_normalize_id(cl_get_prop_value($iblockId, $id, $propReqId));
        $candId = $propCandId ? cl_normalize_id(cl_get_prop_value($iblockId, $id, $propCandId)) : 0;
        $offerId = $propOfferId ? cl_normalize_id(cl_get_prop_value($iblockId, $id, $propOfferId)) : 0;

        if ($reqId > 0) {
            $byReq[$reqId][] = $id;
        }

        $rows[$id] = [
            'id' => $id,
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

function cl_collect_requests(): array
{
    $ids = [];
    $names = [];

    $rs = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => CL_IBLOCK_REQUEST, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($item = $rs->GetNext()) {
        $id = (int)$item['ID'];
        $ids[] = $id;
        $names[$id] = (string)$item['NAME'];
    }

    return [cl_unique_sorted($ids), $names];
}

function cl_render_compact_links(array $ids, array $map): string
{
    if (empty($ids)) {
        return '—';
    }

    $parts = [];
    foreach ($ids as $id) {
        $name = trim((string)($map[$id]['name'] ?? ''));
        $parts[] = '#' . (int)$id . ($name !== '' ? ' — ' . $name : '');
    }

    return implode('<br>', array_map('cl_h', $parts));
}

[$requestIds, $requestNames] = cl_collect_requests();
[$candByReq, $candRows] = cl_collect_entities(CL_IBLOCK_CANDIDATE, CL_PROP_CAND_REQ_ID);
[$offerByReq, $offerRows] = cl_collect_entities(CL_IBLOCK_OFFER, CL_PROP_OFFER_REQ_ID, CL_PROP_OFFER_CAND_ID);
[$empByReq, $empRows] = cl_collect_entities(CL_IBLOCK_EMPLOYEE, CL_PROP_EMP_REQ_ID, CL_PROP_EMP_CAND_ID, CL_PROP_EMP_OFFER_ID);

$requestIds = array_merge($requestIds, array_keys($candByReq), array_keys($offerByReq), array_keys($empByReq));
$requestIds = cl_unique_sorted($requestIds);

$apply = (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && check_bitrix_sessid()
    && (string)($_POST['apply'] ?? '') === 'Y'
);

$selectedReqIds = array_map('intval', (array)($_POST['selected_req'] ?? []));
$selectedReqIds = cl_unique_sorted($selectedReqIds);
$selectedReqMap = array_fill_keys($selectedReqIds, true);

$updated = 0;
$errors = 0;
$errorItems = [];
$message = '';

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
            $warnings[] = 'Оффер #' . $offerId . ' → анкета #' . $candId . ' вне заявки';
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
            $warnings[] = 'Карточка #' . $empId . ' → анкета #' . $candId . ' вне заявки';
        }

        if ($offerId > 0 && !in_array($offerId, $offerIds, true)) {
            $warnings[] = 'Карточка #' . $empId . ' → оффер #' . $offerId . ' вне заявки';
        }
    }

    if ($apply && isset($selectedReqMap[$reqId])) {
        $updateResult = cl_update_request_links($reqId, $candIds, $offerIds, $empIds);
        if (!$updateResult['ok']) {
            $errors++;
            $errorItems[] = $reqId;
        } else {
            $updated++;
        }
    }

    $rows[] = [
        'req_id' => $reqId,
        'req_name' => (string)($requestNames[$reqId] ?? ''),
        'cand_ids' => $candIds,
        'offer_ids' => $offerIds,
        'emp_ids' => $empIds,
        'warnings' => $warnings,
    ];
}

if ($apply) {
    if (empty($selectedReqIds)) {
        $message = 'Не выбрано ни одной заявки для обновления.';
    } else {
        $message = 'Обновление выполнено. Успешно: ' . $updated . '.';
    }
}

?>
<style>
    .cl-wrap { margin: 12px 0 24px; }
    .cl-card { border: 1px solid #ddd; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; background: #fff; }
    .cl-meta { color: #555; margin-top: 4px; font-size: 13px; }
    .cl-actions { margin: 8px 0; }
    .cl-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .cl-table th, .cl-table td { border: 1px solid #e2e8f0; padding: 6px; vertical-align: top; }
    .cl-table th { background: #f8fafc; text-align: left; white-space: nowrap; }
    .cl-table td { line-height: 1.25; }
    .cl-warn { color: #b45309; }
    .cl-ok { color: #166534; }
    .cl-err { color: #b91c1c; }
    .cl-small { font-size: 12px; color: #6b7280; }
</style>

<div class="cl-wrap">
    <div class="cl-card">
        <b>Собранные данные</b>
        <div class="cl-meta">
            Заявок: <?=count($requestIds)?>,
            анкет: <?=count($candRows)?>,
            офферов: <?=count($offerRows)?>,
            карточек сотрудников: <?=count($empRows)?>.
        </div>

        <?php if ($apply): ?>
            <div class="cl-meta <?=($errors > 0 ? 'cl-err' : 'cl-ok')?>" style="margin-top:8px;">
                <?=cl_h($message)?>
                <?php if ($errors > 0): ?>
                    Ошибки: <?=$errors?> (заявки: <?=cl_h(implode(', ', $errorItems))?>).
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="cl-small" style="margin-top:8px;">
            Отметьте заявки и нажмите «Обновить выбранные заявки» — будут обновлены только отмеченные записи.
        </div>
    </div>

    <form method="post">
        <?=bitrix_sessid_post()?>
        <input type="hidden" name="apply" value="Y">

        <div class="cl-actions">
            <button type="button" class="ui-btn ui-btn-light" onclick="for (const c of document.querySelectorAll('.cl-check')) c.checked = true;">Выбрать все</button>
            <button type="button" class="ui-btn ui-btn-light-border" onclick="for (const c of document.querySelectorAll('.cl-check')) c.checked = false;">Снять все</button>
            <button type="submit" class="ui-btn ui-btn-success">Обновить выбранные заявки</button>
        </div>

        <table class="cl-table">
            <thead>
            <tr>
                <th>Обновить</th>
                <th>Заявка</th>
                <th>Анкеты</th>
                <th>Офферы</th>
                <th>Карточки</th>
                <th>Контроль</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <input
                            class="cl-check"
                            type="checkbox"
                            name="selected_req[]"
                            value="<?= (int)$row['req_id'] ?>"
                            <?=(isset($selectedReqMap[(int)$row['req_id']]) ? 'checked' : '')?>
                        >
                    </td>
                    <td>
                        #<?= (int)$row['req_id'] ?>
                        <?php if ($row['req_name'] !== ''): ?>
                            <br><span class="cl-small"><?=cl_h($row['req_name'])?></span>
                        <?php endif; ?>
                    </td>
                    <td><?=cl_render_compact_links($row['cand_ids'], $candRows)?></td>
                    <td><?=cl_render_compact_links($row['offer_ids'], $offerRows)?></td>
                    <td><?=cl_render_compact_links($row['emp_ids'], $empRows)?></td>
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
    </form>
</div>

<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
