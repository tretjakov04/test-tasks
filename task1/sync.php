<?php

declare(strict_types=1);

function parse1cNumber(string $value): float {
    $value = str_replace([' ', "\xC2\xA0"], '', $value);
    $value = str_replace(',', '.', $value);
    return (float) $value;
}

$nomenclature = [];
$report = [];

// Чтение номенклатуры 1С
if (($handle = fopen(__DIR__ . "/data/nomenclature_1c.csv", "r")) !== false) {
    fgetcsv($handle, 1000, ";"); // Пропускаем заголовок
    while (($data = fgetcsv($handle, 1000, ";")) !== false) {
        if (count($data) < 6) continue;

        $rawSku = $data[1];
        $sku = trim(mb_strtolower($rawSku));

        // Поиск дублей в базе 1С
        if (isset($nomenclature[$sku])) {
            $report[] = ['БАЗА 1С', $rawSku, 'Дубль в 1С', 'Артикул встречается в выгрузке несколько раз'];
            continue;
        }

        $nomenclature[$sku] = [
            'price' => parse1cNumber($data[4]), // Индекс 4 - Цена
            'stock' => (int) parse1cNumber($data[5])  // Индекс 5 - Остаток
        ];
    }
    fclose($handle);
}

// Чтение заказов
$json = file_get_contents(__DIR__ . "/data/orders.json");
$ordersData = json_decode($json, true);
$orders = $ordersData['data']['orders'] ?? [];

$processedOrderIds = [];
$stockDemands = [];

// Обработка заказов
foreach ($orders as $order) {
    $orderId = $order['id'];

    if (in_array($orderId, $processedOrderIds)) {
        $report[] = [$orderId, '-', 'Дубликат заказа', 'Заказ проигнорирован'];
        continue;
    }
    $processedOrderIds[] = $orderId;

    if ($order['status'] === 'cancelled') {
        $report[] = [$orderId, '-', 'Заказ отменен', 'Пропускаем сверку'];
        continue;
    }

    if (empty($order['items'])) {
        $report[] = [$orderId, '-', 'Пустой заказ', 'Нет товаров в заказе'];
        continue;
    }

    foreach ($order['items'] as $item) {
        $rawSku = $item['sku'] ?? '';
        $sku = trim(mb_strtolower($rawSku));
        $qty = (int) ($item['quantity'] ?? 0);
        $orderPrice = isset($item['price']) ? (float) $item['price'] : null;

        if ($orderPrice === null) {
            $report[] = [$orderId, $rawSku, 'Отсутствует цена', 'В API нет цены товара'];
            continue;
        }

        if (!isset($nomenclature[$sku])) {
            $report[] = [$orderId, $rawSku, 'Артикул не найден', 'Товар отсутствует в 1С'];
            continue;
        }

        $c1Data = $nomenclature[$sku];

        // Сверка цены
        if (abs($c1Data['price'] - $orderPrice) > 0.005) {
            $report[] = [$orderId, $rawSku, 'Расхождение цены', "Цена 1С: {$c1Data['price']}, Цена МП: {$orderPrice}"];
        }

        // Подсчет требуемого остатка
        if (!isset($stockDemands[$sku])) {
            $stockDemands[$sku] = 0;
        }
        $stockDemands[$sku] += $qty;

        // Сверка остатка
        if ($stockDemands[$sku] > $c1Data['stock']) {
            $report[] = [$orderId, $rawSku, 'Нехватка остатка', "Требуется: {$stockDemands[$sku]}, Доступно: {$c1Data['stock']}"];
        }
    }
}

// Вывод отчета
echo mb_str_pad("ЗАКАЗ", 15) . " | " . mb_str_pad("АРТИКУЛ", 12) . " | " . mb_str_pad("ТИП ПРОБЛЕМЫ", 20) . " | ОПИСАНИЕ\n";
echo str_repeat("-", 90) . "\n";
foreach ($report as $row) {
    echo mb_str_pad($row[0], 15) . " | " . mb_str_pad($row[1], 12) . " | " . mb_str_pad($row[2], 20) . " | " . $row[3] . "\n";
}