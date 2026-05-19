<?php
define('BX_COMPOSITE_DO_NOT_CACHE', true);

use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Создание анкеты кандидата');

if (!Loader::includeModule('iblock') || !Loader::includeModule('bizproc')) {
    ShowError('Не удалось подключить модули iblock/bizproc.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}
Extension::load(['main.core', 'ui.entity-selector']);

const IBL_CANDIDATES = 207;
const IBL_REQUESTS = 201;
const BP_TEMPLATE_1 = 466;
const BP_TEMPLATE_2 = 328;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function normalizeDate(string $v): string { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($v), $m) ? ($m[3].'.'.$m[2].'.'.$m[1]) : trim($v); }
function getIblockElementsById(int $iblockId): array { $r=[]; $rs=CIBlockElement::GetList(['ID'=>'DESC'],['IBLOCK_ID'=>$iblockId,'ACTIVE'=>'Y'],false,false,['ID','NAME']); while($row=$rs->GetNext()){$r[]=['ID'=>(string)$row['ID'],'NAME'=>html_entity_decode((string)$row['NAME'], ENT_QUOTES | ENT_HTML5, 'UTF-8')];} return $r; }
function getElementById(int $iblockId, int $id, array $select): ?array { $row=CIBlockElement::GetList([],['IBLOCK_ID'=>$iblockId,'ID'=>$id,'ACTIVE'=>'Y'],false,['nTopCount'=>1],$select)->GetNext(); return $row?:null; }
function startListWorkflow(int $templateId, int $elementId, array &$errors): bool { $doc=['lists','BizprocDocument','iblock_'.IBL_CANDIDATES.'_'.$elementId]; return CBPDocument::StartWorkflow($templateId, $doc, [], $errors) !== false; }

$mode = 'manual';
$selectedRequestId = (int)($_GET['id_request'] ?? 0);
if ($selectedRequestId > 0) { $mode = 'request'; }
if (isset($_POST['MODE'])) {
    $mode = in_array($_POST['MODE'], ['request', 'manual', 'mass'], true) ? $_POST['MODE'] : 'manual';
    $selectedRequestId = (int)($_POST['SOURCE_REQUEST_ID'] ?? $selectedRequestId);
}
if ($mode === 'mass') { LocalRedirect('/pub/apps/adaptation/check_mass.php'); }

$fields = [
 ['id'=>1083,'code'=>'FAMILIYA','label'=>'Фамилия','type'=>'S','required'=>true],
 ['id'=>1084,'code'=>'IMYA','label'=>'Имя','type'=>'S','required'=>true],
 ['id'=>1085,'code'=>'OTCHESTVO','label'=>'Отчество','type'=>'S'],
 ['id'=>1088,'code'=>'KONTAKTNYY_TELEFON','label'=>'Контактный телефон','type'=>'S','required'=>true],
 ['id'=>1089,'code'=>'EMAIL','label'=>'E-mail','type'=>'S'],
 ['id'=>1323,'code'=>'REKRUTER','label'=>'Рекрутер','type'=>'USER','required'=>true],
 ['id'=>1617,'code'=>'DOLZHNOST','label'=>'Должность','type'=>'S','required'=>true],
 ['id'=>1988,'code'=>'RUKOVODITEL','label'=>'Руководитель','type'=>'USER'],
 ['id'=>1596,'code'=>'ID_ZAYAVKI_NA_PODBOR','label'=>'ID заявки на подбор','type'=>'N'],
 ['id'=>2854,'code'=>'ROUTE','label'=>'Маршрут','type'=>'S','required'=>true],
 ['id'=>1689,'code'=>'REZYUME','label'=>'Резюме','type'=>'FILE'],
 ['id'=>1726,'code'=>'SOGLASOVANIE_KANDIDATA','label'=>'Согласование кандидата руководителем','type'=>'FILE'],
 ['id'=>1098,'code'=>'DATA_VYKHODA','label'=>'Плановая дата выхода','type'=>'DATE'],
];

$formData=[]; foreach($fields as $f){$formData[$f['code']]='';}
$formData['ROUTE']='Без заявки на подбор';
$requestList = getIblockElementsById(IBL_REQUESTS);
$errors=[]; $saveMessage=null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'request' && $selectedRequestId > 0) {
    $rq = getElementById(IBL_REQUESTS, $selectedRequestId, ['ID','PROPERTY_DOLZHNOST','PROPERTY_1035','PROPERTY_NEPOSREDSTVENNYY_RUKOVODITEL']);
    if ($rq) {
        $formData['DOLZHNOST'] = (string)($rq['PROPERTY_DOLZHNOST_VALUE'] ?? '');
        $formData['REKRUTER'] = (string)($rq['PROPERTY_1035_VALUE'] ?? '');
        $formData['RUKOVODITEL'] = (string)($rq['PROPERTY_NEPOSREDSTVENNYY_RUKOVODITEL_VALUE'] ?? '');
        $formData['ID_ZAYAVKI_NA_PODBOR'] = (string)$selectedRequestId;
        $formData['ROUTE'] = 'Из заявки на подбор';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $propertyValues=[];
    foreach($fields as $f){
        $code=$f['code']; $value=$_POST[$code] ?? '';
        if($f['type']==='FILE'){$value=$_FILES[$code] ?? null;}
        elseif($f['type']==='DATE'){$value=normalizeDate((string)$value);}
        elseif($f['type']==='USER'){ $value=(string)(int)preg_replace('/\D+/','',trim((string)$value)); if($value==='0'){$value='';}}
        else{$value=trim((string)$value);}        
        if(!is_array($value)){$formData[$code]=(string)$value;}
        if($f['type']==='FILE'){ if(is_array($value) && !empty($value['name'])){$propertyValues[(int)$f['id']]=$value;}}
        elseif($value!==''){$propertyValues[(int)$f['id']]=$value;}
        if(!empty($f['required']) && trim((string)($formData[$code] ?? ''))===''){$errors[]='Не заполнено обязательное поле: '.$f['label'];}
    }

    $name=trim(($formData['FAMILIYA']??'').' '.($formData['IMYA']??'').' '.($formData['OTCHESTVO']??''));
    if($name===''){$errors[]='Заполните минимум Фамилию и Имя.';}

    if(!$errors){
        $el = new CIBlockElement();
        $newId = $el->Add(['IBLOCK_ID'=>IBL_CANDIDATES,'ACTIVE'=>'Y','NAME'=>$name,'PROPERTY_VALUES'=>$propertyValues]);
        if(!$newId){$errors[]=(string)$el->LAST_ERROR;} else {
            $bpErrors1=[]; $bpErrors2=[];
            startListWorkflow(BP_TEMPLATE_1,(int)$newId,$bpErrors1);
            startListWorkflow(BP_TEMPLATE_2,(int)$newId,$bpErrors2);
            if(!empty($bpErrors1) || !empty($bpErrors2)){$errors[]='Анкета создана, но БП завершились с ошибками.';}
            $saveMessage='Анкета кандидата создана. ID: '.(int)$newId;
        }
    }
}
?>
<form method="post" enctype="multipart/form-data" style="max-width:1000px;margin:20px auto">
<?=bitrix_sessid_post()?>
<div>
<label><input type="radio" name="MODE" value="request" <?=$mode==='request'?'checked':''?>> Из заявки на подбор</label>
<label><input type="radio" name="MODE" value="manual" <?=$mode==='manual'?'checked':''?>> Без заявки на подбор</label>
<label><input type="radio" name="MODE" value="mass" <?=$mode==='mass'?'checked':''?>> Массовый подбор</label>
</div>
<div id="request_block" style="margin:10px 0;display:<?=$mode==='request'?'block':'none'?>">
<select name="SOURCE_REQUEST_ID" id="SOURCE_REQUEST_ID">
<option value="">— выбрать заявку —</option>
<?php foreach($requestList as $it): ?><option value="<?=h($it['ID'])?>" <?=((string)$selectedRequestId===(string)$it['ID'])?'selected':''?>><?=h($it['ID'].' — '.$it['NAME'])?></option><?php endforeach; ?>
</select>
</div>
<?php if($saveMessage):?><div style="color:green"><?=h($saveMessage)?></div><?php endif;?>
<?php if($errors):?><div style="color:red"><?=h(implode("\n",$errors))?></div><?php endif;?>
<?php foreach($fields as $f): $code=$f['code']; ?>
<div style="margin:8px 0">
<label><?=h($f['label'])?><?=!empty($f['required'])?' *':''?></label><br>
<?php if($f['type']==='USER'): ?>
<input type="hidden" name="<?=h($code)?>" id="<?=h($code)?>" value="<?=h($formData[$code])?>"><div id="<?=h($code)?>_selector"></div>
<?php elseif($f['type']==='FILE'): ?>
<input type="file" name="<?=h($code)?>" id="<?=h($code)?>">
<?php elseif($f['type']==='DATE'): ?>
<input type="date" name="<?=h($code)?>" id="<?=h($code)?>" value="<?=h($formData[$code])?>">
<?php else: ?>
<input type="text" name="<?=h($code)?>" id="<?=h($code)?>" value="<?=h($formData[$code])?>" style="width:100%">
<?php endif; ?>
</div>
<?php endforeach; ?>
<button class="ui-btn ui-btn-success" type="submit">Создать анкету</button>
</form>
<script>
BX.ready(function(){
 ['REKRUTER','RUKOVODITEL'].forEach(function(code){
  const hidden=BX(code),container=BX(code+'_selector'); if(!hidden||!container||!BX.UI||!BX.UI.EntitySelector)return;
  const pre=[]; if(hidden.value){ const id=parseInt(String(hidden.value).replace('user_',''),10); if(id>0){pre.push(['user',id]);}}
  const ts=new BX.UI.EntitySelector.TagSelector({dialogOptions:{context:code+'_context',entities:[{id:'user'}],multiple:false,preselectedItems:pre},events:{onAfterTagAdd:function(){const tags=ts.getTags(); hidden.value=tags.length?String(tags[0].getId()):'';},onAfterTagRemove:function(){hidden.value='';}}});
  ts.renderTo(container);
 });
 document.querySelectorAll('input[name="MODE"]').forEach(function(el){el.addEventListener('change',function(){if(this.value==='mass'){window.location.href='/pub/apps/adaptation/check_mass.php'; return;} BX('request_block').style.display=this.value==='request'?'block':'none';});});
 const r=BX('SOURCE_REQUEST_ID'); if(r){r.addEventListener('change',function(){const id=parseInt(this.value,10)||0; if(id>0){window.location.href='?id_request='+id;}});}
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
