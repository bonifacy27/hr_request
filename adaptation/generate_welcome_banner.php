<?php
/**
 * banner_photo_bp.php — v5.8
 * Дата: 2026-03-18
 *
 * Что сделано в v5.8:
 *  - Добавлена логика для поля PRINYAT_PO_REKOMENDATSII (PROPERTY_3108)
 *  - Если PRINYAT_PO_REKOMENDATSII пусто или = "Принят без рекомендации" — логика остается прежней
 *  - Если PRINYAT_PO_REKOMENDATSII заполнено другим значением:
 *      * по умолчанию включается режим "С рекомендацией"
 *      * в поле "Рекомендация" подставляется значение PRINYAT_PO_REKOMENDATSII
 *
 * Что было ранее:
 *  - Возврат в задание бизнес-процесса после сохранения
 *  - Информационный блок: ФИО, дата приёма, подсказка по фото
 *  - Подстановка фото по полу (tri_men.jpg / tri_women.jpg)
 *  - Кнопка "Запланировать пост", редактируемое время публикации
 *  - Настройка размера шрифта для «Строка 3» и «Задачи»
 *  - Загрузка фото в карточку (FOTO_SOTRUDNIKA) с проверками: JPG/PNG, размер ≤5 МБ, PNG→JPG, resize 1024px
 */
use Bitrix\Main\Loader;

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

global $USER;
if(!$USER->IsAuthorized()) die('Ошибка: пользователь не авторизован.');

// ==== Константы/пути ====
$IBLOCK_ANKET   = 196;      // ИБ анкеты
$IBLOCK_BANNER  = 367;      // ИБ баннеров
$TEMPLATE_DIR   = $_SERVER['DOCUMENT_ROOT'].'/upload/application/hr-adaptation/templates/';
$BANNERS_DIR    = $_SERVER['DOCUMENT_ROOT'].'/upload/application/hr-adaptation/banners/';
$BANNERS_URL_PR = '/upload/application/hr-adaptation/banners/';
$PREVIEW_DIR    = $_SERVER['DOCUMENT_ROOT'].'/upload/application/hr-adaptation/previews/';
$PREVIEW_URL_PR = '/upload/application/hr-adaptation/previews/';

// ==== Хелперы ====
function ensureDir(string $dir): void {
    if(!is_dir($dir)) {
        \Bitrix\Main\IO\Directory::createDirectory($dir);
    }
}

function generateRandomTime(): string {
    $hours = 10 + mt_rand(0, 3); // 10, 11, 12, 13
    $minutes = mt_rand(0, 5) * 10; // 00, 10, 20, ..., 50
    return sprintf('%02d:%02d', $hours, $minutes);
}

function cleanupPreviews(int $elementId, string $dir): void {
    foreach(glob(rtrim($dir,'/').'/'.$elementId.'_*_preview.jpg') ?: [] as $f){
        @unlink($f);
    }
}

function fileSrcFromPropVal($val){
    if(empty($val)) return null;
    if(is_array($val)){
        if(!empty($val['SRC'])) return $val['SRC'];
        if(!empty($val['ID'])){
            $f = CFile::GetFileArray($val['ID']);
            return $f ? $f['SRC'] : null;
        }
        if(!empty($val[0])){
            $f = CFile::GetFileArray($val[0]);
            return $f ? $f['SRC'] : null;
        }
    }
    if(is_numeric($val)){
        $f = CFile::GetFileArray($val);
        return $f ? $f['SRC'] : null;
    }
    if(is_string($val)) return $val;
    return null;
}

function copyPhotoToTemp(string $srcAbs): ?string {
    $tempDir = $_SERVER['DOCUMENT_ROOT'].'/upload/temp/';
    ensureDir($tempDir);
    $ext = strtolower(pathinfo($srcAbs, PATHINFO_EXTENSION) ?: 'jpg');
    $dst = $tempDir.'photo_'.uniqid('',true).'.'.$ext;
    return @copy($srcAbs,$dst) ? $dst : null;
}

/**
 * Принять загруженный файл фото, провалидировать, при необходимости конвертировать PNG→JPG,
 * уменьшить ширину до 1024px и вернуть путь к подготовленному JPG (в /upload/temp/).
 * Возвращает [ok(bool), path(string|null), error(string|null)].
 */
function prepareEmployeePhotoUpload(array $file, int $maxBytes = 5242880, int $maxWidth = 1024): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_OK);
    if ($err === UPLOAD_ERR_NO_FILE) return [false, null, 'Файл не выбран.'];
    if ($err !== UPLOAD_ERR_OK) return [false, null, 'Ошибка загрузки файла (код '.$err.').'];

    $tmp = (string)($file['tmp_name'] ?? '');
    $name = (string)($file['name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return [false, null, 'Файл не является загруженным файлом.'];

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) return [false, null, 'Пустой файл.'];
    if ($size > $maxBytes) return [false, null, 'Файл слишком большой. Максимум 5 МБ.'];

    $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '', 'UTF-8');
    if (!in_array($ext, ['jpg','jpeg','png'], true)) return [false, null, 'Разрешены только файлы JPG/JPEG/PNG.'];

    if (function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($tmp);
        if ($mime !== '' && !in_array($mime, ['image/jpeg','image/png'], true)) {
            return [false, null, 'Недопустимый тип файла ('.$mime.'). Разрешены JPG/PNG.'];
        }
    }

    $tempDir = $_SERVER['DOCUMENT_ROOT'].'/upload/temp/';
    ensureDir($tempDir);
    $outJpg = $tempDir.'employee_photo_'.$GLOBALS['elementId'].'_'.time().'.jpg';

    if (!extension_loaded('gd')) {
        $fallback = $tempDir.'employee_photo_'.$GLOBALS['elementId'].'_'.time().'.'.$ext;
        if (@move_uploaded_file($tmp, $fallback)) return [true, $fallback, null];
        return [false, null, 'Не удалось сохранить загруженный файл.'];
    }

    $img = null;
    if ($ext === 'png') {
        $img = @imagecreatefrompng($tmp);
        if (!$img) return [false, null, 'Не удалось прочитать PNG.'];
    } else {
        $img = @imagecreatefromjpeg($tmp);
        if (!$img) return [false, null, 'Не удалось прочитать JPG.'];
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 0 || $h <= 0) {
        imagedestroy($img);
        return [false, null, 'Некорректное изображение.'];
    }

    if ($w > $maxWidth) {
        $newW = $maxWidth;
        $newH = (int)round($h * ($newW / $w));
        $dst = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($img);
        $img = $dst;
    } else {
        if ($ext === 'png') {
            $dst = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $w, $h, $white);
            imagecopy($dst, $img, 0, 0, 0, 0, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }
    }

    $ok = @imagejpeg($img, $outJpg, 90);
    imagedestroy($img);
    if (!$ok) return [false, null, 'Не удалось сохранить JPG.'];

    return [true, $outJpg, null];
}

/**
 * Определение пола сотрудника.
 */
function detectEmployeeGender(array $props, string $firstName = '', string $otchestvo = ''): string
{
    if (!empty($props['POL'])) {
        $enumId = (int)($props['POL']['VALUE_ENUM_ID'] ?? 0);
        if ($enumId === 696) return 'F';
        if ($enumId === 695) return 'M';
    }

    $otch = trim(mb_strtolower($otchestvo !== '' ? $otchestvo : (string)($props['OTCHESTVO']['VALUE'] ?? ''), 'UTF-8'));
    if ($otch !== '') {
        if (preg_match('/(вна|чна)$/u', $otch)) return 'F';
        if (preg_match('/(вич|ич)$/u', $otch)) return 'M';
    }

    $name = trim(mb_strtolower($firstName, 'UTF-8'));
    if ($name !== '') {
        $maleExceptions = ['илья','никита','кузьма','фома','лука','савва','жора','женя','валя','саша','паша'];
        if (in_array($name, $maleExceptions, true)) return 'M';
        $last = mb_substr($name, -1, null, 'UTF-8');
        if ($last === 'а' || $last === 'я') return 'F';
    }
    return 'M';
}

// === Морфология ФИО (родительный падеж) ===
function ruInferGenderSimple(?string $first, ?string $hint = null): string {
    if ($hint === 'M' || $hint === 'F') return $hint;
    $n = trim((string)$first);
    if ($n === '') return 'M';
    $nL = mb_strtolower($n, 'UTF-8');
    $maleExceptions = ['никита','илья','кузьма','лука','фома'];
    if (in_array($nL, $maleExceptions, true)) return 'M';
    $last = mb_substr($nL, -1, null, 'UTF-8');
    if ($last === 'а' || $last === 'я') return 'F';
    return 'M';
}

function ruGenitiveFirstName(string $name, string $gender): string {
    $n = trim($name);
    if ($n==='') return $n;

    $map = [
        'Павел'=>'Павла',
        'Лев'=>'Льва',
        'Пётр'=>'Петра',
        'Петр'=>'Петра',
        'Илья'=>'Ильи',
        'Фома'=>'Фомы',
        'Лука'=>'Луки'
    ];
    if (isset($map[$n])) return $map[$n];

    $last = mb_substr($n, -1, null, 'UTF-8');
    $last2= mb_substr($n, -2, null, 'UTF-8');
    $pre  = mb_substr($n, 0, mb_strlen($n,'UTF-8')-1, 'UTF-8');
    $pre2 = mb_substr($n, 0, mb_strlen($n,'UTF-8')-2, 'UTF-8');

    if ($gender==='M') {
        if ($last2==='ий') return $pre2.'ия';
        if ($last==='й')   return $pre.'я';
        if ($last==='ь')   return $pre.'я';
        if ($last==='а') {
            $prev = mb_substr($n, -2, 1, 'UTF-8');
            $iSet=['г','к','х','ж','ч','ш','щ'];
            return $pre.(in_array($prev,$iSet,true)?'и':'ы');
        }
        if ($last==='я')   return $pre.'и';
        return $n.'а';
    } else {
        if ($last==='а') {
            $prev = mb_substr($n, -2, 1, 'UTF-8');
            $iSet=['г','к','х','ж','ч','ш','щ'];
            return $pre.(in_array($prev,$iSet,true)?'и':'ы');
        }
        if ($last==='я')   return $pre.'и';
        if ($last==='ь')   return $pre.'и';
        return $n;
    }
}

function ruIsIndeclinableLast(string $ln): bool {
    $l = mb_strtolower($ln,'UTF-8');
    $indecl = ['ко','енко','чко','шко','ишвили','дзе'];
    foreach($indecl as $suf){
        if (mb_substr($l, -mb_strlen($suf,'UTF-8'), null, 'UTF-8') === $suf) return true;
    }
    $last = mb_substr($l,-1,null,'UTF-8');
    if (in_array($last,['о','е','ё','и','у','ю','ы','э','й'],true)) return true;
    return false;
}

function ruGenitiveLastName(string $ln, string $gender): string {
    $n = trim($ln);
    if ($n==='') return $n;
    if (ruIsIndeclinableLast($n)) return $n;

    $l1 = mb_substr($n,-1,null,'UTF-8');
    $l2 = mb_substr($n,-2,null,'UTF-8');
    $pre1 = mb_substr($n,0,mb_strlen($n,'UTF-8')-1,'UTF-8');
    $pre2 = mb_substr($n,0,mb_strlen($n,'UTF-8')-2,'UTF-8');

    if ($gender==='M') {
        if (preg_match('~(ов|ев|ёв|ин|ын|кин)$~u',$n)) return $n.'а';
        if (preg_match('~(ский|ской|цкий)$~u',$n))     return $pre2.'ого';
        if (in_array($l2,['ой','ый'],true) || $l2==='ий') return $pre2.'ого';
        if ($l1==='ь') return $pre1.'я';
        return $n.'а';
    } else {
        if (preg_match('~(ова|ева|ёва|ина|ына|кина)$~u',$n)) return $pre1.'ой';
        if ($l2==='ая') return $pre2.'ой';
        if ($l2==='яя') return $pre2.'ей';
        if ($l1==='я')  return $pre1.'и';
        if ($l1==='ь')  return $pre1.'и';
        return $n;
    }
}

function ruFullNameGenitive(string $first, string $last, ?string $genderHint = null): string {
    $gender = ruInferGenderSimple($first, $genderHint);
    $f = ruGenitiveFirstName($first, $gender);
    $l = ruGenitiveLastName($last, $gender);
    return trim($f.' '.$l);
}

function getManagerGenitiveFio(array $props): ?string {
    foreach (['RUKOVODITEL','MANAGER'] as $code) {
        if (!empty($props[$code]['VALUE'])) {
            $v = $props[$code]['VALUE'];
            if (is_array($v)) $v = $v[0];
            if (is_numeric($v)) {
                $rs = CUser::GetByID((int)$v);
                if ($u = $rs->Fetch()) {
                    $first = trim((string)$u['NAME']);
                    $last  = trim((string)$u['LAST_NAME']);
                    $gender= $u['PERSONAL_GENDER'] ?: null;
                    if ($first!=='' || $last!=='') return ruFullNameGenitive($first,$last,$gender);
                }
            }
        }
    }

    foreach (['RUKOVODITEL_FIO','RUKOVODITEL','MANAGER'] as $code) {
        $raw = $props[$code]['VALUE'] ?? '';
        if ($raw && !is_numeric($raw)) {
            $parts = preg_split('/\s+/u', trim((string)$raw));
            $first = $parts[0] ?? '';
            $last  = $parts[count($parts)-1] ?? '';
            if ($first!=='' || $last!=='') return ruFullNameGenitive($first,$last,null);
        }
    }

    return null;
}

/** Рендер JPG. Возвращает абсолютный путь. */
function renderBannerJpg(array $params, int $elementId, ?string $photoPath, bool $hasRek, string $rekText, string $backgroundPath, string $outDir, bool $isPreview=false): string
{
    $W=794; $H=454;
    $PHOTO=['X'=>71,'Y'=>65,'W'=>113,'H'=>148];
    $T1=['X'=>200,'Y'=>50,'W'=>415,'SIZE'=>15,'COLOR'=>[0,102,204],'BOLD'=>true];
    $T2=['X'=>200,'Y'=>80,'W'=>315,'SIZE'=>16,'COLOR'=>[204,0,0],'BOLD'=>true];
    $T3=['X'=>200,'Y'=>110,'W'=>305,'SIZE'=>12,'COLOR'=>[0,0,0],'BOLD'=>false,'LH'=>1.2];
    $TT=['X'=>65,'Y'=>285,'W'=>650,'SIZE'=>15,'COLOR'=>[0,102,204],'BOLD'=>true,'TEXT'=>'Задачи:'];
    $L4=['X'=>65,'Y'=>310,'W'=>($hasRek?450:650),'SIZE'=>12,'COLOR'=>[0,0,0],'BOLD'=>false,'LH'=>1.5];

    if (isset($params['line3_size']) && (int)$params['line3_size']>0) {
        $T3['SIZE'] = max(6, min(72, (int)$params['line3_size']));
    }
    if (isset($params['line4_size']) && (int)$params['line4_size']>0) {
        $L4['SIZE'] = max(6, min(72, (int)$params['line4_size']));
    }

    $TR=['X'=>585,'Y'=>350,'W'=>203,'SIZE'=>13,'COLOR'=>[255,255,255],'BOLD'=>false,'LH'=>1.3];

    $pick=function($list){
        foreach($list as $p){
            if($p && is_file($p)) return $p;
        }
        return null;
    };

    $FONT_REG =$pick([
        '/home/bitrix/fonts/Montserrat-Regular.ttf',
        $_SERVER['DOCUMENT_ROOT'].'/bitrix/fonts/pt_sans/pt_sans-web-regular.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
    ]);

    $FONT_BOLD=$pick([
        '/home/bitrix/fonts/Montserrat-ExtraBold.ttf',
        $_SERVER['DOCUMENT_ROOT'].'/bitrix/fonts/pt_sans/pt_sans-web-bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        $FONT_REG
    ]);

    if(!$FONT_REG) throw new RuntimeException('Нет TTF-шрифта (DejaVuSans/Montserrat).');

    $measure=function($font,$size,$s){
        $b=@imagettfbbox($size,0,$font,(string)$s);
        if(!$b) return [0,0];
        return [abs($b[2]-$b[0]),abs($b[7]-$b[1])];
    };

    $wrap=function($im,$text,$x,$y,$maxW,$font,$size,$rgb,$lh=1.3,$align='left') use($measure){
        $c=imagecolorallocate($im,$rgb[0],$rgb[1],$rgb[2]);
        [, $lineH]=$measure($font,$size,'ЁЙЩЦЖФЫДТpqgy');
        $paras=preg_split("/\r\n|\n|\r/u",(string)$text);

        foreach($paras as $p){
            if($p===''){
                $y+=(int)($lineH*$lh);
                continue;
            }

            $words=preg_split('/\s+/u',trim($p));
            $line='';

            foreach($words as $w){
                $test=$line===''?$w:$line.' '.$w;
                [$tw]=$measure($font,$size,$test);

                if($tw<=$maxW){
                    $line=$test;
                }else{
                    if($line!==''){
                        [$lw]=$measure($font,$size,$line);
                        $dx=$align==='center'?(int)(($maxW-$lw)/2):($align==='right'?(int)($maxW-$lw):0);
                        imagettftext($im,$size,0,(int)($x+$dx),(int)$y,$c,$font,$line);
                        $y+=(int)($lineH*$lh);
                    }
                    $line=$w;
                }
            }

            if($line!==''){
                [$lw]=$measure($font,$size,$line);
                $dx=$align==='center'?(int)(($maxW-$lw)/2):($align==='right'?(int)($maxW-$lw):0);
                imagettftext($im,$size,0,(int)($x+$dx),(int)$y,$c,$font,$line);
                $y+=(int)($lineH*$lh);
            }
        }

        return $y;
    };

    $bullets=function($im,$text,$x,$y,$maxW,$font,$size,$rgb,$lh=1.8) use($wrap){
        $r=imagecolorallocate($im,204,0,0);
        $indent=16;
        $rad=4;
        $lines=preg_split("/\r\n|\n|\r/u",(string)$text);

        foreach($lines as $raw){
            $line=trim($raw);
            if($line==='') continue;

            $is=mb_substr($line,0,1,'UTF-8')==='-';
            if($is) $line=trim(mb_substr($line,1,null,'UTF-8'));

            if($is){
                $up=(int)round($size*0.20);
                imagefilledellipse($im,(int)($x+$rad),(int)($y-$rad/2-$up),$rad*2,$rad*2,$r);
                $y=$wrap($im,$line,(int)($x+$indent),(int)$y,(int)($maxW-$indent),$font,$size,$rgb,$lh,'left');
            } else {
                $y=$wrap($im,$line,(int)$x,(int)$y,(int)$maxW,$font,$size,$rgb,$lh,'left');
            }
        }

        return $y;
    };

    $im=imagecreatetruecolor($W,$H);
    imagealphablending($im,true);
    imagesavealpha($im,true);

    $white=imagecolorallocate($im,255,255,255);
    imagefill($im,0,0,$white);

    if(!is_file($backgroundPath)) throw new RuntimeException('Фон не найден: '.$backgroundPath);

    $ext=strtolower(pathinfo($backgroundPath, PATHINFO_EXTENSION));
    $bg=$ext==='png'?@imagecreatefrompng($backgroundPath):@imagecreatefromjpeg($backgroundPath);
    if($bg){
        imagecopyresampled($im,$bg,0,0,0,0,$W,$H,imagesx($bg),imagesy($bg));
        imagedestroy($bg);
    }

    if($photoPath && is_file($photoPath)){
        $pext=strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
        $src=null;

        if(in_array($pext,['jpg','jpeg'])) $src=@imagecreatefromjpeg($photoPath);
        elseif($pext==='png') $src=@imagecreatefrompng($photoPath);
        elseif($pext==='gif') $src=@imagecreatefromgif($photoPath);

        if(!$src) $src=@imagecreatefromstring(@file_get_contents($photoPath));

        if($src){
            $sw=imagesx($src);
            $sh=imagesy($src);
            $scale=max($PHOTO['W']/$sw,$PHOTO['H']/$sh);
            $cw=(int)round($PHOTO['W']/$scale);
            $ch=(int)round($PHOTO['H']/$scale);
            $sx=(int)round(($sw-$cw)/2);
            $sy=(int)round(($sh-$ch)/2);

            imagecopyresampled($im,$src,$PHOTO['X'],$PHOTO['Y'],$sx,$sy,$PHOTO['W'],$PHOTO['H'],$cw,$ch);
            imagedestroy($src);
        }
    }

    $font1=$T1['BOLD']?$FONT_BOLD:$FONT_REG;
    $font2=$T2['BOLD']?$FONT_BOLD:$FONT_REG;
    $font3=$T3['BOLD']?$FONT_BOLD:$FONT_REG;
    $fontT=$TT['BOLD']?$FONT_BOLD:$FONT_REG;
    $font4=$L4['BOLD']?$FONT_BOLD:$FONT_REG;
    $fontR=$TR['BOLD']?$FONT_BOLD:$FONT_REG;

    $wrap($im,(string)$params['line1'],$T1['X'],$T1['Y'],$T1['W'],$font1,$T1['SIZE'],$T1['COLOR'],1.2,'left');
    $wrap($im,(string)$params['line2'],$T2['X'],$T2['Y'],$T2['W'],$font2,$T2['SIZE'],$T2['COLOR'],1.2,'left');
    $wrap($im,(string)$params['line3'],$T3['X'],$T3['Y'],$T3['W'],$font3,$T3['SIZE'],$T3['COLOR'],$T3['LH']??1.4,'left');
    $wrap($im,(string)$TT['TEXT'],$TT['X'],$TT['Y'],$TT['W'],$fontT,$TT['SIZE'],$TT['COLOR'],1.2,'left');
    $bullets($im,(string)$params['line4'],$L4['X'],$L4['Y'],$L4['W'],$font4,$L4['SIZE'],$L4['COLOR'],$L4['LH']??1.8);

    if ($hasRek && trim($rekText)!=='') {
        $wrap($im,(string)$rekText,$TR['X'],$TR['Y'],$TR['W'],$fontR,$TR['SIZE'],$TR['COLOR'],$TR['LH']??1.3,'left');
    }

    ensureDir($outDir);
    $suffix=$isPreview? '_preview' : '';
    $name=$elementId.'_'.uniqid('',true).$suffix.'.jpg';
    $full=rtrim($outDir,'/').'/'.$name;
    imageinterlace($im,true);
    imagejpeg($im,$full,90);
    imagedestroy($im);

    if(!is_file($full)) throw new RuntimeException('Не удалось сохранить JPG баннера.');
    return $full;
}

// ==== Входные данные ====
$elementId = isset($_GET['ID']) ? (int)$_GET['ID'] : 0;
if($elementId <= 0) die('Ошибка: ID элемента не указан/некорректен.');
$GLOBALS['elementId'] = $elementId;

// === Функция получения ID задания БП ===
function getBPTaskId($documentId, $userId) {
    global $IBLOCK_ANKET;

    $documentType = ['lists', 'Bitrix\Lists\BizprocDocumentLists', "iblock_".$IBLOCK_ANKET];
    $documentId = ['lists', 'Bitrix\Lists\BizprocDocumentLists', $documentId];
    $arDocumentStates = CBPDocument::GetDocumentStates($documentType, $documentId);

    foreach ($arDocumentStates as $arDocumentState) {
        if ($arDocumentState['STATE_NAME'] == "InProgress") {
            $tasks = CBPDocument::GetUserTasksForWorkflow($userId, $arDocumentState['ID']);
            if (!empty($tasks)) {
                return $tasks[0]['ID'];
            }
        }
    }

    return false;
}

// === Формирование URL возврата ===
$bpTaskId = getBPTaskId($elementId, $USER->GetID());
if ($bpTaskId) {
    $backUrl = "/company/personal/bizproc/" . $bpTaskId . "/?back_url=%2Fcompany%2Fpersonal%2Fbizproc%2F";
} else {
    $backUrl = $_SERVER['HTTP_REFERER'] ?? '/';
}

if(!Loader::includeModule('iblock')) die('Ошибка: модуль iblock недоступен.');

// Тянем анкету + свойства
$elRes = CIBlockElement::GetList(
    [],
    ['IBLOCK_ID'=>$IBLOCK_ANKET,'ID'=>$elementId],
    false,
    false,
    ['ID','IBLOCK_ID','NAME','PROPERTY_*']
);
$element = $elRes->GetNextElement();
if(!$element) die('Ошибка: элемент анкеты не найден.');

$fields = $element->GetFields();
$props  = $element->GetProperties();

// Фото из FOTO_SOTRUDNIKA (ID=969)
$fotoProp=null;
foreach($props as $p){
    if(($p['CODE'] ?? '') === 'FOTO_SOTRUDNIKA' || (int)($p['ID'] ?? 0) === 969){
        $fotoProp = $p;
        break;
    }
}
$fotoSotrudnikaSrc = $fotoProp ? fileSrcFromPropVal($fotoProp['VALUE']) : null;

// Для загрузки фото в карточку будем знать "ключ" свойства
$fotoPropKey = $fotoProp ? (($fotoProp['CODE'] ?? '') !== '' ? $fotoProp['CODE'] : (int)($fotoProp['ID'] ?? 0)) : 'FOTO_SOTRUDNIKA';

// Пол сотрудника
$employeeGender = detectEmployeeGender($props);
$malePhAbs   = $_SERVER['DOCUMENT_ROOT'].'/upload/application/hr-adaptation/templates/tri_men.jpg';
$femalePhAbs = $_SERVER['DOCUMENT_ROOT'].'/upload/application/hr-adaptation/templates/tri_women.jpg';

// Тексты
$imya  = trim((string)($props['IMYA']['VALUE'] ?? ''));
$fam   = trim((string)($props['FAMILIYA']['VALUE'] ?? ''));
$imyaFam = trim($imya.' '.$fam);
$dolzh = trim((string)($props['DOLZHNOST']['VALUE'] ?? $props['DOLZNOST']['VALUE'] ?? $props['POSITION']['VALUE'] ?? ''));
$otdel = trim((string)($props['OTDEL']['VALUE'] ?? $props['PODRAZDELENIE']['VALUE'] ?? $props['DEPARTMENT']['VALUE'] ?? ''));
$tasksTxt = (string)($props['OSNOVNYE_OBYAZANNOSTI_TXT']['VALUE'] ?? '');
$zadDefault = (trim($tasksTxt) !== '') ? $tasksTxt : "- Познакомиться с командой\n- Получить доступы и инструменты\n- Войти в курс задач";

// Базовое поле рекомендации (как было раньше)
$rekBase = (string)($props['REKOMENDATSIYA']['VALUE'] ?? $props['REKOM']['VALUE'] ?? $props['RECOMMENDATION']['VALUE'] ?? '');

// Новое поле: PRINYAT_PO_REKOMENDATSII / PROPERTY_3108
$prinyatPoRekomendatsii = trim((string)($props['PRINYAT_PO_REKOMENDATSII']['VALUE'] ?? ''));

// Определяем, нужно ли по умолчанию включать режим "С рекомендацией" из PRINYAT_PO_REKOMENDATSII
$usePrinyatPoRekAsDefault = (
    $prinyatPoRekomendatsii !== ''
    && mb_strtolower($prinyatPoRekomendatsii, 'UTF-8') !== mb_strtolower('Принят без рекомендации', 'UTF-8')
);

// Если новое поле заполнено корректным текстом рекомендации — используем его,
// иначе оставляем старую логику
$rek = $usePrinyatPoRekAsDefault ? $prinyatPoRekomendatsii : $rekBase;

$dataPriema = trim((string)($props['DATA_PRIEMA']['VALUE'] ?? $props['DATE_START']['VALUE'] ?? $props['HIRE_DATE']['VALUE'] ?? date('d.m.Y')));

// Форматирование времени для datetime-local
$dateObj = DateTime::createFromFormat('d.m.Y', $dataPriema);
if (!$dateObj) $dateObj = new DateTime();

$time = generateRandomTime();
$dateTimeObj = clone $dateObj;
$dateTimeObj->setTime((int)substr($time,0,2), (int)substr($time,3,2));
$dataPriemaFormatted = $dateTimeObj->format('Y-m-d\TH:i');

// Старая защита остается
if(stripos($rek,'нет') !== false) {
    // Но не обнуляем рекомендацию, если она пришла из PRINYAT_PO_REKOMENDATSII и реально должна быть показана
    if (!$usePrinyatPoRekAsDefault) {
        $rek = '';
    }
}

$welcome = 'Добро пожаловать!';

// Строка 3
$managerGen = getManagerGenitiveFio($props);
$rukLine = $managerGen ? ('В команде '.$managerGen) : '';
$line3Default = trim("$dolzh\n$otdel\n$rukLine\nЖелаем профессиональных\n успехов и достижений!");

// ВАЖНО: дефолт режима рекомендаций
// 1) если PRINYAT_PO_REKOMENDATSII заполнено не "Принят без рекомендации" — включаем по умолчанию
// 2) иначе оставляем старую логику по наличию рекомендации
$hasRekDefault = $usePrinyatPoRekAsDefault ? true : ($rek !== '');

$employeeGender = detectEmployeeGender($props, $imya, (string)($props['OTCHESTVO']['VALUE'] ?? ''));
$verb = ($employeeGender === 'F') ? 'присоединилась' : 'присоединился';
$defaultMessageSubject = "Коллеги, к нашей команде {$verb} {$imya} {$fam} ({$dolzh})";

// ==== Обработка формы ====
$action = null;
$params = [];
$messageSubject = '';
$type = '';
$hasRek = false;
$rekText = '';
$photoUploadNotice = '';
$photoUploadError  = '';

if($_SERVER['REQUEST_METHOD']==='POST' && check_bitrix_sessid()){
    $action = $_POST['action'] ?? null;

    // === Загрузка фото сотрудника ===
    if (!empty($_FILES['employee_photo_upload']) && (int)($_FILES['employee_photo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$ok, $preparedPath, $errMsg] = prepareEmployeePhotoUpload($_FILES['employee_photo_upload'], 5 * 1024 * 1024, 1024);

        if (!$ok) {
            $photoUploadError = (string)$errMsg;
        } else {
            $fileArr = CFile::MakeFileArray($preparedPath);
            $fileArr['MODULE_ID'] = 'iblock';

            CIBlockElement::SetPropertyValuesEx(
                $elementId,
                $IBLOCK_ANKET,
                ['FOTO_SOTRUDNIKA' => $fileArr]
            );

            $fotoSotrudnikaSrc = null;
            $rsP = CIBlockElement::GetProperty($IBLOCK_ANKET, $elementId, ['sort' => 'asc'], ['CODE' => 'FOTO_SOTRUDNIKA']);
            if ($pRow = $rsP->Fetch()) {
                $fid = (int)($pRow['VALUE'] ?? 0);
                if ($fid > 0) $fotoSotrudnikaSrc = CFile::GetPath($fid);
            }

            $photoUploadNotice = $fotoSotrudnikaSrc
                ? 'Фото загружено и сохранено в карточке сотрудника.'
                : 'Фото загружено, но не удалось прочитать его из карточки.';
        }
    }

    $type = isset($_POST['banner_type'])
        ? (string)$_POST['banner_type']
        : ($hasRekDefault ? 'with_rekomendatsiya' : 'without_rekomendatsiya');

    $hasRek = ($type === 'with_rekomendatsiya');

    $rekText = isset($_POST['rekomendatsiya'])
        ? trim((string)$_POST['rekomendatsiya'])
        : $rek;

    if (in_array($action, ['save_to_banner','generate_banner'], true) && $hasRek && $rekText === '') {
        $hasRek = false;
    }

    $params = [
        'line1' => isset($_POST['line1']) ? (string)$_POST['line1'] : $welcome,
        'line2' => isset($_POST['line2']) ? (string)$_POST['line2'] : $imyaFam,
        'line3' => isset($_POST['line3']) ? (string)$_POST['line3'] : $line3Default,
        'line4' => isset($_POST['line4']) ? (string)$_POST['line4'] : $zadDefault,
        'line3_size' => isset($_POST['line3_size']) ? (int)$_POST['line3_size'] : 12,
        'line4_size' => isset($_POST['line4_size']) ? (int)$_POST['line4_size'] : 12,
    ];

    $messageSubject = isset($_POST['message_subject'])
        ? trim((string)$_POST['message_subject'])
        : $defaultMessageSubject;

} else {
    $type = $hasRekDefault ? 'with_rekomendatsiya' : 'without_rekomendatsiya';
    $hasRek = $hasRekDefault;
    $rekText = $rek;

    if($hasRek && $rekText === '') $hasRek = false;

    $params = [
        'line1' => $welcome,
        'line2' => $imyaFam,
        'line3' => $line3Default,
        'line4' => $zadDefault,
        'line3_size' => 12,
        'line4_size' => 12,
    ];

    $messageSubject = $defaultMessageSubject;
}

// Фон
$bgFile = $hasRek ? 'welcome2.jpg' : 'welcome1.jpg';
$backgroundPath = $TEMPLATE_DIR.$bgFile;
if(!file_exists($backgroundPath)){
    $alt=$TEMPLATE_DIR.($hasRek ? 'welcome1.jpg' : 'welcome2.jpg');
    if(file_exists($alt)) {
        $backgroundPath=$alt;
    } else {
        die('Ошибка: фоновые welcome1.jpg/welcome2.jpg не найдены.');
    }
}

// Фото -> temp
$photoAbs = null;
if($fotoSotrudnikaSrc){
    $full = (strpos($fotoSotrudnikaSrc,'/')===0)
        ? $_SERVER['DOCUMENT_ROOT'].$fotoSotrudnikaSrc
        : $_SERVER['DOCUMENT_ROOT'].'/'.$fotoSotrudnikaSrc;

    if(file_exists($full)) $photoAbs = $full;
}
if(!$photoAbs){
    $ph = ($employeeGender==='F') ? $femalePhAbs : $malePhAbs;
    if(is_file($ph)) $photoAbs = $ph;
}

$tempPhotoPath = null;
if($photoAbs) $tempPhotoPath = copyPhotoToTemp($photoAbs);

try{
    if($action === 'generate_banner'){
        $jpg = renderBannerJpg($params,$elementId,$tempPhotoPath,$hasRek,$rekText,$backgroundPath,$BANNERS_DIR,false);
        header('Content-Type: image/jpeg');
        header('Content-Disposition: attachment; filename="banner_'.date('Ymd_His').'.jpg"');
        readfile($jpg);
        @unlink($jpg);
        if($tempPhotoPath && file_exists($tempPhotoPath)) @unlink($tempPhotoPath);
        exit;
    }

    if($action === 'save_to_banner'){
        if(!Loader::includeModule('iblock')) throw new RuntimeException('Модуль iblock недоступен.');

        $jpg = renderBannerJpg($params,$elementId,$tempPhotoPath,$hasRek,$rekText,$backgroundPath,$BANNERS_DIR,false);
        $jpgUrl = $BANNERS_URL_PR.basename($jpg);

        if($tempPhotoPath && file_exists($tempPhotoPath)) @unlink($tempPhotoPath);

        $publishDateTime = isset($_POST['publish_datetime'])
            ? DateTime::createFromFormat('Y-m-d\TH:i', $_POST['publish_datetime'])
            : DateTime::createFromFormat('Y-m-d\TH:i', $dataPriemaFormatted);

        $formattedTime = $publishDateTime
            ? $publishDateTime->format('d.m.Y H:i')
            : $dataPriema.' '.generateRandomTime();

        $el = new CIBlockElement();

        $exists = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID'=>$IBLOCK_BANNER,'PROPERTY_KARTOCHKA_SOTRUDNIKA'=>$elementId],
            false,
            false,
            ['ID','NAME']
        )->Fetch();

        $propsBanner = [
            'OTVETSTVENNYY'         => $USER->GetID(),
            'TEG'                   => 'Кадровые изменения',
            'BANNER2'               => ['VALUE'=>['TYPE'=>'HTML','TEXT'=>'<img src="'.$jpgUrl.'" alt="welcome banner">']],
            'URL_BANNERA_STROKA'    => $jpgUrl,
            'VREMYA_POSTA'          => $formattedTime,
            'TIP_BANNERA'           => 6236,
            'OPUBLIKOVANO'          => 'N',
            'KARTOCHKA_SOTRUDNIKA'  => $elementId,
        ];

        $fieldsBanner = [
            'MODIFIED_BY'       => $USER->GetID(),
            'IBLOCK_SECTION_ID' => false,
            'IBLOCK_ID'         => $IBLOCK_BANNER,
            'NAME'              => $messageSubject,
            'ACTIVE'            => 'Y',
            'PROPERTY_VALUES'   => $propsBanner,
        ];

        if($exists){
            $ok = $el->Update($exists['ID'],$fieldsBanner);
            if(!$ok) throw new RuntimeException('Ошибка обновления: '.$el->LAST_ERROR);
        } else {
            $newId = $el->Add($fieldsBanner);
            if(!$newId) throw new RuntimeException('Ошибка создания: '.$el->LAST_ERROR);
        }

        LocalRedirect($backUrl);
    }
}
catch(Throwable $e){
    if($tempPhotoPath && file_exists($tempPhotoPath)) @unlink($tempPhotoPath);
    die('Ошибка: '.$e->getMessage());
}

// Генерация превью
cleanupPreviews($elementId,$PREVIEW_DIR);
$previewAbs = renderBannerJpg($params,$elementId,$tempPhotoPath,$hasRek,$rekText,$backgroundPath,$PREVIEW_DIR,true);
$previewUrl = $PREVIEW_URL_PR.basename($previewAbs);
if($tempPhotoPath && file_exists($tempPhotoPath)) @unlink($tempPhotoPath);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Баннер Welcome — v5.8</title>
<style>
 body{font-family:Arial, sans-serif; margin:16px;}
 .wrap{display:flex; gap:24px; align-items:flex-start;}
 .preview{width:794px}
 .preview img{width:794px; height:454px; display:block; border:1px dashed #ddd; box-shadow:0 2px 12px rgba(0,0,0,.06)}
 form{flex:1 1 auto; min-width:360px}
 .field{margin-bottom:12px}
 .field label{display:block; font-weight:600; margin-bottom:6px}
 .field input[type=text], .field textarea, .field input[type=datetime-local]{width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font:inherit; box-sizing:border-box}
 .row{display:flex; gap:12px}
 .muted{color:#777; font-size:12px}
 .actions{display:flex; gap:12px; margin-top:16px}
 button{padding:10px 14px; border:0; border-radius:8px; cursor:pointer}
 .btn-primary{background:#0066cc; color:#fff}
 .btn-secondary{background:#efefef}
 .info-block{margin-bottom:16px; padding:12px; background:#ffe; border-radius:6px; font-size:14px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="preview">
    <img src="<?=htmlspecialchars($previewUrl)?>?t=<?=time()?>" alt="preview">
  </div>

  <form method="post" enctype="multipart/form-data">
    <?=bitrix_sessid_post()?>
    <input type="hidden" name="back" value="<?=htmlspecialchars($backUrl)?>">

    <div class="info-block">
      <strong>Сотрудник:</strong> <?= htmlspecialchars($imyaFam) ?><br>
      <strong>Дата трудоустройства:</strong> <?= htmlspecialchars($dataPriema) ?><br>

      <?php if($photoUploadNotice): ?>
        <div style="margin-top:6px;color:#0a7b2b"><small><?=htmlspecialchars($photoUploadNotice)?></small></div>
      <?php endif; ?>

      <?php if($photoUploadError): ?>
        <div style="margin-top:6px;color:#b00020"><small><?=htmlspecialchars($photoUploadError)?></small></div>
      <?php endif; ?>

      <small>
        Проверьте и отредактируйте поля баннера. Часть данных добавляется автоматически.
        Если на баннере нет фото — можно загрузить его ниже (оно прикрепится к
        <a href="/services/lists/196/element/0/<?=$elementId?>/" target="_blank">карточке сотрудника</a>).
        Если файл не загружать и в карточке фото нет, будет использовано фото по-умолчанию.
      </small>
    </div>

    <div class="field">
      <label>Фотография сотрудника (загрузить в карточку)</label>
      <input type="file" name="employee_photo_upload" accept="image/jpeg,image/png">
      <div class="muted">
        Допустимые форматы: JPG/JPEG/PNG. Если загрузить, файл сохранится в поле фото карточки и будет использован на баннере.
        Не забудьте нажать "Обновить превью" после загрузки фото!
      </div>
    </div>

    <div class="field">
      <label>Тип баннера</label>
      <label><input type="radio" name="banner_type" value="without_rekomendatsiya" <?= $hasRek ? '' : 'checked' ?>> Без рекомендации</label>
      <label><input type="radio" name="banner_type" value="with_rekomendatsiya" <?= $hasRek ? 'checked' : '' ?>> С рекомендацией</label>
      <div class="muted">Фон: <code>welcome2.jpg</code> для «с рекомендацией», иначе <code>welcome1.jpg</code>.</div>
    </div>

    <div class="row">
      <div class="field" style="flex:1">
        <label>Строка 1</label>
        <input type="text" name="line1" value="<?=htmlspecialchars($params['line1'])?>">
      </div>
      <div class="field" style="flex:1">
        <label>Строка 2</label>
        <input type="text" name="line2" value="<?=htmlspecialchars($params['line2'])?>">
      </div>
    </div>

    <div class="row">
      <div class="field" style="flex:1">
        <label>Строка 3 (многострочная)</label>
        <textarea name="line3" rows="4"><?=htmlspecialchars($params['line3'])?></textarea>
      </div>
      <div class="field" style="width:180px">
        <label>Размер шрифта «Строка 3»</label>
        <input type="number" name="line3_size" min="6" max="72" step="1" value="<?= (int)($params['line3_size'] ?? 12) ?>">
      </div>
    </div>

    <div class="row">
      <div class="field" style="flex:1">
        <label>Задачи (каждая с новой строки; для буллета начинайте строку с «-»)</label>
        <textarea name="line4" rows="6"><?=htmlspecialchars($params['line4'])?></textarea>
      </div>
      <div class="field" style="width:180px">
        <label>Размер шрифта «Задачи»</label>
        <input type="number" name="line4_size" min="6" max="72" step="1" value="<?= (int)($params['line4_size'] ?? 12) ?>">
      </div>
    </div>

    <div class="field" id="rek-field" style="<?= $hasRek ? '' : 'display:none' ?>">
      <label>Рекомендация (для типа «С рекомендацией»)</label>
      <textarea name="rekomendatsiya" rows="4"><?=htmlspecialchars($rekText)?></textarea>
    </div>

    <div class="field">
      <label>Тема сообщения (для ИБ баннеров)</label>
      <input type="text" name="message_subject" value="<?=htmlspecialchars($messageSubject)?>">
    </div>

    <div class="field">
      <label>Дата и время публикации</label>
      <input type="datetime-local" name="publish_datetime" value="<?= htmlspecialchars($dataPriemaFormatted) ?>" required>
      <div class="muted">Можно изменить. Используется для планирования поста.</div>
    </div>

    <div class="actions">
      <button type="submit" name="action" value="refresh_preview" class="btn-secondary">Обновить превью</button>
      <button type="submit" name="action" value="generate_banner" class="btn-secondary">Скачать JPG</button>
      <button type="submit" name="action" value="save_to_banner" class="btn-primary">Запланировать пост</button>
    </div>

    <div class="muted" style="margin-top:8px">
      При сохранении создаётся/обновляется элемент ИБ #<?=$IBLOCK_BANNER?> со свойствами:
      OTVETSTVENNYY, TEG, BANNER2 (HTML-IMG), URL_BANNERA_STROKA, VREMYA_POSTA, TIP_BANNERA=6236, KARTOCHKA_SOTRUDNIKA.
    </div>
  </form>
</div>
</body>
</html>