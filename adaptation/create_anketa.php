<?php
define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание анкеты нового сотрудника');

if (!Loader::includeModule('iblock')) {
    ShowError('Не удалось подключить модуль iblock.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

const IBL_NEW_EMPLOYEE_FORM = 196;
const IBL_REQUESTS = 201;
const IBL_CANDIDATES = 207;
const IBL_OFFERS = 218;

const OFFER_PROP_DIRECTION = 1996;
const OFFER_PROP_DEPARTMENT = 1163;
const OFFER_PROP_POSITION = 1161;
const OFFER_PROP_CHIEF = 1164;
const OFFER_PROP_WORK_FORMAT = 1327;
const OFFER_PROP_OFFICE = 1326;
const OFFER_PROP_WORK_START = 1329;
const OFFER_PROP_ORGANIZATION = 2753;
const OFFER_PROP_RECRUITER = 1190;
const OFFER_PROP_REQUEST_ID = 1601;
const OFFER_PROP_CANDIDATE_ID = 1603;
const OFFER_PROP_PLANNED_START_DATE = 1174;
const OFFER_PROP_CANDIDATE_FIO = 1157;
const OFFER_PROP_CANDIDATE_PHONE = 1158;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cleanValue($v): string { return trim((string)(is_array($v) ? reset($v) : $v)); }
function dmy($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return "$m[3].$m[2].$m[1]";
    return $v;
}
function plus3months(string $date): string {
    $dt = DateTime::createFromFormat('d.m.Y', dmy($date));
    if (!$dt) return '';
    $dt->modify('+3 months');
    return $dt->format('d.m.Y');
}
function propValue(array $props, string $code): string {
    $v = $props[$code]['VALUE'] ?? '';
    return cleanValue($v);
}
function loadPropsByCodes(int $iblockId, int $id, array $codes): array {
    $props=[]; CIBlockElement::GetPropertyValuesArray($props,$iblockId,['ID'=>$id],['CODE'=>$codes]); return $props[$id]??[];
}
function getTargetPropsMeta(): array {
    $meta=[];
    $rs=CIBlockProperty::GetList(['SORT'=>'ASC'],['IBLOCK_ID'=>IBL_NEW_EMPLOYEE_FORM,'ACTIVE'=>'Y']);
    while($p=$rs->Fetch()) $meta[$p['CODE']]=$p;
    return $meta;
}

$idOffer = (int)($_GET['id_offer'] ?? 0);
$idRequest = (int)($_GET['id_request'] ?? 0);
$offerData=[]; $requestProps=[]; $candidateProps=[];

if ($idOffer > 0) {
    $select = [
        'ID',
        'PROPERTY_'.OFFER_PROP_DIRECTION,'PROPERTY_'.OFFER_PROP_DEPARTMENT,'PROPERTY_'.OFFER_PROP_POSITION,
        'PROPERTY_'.OFFER_PROP_CHIEF,'PROPERTY_'.OFFER_PROP_WORK_FORMAT,'PROPERTY_'.OFFER_PROP_OFFICE,
        'PROPERTY_'.OFFER_PROP_WORK_START,'PROPERTY_'.OFFER_PROP_ORGANIZATION,'PROPERTY_'.OFFER_PROP_RECRUITER,
        'PROPERTY_'.OFFER_PROP_REQUEST_ID,'PROPERTY_'.OFFER_PROP_CANDIDATE_ID,'PROPERTY_'.OFFER_PROP_PLANNED_START_DATE,
        'PROPERTY_'.OFFER_PROP_CANDIDATE_FIO,'PROPERTY_'.OFFER_PROP_CANDIDATE_PHONE,
    ];
    $offer = CIBlockElement::GetList([],['IBLOCK_ID'=>IBL_OFFERS,'ID'=>$idOffer,'ACTIVE'=>'Y'],false,['nTopCount'=>1],$select)->GetNext();
    if ($offer) {
        $offerData = [
            'FIO' => cleanValue($offer['PROPERTY_'.OFFER_PROP_CANDIDATE_FIO.'_VALUE'] ?? ''),
            'PHONE' => cleanValue($offer['PROPERTY_'.OFFER_PROP_CANDIDATE_PHONE.'_VALUE'] ?? ''),
            'DIRECTION' => cleanValue($offer['PROPERTY_'.OFFER_PROP_DIRECTION.'_VALUE'] ?? ''),
            'DEPARTMENT' => cleanValue($offer['PROPERTY_'.OFFER_PROP_DEPARTMENT.'_VALUE'] ?? ''),
            'POSITION' => cleanValue($offer['PROPERTY_'.OFFER_PROP_POSITION.'_VALUE'] ?? ''),
            'CHIEF' => cleanValue($offer['PROPERTY_'.OFFER_PROP_CHIEF.'_VALUE'] ?? ''),
            'WORK_FORMAT' => cleanValue($offer['PROPERTY_'.OFFER_PROP_WORK_FORMAT.'_VALUE'] ?? ''),
            'OFFICE' => cleanValue($offer['PROPERTY_'.OFFER_PROP_OFFICE.'_VALUE'] ?? ''),
            'WORK_START' => cleanValue($offer['PROPERTY_'.OFFER_PROP_WORK_START.'_VALUE'] ?? ''),
            'ORG' => cleanValue($offer['PROPERTY_'.OFFER_PROP_ORGANIZATION.'_VALUE'] ?? ''),
            'RECRUITER' => cleanValue($offer['PROPERTY_'.OFFER_PROP_RECRUITER.'_VALUE'] ?? ''),
            'START_DATE' => dmy(cleanValue($offer['PROPERTY_'.OFFER_PROP_PLANNED_START_DATE.'_VALUE'] ?? '')),
        ];
        if ($idRequest <= 0) $idRequest = (int)($offer['PROPERTY_'.OFFER_PROP_REQUEST_ID.'_VALUE'] ?? 0);
        $candidateId = (int)($offer['PROPERTY_'.OFFER_PROP_CANDIDATE_ID.'_VALUE'] ?? 0);
        if ($candidateId > 0) $candidateProps = loadPropsByCodes(IBL_CANDIDATES,$candidateId,['EST_LI_OBYAZATELSTVO_LST','SODERZHANIE_OBYAZATELSTV','LICHNAYA_POCHTA_KANDIDATA']);
    }
}
if ($idRequest > 0) {
    $requestProps = loadPropsByCodes(IBL_REQUESTS,$idRequest,[
        'POL','DIREKTSIYA','OTDEL','DOLZHNOST','RUKOVODITEL','OTVETSTVENNYY_MENEDZHER_OPIA','FORMAT_RABOTY_','ADRES_OFISA_LST',
        'NACHALO_RABOCHEGO_DNYA','OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH','ORGANIZATSIYA'
    ]);
}

$fio = preg_split('/\s+/', trim($offerData['FIO'] ?? '')) ?: [];
$prefill = [
    'FAMILIYA'=>$fio[0]??'', 'IMYA'=>$fio[1]??'', 'OTCHESTVO'=>$fio[2]??'',
    'ORGANIZATSIYA'=>$offerData['ORG'] ?? propValue($requestProps,'ORGANIZATSIYA'),
    'POL'=>propValue($requestProps,'POL'), 'DIREKTSIYA'=>$offerData['DIRECTION'] ?? propValue($requestProps,'DIREKTSIYA'),
    'OTDEL'=>($offerData['DEPARTMENT']??'') ?: propValue($requestProps,'OTDEL'),
    'DOLZHNOST'=>($offerData['POSITION']??'') ?: propValue($requestProps,'DOLZHNOST'),
    'RUKOVODITEL'=>($offerData['CHIEF']??'') ?: propValue($requestProps,'RUKOVODITEL'),
    'OTVETSTVENNYY_MENEDZHER_OPIA'=>($offerData['RECRUITER']??'') ?: propValue($requestProps,'OTVETSTVENNYY_MENEDZHER_OPIA'),
    'DATA_PRIEMA'=>$offerData['START_DATE'] ?? '', 'DATA_OKONCHANIYA_IS'=>plus3months($offerData['START_DATE'] ?? ''),
    'KONTAKTNYY_NOMER_TELEFONA'=>$offerData['PHONE'] ?? '',
    'FORMAT_RABOTY_'=>($offerData['WORK_FORMAT']??'') ?: propValue($requestProps,'FORMAT_RABOTY_'),
    'ADRES_OFISA_LST'=>($offerData['OFFICE']??'') ?: propValue($requestProps,'ADRES_OFISA_LST'),
    'NACHALO_RABOCHEGO_DNYA'=>($offerData['WORK_START']??'') ?: propValue($requestProps,'NACHALO_RABOCHEGO_DNYA'),
    'EST_LI_OBYAZATELSTVO_LST'=>propValue($candidateProps,'EST_LI_OBYAZATELSTVO_LST'), 'SODERZHANIE_OBYAZATELSTV'=>propValue($candidateProps,'SODERZHANIE_OBYAZATELSTV'),
    'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI'=>propValue($requestProps,'OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI'),
    'DOLZHNOST_DLYA_NOVOSTI'=>($offerData['POSITION']??''),
    'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA'=>propValue($requestProps,'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA'),
    'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH'=>propValue($requestProps,'OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH'),
    'LICHNAYA_POCHTA_KANDIDATA'=>propValue($candidateProps,'LICHNAYA_POCHTA_KANDIDATA'),
];

$targetMeta = getTargetPropsMeta();
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && check_bitrix_sessid()) {
    $name = trim(($_POST['FAMILIYA']??'').' '.($_POST['IMYA']??'').' '.($_POST['OTCHESTVO']??''));
    if ($name==='') $errors[]='Заполните ФИО сотрудника.';
    if (!$errors) {
        $propValues=[];
        foreach($_POST as $code=>$value){
            if (!isset($targetMeta[$code])) continue;
            $type = $targetMeta[$code]['PROPERTY_TYPE'];
            $val = cleanValue($value);
            if ($type === 'S' && $targetMeta[$code]['USER_TYPE']==='Date') $val = dmy($val);
            $propValues[$code]=$val;
        }
        $el=new CIBlockElement();
        $newId=$el->Add(['IBLOCK_ID'=>IBL_NEW_EMPLOYEE_FORM,'ACTIVE'=>'Y','NAME'=>$name,'PROPERTY_VALUES'=>$propValues]);
        if($newId){ LocalRedirect('/services/lists/196/view/'.$newId.'/?list_section_id='); }
        $errors[]='Не удалось создать анкету: '.$el->LAST_ERROR;
    }
}
if ($_SERVER['REQUEST_METHOD']!=='POST') $_POST=$prefill;

$fields=['FAMILIYA','IMYA','OTCHESTVO','STATUS_SOTRUDNIKA','ORGANIZATSIYA','POL','DIREKTSIYA','OTDEL','DOLZHNOST','RUKOVODITEL','OTVETSTVENNYY_MENEDZHER_OPIA','DATA_PRIEMA','DATA_OKONCHANIYA_IS','KONTAKTNYY_NOMER_TELEFONA','FORMAT_RABOTY_','ADRES_OFISA_LST','NACHALO_RABOCHEGO_DNYA','EST_LI_OBYAZATELSTVO_LST','SODERZHANIE_OBYAZATELSTV','OSNOVNYE_OBYAZANNOSTI_DLYA_NOVOSTI','DOLZHNOST_DLYA_NOVOSTI','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_ARM_SOTRUDNIKA','OPISANIE_K_ZAYAVKE_NA_SOZDANIE_RABOCHEGO_MESTA_AKH','LICHNAYA_POCHTA_KANDIDATA'];
?>
<?php foreach($errors as $e): ?><div class="ui-alert ui-alert-danger"><span class="ui-alert-message"><?=h($e)?></span></div><?php endforeach; ?>
<form method="post"><?=bitrix_sessid_post()?><div class="ui-form">
<?php foreach($fields as $code): if(!isset($targetMeta[$code])) continue; $m=$targetMeta[$code]; ?>
<div class="ui-form-row"><div class="ui-form-label"><label><?=h($m['NAME'])?></label></div><div class="ui-form-content">
<?php if($m['PROPERTY_TYPE']==='L'): $en=[]; $rs=CIBlockPropertyEnum::GetList(['SORT'=>'ASC'],['PROPERTY_ID'=>$m['ID']]); while($e=$rs->Fetch()) $en[]=$e; ?>
<select name="<?=h($code)?>" class="ui-ctl-element"><option value=""></option><?php foreach($en as $e): ?><option value="<?=h($e['ID'])?>" <?=((string)($_POST[$code]??'')===(string)$e['ID']?'selected':'')?>><?=h($e['VALUE'])?></option><?php endforeach; ?></select>
<?php else: ?><input class="ui-ctl-element" type="text" name="<?=h($code)?>" value="<?=h($_POST[$code]??'')?>"><?php endif; ?>
</div></div>
<?php endforeach; ?>
<div class="ui-form-row"><div class="ui-form-content"><button class="ui-btn ui-btn-success" type="submit">Создать анкету</button></div></div>
</div></form>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
