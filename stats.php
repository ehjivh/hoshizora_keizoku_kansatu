<?php

/**
 * 地方区分のマッピング定義
 */
function getRegionMapping() {
    return [
        '北海道' => '北海道',
        '青森県' => '東北', '岩手県' => '東北', '宮城県' => '東北', '秋田県' => '東北', '山形県' => '東北', '福島県' => '東北',
        '茨城県' => '関東', '栃木県' => '関東', '群馬県' => '関東', '埼玉県' => '関東', '千葉県' => '関東', '東京都' => '関東', '神奈川県' => '関東',
        '新潟県' => '中部', '富山県' => '中部', '石川県' => '中部', '福井県' => '中部', '山梨県' => '中部', '長野県' => '中部', '岐阜県' => '中部', '静岡県' => '中部', '愛知県' => '中部',
        '三重県' => '近畿', '滋賀県' => '近畿', '京都府' => '近畿', '大阪府' => '近畿', '兵庫県' => '近畿', '奈良県' => '近畿', '和歌山県' => '近畿',
        '鳥取県' => '中国', '島根県' => '中国', '岡山県' => '中国', '広島県' => '中国', '山口県' => '中国',
        '徳島県' => '四国', '香川県' => '四国', '愛媛県' => '四国', '高知県' => '四国',
        '福岡県' => '九州・沖縄', '佐賀県' => '九州・沖縄', '長崎県' => '九州・沖縄', '熊本県' => '九州・沖縄', '大分県' => '九州・沖縄', '宮崎県' => '九州・沖縄', '鹿児島県' => '九州・沖縄', '沖縄県' => '九州・沖縄'
    ];
}

/**
 * 基本統計量の計算
 */
function calculateStatistics($values) {
    $count = count($values);
    if ($count === 0) return null;

    $mean = array_sum($values) / $count;

    $variance = 0.0;
    foreach ($values as $val) {
        $variance += pow($val - $mean, 2);
    }
    // 標本標準偏差
    $stdDev = sqrt($variance / $count);

    return [
        'count'  => $count,
        'mean'   => round($mean, 2),
        'stdDev' => round($stdDev, 2),
        'min'    => round(min($values), 2),
        'max'    => round(max($values), 2)
    ];
}

// 1. JSONファイルの読み込み
// スクリプトの実行ディレクトリから geojson/all_points.geojson を読み込む
$jsonFile = __DIR__ . '/geojson/all_points.geojson';
if (!file_exists($jsonFile)) {
    // 標準エラー出力にメッセージを出す（CSVデータに混入させないため）
    fwrite(STDERR, "エラー: {$jsonFile} が見つからない。\n");
    exit(1);
}
$jsonString = file_get_contents($jsonFile);
$data = json_decode($jsonString, true);

if (!isset($data['features'])) {
    fwrite(STDERR, "エラー: 有効なFeatureCollectionではない。\n");
    exit(1);
}

$regionMap = getRegionMapping();

$cityData = [];
$prefData = [];
$regionData = [];

// 2. データの集約処理
foreach ($data['features'] as $feature) {
    $props = $feature['properties'] ?? [];
    
    // プロパティ名の揺れに対応
    $pref = $props['都道府県'] ?? $props['都道府県名'] ?? null;
    $city = $props['市町村'] ?? $props['市町村名'] ?? null;
    $brightnessStr = $props['夜空の明るさ'] ?? null;

    // 欠損データ、および数値として評価できないデータの除外
    if (empty($pref) || empty($city) || $brightnessStr === '' || $brightnessStr === null || !is_numeric($brightnessStr)) {
        continue;
    }

    $val = (float)$brightnessStr;
    $region = $regionMap[$pref] ?? '不明';
    $cityKey = $pref . '_' . $city;

    $cityData[$cityKey][] = $val;
    $prefData[$pref][] = $val;
    $regionData[$region][] = $val;
}

// 3. 統計量の計算とCSV出力
$fp = fopen('php://output', 'w');

// BOMを付与（Excel等での文字化け防止のため）
fwrite($fp, "\xEF\xBB\xBF");

// CSVヘッダ出力
fputcsv($fp, ['集計区分', '地域名', 'データ数', '平均値', '標準偏差', '最小値', '最大値']);

function writeCsvStats($fp, $categoryName, $groupedData) {
    foreach ($groupedData as $key => $values) {
        $stats = calculateStatistics($values);
        if ($stats) {
            fputcsv($fp, [
                $categoryName,
                $key,
                $stats['count'],
                $stats['mean'],
                $stats['stdDev'],
                $stats['min'],
                $stats['max']
            ]);
        }
    }
}

// 地方別、県別、市町村別の順でCSVに書き出す
writeCsvStats($fp, '地方別', $regionData);
writeCsvStats($fp, '県別', $prefData);
writeCsvStats($fp, '市町村別', $cityData);

fclose($fp);

// 4. JSON形式での統計量出力
$jsonOutputData = [
    '地方別' => [],
    '県別' => [],
    '市町村別' => []
];

function buildJsonStats(&$jsonOutputData, $categoryName, $groupedData) {
    foreach ($groupedData as $key => $values) {
        $stats = calculateStatistics($values);
        if ($stats) {
            $jsonOutputData[$categoryName][$key] = $stats;
        }
    }
}

buildJsonStats($jsonOutputData, '地方別', $regionData);
buildJsonStats($jsonOutputData, '県別', $prefData);
buildJsonStats($jsonOutputData, '市町村別', $cityData);

$jsonOutputPath = __DIR__ . '/stats.json';
file_put_contents($jsonOutputPath, json_encode($jsonOutputData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// 5. GeoJSONへの統計量付与と出力
function outputGeoJsonWithStats($inputFile, $outputFile, $groupedData, $level) {
    if (!file_exists($inputFile)) {
        fwrite(STDERR, "警告: {$inputFile} が見つからないため、GeoJSON出力をスキップします。\n");
        return;
    }

    $jsonString = file_get_contents($inputFile);
    $geoJson = json_decode($jsonString, true);

    if (!isset($geoJson['features'])) {
        fwrite(STDERR, "警告: {$inputFile} は有効なFeatureCollectionではありません。\n");
        return;
    }

    foreach ($geoJson['features'] as &$feature) {
        $props = $feature['properties'] ?? [];
        $key = null;

        if ($level === 'pref') {
            $key = $props['N03_001'] ?? null;
        } elseif ($level === 'city') {
            $pref = $props['N03_001'] ?? '';
            $city = $props['N03_004'] ?? '';
            $county = $props['N03_003'] ?? '';
            
            // 観測データの市町村名表記の揺れに対応するため複数のキーを試行
            $keysToTry = [
                $pref . '_' . $city,
                $pref . '_' . $county . $city
            ];
            
            foreach ($keysToTry as $k) {
                if (isset($groupedData[$k])) {
                    $key = $k;
                    break;
                }
            }
        }

        if ($key && isset($groupedData[$key])) {
            $stats = calculateStatistics($groupedData[$key]);
            if ($stats) {
                $feature['properties']['count'] = $stats['count'];
                $feature['properties']['mean'] = $stats['mean'];
                $feature['properties']['stdDev'] = $stats['stdDev'];
                $feature['properties']['min'] = $stats['min'];
                $feature['properties']['max'] = $stats['max'];
            }
        }
    }

    // JSONとして保存（日本語のUnicodeエスケープ防止）
    $outputJsonString = json_encode($geoJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($outputJsonString === false) {
        fwrite(STDERR, "エラー: {$outputFile} のJSONエンコードに失敗しました。\n");
        return;
    }

    file_put_contents($outputFile, $outputJsonString);
}

// 実行ディレクトリ直下にある境界データを指定
$prefGeoJson = __DIR__ . '/prefectures.json';
$cityGeoJson = __DIR__ . '/N03-21_210101.json';

$outputPref = __DIR__ . '/geojson/stats_pref.geojson';
$outputCity = __DIR__ . '/geojson/stats_city.geojson';

outputGeoJsonWithStats($prefGeoJson, $outputPref, $prefData, 'pref');
outputGeoJsonWithStats($cityGeoJson, $outputCity, $cityData, 'city');

?>

