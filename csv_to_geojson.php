<?php

/**
 * 星空の明るさ調査 CSV to GeoJSON 変換プログラム
 * 
 * CSVファイルを読み込み、以下のGeoJSONを生成します。
 * 1. 各CSVファイルごとのGeoJSON
 * 2. 全データを結合した all_points.geojson
 * 3. 継続観察登録地点を集計した continuous_points.geojson
 */
class CsvToGeoJsonConverter
{
    /** @var string 入力となるCSVファイルが格納されているディレクトリパス */
    private string $inputDir;

    /** @var string 生成したGeoJSONファイルを保存するディレクトリパス */
    private string $outputDir;

    /** @var array 全てのCSVから読み込んだ全地点データを保持する配列 */
    private array $allFeatures = [];

    /** @var array 継続観察登録地点のデータを集計用に保持する多次元配列 [地点キー][調査時期][] = 観測データ */
    private array $rawContinuousData = [];

    /** @var DateTimeZone 日時パース処理に使用するタイムゾーン（Asia/Tokyo） */
    private DateTimeZone $timezone;

    /**
     * コンストラクタ
     *
     * @param string $baseDir ベースとなるディレクトリパス
     */
    public function __construct(string $baseDir)
    {
        $this->inputDir = rtrim($baseDir, '/\\') . '/csv';
        $this->outputDir = rtrim($baseDir, '/\\') . '/geojson';
        $this->timezone = new DateTimeZone('Asia/Tokyo');

        // 出力先ディレクトリが存在しない場合は作成
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * メイン処理を実行する
     *
     * ディレクトリ内のCSVファイルを検索し、個別のGeoJSON変換処理を行った後、
     * 結合ファイル(all_points.geojson)と継続観察地点集約ファイル(continuous_points.geojson)を出力します。
     */
    public function execute(): void
    {
        $files = glob($this->inputDir . '/*.csv');

        if (empty($files)) {
            echo "No CSV files found in the {$this->inputDir} directory.\n";
            return;
        }

        // 各CSVファイルを順番に処理
        foreach ($files as $file) {
            $this->processFile($file);
        }

        // 統合されたGeoJSONファイルの生成・出力
        $this->exportCombinedGeoJson();
        $this->exportContinuousGeoJson();
        echo "Done.\n";
    }

    /**
     * 1つのCSVファイルを読み込み、GeoJSON Featureの配列に変換してファイルへ保存する
     *
     * @param string $file 処理対象のCSVファイルパス
     */
    private function processFile(string $file): void
    {
        echo "Processing " . basename($file) . "...\n";

        $period = $this->extractPeriod(basename($file));
        $data = $this->readFileContents($file);
        if ($data === false) {
            echo "Failed to read $file\n";
            return;
        }

        // fgetcsvで正しくパースできるよう、メモリ上のストリームにデータを展開
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $data);
        rewind($stream);

        // ヘッダー行の取得と正規化
        $headers = fgetcsv($stream);
        if ($headers === false) {
            fclose($stream);
            return;
        }
        $headers = $this->normalizeHeaders($headers);
        [$latIndex, $lonIndex] = $this->findCoordinateIndices($headers);

        $features = [];
        // データ行を1行ずつパース
        while (($row = fgetcsv($stream)) !== false) {
            if (empty(array_filter($row))) {
                continue; // 空行をスキップ
            }

            // Featureオブジェクト（地点データ）を生成
            $feature = $this->createFeature($row, $headers, $latIndex, $lonIndex, $period);
            if ($feature !== null) {
                $features[] = $feature;
                $this->allFeatures[] = $feature; // 結合出力用に追加
                $this->collectContinuousData($feature, $period); // 継続観察地点集約用に追加
            }
        }
        fclose($stream);

        // 個別ファイルのGeoJSONを出力
        $outputFilename = preg_replace('/\.csv$/i', '.geojson', basename($file));
        $this->saveGeoJson($outputFilename, $features);
        echo "Saved " . count($features) . " features to {$outputFilename}\n";
    }

    /**
     * ファイル名から調査時期（例: 2018_S）を正規表現で抽出する
     *
     * @param string $filename 対象のファイル名
     * @return string 抽出した調査時期。見つからない場合は空文字。
     */
    private function extractPeriod(string $filename): string
    {
        if (preg_match('/^result_(\d{4}_[SW])\.csv$/i', $filename, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * ファイルの内容を読み込み、文字コードを判定してUTF-8に変換する
     * 
     * 日本語Windows環境由来のShift-JIS(SJIS-win)などで発生する文字化けを防ぎます。
     *
     * @param string $file 読み込むファイルパス
     * @return string|false UTF-8に変換されたファイル内容（BOM除去済み）。失敗時はfalse
     */
    private function readFileContents(string $file): string|false
    {
        $data = file_get_contents($file);
        if ($data === false) {
            return false;
        }

        $encoding = mb_detect_encoding($data, 'UTF-8, SJIS-win, eucJP-win, SJIS, EUC-JP', true);
        if ($encoding === false) {
            $encoding = 'SJIS-win';
        }
        if ($encoding !== 'UTF-8') {
            $data = mb_convert_encoding($data, 'UTF-8', $encoding);
        }

        // 先頭にBOM(Byte Order Mark)が含まれていれば削除
        return preg_replace('/^\xEF\xBB\xBF/', '', $data);
    }

    /**
     * CSVヘッダーの配列を受け取り、表記のブレや改行を正規化する
     *
     * @param array $headers 元のヘッダー配列
     * @return array 正規化されたヘッダー配列
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($h) {
            $h = trim(preg_replace('/[\r\n]+/', ' ', $h));
            // 表現の揺らぎを統一
            if (preg_match('/^No\.?$/', $h)) return 'No';
            if (preg_match('/^継続観察.*登録地点$/', $h)) return '継続観察登録地点';
            if (preg_match('/^夜空の.*明るさ/', $h)) return '夜空の明るさ';
            return $h;
        }, $headers);
    }

    /**
     * 緯度と経度が含まれるカラムのインデックス（列番号）を検索する
     *
     * @param array $headers ヘッダー配列
     * @return array [緯度のインデックス, 経度のインデックス]
     */
    private function findCoordinateIndices(array $headers): array
    {
        $latIndex = array_search('緯度', $headers);
        $lonIndex = array_search('経度', $headers);

        // 完全一致しない場合は部分一致で探索
        if ($latIndex === false) {
            foreach ($headers as $i => $h) {
                if (strpos($h, '緯度') !== false) {
                    $latIndex = $i;
                    break;
                }
            }
            if ($latIndex === false) $latIndex = 5; // 見つからない場合のフォールバック
        }

        if ($lonIndex === false) {
            foreach ($headers as $i => $h) {
                if (strpos($h, '経度') !== false) {
                    $lonIndex = $i;
                    break;
                }
            }
            if ($lonIndex === false) $lonIndex = 6; // 見つからない場合のフォールバック
        }

        return [$latIndex, $lonIndex];
    }

    /**
     * CSVの1行分のデータからGeoJSONのFeature（地点情報）オブジェクトを生成する
     *
     * @param array $row CSVの1行データ
     * @param array $headers 正規化済みのヘッダー
     * @param int $latIndex 緯度のカラムインデックス
     * @param int $lonIndex 経度のカラムインデックス
     * @param string $period 調査時期（例: 2018_S）
     * @return array|null 正常に生成できた場合はFeature配列、座標が無効な場合はnull
     */
    private function createFeature(array $row, array $headers, int $latIndex, int $lonIndex, string $period): ?array
    {
        $lat = isset($row[$latIndex]) ? trim($row[$latIndex]) : null;
        $lon = isset($row[$lonIndex]) ? trim($row[$lonIndex]) : null;

        // 緯度・経度が数値でない場合（無効な座標）は除外
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        $properties = [];
        if ($period !== '') {
            $properties['調査時期'] = $period;
        }

        // すべてのカラムをプロパティとして格納する
        foreach ($row as $i => $val) {
            $key = isset($headers[$i]) && $headers[$i] !== '' ? $headers[$i] : "Column_$i";
            if ($key === '撮影日時') {
                $val = $this->formatToIso8601($val, $period);
            }
            $properties[$key] = $val;
        }

        return [
            "type" => "Feature",
            "geometry" => [
                "type" => "Point",
                "coordinates" => [(float)$lon, (float)$lat]
            ],
            "properties" => $properties
        ];
    }

    /**
     * 様々な形式の日時文字列を ISO 8601 規格（ATOM形式）に変換する
     * 
     * 「年」が欠損している古いデータ（例: 8月2日 9:01 PM）については、調査時期情報から
     * 年を補完した上でパース処理を行います。
     *
     * @param string $dateStr 対象の日時文字列
     * @param string $period 調査時期（年の補完に使用）
     * @return string 変換後のISO 8601日時文字列。パース不可能な場合は元の文字列
     */
    private function formatToIso8601(string $dateStr, string $period): string
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') {
            return '';
        }

        // 例: "8月2日 9:01 PM" のように年抜け・AM/PM表記の形式をパース
        if (preg_match('/^(\d+)月(\d+)日\s+(\d+):(\d+)\s+(AM|PM)$/i', $dateStr, $m)) {
            $year = substr($period, 0, 4); // "2018_S" から "2018" を取得
            $month = $m[1];
            $day = $m[2];
            $hour = (int)$m[3];
            $min = $m[4];

            // 24時間表記に変換
            if (strtoupper($m[5]) === 'PM' && $hour < 12) $hour += 12;
            if (strtoupper($m[5]) === 'AM' && $hour == 12) $hour = 0;

            $dateStr = sprintf("%04d-%02d-%02d %02d:%02d:00", $year, $month, $day, $hour, $min);
        }

        // DateTimeオブジェクトを使用してISO 8601にパース
        try {
            $dt = new DateTime($dateStr, $this->timezone);
            return $dt->format(DateTime::ATOM);
        } catch (Exception $e) {
            return $dateStr; // 変換エラー時は元の文字列をそのまま返す
        }
    }

    /**
     * 継続観察登録地点のデータを集計用配列（$rawContinuousData）に格納する
     * 
     * 同一の場所であるかを判定するため、緯度・経度を小数第3位で丸めた値を「地点キー」としてグループ化します。
     *
     * @param array $feature 生成されたGeoJSON Feature配列
     * @param string $period 調査時期
     */
    private function collectContinuousData(array $feature, string $period): void
    {
        $properties = $feature['properties'];
        $isContinuous = false;

        // "継続観察登録地点"が「○」または「〇」であるか判定
        if (isset($properties['継続観察登録地点'])) {
            $cVal = trim($properties['継続観察登録地点']);
            if ($cVal === '○' || $cVal === '〇') {
                $isContinuous = true;
            }
        }

        if ($isContinuous) {
            $coords = $feature['geometry']['coordinates'];
            // 緯度・経度をそれぞれ小数第3位（約100m精度）で丸めてキーにする
            $locKey = round($coords[1], 3) . ',' . round($coords[0], 3);

            if (!isset($this->rawContinuousData[$locKey])) {
                $this->rawContinuousData[$locKey] = [];
            }
            if (!isset($this->rawContinuousData[$locKey][$period])) {
                $this->rawContinuousData[$locKey][$period] = [];
            }
            // 指定された地点・時期の配列にプロパティを蓄積
            $this->rawContinuousData[$locKey][$period][] = $properties;
        }
    }

    /**
     * 蓄積された全ての地点データを出力する (all_points.geojson)
     */
    private function exportCombinedGeoJson(): void
    {
        if (empty($this->allFeatures)) {
            return;
        }

        echo "\nGenerating all_points.geojson with " . count($this->allFeatures) . " features...\n";
        $this->saveGeoJson('all_points.geojson', $this->allFeatures);
        echo "Saved combined features to all_points.geojson\n";
    }

    /**
     * 継続観察登録地点ごとの平均値を算出し、時系列データとして出力する (continuous_points.geojson)
     * 
     * 備考欄に記載がある異常データ（天候不良など）は平均の算出から除外されます。
     */
    private function exportContinuousGeoJson(): void
    {
        $continuousFeatures = [];

        foreach ($this->rawContinuousData as $locKey => $periodData) {
            // 代表的なメタデータとして、蓄積された最初のデータを使用
            $firstData = reset($periodData)[0];
            $timeSeries = [];

            // 調査時期ごとの集計計算
            foreach ($periodData as $p => $observations) {
                if (empty($observations)) continue;

                $sumBrightness = 0;
                $sumVariance = 0;
                $validCount = 0;

                foreach ($observations as $obs) {
                    // 備考に文字が入っている場合は例外的な状況とみなし、計算から除外する
                    $bikou = trim($obs['備考'] ?? '');
                    if ($bikou !== '') {
                        continue;
                    }

                    $sumBrightness += (float)($obs['夜空の明るさ'] ?? 0);
                    $sumVariance += (float)($obs['ばらつき'] ?? 0);
                    $validCount++;
                }

                // 有効なデータが1つもなければこの時期の記録は生成しない
                if ($validCount === 0) continue;

                $timeSeries[] = [
                    '調査時期' => $p,
                    '観測回数' => $validCount,
                    '夜空の明るさ_平均' => round($sumBrightness / $validCount, 2),
                    'ばらつき_平均' => round($sumVariance / $validCount, 2)
                ];
            }

            // 全ての時期で有効データがなかった場合は地点自体を除外
            if (empty($timeSeries)) continue;

            // 時系列を新しい順にソート（文字列の降順）
            usort($timeSeries, function ($a, $b) {
                return strcmp($b['調査時期'], $a['調査時期']);
            });

            // 集約されたFeatureデータを構築
            $continuousFeatures[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [
                        (float)($firstData['経度']),
                        (float)($firstData['緯度']),
                    ],
                ],
                'properties' => [
                    '都道府県' => $firstData['都道府県'] ?? null,
                    '市区町村' => $firstData['市区町村'] ?? ($firstData['市町村'] ?? null),
                    '撮影場所' => $firstData['撮影場所'] ?: null,
                    '時系列データ' => $timeSeries,
                ],
            ];
        }

        if (!empty($continuousFeatures)) {
            echo "Generating continuous_points.geojson with " . count($continuousFeatures) . " features...\n";
            $this->saveGeoJson('continuous_points.geojson', $continuousFeatures);
            echo "Saved continuous points to continuous_points.geojson\n";
        }
    }

    /**
     * Feature配列をGeoJSONファイルとして保存する
     *
     * @param string $filename 出力するファイル名（例: all_points.geojson）
     * @param array $features GeoJSONに書き込むFeature配列
     */
    private function saveGeoJson(string $filename, array $features): void
    {
        $geojson = [
            "type" => "FeatureCollection",
            "features" => $features
        ];

        $filePath = $this->outputDir . '/' . $filename;
        // JSON出力: ユニコードエスケープせず、見やすく整形し、スラッシュをエスケープしない
        file_put_contents($filePath, json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// ===== 実行 =====
$converter = new CsvToGeoJsonConverter(__DIR__);
$converter->execute();