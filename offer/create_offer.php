<?php
/**
 * Форма создания заявки на оффер.
 * URL: /forms/staff_recruiting/offer/create_offer.php?id_ankety=12345
 */

define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\Context;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание заявки на оффер');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

const IBL_CANDIDATES = 207;
const IBL_REQUESTS = 201;
const IBL_OFFERS = 218;
const PROP_REQ_OFFERS_MULTI = 3128;

const OFFER_PROP_CANDIDATE_FIO = 1157;
const OFFER_PROP_IS_CHIEF_POSITION = 1618;
const OFFER_PROP_POSITION = 1161;
const OFFER_PROP_DIRECTION = 1996;
const OFFER_PROP_DEPARTMENT = 1163;
const OFFER_PROP_CHIEF = 1164;
const OFFER_PROP_CHIEF_POSITION = 1169;
const OFFER_PROP_SALARY = 1165;
const OFFER_PROP_ISN = 1184;
const OFFER_PROP_BONUS_TYPE = 1998;
const OFFER_PROP_BONUS_PERCENT = 1186;
const OFFER_PROP_BENEFITS = 1177;
const OFFER_PROP_WORK_FORMAT = 1327;
const OFFER_PROP_OFFICE = 1326;
const OFFER_PROP_WORK_SCHEDULE = 1328;
const OFFER_PROP_WORK_START = 1329;
const OFFER_PROP_EQUIPMENT = 2070;
const OFFER_PROP_CONTRACT_TYPE = 2002;
const OFFER_PROP_ORGANIZATION = 2753;
const OFFER_PROP_RECRUITER = 1190;
const OFFER_PROP_REQUEST_ID = 1601;
const OFFER_PROP_CANDIDATE_ID = 1603;
const OFFER_PROP_FW_CANDIDATE_ID = 1602;
const OFFER_PROP_COMMENT = 2857;

const DEFAULT_EQUIPMENT = '3263612';
const DEFAULT_CONTRACT = '3263600';
const DEFAULT_ORGANIZATION = '3197820';

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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

function getIblockOptions(int $iblockId): array
{
    $res = [];
    $rs = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME']
    );
    while ($row = $rs->GetNext()) {
        $res[] = [
            'ID' => (string)$row['ID'],
            'NAME' => (string)$row['NAME'],
        ];
    }
    return $res;
}

function getCandidateById(int $candidateId): ?array
{
    $select = [
        'ID',
        'PROPERTY_1083',
        'PROPERTY_1084',
        'PROPERTY_1085',
        'PROPERTY_1596',
        'PROPERTY_1594',
        'PROPERTY_1323',
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
        'REQUEST_ID' => (int)($row['PROPERTY_1596_VALUE'] ?? 0),
        'FW_CANDIDATE_ID' => trim((string)($row['PROPERTY_1594_VALUE'] ?? '')),
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
    ], false, ['nTopCount' => 1], ['ID'])->GetNext();

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
        'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA',
        'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA',
        'YURIDICHESKOE_LITSO',
    ];

    $props = [];
    CIBlockElement::GetPropertyValuesArray($props, IBL_REQUESTS, ['ID' => (int)$row['ID']], ['CODE' => $propCodes]);
    $p = $props[(int)$row['ID']] ?? [];

    $raw = static function (array $allProps, string $code): string {
        $v = $allProps[$code]['VALUE'] ?? '';
        if (is_array($v)) {
            $v = reset($v);
        }
        return trim((string)$v);
    };

    return [
        'CHIEF_POSITION_FLAG' => $raw($p, 'RUKOVODYASHCHAYA_DOLZHNOST'),
        'POSITION' => $raw($p, 'DOLZHNOST'),
        'DIRECTION' => $raw($p, 'DIREKTSIYA'),
        'DEPARTMENT' => $raw($p, 'PODRAZDELENIE'),
        'CHIEF' => $raw($p, 'NEPOSREDSTVENNYY_RUKOVODITEL'),
        'CHIEF_POSITION' => $raw($p, 'DOLZHNOST_RUKOVODITELYA'),
        'SALARY' => $raw($p, 'OKLAD'),
        'ISN' => $raw($p, 'ISN_RUB_GROSS'),
        'BONUS_TYPE' => $raw($p, 'PREDPOLAGAEMYY_TIP_PREMIROVANIYA_PRIVYAZKA'),
        'BONUS_PERCENT' => $raw($p, 'PROTSENT_PREMII_'),
        'WORK_FORMAT' => $raw($p, 'FORMAT_RABOTY_PRIVYAZKA'),
        'OFFICE' => $raw($p, 'OFIS_PRIVYAZKA'),
        'WORK_SCHEDULE' => $raw($p, 'GRAFIK_RABOTY_PRIVYAZKA'),
        'WORK_START' => $raw($p, 'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA'),
        'CONTRACT_TYPE' => $raw($p, 'TIP_DOGOVORA_S_SOTRUDNIKOM_PRIVYAZKA'),
        'EQUIPMENT' => $raw($p, 'OBORUDOVANIE_DLYA_RABOTY_PRIVYAZKA'),
        'ORGANIZATION' => $raw($p, 'YURIDICHESKOE_LITSO'),
    ];
}

function normalizeChiefPosition(string $flag): string
{
    if ($flag === 'Y') {
        return '1159';
    }
    return '1160';
}

function appendOfferToRequest(int $requestId, int $offerId): void
{
    if ($requestId <= 0 || $offerId <= 0) {
        return;
    }

    $values = [];
    $rs = CIBlockElement::GetProperty(IBL_REQUESTS, $requestId, ['sort' => 'asc'], ['ID' => PROP_REQ_OFFERS_MULTI]);
    while ($p = $rs->Fetch()) {
        $v = (int)($p['VALUE'] ?? 0);
        if ($v > 0) {
            $values[] = $v;
        }
    }

    $values[] = $offerId;
    $values = array_values(array_unique(array_map('intval', $values)));

    CIBlockElement::SetPropertyValuesEx($requestId, IBL_REQUESTS, [
        PROP_REQ_OFFERS_MULTI => $values,
    ]);
}

$candidateId = (int)Context::getCurrent()->getRequest()->getQuery('id_ankety');
$candidate = null;
$requestItem = null;
$errors = [];
$saveMessage = null;

$formData = [
    'candidate_fio' => '',
    'is_chief_position' => '1160',
    'position' => '',
    'direction' => '',
    'department' => '',
    'chief' => '',
    'chief_position' => '',
    'salary' => '',
    'isn' => '',
    'bonus_type' => '',
    'bonus_percent' => '',
    'benefits' => 'ДМС по истечению испытательного срока',
    'work_format' => '',
    'office' => '',
    'work_schedule' => '',
    'work_start' => '',
    'equipment' => DEFAULT_EQUIPMENT,
    'contract_type' => DEFAULT_CONTRACT,
    'organization' => DEFAULT_ORGANIZATION,
    'recruiter' => '',
    'request_id' => '',
    'candidate_id' => '',
    'fw_candidate_id' => '',
    'comment' => '',
];

if ($candidateId > 0) {
    $candidate = getCandidateById($candidateId);
    if (!$candidate) {
        $errors[] = 'Анкета кандидата не найдена.';
    } else {
        $formData['candidate_fio'] = $candidate['FIO'];
        $formData['recruiter'] = (string)$candidate['RECRUITER_ID'];
        $formData['request_id'] = (string)$candidate['REQUEST_ID'];
        $formData['candidate_id'] = (string)$candidate['ID'];
        $formData['fw_candidate_id'] = (string)$candidate['FW_CANDIDATE_ID'];
        $formData['comment'] = 'Из анкеты кандидата ' . (int)$candidate['ID'];

        if ((int)$candidate['REQUEST_ID'] > 0) {
            $requestItem = getRequestById((int)$candidate['REQUEST_ID']);
            if ($requestItem) {
                $formData['is_chief_position'] = normalizeChiefPosition((string)$requestItem['CHIEF_POSITION_FLAG']);
                $formData['position'] = (string)$requestItem['POSITION'];
                $formData['direction'] = (string)$requestItem['DIRECTION'];
                $formData['department'] = (string)$requestItem['DEPARTMENT'];
                $formData['chief'] = (string)$requestItem['CHIEF'];
                $formData['chief_position'] = (string)$requestItem['CHIEF_POSITION'];
                $formData['salary'] = (string)$requestItem['SALARY'];
                $formData['isn'] = (string)$requestItem['ISN'];
                $formData['bonus_type'] = (string)$requestItem['BONUS_TYPE'];
                $formData['bonus_percent'] = (string)$requestItem['BONUS_PERCENT'];
                $formData['work_format'] = (string)$requestItem['WORK_FORMAT'];
                $formData['office'] = (string)$requestItem['OFFICE'];
                $formData['work_schedule'] = (string)$requestItem['WORK_SCHEDULE'];
                $formData['work_start'] = (string)$requestItem['WORK_START'];
                $formData['equipment'] = (string)($requestItem['EQUIPMENT'] ?: DEFAULT_EQUIPMENT);
                $formData['contract_type'] = (string)($requestItem['CONTRACT_TYPE'] ?: DEFAULT_CONTRACT);
                $formData['organization'] = (string)($requestItem['ORGANIZATION'] ?: DEFAULT_ORGANIZATION);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && (string)($_POST['action'] ?? '') === 'save') {
    foreach ($formData as $key => $defaultValue) {
        $formData[$key] = trim((string)($_POST[$key] ?? ''));
    }

    if ($formData['candidate_fio'] === '') {
        $errors[] = 'Заполните поле «ФИО кандидата».';
    }
    if ($formData['position'] === '') {
        $errors[] = 'Заполните поле «Должность».';
    }

    if (empty($errors)) {
        $props = [
            OFFER_PROP_CANDIDATE_FIO => $formData['candidate_fio'],
            OFFER_PROP_IS_CHIEF_POSITION => $formData['is_chief_position'],
            OFFER_PROP_POSITION => $formData['position'],
            OFFER_PROP_DIRECTION => $formData['direction'],
            OFFER_PROP_DEPARTMENT => $formData['department'],
            OFFER_PROP_CHIEF => $formData['chief'],
            OFFER_PROP_CHIEF_POSITION => $formData['chief_position'],
            OFFER_PROP_SALARY => $formData['salary'],
            OFFER_PROP_ISN => $formData['isn'],
            OFFER_PROP_BONUS_TYPE => $formData['bonus_type'],
            OFFER_PROP_BONUS_PERCENT => $formData['bonus_percent'],
            OFFER_PROP_BENEFITS => $formData['benefits'],
            OFFER_PROP_WORK_FORMAT => $formData['work_format'],
            OFFER_PROP_OFFICE => $formData['office'],
            OFFER_PROP_WORK_SCHEDULE => $formData['work_schedule'],
            OFFER_PROP_WORK_START => $formData['work_start'],
            OFFER_PROP_EQUIPMENT => ($formData['equipment'] !== '' ? $formData['equipment'] : DEFAULT_EQUIPMENT),
            OFFER_PROP_CONTRACT_TYPE => ($formData['contract_type'] !== '' ? $formData['contract_type'] : DEFAULT_CONTRACT),
            OFFER_PROP_ORGANIZATION => ($formData['organization'] !== '' ? $formData['organization'] : DEFAULT_ORGANIZATION),
            OFFER_PROP_RECRUITER => (int)$formData['recruiter'],
            OFFER_PROP_REQUEST_ID => (int)$formData['request_id'],
            OFFER_PROP_CANDIDATE_ID => (int)$formData['candidate_id'],
            OFFER_PROP_FW_CANDIDATE_ID => $formData['fw_candidate_id'],
            OFFER_PROP_COMMENT => $formData['comment'],
        ];

        $el = new CIBlockElement();
        $offerId = $el->Add([
            'IBLOCK_ID' => IBL_OFFERS,
            'NAME' => 'Оффер: ' . $formData['candidate_fio'],
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => $props,
        ]);

        if ($offerId) {
            appendOfferToRequest((int)$formData['request_id'], (int)$offerId);
            LocalRedirect('/services/lists/218/view/0/?list_section_id=');
        } else {
            $errors[] = 'Не удалось создать запись в списке офферов: ' . ($el->LAST_ERROR ?: 'неизвестная ошибка');
        }
    }

    if (!empty($errors)) {
        $saveMessage = [
            'type' => 'danger',
            'text' => implode(' ', $errors),
        ];
    }
}

$formatList = getIblockOptions(234);
$officeList = getIblockOptions(233);
$scheduleList = getIblockOptions(236);
$startTimeList = getIblockOptions(237);
$equipmentList = getIblockOptions(326);
$contractList = getIblockOptions(325);
$organizationList = getIblockOptions(308);

?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<div class="container my-4">
    <div class="d-flex align-items-center mb-3">
        <h1 class="h3 mb-0">Заявка на оффер</h1>
    </div>

    <?php if ($candidateId > 0): ?>
        <div class="alert alert-info" role="alert">
            Предзаполнение выполнено по анкете кандидата ID: <strong><?=h($candidateId)?></strong>.
        </div>
    <?php endif; ?>

    <?php if ($saveMessage): ?>
        <div class="alert alert-<?=h($saveMessage['type'])?>" role="alert"><?=h($saveMessage['text'])?></div>
    <?php endif; ?>

    <form method="post">
        <?=bitrix_sessid_post()?>

        <div class="card mb-3">
            <div class="card-header">Общие сведения</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>ФИО кандидата <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="candidate_fio" value="<?=h($formData['candidate_fio'])?>" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Кандидат на руководящую должность</label>
                        <select name="is_chief_position" class="form-control">
                            <option value="1160" <?=$formData['is_chief_position'] === '1160' ? 'selected' : ''?>>Нет</option>
                            <option value="1159" <?=$formData['is_chief_position'] === '1159' ? 'selected' : ''?>>Да</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Должность <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="position" value="<?=h($formData['position'])?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Дирекция</label>
                        <input type="text" class="form-control" name="direction" value="<?=h($formData['direction'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Подразделение</label>
                        <input type="text" class="form-control" name="department" value="<?=h($formData['department'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>ID заявки на подбор</label>
                        <input type="number" class="form-control" name="request_id" value="<?=h($formData['request_id'])?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Условия оффера</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Непосредственный руководитель (ID пользователя)</label>
                        <input type="number" class="form-control" name="chief" value="<?=h($formData['chief'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Должность руководителя</label>
                        <input type="text" class="form-control" name="chief_position" value="<?=h($formData['chief_position'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Оклад</label>
                        <input type="text" class="form-control" name="salary" value="<?=h($formData['salary'])?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>ИСН (gross)</label>
                        <input type="text" class="form-control" name="isn" value="<?=h($formData['isn'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Тип премирования</label>
                        <input type="text" class="form-control" name="bonus_type" value="<?=h($formData['bonus_type'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Процент премии</label>
                        <input type="text" class="form-control" name="bonus_percent" value="<?=h($formData['bonus_percent'])?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Льготы</label>
                    <textarea class="form-control" name="benefits" rows="2"><?=h($formData['benefits'])?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Формат работы</label>
                        <select class="form-control" name="work_format">
                            <option value="">— Выберите —</option>
                            <?php foreach ($formatList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['work_format'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Офис</label>
                        <select class="form-control" name="office">
                            <option value="">— Выберите —</option>
                            <?php foreach ($officeList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['office'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>График работы</label>
                        <select class="form-control" name="work_schedule">
                            <option value="">— Выберите —</option>
                            <?php foreach ($scheduleList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['work_schedule'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Начало рабочего дня</label>
                        <select class="form-control" name="work_start">
                            <option value="">— Выберите —</option>
                            <?php foreach ($startTimeList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['work_start'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Оборудование</label>
                        <select class="form-control" name="equipment">
                            <?php foreach ($equipmentList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['equipment'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Тип трудового договора</label>
                        <select class="form-control" name="contract_type">
                            <?php foreach ($contractList as $o): ?>
                                <option value="<?=h($o['ID'])?>" <?=$formData['contract_type'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Юридическое лицо</label>
                    <select class="form-control" name="organization">
                        <?php foreach ($organizationList as $o): ?>
                            <option value="<?=h($o['ID'])?>" <?=$formData['organization'] === $o['ID'] ? 'selected' : ''?>><?=h($o['NAME'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Связи</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>ID анкеты кандидата</label>
                        <input type="number" class="form-control" name="candidate_id" value="<?=h($formData['candidate_id'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>ID кандидата Friendwork</label>
                        <input type="text" class="form-control" name="fw_candidate_id" value="<?=h($formData['fw_candidate_id'])?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Рекрутер (ID пользователя)</label>
                        <input type="number" class="form-control" name="recruiter" value="<?=h($formData['recruiter'])?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Комментарий</label>
                    <textarea class="form-control" name="comment" rows="2"><?=h($formData['comment'])?></textarea>
                </div>
            </div>
        </div>

        <div class="text-right">
            <button type="submit" class="btn btn-primary" name="action" value="save">Создать оффер</button>
            <a href="/services/lists/218/view/0/?list_section_id=" class="btn btn-link">К списку офферов</a>
        </div>
    </form>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
