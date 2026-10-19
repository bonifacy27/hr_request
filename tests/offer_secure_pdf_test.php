<?php
require_once __DIR__ . '/../offer/offer_secure_pdf.php';

function checkOffer($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rowsByLabel($rows) {
    $result = [];
    foreach ($rows as $row) {
        if (isset($row['label'])) {
            $result[$row['label']] = $row['value'];
        }
    }
    return $result;
}

$props = [];
foreach ([
    'POLNOE_FIO_KANDIDATA' => 'Иванов Иван Иванович',
    'KONTAKTNYY_TELEFON_KANDIDATA_7_' => '+7 900 000-00-00',
    'PLANIRUEMAYA_DATA_OTPRAVKI_OFFERA_KANDIDATU' => '2026-10-14',
    'DOLZHNOST_ESLI_OTSUTSTVUET_V_SPISKE' => 'Разработчик PHP',
    'POZDRAZDELENIE_ESLI_OTSUTSTVUET_V_SPISKE' => 'Отдел разработки',
    'FIO_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE' => 'Петров Петр',
    'DOLZHNOST_RUKOVODITELYA_ESLI_OTSUTSTVUET_V_SPISKE' => 'Руководитель отдела',
    'PREMIALNAYA_CHAST_' => 10,
    'ZARABOTNAYA_PLATA_FIKSIROVANNAYA_CHAST_ZA_MESYATS_' => 150000,
    'PREMIALNAYA_CHAST_RUB_GROSS' => 30000,
    'PREMIALNAYA_CHAST_PREMII_OT_OKLADA' => 20,
    'ISN_RUB_GROSS' => 10000,
    'RAYONNYY_KOEFFITSIENT' => '1.2',
    'DOKHOD_V_MESYATS_V_SREDNEM_RUB_GROSS' => 226000,
    'ISPYTATELNYY_SROK_ESLI_VYBRANO_INOE_' => '3 месяца',
    'PLANIRUEMAYA_DATA_VYKHODA_NA_RABOTU' => '01.11.2026',
    'DOGOVOR_S_SOTRUDNIKOM_LINK' => 11,
    'SOGLASNO_POLITIKAM_KOMPANII' => 'ДМС после испытательного срока',
    'ADRES_OFISA_LST' => 12,
    'FORMAT_RABOTY_NEW' => 13,
    'GRAFIK_RABOTY_NEW' => 14,
    'NACHALO_RABOCHEGO_DNYA_NEW' => 15,
    'OBORUDOVANIE_DLYA_RABOTY_LINK' => 16,
] as $code => $value) {
    $props[$code] = ['VALUE' => $value];
}
$recruiter = ['LAST_NAME' => 'Сидорова', 'NAME' => 'Анна',
    'WORK_POSITION' => 'Рекрутер', 'PERSONAL_MOBILE' => '+7 900 000-00-01',
    'EMAIL' => 'recruiter@example.com'];
$names = [10 => 'Ежемесячно', 11 => 'Трудовой договор', 12 => 'Москва',
    13 => 'Гибридный', 14 => '5/2', 15 => '09:00, гибкий час',
    16 => 'Корпоративный ноутбук', 3339193 => 'ООО «Семейный магазин»'];
$resolver = static function ($id) use (&$names) { return $names[$id] ?? ''; };
$rows = offerSecureRows($props, $recruiter, $resolver);
$values = rowsByLabel($rows);
checkOffer($values['Фиксированная часть за месяц*'] === '150 000 рублей', 'Фиксированная зарплата');
checkOffer($values['Премиальная часть по итогам работы за период до*'] === '30 000 рублей / 20% от оклада / Ежемесячно', 'Премия');
checkOffer($values['ИСН за месяц*'] === '10 000 рублей', 'ИСН');
checkOffer($values['Доход в среднем в месяц, с учетом РК и ИСН до*'] === '226 000 рублей', 'РК и ИСН в подписи дохода');
checkOffer($values['Руководитель'] === "Петров Петр\nРуководитель отдела", 'ФИО и должность руководителя');
checkOffer($values['Email рекрутера'] === 'recruiter@example.com', 'Контакты рекрутера');

$names[10] = 'Ежеквартально';
checkOffer(strpos(rowsByLabel(offerSecureRows($props, $recruiter, $resolver))['Премиальная часть по итогам работы за период до*'], 'Ежеквартально') !== false, 'Квартальная премия');
$names[10] = 'Иное';
$props['ISN_RUB_GROSS']['VALUE'] = 0;
$props['RAYONNYY_KOEFFITSIENT']['VALUE'] = '1';
$props['YURIDICHESKOE_LITSO']['VALUE'] = 3339193;
$minimal = rowsByLabel(offerSecureRows($props, [], $resolver));
checkOffer(!isset($minimal['Премиальная часть по итогам работы за период до*']), 'Другой тип премии скрыт');
checkOffer(!isset($minimal['ИСН за месяц*']) && !isset($minimal['Региональный коэффициент']), 'Нулевой ИСН и РК=1 скрыты');
checkOffer(isset($minimal['Доход в среднем в месяц до*']), 'Подпись без РК и ИСН');
checkOffer(strpos($minimal['Подразделение'], 'Проект "Телемагазин"') !== false, 'Проект Телемагазин');
checkOffer(!isset($minimal['Сайт компании']), 'Ссылка исключена для Телемагазина');
checkOffer(count(offerSecureRows([], [], $resolver)) > 0, 'Отсутствующие необязательные поля');

// Optional integration check using the actual TCPDF installation.
if (getenv('TCPDF_TEST_PATH')) {
    require_once getenv('TCPDF_TEST_PATH');
    $destination = getenv('OFFER_TEST_PDF');
    checkOffer((bool)$destination, 'Задайте OFFER_TEST_PDF для интеграционной проверки');
    offerSecurePdf($rows, 12345, 'test')->Output($destination, 'F');
    $longRows = [['label' => 'Длинное значение <b>текст</b>', 'value' => str_repeat('Условия & подробности работы. ', 1500)]];
    offerSecurePdf($longRows, 12345, 'test')->Output($destination . '.long.pdf', 'F');
    try {
        offerSecurePdf($rows, 12345, '');
        throw new RuntimeException('Пустой пароль был принят');
    } catch (InvalidArgumentException $e) {
        // Expected: cannot silently generate a PDF without an opening password.
    }
}
echo "OK\n";
