<?php
/** Run: php tests/kpi_editor_test.php */
require __DIR__ . '/../plans/edit_tasks_lib.php';
function expect($condition, $message) {if (!$condition) throw new RuntimeException($message);}
function rejects(callable $operation, $message) {
    try {$operation();} catch (RuntimeException $exception) {return;}
    throw new RuntimeException('Expected rejection: ' . $message);
}
$tomorrow = '2026-10-09';
$types = [10 => 'Продажи', 20 => 'Качество'];
$task = ['id'=>1,'name'=>'Продажи','type'=>10,'planned_result'=>'План продаж','weight'=>50,'due_date'=>$tomorrow,'status'=>3396791];
$input = ['id'=>1] + kpiEditValues($task);
expect(kpiEditChanges([1=>$task], [$input], $types, $tomorrow) === [], 'Unchanged task');
expect(kpiEditDate('31.02.2026') === '', 'Invalid calendar date');
expect(kpiEditDate('09.10.2026') === $tomorrow, 'Date conversion');
$changed = $input;
$changed['planned_result'] = 'Новый результат';
$changed['due_date'] = '2026-10-10';
$changes = kpiEditChanges([1=>$task], [$changed], $types, $tomorrow);
expect(count($changes) === 1 && $changes[0]['action'] === 'update', 'Tomorrow task editable');
$text = kpiEditDescribe($changes, $types);
expect(strpos($text, 'План продаж → Новый результат') !== false && strpos($text, '09.10.2026 → 10.10.2026') !== false, 'Before/after details');
$past = $changed; $past['due_date'] = '2026-10-08';
rejects(function () use ($task,$past,$types,$tomorrow) {kpiEditChanges([1=>$task],[$past],$types,$tomorrow);}, 'Past target date');
foreach ([['status'=>3507933], ['status'=>3347534], ['due_date'=>'2026-10-08'], ['due_date'=>'']] as $override) {
    $locked = array_replace($task,$override);
    expect(kpiEditChanges([1=>$locked],[['id'=>1]],$types,$tomorrow) === [], 'Locked task retained');
    rejects(function () use ($locked,$changed,$types,$tomorrow) {kpiEditChanges([1=>$locked],[$changed],$types,$tomorrow);}, 'Locked task edit');
    rejects(function () use ($locked,$types,$tomorrow,$input) {kpiEditChanges([1=>$locked],[array_replace($input,['id'=>0])],$types,$tomorrow);}, 'Locked task delete');
}
$new = array_replace($input, ['id'=>0]);
$changes = kpiEditChanges([1=>$task],[$new],$types,$tomorrow);
expect(array_column($changes,'action') === ['add','delete'], 'Add/delete tomorrow task');
rejects(function () use ($task,$types,$tomorrow) {kpiEditChanges([1=>$task],[],$types,$tomorrow);}, 'At least one KPI');
foreach ([[$input,$input], [array_replace($input,['id'=>999])], [array_replace($input,['type'=>999])], [array_replace($input,['weight'=>'1.5'])], [array_replace($input,['due_date'=>'31.02.2026'])]] as $invalid) {
    rejects(function () use ($task,$invalid,$types,$tomorrow) {kpiEditChanges([1=>$task],$invalid,$types,$tomorrow);}, 'Invalid input');
}
// После полуночи задача со сроком вчерашнего «завтра» блокируется.
rejects(function () use ($task,$changed,$types) {kpiEditChanges([1=>$task],[$changed],$types,'2026-10-10');}, 'Midnight revalidation');
// Половина ИС округляется вперед до следующего календарного дня.
$window = kpiEditWindow('01.07.2026', '01.10.2026');
expect($window === ['first'=>'2026-08-16','last'=>'2026-09-10'], 'Trial midpoint and end minus 21 days');
kpiEditCheckWindow($window, '', '2026-08-16');
kpiEditCheckWindow($window, '', '2026-09-10');
rejects(function() use($window){kpiEditCheckWindow($window,'','2026-08-15');}, 'Before midpoint');
rejects(function() use($window){kpiEditCheckWindow($window,'','2026-09-11');}, 'After final edit date');
rejects(function() use($window){kpiEditCheckWindow($window,'16.08.2026 10:00:00','2026-08-16');}, 'Only once');
$odd = kpiEditWindow('01.07.2026', '30.09.2026');
expect($odd['first'] === '2026-08-16', 'Odd trial duration rounds forward');
rejects(function(){kpiEditWindow('','01.10.2026');}, 'Missing trial dates');
rejects(function(){kpiEditCheckWindow(kpiEditWindow('01.07.2026','01.08.2026'),'','2026-07-17');}, 'No editing interval for short probation');
$latest = '2026-10-10';
kpiEditChanges([1=>$task],[$changed],$types,$tomorrow,$latest);
$late = $changed; $late['due_date'] = '2026-10-11';
rejects(function() use($task,$late,$types,$tomorrow,$latest){kpiEditChanges([1=>$task],[$late],$types,$tomorrow,$latest);}, 'Changed date exceeds end minus 21');
rejects(function() use($task,$late,$types,$tomorrow,$latest){kpiEditChanges([1=>$task],[array_replace($late,['id'=>0])],$types,$tomorrow,$latest);}, 'New date exceeds end minus 21');
$completedText=kpiEditDescribe([['action'=>'add','id'=>0,'after'=>kpiEditValues($task)]],$types,true,[123]);
expect(strpos($completedText,'Добавлена новая KPI-задача #123') !== false,'New ID in notification');
require_once __DIR__ . '/../plans/delegate_permissions.php';
expect(kpiEditActionAvailable(5,5,6,'01.07.2026','01.10.2026','','2026-08-16'), 'Manager action visible');
expect(kpiEditActionAvailable(6,5,6,'01.07.2026','01.10.2026','','2026-09-10'), 'Recruiter action on last day');
expect(kpiEditActionAvailable(3532,5,6,'01.07.2026','01.10.2026','','2026-08-20'), 'User 3532 action');
expect(!kpiEditActionAvailable(9,5,6,'01.07.2026','01.10.2026','','2026-08-20'), 'Unrelated user action hidden');
expect(!kpiEditActionAvailable(5,5,6,'01.07.2026','01.10.2026','','2026-08-15'), 'Action before midpoint hidden');
expect(!kpiEditActionAvailable(5,5,6,'01.07.2026','01.10.2026','','2026-09-11'), 'Action after last day hidden');
expect(!kpiEditActionAvailable(5,5,6,'01.07.2026','01.10.2026','16.08.2026 10:00:00','2026-08-20'), 'Already edited action hidden');
class KpiResult {private $rows; function __construct($rows){$this->rows=$rows;} function Fetch(){return array_shift($this->rows) ?: false;}}
class CIBlockElement {
    static $elements = []; static $links = []; static $nextId = 100; static $updates = [];
    public $LAST_ERROR = '';
    static function GetList($a,$filter){return new KpiResult(isset(self::$elements[$filter['ID']])?[self::$elements[$filter['ID']]]:[]);}
    static function GetProperty($iblock,$id,$order,$filter){
        if ($iblock === 359 && (int)($filter['ID'] ?? 0) === KPI_EDIT_ONCE_PROPERTY_ID) return new KpiResult([['VALUE'=>self::$elements[$id]['PROPERTY_' . KPI_EDIT_ONCE_PROPERTY_ID . '_VALUE'] ?? '']]);
        if ($iblock === 359) return new KpiResult(array_map(function($id){return ['VALUE'=>$id];},self::$links));
        $props=[];foreach(self::$elements[$id]['props'] as $code=>$value) $props[]=['CODE'=>$code,'VALUE'=>$value];return new KpiResult($props);
    }
    static function SetPropertyValuesEx($id,$iblock,$properties){
        if($iblock===359){
            foreach ($properties as $code=>$value) {
                if ($code === 'ZADACHI_KPI') self::$links=$value;
                elseif ($code === KPI_EDIT_ONCE_PROPERTY_ID) self::$elements[$id]['PROPERTY_' . $code . '_VALUE']=$value;
                else throw new RuntimeException('Unexpected plan property');
            }
        }
        else self::$elements[$id]['props']=array_replace(self::$elements[$id]['props'],$properties);
    }
    function Add($fields){$id=self::$nextId++;self::$elements[$id]=['ID'=>$id,'NAME'=>$fields['NAME'],'PREVIEW_TEXT'=>$fields['PREVIEW_TEXT'],'props'=>$fields['PROPERTY_VALUES']];return $id;}
    function Update($id,$fields){self::$updates[]=$fields;self::$elements[$id]=array_replace(self::$elements[$id],$fields);return true;}
    static function Delete($id){unset(self::$elements[$id]);return true;}
}
function seed($id,$status=3396791){
    CIBlockElement::$elements[$id]=['ID'=>$id,'NAME'=>'Продажи','PREVIEW_TEXT'=>'План продаж','props'=>[
        'TIP_ZADACHI_KPI'=>10,'PLANIRUEMY_REZULTAT'=>'План продаж','VES'=>50,'SROK'=>'09.10.2026','STATUS'=>$status,'OTVETSTVENNYY'=>7,'FACT'=>'Не менять']];
}
seed(1);seed(2);seed(3,3347534);CIBlockElement::$links=[1,2,3];
$existing=kpiEditLoad(77);
$changes=kpiEditChanges($existing,[$changed,$new,['id'=>3]],$types,$tomorrow);
$created=kpiEditSave(77,$changes,$existing,$types,5);
expect($created === [100], 'Created ID');
expect(!isset(CIBlockElement::$elements[2]), 'Removed element');
expect(CIBlockElement::$elements[1]['props']['OTVETSTVENNYY'] === 7 && CIBlockElement::$elements[1]['props']['FACT'] === 'Не менять', 'Preserved properties');
expect(CIBlockElement::$elements[3]['props']['STATUS'] === 3347534, 'Completed task preserved');
expect(CIBlockElement::$elements[100]['props']['STATUS'] === 3396791 && CIBlockElement::$elements[100]['props']['OTVETSTVENNYY'] === 5, 'New task initialization');
expect(!isset(CIBlockElement::$updates[0]['PROPERTY_VALUES']), 'Update does not replace properties');
class CBPDocument {
    static $calls = []; static $fail = false;
    static function StartWorkflow($template,$document,$parameters,&$errors){self::$calls[]=[$template,$document,$parameters]; if(self::$fail){$errors[]=['message'=>'Failed'];return false;}return 'workflow';}
}
$history="Автор: Иванов (ID 5).\n".$text;
kpiEditStartWorkflow(1372,77,['par_Changes'=>$history]);
expect(CBPDocument::$calls[0] === [1372,['lists','Bitrix\\Lists\\BizprocDocumentLists',77],['par_Changes'=>$history]], 'Notification document and multiline parameter');
CBPDocument::$fail=true;
rejects(function(){kpiEditStartWorkflow(1372,77,['par_Changes'=>'history']);},'Notification failure');
echo "PASS: KPI edit rules, date boundaries, changes, preservation, CRUD and notification\n";
