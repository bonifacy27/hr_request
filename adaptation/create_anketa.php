<?php
define('BX_COMPOSITE_DO_NOT_CACHE', true);
use Bitrix\Main\Loader;
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание анкеты нового сотрудника');
if (!Loader::includeModule('iblock')) { ShowError('Не удалось подключить модуль iblock.'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }

const IBL_EMP=196; const IBL_REQ=201; const IBL_CAND=207; const IBL_OFFER=218;
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function v($a,$k){$x=$a[$k]??''; if(is_array($x))$x=reset($x); return trim((string)$x);} 
function dmy($s){$s=trim((string)$s); if($s==='' )return ''; if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$s,$m)) return "$m[3].$m[2].$m[1]"; return $s;}
function plus90($d){$t=strtotime(dmy($d)); return $t?date('d.m.Y',strtotime('+90 days',$t)):'';}

$idOffer=(int)($_GET['id_offer']??0); $idRequest=(int)($_GET['id_request']??0);
$pref=[];

if($idOffer>0){
    $sel=['ID','PROPERTY_POLNOE_FIO_KANDIDATA','PROPERTY_DIREKTSIYA','PROPERTY_POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE','PROPERTY_DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE','PROPERTY_FIO_RUKOVODITELYA_IZ_SPISKA','PROPERTY_FIO_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE','PROPERTY_REKRUTER','PROPERTY_FORMAT_RABOTY_NEW','PROPERTY_ADRES_OFISA_LST','PROPERTY_NACHALO_RABOCHEGO_DNYA_NEW','PROPERTY_KONTAKTNYY_TELEFON_KANDIDATA_7_','PROPERTY_ID_ANKETY_KANDIDATA','PROPERTY_ID_ZAYAVKI_NA_PODBOR','PROPERTY_PLANIRUEMAYA_DATA_VYKHODA_NA_RABOTU'];
    $o=CIBlockElement::GetList([],['IBLOCK_ID'=>IBL_OFFER,'ID'=>$idOffer,'ACTIVE'=>'Y'],false,['nTopCount'=>1],$sel)->GetNext();
    if($o){
        $fio=preg_split('/\s+/',v($o,'PROPERTY_POLNOE_FIO_KANDIDATA_VALUE'))?:[];
        $pref[951]=$fio[0]??''; $pref[952]=$fio[1]??''; $pref[953]=trim(($fio[2]??'').' '.($fio[3]??''));
        $pref[956]=v($o,'PROPERTY_DIREKTSIYA_VALUE');
        $pref[957]=v($o,'PROPERTY_POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE_VALUE');
        $pref[958]=v($o,'PROPERTY_DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE_VALUE');
        $pref[959]=v($o,'PROPERTY_FIO_RUKOVODITELYA_IZ_SPISKA_VALUE')?:v($o,'PROPERTY_FIO_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE_VALUE');
        $pref[961]=v($o,'PROPERTY_REKRUTER_VALUE');
        $pref[1421]=v($o,'PROPERTY_FORMAT_RABOTY_NEW_VALUE');
        $pref[1420]=v($o,'PROPERTY_ADRES_OFISA_LST_VALUE');
        $pref[1623]=v($o,'PROPERTY_NACHALO_RABOCHEGO_DNYA_NEW_VALUE');
        $pref[1059]=v($o,'PROPERTY_KONTAKTNYY_TELEFON_KANDIDATA_7__VALUE') ?: 'Отсутствует';
        $pref[963]=dmy(v($o,'PROPERTY_PLANIRUEMAYA_DATA_VYKHODA_NA_RABOTU_VALUE'));
        $pref[964]=plus90($pref[963]);
        $pref[2085]=(int)$o['ID'];
        $idRequest = $idRequest ?: (int)v($o,'PROPERTY_ID_ZAYAVKI_NA_PODBOR_VALUE');
        $idCand = (int)v($o,'PROPERTY_ID_ANKETY_KANDIDATA_VALUE');
        $pref[1619]=$idRequest; $pref[1621]=$idCand;

        if($idCand>0){
            $c=CIBlockElement::GetList([],['IBLOCK_ID'=>IBL_CAND,'ID'=>$idCand,'ACTIVE'=>'Y'],false,['nTopCount'=>1],['ID','PROPERTY_E_MAIL','PROPERTY_STATUS_ANKETY','PROPERTY_KOMMENTARIY_SB_PO_OGRANICHENIYAM'])->GetNext();
            if($c){
                $pref[1108]=v($c,'PROPERTY_E_MAIL_VALUE');
                if((int)v($c,'PROPERTY_STATUS_ANKETY_VALUE')===2116){ $pref[989]=716; $pref[1076]=v($c,'PROPERTY_KOMMENTARIY_SB_PO_OGRANICHENIYAM_VALUE'); }
                else { $pref[989]=717; $pref[1076]='Нет обязательств'; }
            }
        }
    }
}
if($idRequest>0){
    $r=CIBlockElement::GetList([],['IBLOCK_ID'=>IBL_REQ,'ID'=>$idRequest,'ACTIVE'=>'Y'],false,['nTopCount'=>1],['ID','PROPERTY_OBYAZANNOSTI','PROPERTY_YURIDICHESKOE_LITSO','PROPERTY_OTDEL','PROPERTY_DOLZHNOST','PROPERTY_RUKOVODITEL','PROPERTY_OTVETSTVENNYY_MENEDZHER_OPIA','PROPERTY_FORMAT_RABOTY_','PROPERTY_ADRES_OFISA_LST','PROPERTY_NACHALO_RABOCHEGO_DNYA','PROPERTY_DIREKTSIYA'])->GetNext();
    if($r){
        $pref[1835]=$pref[1835]??(v($r,'PROPERTY_YURIDICHESKOE_LITSO_VALUE') ?: '3197820');
        $pref[2864]=v($r,'PROPERTY_OBYAZANNOSTI_VALUE');
        $pref[957]=$pref[957]?:v($r,'PROPERTY_OTDEL_VALUE');
        $pref[958]=$pref[958]?:v($r,'PROPERTY_DOLZHNOST_VALUE');
        $pref[959]=$pref[959]?:v($r,'PROPERTY_RUKOVODITEL_VALUE');
        $pref[961]=$pref[961]?:v($r,'PROPERTY_OTVETSTVENNYY_MENEDZHER_OPIA_VALUE');
        $pref[1421]=$pref[1421]?:v($r,'PROPERTY_FORMAT_RABOTY__VALUE');
        $pref[1420]=$pref[1420]?:v($r,'PROPERTY_ADRES_OFISA_LST_VALUE');
        $pref[1623]=$pref[1623]?:v($r,'PROPERTY_NACHALO_RABOCHEGO_DNYA_VALUE');
        $pref[956]=$pref[956]?:v($r,'PROPERTY_DIREKTSIYA_VALUE');
    }
}

$meta=[]; $rs=CIBlockProperty::GetList(['SORT'=>'ASC'],['IBLOCK_ID'=>IBL_EMP,'ACTIVE'=>'Y']); while($p=$rs->Fetch()) $meta[(int)$p['ID']]=$p;
$fields=[951,952,953,1835,955,956,957,958,959,961,963,964,1059,1421,1420,1623,989,1076,2864,2865,1108,1619,1621,2085];
$errors=[];
if($_SERVER['REQUEST_METHOD']!=='POST'){ foreach($pref as $pid=>$val) $_POST['P'][$pid]=$val; }
if($_SERVER['REQUEST_METHOD']==='POST' && check_bitrix_sessid()){
    $props=[]; foreach((array)($_POST['P']??[]) as $pid=>$val){ $pid=(int)$pid; if(!isset($meta[$pid])) continue; $m=$meta[$pid]; $val=v(['x'=>$val],'x'); if($m['PROPERTY_TYPE']==='S' && $m['USER_TYPE']==='Date') $val=dmy($val); $props[$pid]=$val; }
    $name=trim((($_POST['P'][951]??'').' '.($_POST['P'][952]??'').' '.($_POST['P'][953]??''))); if($name==='') $name='Сотрудник из оффера '.$idOffer;
    $el=new CIBlockElement(); $newId=$el->Add(['IBLOCK_ID'=>IBL_EMP,'NAME'=>$name,'ACTIVE'=>'Y','PROPERTY_VALUES'=>$props]);
    if($newId) LocalRedirect('/services/lists/196/view/'.$newId.'/?list_section_id='); else $errors[]=$el->LAST_ERROR;
}
?>
<?php foreach($errors as $e): ?><div class="ui-alert ui-alert-danger"><span class="ui-alert-message"><?=h($e)?></span></div><?php endforeach; ?>
<form method="post"><?=bitrix_sessid_post()?><div class="ui-form">
<?php foreach($fields as $pid): if(!isset($meta[$pid])) continue; $m=$meta[$pid]; $cur=$_POST['P'][$pid]??''; ?>
<div class="ui-form-row"><div class="ui-form-label"><label><?=h($m['NAME'])?></label></div><div class="ui-form-content">
<?php if($m['PROPERTY_TYPE']==='L'): ?><select name="P[<?=$pid?>]" class="ui-ctl-element"><option value=""></option><?php $er=CIBlockPropertyEnum::GetList(['SORT'=>'ASC'],['PROPERTY_ID'=>$pid]); while($e=$er->Fetch()): ?><option value="<?=h($e['ID'])?>" <?=((string)$cur===(string)$e['ID']?'selected':'')?>><?=h($e['VALUE'])?></option><?php endwhile; ?></select>
<?php else: ?><input class="ui-ctl-element" type="text" name="P[<?=$pid?>]" value="<?=h($cur)?>"><?php endif; ?>
</div></div>
<?php endforeach; ?>
<div class="ui-form-row"><div class="ui-form-content"><button class="ui-btn ui-btn-success" type="submit">Создать анкету</button></div></div>
</div></form>
<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
