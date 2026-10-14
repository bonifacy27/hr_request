<?php
/** Attach the encrypted PDF through Bitrix's file-property API. */
function offerSecureAttachPdf(int $offerId, string $savePath): int
{
    $property = CIBlockProperty::GetByID(3196, 218)->Fetch();
    if (!$property || $property['CODE'] !== 'OFFER_PDF_DRAFT'
        || $property['PROPERTY_TYPE'] !== 'F' || $property['MULTIPLE'] !== 'N') {
        throw new RuntimeException('Свойство 3196 OFFER_PDF_DRAFT должно быть одиночным файловым свойством инфоблока 218');
    }
    $previous = CIBlockElement::GetProperty(218, $offerId, [], ['ID' => 3196])->Fetch();
    $previousId = (int)($previous['VALUE'] ?? 0);
    if (!is_file($savePath) || filesize($savePath) === 0) {
        throw new RuntimeException('Не удалось подготовить PDF для записи в OFFER_PDF_DRAFT');
    }
    $file = CFile::MakeFileArray($savePath);
    if (!$file) {
        throw new RuntimeException('Не удалось подготовить PDF для записи в OFFER_PDF_DRAFT');
    }
    $file['name'] = basename($savePath);
    $file['type'] = 'application/pdf';
    // Bitrix registers the file and replaces the previous single-file value.
    CIBlockElement::SetPropertyValuesEx($offerId, 218, [
        'OFFER_PDF_DRAFT' => ['VALUE' => $file, 'DESCRIPTION' => ''],
    ]);

    // SetPropertyValuesEx has no reliable success return value: read it back.
    $stored = CIBlockElement::GetProperty(218, $offerId, [], ['ID' => 3196])->Fetch();
    $fileId = (int)($stored['VALUE'] ?? 0);
    $storedFile = $fileId > 0 ? CFile::GetFileArray($fileId) : false;
    if ($fileId <= 0 || $fileId === $previousId || !$storedFile
        || (int)$storedFile['FILE_SIZE'] !== filesize($savePath)
        || $storedFile['ORIGINAL_NAME'] !== $file['name']) {
        throw new RuntimeException('Не удалось записать созданный PDF в OFFER_PDF_DRAFT');
    }
    return $fileId;
}

/** Text representation of the fields and conditions in offer_cli.php. */
function offerSecureRows(array $props, array $recruiter, callable $resolveElementName): array
{
    $propStr = static function ($code) use ($props) {
        return trim((string)($props[$code]['VALUE'] ?? ''));
    };
    $propNum = static function ($code) use ($props) {
        return (float)($props[$code]['VALUE'] ?? 0);
    };
    $propEnumName = static function ($code) use ($props, $resolveElementName) {
        $id = $props[$code]['VALUE'] ?? null;
        return $id ? trim((string)$resolveElementName($id)) : '';
    };
    $fmtRub = static function ($value) {
        return number_format($value, 0, ',', ' ') . ' рублей';
    };
    // Only this organization uses the shortened "Телемагазин" template. An empty
    // organization (as well as НАО "НСК") keeps the existing six-page template.
    $isTelemagOffer = ((int)$propStr("YURIDICHESKOE_LITSO") === 3339193);

    $CANDIDATE_FIO   = $propStr("POLNOE_FIO_KANDIDATA");
    $CANDIDATE_PHONE = $propStr("KONTAKTNYY_TELEFON_KANDIDATA_7_");

    $dateSend = $propStr("PLANIRUEMAYA_DATA_OTPRAVKI_OFFERA_KANDIDATU");
    $dateSendTimestamp = $dateSend !== "" ? strtotime($dateSend) : false;
    $DATE_SEND = $dateSendTimestamp !== false ? date("d.m.Y", $dateSendTimestamp) : $dateSend;

    $ruk = trim(
        $propStr("FIO_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE") . "\n" .
        $propStr("DOLZHNOST_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE")
    );

    $department = $propStr("POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE");
    if ($isTelemagOffer) {
        $department .= ($department !== "" ? "\n" : "") . "Проект \"Телемагазин\"";
    }

    $col1Data = [
        ["label" => "Должность",    "value" => $propStr("DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE")],
        ["label" => "Подразделение","value" => $department],
        ["label" => "Руководитель", "value" => $ruk],
    ];

    $col2Data = [];

    // Premium type (enum)
    $premiaType = $propEnumName("PREMIALNAYA_CHAST_");

    // ISN
    $isn = $propNum("ISN_RUB_GROSS");

    // RK
    $rk = $propStr("RAYONNYY_KOEFFITSIENT");
    $rk_is_one = ($rk == "1");

    // Fixed salary
    $fix = $fmtRub($propNum("ZARABOTNAYA_PLATA_FIKSIROVANNAYA_CHAST_ZA_MESYATS_"));
    $col2Data[] = [
        "label"    => "Фиксированная часть за месяц*",
        "value"    => $fix,
        "footnote" => "Сумма до вычета НДФЛ"
    ];

    // Premium
    if ($premiaType == "Ежемесячно" || $premiaType == "Ежеквартально") {

        $premRub = $fmtRub($propNum("PREMIALNAYA_CHAST_RUB_GROSS"));
        $premPct = $propStr("PREMIALNAYA_CHAST_PREMII_OT_OKLADA") . "% от оклада";

        $premOut = $premRub . " / " . $premPct . " / " . $premiaType;

        $col2Data[] = [
            "label"    => "Премиальная часть по итогам работы за период до*",
            "value"    => $premOut,
            "footnote" => "Сумма до вычета НДФЛ"
        ];
    }

    // ISN
    if ($isn > 0) {
        $col2Data[] = [
            "label"    => "ИСН за месяц*",
            "value"    => $fmtRub($isn),
            "footnote" => "Сумма до вычета НДФЛ"
        ];
    }

    // RK
    if (!$rk_is_one) {
        $col2Data[] = [
            "label"    => "Региональный коэффициент",
            "value"    => $rk,
            "footnote" => ""
        ];
    }

    // Average income
    $dohod = $fmtRub($propNum("DOKHOD_V_MESYATS_V_SREDNEM_RUB_GROSS"));

    if (!$rk_is_one && $isn > 0)
        $labelAvg = "Доход в среднем в месяц, с учетом РК и ИСН до*";
    elseif (!$rk_is_one)
        $labelAvg = "Доход в среднем в месяц, с учетом РК до*";
    elseif ($isn > 0)
        $labelAvg = "Доход в среднем в месяц, с учетом ИСН до*";
    else
        $labelAvg = "Доход в среднем в месяц до*";

    $col2Data[] = [
        "label" => $labelAvg,
        "value" => $dohod,
        "footnote" => "Сумма до вычета НДФЛ"
    ];

    // Build column 3
    $col3Data = [
        [
            "label"  => "Испытательный срок",
            "label2" => "Испытательный\nсрок",
            "value"  => $propStr("ISPYTATELNYY_SROK_ESLI_VYBRANO_INOE_")
        ],
        [
            "label"  => "Планируемая дата выхода на работу",
            "label2" => "Планируемая дата\nвыхода на работу",
            "value"  => $propStr("PLANIRUEMAYA_DATA_VYKHODA_NA_RABOTU")
        ],
        [
            "label"  => "Тип договора",
            "label2" => "Тип договора",
            "value"  => $propEnumName("DOGOVOR_S_SOTRUDNIKOM_LINK")
        ],
        [
            "label"  => "Согласно политикам Компании",
            "label2" => "Согласно политикам Компании",
            "value"  => $propStr("SOGLASNO_POLITIKAM_KOMPANII")
        ],
        [
            "label"  => "Офис и формат работы",
            "label2" => "Офис и формат работы",
            "value"  => $propEnumName("ADRES_OFISA_LST") . "\n" . $propEnumName("FORMAT_RABOTY_NEW")
        ],
        [
            "label"  => "График работы,\nначало рабочего дня,\nналичие гибкого часа",
            "label2" => "График работы, начало рабочего дня",
            "value"  => $propEnumName("GRAFIK_RABOTY_NEW") . ", " . $propEnumName("NACHALO_RABOCHEGO_DNYA_NEW")
        ],
        [
            "label"  => "Оборудование для работы",
            "label2" => "Оборудование для работы (сотрудника или работодателя)",
            "value"  => offerSecureNormalizeEquipment($propEnumName("OBORUDOVANIE_DLYA_RABOTY_LINK"))
        ],
    ];


    $rows = [
        ['label' => 'ФИО кандидата', 'value' => $CANDIDATE_FIO],
        ['label' => 'Телефон кандидата', 'value' => $CANDIDATE_PHONE],
        ['label' => 'Планируемая дата отправки оффера', 'value' => $DATE_SEND],
        ['label' => 'Юридическое лицо', 'value' => $propEnumName('YURIDICHESKOE_LITSO')],
    ];
    $rows = array_merge($rows, $col1Data, [
        ['section' => 'Заработная плата'],
    ], $col2Data, [
        ['label' => 'Примечание', 'value' => '*Суммы до вычета НДФЛ. С 01.01.2025 вступил в силу закон о прогрессивной шкале НДФЛ, где ставка НДФЛ может меняться от 13% до 22%.'],
        ['section' => 'Условия'],
    ], $col3Data, [
        ['label' => 'Срок действия предложения', 'value' => 'Условия данного предложения действуют в течение 3 календарных дней. Ждем вашего ответа!'],
        ['section' => 'Рекрутер'],
        ['label' => 'ФИО рекрутера', 'value' => trim(($recruiter['LAST_NAME'] ?? '') . ' ' . ($recruiter['NAME'] ?? ''))],
        ['label' => 'Должность рекрутера', 'value' => trim($recruiter['WORK_POSITION'] ?? '')],
        ['label' => 'Телефон рекрутера', 'value' => trim($recruiter['PERSONAL_MOBILE'] ?? '')],
        ['label' => 'Email рекрутера', 'value' => trim($recruiter['EMAIL'] ?? '')],
    ]);
    if (!$isTelemagOffer) {
        $rows[] = ['label' => 'Сайт компании', 'value' => 'https://tricolor.ru/'];
    }
    return $rows;
}

function offerSecureNormalizeEquipment($text)
{
    $text = trim($text);

    if (stripos($text, "Оборудование сотрудника") !== false)
        return "Оборудование сотрудника";

    if (stripos($text, "Стационарный ПК в офисе и личное оборудование") !== false)
        return "Стационарный ПК в офисе";

    if (stripos($text, "Стационарный ПК в офисе") !== false)
        return "Стационарный ПК в офисе";

    if (stripos($text, "Корпоративный ноутбук") !== false)
        return "Корпоративный ноутбук";

    return $text;
}

/** Build only an encrypted PDF; no unprotected intermediate file is written. */
function offerSecurePdf(array $rows, int $offerId, string $password = 'test'): TCPDF
{
    if ($password === '') {
        throw new InvalidArgumentException('Пароль на открытие PDF не может быть пустым');
    }
    if (!extension_loaded('openssl') || !in_array('aes-256-cbc', openssl_get_cipher_methods(), true)) {
        throw new RuntimeException('Для AES-256 требуется PHP OpenSSL с aes-256-cbc');
    }

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetTitle('Оффер №' . $offerId);
    // Mode 3 is AES-256. A random owner password cannot bypass the user password.
    $pdf->SetProtection([], $password, bin2hex(random_bytes(32)), 3);
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->AddPage();

    $escape = static function ($text) {
        return nl2br(htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    };
    $html = '<h2>Предложение о работе — оффер №' . $offerId . '</h2>'
        . '<p>Строго конфиденциально</p>';
    foreach ($rows as $row) {
        if (isset($row['section'])) {
            $html .= '<h3>' . $escape($row['section']) . '</h3>';
            continue;
        }
        $value = trim((string)$row['value']);
        $html .= '<p><b>' . $escape(preg_replace('/\s+/u', ' ', $row['label'])) . ':</b> '
            . ($value !== '' ? $escape($value) : '—');
        if (!empty($row['footnote'])) {
            $html .= '<br /><small>' . $escape($row['footnote']) . '</small>';
        }
        $html .= '</p>';
    }
    $pdf->writeHTML($html, true, false, true, false, '');
    return $pdf;
}


