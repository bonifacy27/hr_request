<?php
/**
 * Text-only encrypted offer for approval (test password: test).
 * php offer_secure_cli.php <offerId> <outputFilename.pdf>
 * Install beside offer_cli.php; uses the same Bitrix root and TCPDF installation.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if ($argc !== 3 || !ctype_digit($argv[1]) || (int)$argv[1] < 1) {
    fwrite(STDERR, "Usage: php offer_secure_cli.php <offerId> <outputFilename.pdf>\n");
    exit(1);
}
$offerId = (int)$argv[1];
$outputFilename = trim($argv[2]);
if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.pdf\z/i', $outputFilename)) {
    fwrite(STDERR, "Output must be a PDF filename without a directory path\n");
    exit(1);
}

foreach (['ADMIN_SECTION', 'NO_KEEP_STATISTIC', 'NO_AGENT_CHECK', 'DisableEventsCheck',
    'BX_CRONTAB', 'NOT_CHECK_PERMISSIONS', 'BX_NO_ACCELERATOR_RESET'] as $flag) {
    if (!defined($flag)) {
        define($flag, true);
    }
}

$tempPath = null;
try {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../..');
    $documentRoot = $_SERVER['DOCUMENT_ROOT'];
    if (!$documentRoot || !is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php')) {
        throw new RuntimeException('Не найдено ядро Bitrix: установите утилиту рядом с offer_cli.php');
    }
    require_once $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
    global $USER;
    // Same trusted CLI execution context as offer_cli.php.
    $USER->Authorize(1);
    if (!CModule::IncludeModule('iblock')) {
        throw new RuntimeException('Модуль инфоблоков не подключен');
    }

    $offer = CIBlockElement::GetList([], ['IBLOCK_ID' => 218, 'ID' => $offerId],
        false, false, ['ID', 'NAME'])->Fetch();
    if (!$offer) {
        throw new RuntimeException('Оффер не найден в инфоблоке 218');
    }
    $props = [];
    $properties = CIBlockElement::GetProperty(218, $offerId, ['sort' => 'asc'], []);
    while ($property = $properties->Fetch()) {
        $props[$property['CODE']] = $property;
    }
    $recruiterId = (int)($props['REKRUTER']['VALUE'] ?? 0);
    $recruiter = $recruiterId > 0 ? (CUser::GetByID($recruiterId)->Fetch() ?: []) : [];
    $resolveElementName = static function ($id) {
        $element = CIBlockElement::GetByID($id)->Fetch();
        return $element ? $element['NAME'] : '';
    };

    require_once $documentRoot . '/tcpdf/tcpdf.php';
    require_once __DIR__ . '/offer_secure_pdf.php';
    $rows = offerSecureRows($props, $recruiter, $resolveElementName);
    $pdf = offerSecurePdf($rows, $offerId, 'test');

    $saveDir = $documentRoot . '/upload/application/offers/secure_pdf/';
    if (!is_dir($saveDir) && !mkdir($saveDir, 0700, true) && !is_dir($saveDir)) {
        throw new RuntimeException('Не удалось создать каталог PDF');
    }
    $savePath = $saveDir . $outputFilename;
    // Publish atomically, with access restricted to the generating OS user.
    $tempPath = tempnam($saveDir, '.offer-');
    if ($tempPath === false) {
        throw new RuntimeException('Не удалось создать временный файл PDF');
    }
    if (!chmod($tempPath, 0600)) {
        throw new RuntimeException('Не удалось установить права на файл PDF');
    }
    $pdf->Output($tempPath, 'F');
    if (!is_file($tempPath) || filesize($tempPath) === 0 || !rename($tempPath, $savePath)) {
        throw new RuntimeException('Не удалось сохранить PDF');
    }
    $tempPath = null;
    echo "PDF saved: {$savePath}\n";
} catch (Throwable $e) {
    if ($tempPath && is_file($tempPath)) {
        unlink($tempPath);
    }
    fwrite(STDERR, 'PDF generation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
