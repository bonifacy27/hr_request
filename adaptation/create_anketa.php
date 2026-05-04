<?php
/**
 * Форма создания анкеты нового сотрудника.
 * URL: /forms/staff_recruiting/adaptation/create_anketa.php?id_offer=1234|id_request=1234
 */
define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\UI\Extension;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание анкеты нового сотрудника');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

Extension::load(['main.core', 'ui.buttons', 'ui.forms', 'ui.entity-selector']);

const IBL_EMPLOYEE = 196;
const IBL_REQUEST = 201;
const IBL_CANDIDATE = 207;
const IBL_OFFER = 218;
const IBL_STATUS = 374;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rawProp(array $props, string $code): string {
    $v = $props[$code]['VALUE'] ?? '';
    if (is_array($v)) { $v = reset($v); }
    return trim((string)$v);
}
function addMonths3(string $date): string {
    $date = trim($date);
    if ($date === '') return '';
    $ts = strtotime(str_replace('.', '-', $date));
    return $ts ? date('d.m.Y', strtotime('+3 months', $ts)) : '';
}
function ymd2dmy(string $v): string {
    $v = trim($v);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return $m[3].'.'.$m[2].'.'.$m[1];
    return $v;
}

function fetchProps(int $iblockId, int $id, array $codes): array {
    if ($id <= 0) return [];
    $props = [];
    CIBlockElement::GetPropertyValuesArray($props, $iblockId, ['ID' => $id], ['CODE' => $codes]);
    return $props[$id] ?? [];
}

$request = Context::getCurrent()->getRequest();
$idOffer = (int)$request->getQuery('id_offer');
$idReqFromUrl = (int)$request->getQuery('id_request');

$offerP = $idOffer ? fetchProps(IBL_OFFER, $idOffer, [
    'POLNOE_FIO_KANDIDATA','DIREKTSIYA','POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE','DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE',
    'FIO_RUKOVODITELYA_IZ_SPISKA','REKRUTER','FORMAT_RABOTY_NEW','ADRES_OFISA_LST','NACHALO_RABOCHEGO_DNYA_NEW',
    'KONTAKTNYY_TELEFON_KANDIDATA_7_','PLANIRUEMAYA_DATA_VYKHODA_NA_RABOTU','ID_ZAYAVKI_NA_PODBOR',
    'ID_ANKETY_KANDIDATA','DOLZHNOST_DLYA_NOVOSTI'
]) : [];

$idReq = $idReqFromUrl ?: (int)rawProp($offerP, 'ID_ZAYAVKI_NA_PODBOR');
$idCandidate = (int)rawProp($offerP, 'ID_ANKETY_KANDIDATA');

$reqP = $idReq ? fetchProps(IBL_REQUEST, $idReq, [
    'POL','DIREKTSIYA','PODRAZDELENIE','DOLZHNOST','NEPOSREDSTVENNYY_RUKOVODITEL','REKRUTER','FORMAT_RABOTY_PRIVYAZKA',
    'OFIS_PRIVYAZKA','NACHALO_RABOCHEGO_DNYA_PRIVYAZKA','OBYAZANNOSTI','KOMMENTARIY_DLYA_ARM','KOMMENTARIY_DLYA_AKHS','YURIDICHESKOE_LITSO'
]) : [];

$candidateP = $idCandidate ? fetchProps(IBL_CANDIDATE, $idCandidate, ['E_MAIL','STATUS_ANKETY','KOMMENTARIY_SB_PO_OGRANICHENIYAM']) : [];

$fio = preg_split('/\s+/', rawProp($offerP, 'POLNOE_FIO_KANDIDATA'));
$form = [
    '951' => $fio[0] ?? '', '952' => $fio[1] ?? '', '953' => $fio[2] ?? '',
    '1835' => rawProp($offerP, 'ORGANIZATSIYA') ?: rawProp($reqP, 'YURIDICHESKOE_LITSO') ?: '3197820',
    '955' => rawProp($reqP, 'POL'),
    '956' => rawProp($offerP, 'DIREKTSIYA') ?: rawProp($reqP, 'DIREKTSIYA'),
    '957' => rawProp($offerP, 'POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE') ?: rawProp($reqP, 'PODRAZDELENIE'),
    '958' => rawProp($offerP, 'DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE') ?: rawProp($reqP, 'DOLZHNOST'),
    '959' => rawProp($offerP, 'FIO_RUKOVODITELYA_IZ_SPISKA') ?: rawProp($reqP, 'NEPOSREDSTVENNYY_RUKOVODITEL'),
    '961' => rawProp($offerP, 'REKRUTER') ?: rawProp($reqP, 'REKRUTER'),
    '963' => rawProp($offerP, 'PLANIRUEMAYA_DATA_VYKHODA_NA_RABOTU'),
    '964' => addMonths3(rawProp($offerP, 'PLANIRUEMAYA_DATA_VYKHODA_NA_RABOTU')),
    '1059' => rawProp($offerP, 'KONTAKTNYY_TELEFON_KANDIDATA_7_'),
    '1421' => rawProp($offerP, 'FORMAT_RABOTY_NEW') ?: rawProp($reqP, 'FORMAT_RABOTY_PRIVYAZKA'),
    '1420' => rawProp($offerP, 'ADRES_OFISA_LST') ?: rawProp($reqP, 'OFIS_PRIVYAZKA'),
    '1623' => rawProp($offerP, 'NACHALO_RABOCHEGO_DNYA_NEW') ?: rawProp($reqP, 'NACHALO_RABOCHEGO_DNYA_PRIVYAZKA'),
    '2864' => rawProp($reqP, 'OBYAZANNOSTI'),
    '2865' => rawProp($offerP, 'DOLZHNOST_DLYA_NOVOSTI') ?: rawProp($offerP, 'DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE'),
    '992' => rawProp($reqP, 'KOMMENTARIY_DLYA_ARM'),
    '993' => rawProp($reqP, 'KOMMENTARIY_DLYA_AKHS'),
    '1108' => rawProp($candidateP, 'E_MAIL'),
    '1619' => $idReq,
    '1621' => $idCandidate,
    '2085' => $idOffer,
];

if (rawProp($candidateP, 'STATUS_ANKETY') === '2116') { $form['989']='716'; $form['1076']=rawProp($candidateP, 'KOMMENTARIY_SB_PO_OGRANICHENIYAM'); }

$createdId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    foreach ($_POST['p'] ?? [] as $pid => $val) { $form[(string)$pid] = is_array($val) ? reset($val) : trim((string)$val); }
    $props = [];
    foreach ($form as $pid => $v) {
        if ($v === '' || $v === null) continue;
        $props[(int)$pid] = in_array((int)$pid, [1835,1421,1420,1198,1623,954,955,976,978,990,1901,1109], true) ? [(int)$v] : $v;
    }
    $el = new CIBlockElement();
    $createdId = (int)$el->Add(['IBLOCK_ID'=>IBL_EMPLOYEE,'NAME'=>'Сотрудник: '.trim(($form['951'] ?? '').' '.($form['952'] ?? '')),'PROPERTY_VALUES'=>$props]);
    if (!$createdId) ShowError($el->LAST_ERROR);
}

$statusList = CIBlockElement::GetList(['SORT'=>'ASC'], ['IBLOCK_ID'=>IBL_STATUS,'ACTIVE'=>'Y'], false, false, ['ID','NAME']);
?><div class="ui-entity-editor-section">
<h2>Анкета нового сотрудника</h2>
<?php if ($createdId): ?><div class="ui-alert ui-alert-success">Анкета создана, ID: <?= (int)$createdId ?></div><?php endif; ?>
<form method="post"><?php echo bitrix_sessid_post(); ?>
<table class="ui-form"><tbody>
<?php
$fields = [951=>'Фамилия',952=>'Имя',953=>'Отчество',954=>'Статус сотрудника',1835=>'Организация',955=>'Пол',956=>'Дирекция',957=>'Отдел',958=>'Должность',959=>'Руководитель',961=>'Рекрутер',963=>'Дата приема',964=>'Дата окончания ИС',1059=>'Контактный телефон',1421=>'Формат работы',1420=>'Офис',1198=>'Местоположение',967=>'Номер кабинета',1623=>'Начало рабочего дня',989=>'Есть ли обязательство?',1076=>'Содержание обязательств',3108=>'Принят по рекомендации',970=>'ФИО в дательном падеже',971=>'ФИО в винительном падеже',2873=>'ФИО руководителя в родительном падеже',2864=>'Основные обязанности',2865=>'Должность (для новости)',976=>'Рабочее место',978=>'Доступ',988=>'Пропуск нужен?',990=>'Необходимая мебель',991=>'Комментарий к учетке',992=>'Комментарий к ARM',994=>'Комментарий к пропуску',993=>'Комментарий к АХС',1901=>'Доступы',1109=>'VDI ОС',1108=>'Личная почта'];
$listIds = [954,955,989,976,978,990,1901,1109,1835,1421,1420,1198,1623];
foreach ($fields as $pid=>$label): ?>
<tr><td style="padding:6px 12px;"><?=h($label)?></td><td style="padding:6px 12px;">
<?php if ($pid===954): ?><select name="p[954]"><option value=""></option><?php while($s=$statusList->GetNext()): ?><option value="<?=$s['ID']?>" <?=$form['954']==$s['ID']?'selected':''?>><?=h($s['NAME'])?></option><?php endwhile; ?></select>
<?php elseif (in_array($pid,$listIds,true)): ?><input class="ui-ctl-element" type="text" name="p[<?=$pid?>]" value="<?=h($form[(string)$pid] ?? '')?>" placeholder="ID элемента списка">
<?php elseif (in_array($pid,[963,964],true)): ?><input type="date" name="p[<?=$pid?>]" value="<?=h(preg_match('/^\d{2}\.\d{2}\.\d{4}$/',$form[(string)$pid] ?? '') ? date('Y-m-d',strtotime(str_replace('.','-',$form[(string)$pid]))) : ($form[(string)$pid] ?? ''))?>">
<?php else: ?><input class="ui-ctl-element" type="text" name="p[<?=$pid?>]" value="<?=h($form[(string)$pid] ?? '')?>"><?php endif; ?>
</td></tr>
<?php endforeach; ?>
</tbody></table>
<input type="hidden" name="p[1619]" value="<?= (int)$form['1619'] ?>"><input type="hidden" name="p[1621]" value="<?= (int)$form['1621'] ?>"><input type="hidden" name="p[2085]" value="<?= (int)$form['2085'] ?>">
<button class="ui-btn ui-btn-success" type="submit">Создать анкету</button>
</form></div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
