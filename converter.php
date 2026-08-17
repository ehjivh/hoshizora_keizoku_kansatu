<?php

/**
 * 星空の明るさ調査 GeoJSON変換プログラム
 * 
 * 環境省が実施する「星空の明るさ調査」のデータ（CSV形式）を読み込み、
 * GeoJSON形式に変換・集計する。
 */

class StarrySkyDataConverter
{
	private string $inputDir;
	private string $outputDir;
	private array $rawContinuousData = [];  // [地点キー => [調査時期 => [観測データ1, 観測データ2...]]]
	private array $generalData = [];        // [観測データ1, 観測データ2, ...]

	/**
	 * コンストラクタ
	 */
	public function __construct(string $inputDir, string $outputDir = null)
	{
		$this->inputDir = rtrim($inputDir, '/\\');
		$this->outputDir = $outputDir ?? $this->inputDir;

		if (!is_dir($this->inputDir)) {
			throw new RuntimeException("入力ディレクトリが存在しません: {$this->inputDir}");
		}

		if (!is_dir($this->outputDir)) {
			mkdir($this->outputDir, 0755, true);
		}
	}

	/**
	 * メイン処理
	 */
	public function execute(): void
	{
		echo "=== 星空の明るさ調査 GeoJSON変換プログラム ===\n";
		echo "入力ディレクトリ: {$this->inputDir}\n";
		echo "出力ディレクトリ: {$this->outputDir}\n\n";

		// ステップ1: CSVファイルを走査
		$files = $this->scanCsvFiles();
		if (empty($files)) {
			echo "警告: result_*.csv ファイルが見つかりません\n";
			return;
		}

		echo "見つかったファイル数: " . count($files) . "\n";

		// ステップ2: ファイル処理
		foreach ($files as $filePath => $period) {
			echo "処理中: {$period}\n";
			$this->processFile($filePath, $period);
		}

		// ステップ3: 継続観察地点データの集計
		echo "\n継続観察地点データを集計中...\n";
		$continuousFeatures = $this->aggregateContinuousData();

		// ステップ4: GeoJSON生成・出力
		echo "GeoJSON出力中...\n";
		$this->exportGeoJson('continuous_points.geojson', $continuousFeatures);
		$this->exportGeoJson('general_points.geojson', $this->generalData);

		echo "処理完了！\n";
		echo "出力ファイル:\n";
		echo "  - continuous_points.geojson (継続観察登録地点用) - " . count($continuousFeatures) . " 地点\n";
		echo "  - general_points.geojson (一般地点用) - " . count($this->generalData) . " 観測\n";
	}

	/**
	 * CSVファイルを走査し、period => filePath の連想配列を返す
	 */
	private function scanCsvFiles(): array
	{
		$files = [];
		$pattern = '/^result_(\d{4}_[SW])\.csv$/';

		$iterator = new FilesystemIterator($this->inputDir, FilesystemIterator::SKIP_DOTS);

		foreach ($iterator as $file) {
			if ($file->isFile()) {
				$filename = $file->getFilename();
				if (preg_match($pattern, $filename, $matches)) {
					$period = $matches[1];
					$files[$file->getRealPath()] = $period;
				}
			}
		}

		// period順にソート
		asort($files);
		return $files;
	}

	/**
	 * CSVファイルを読み込みデータを分類
	 */
	private function processFile(string $filePath, string $period): void
	{
		// 一時ファイルにUTF-8変換したCSVを保存
		$file = fopen($filePath, 'rb');
		if (!$file) {
			throw new RuntimeException("ファイルを開けません: {$filePath}");
		}

		// ファイル全体を読み込み
		$contents = stream_get_contents($file);
		fclose($file);

		// 文字エンコーディングの判別と変換
		$encoding = $this->detectEncoding($contents);
		$contents = mb_convert_encoding($contents, 'UTF-8', $encoding);

		// 一時ファイルに書き込み
		$tmpFile = tempnam(sys_get_temp_dir(), 'csv_');
		file_put_contents($tmpFile, $contents);

		try {
			// CSVを正しくパース（fgetcsv使用）
			$handle = fopen($tmpFile, 'r');
			if (!$handle) {
				throw new RuntimeException("一時ファイルを開けません: {$tmpFile}");
			}

			// ヘッダ行を読み込み
			$header = fgetcsv($handle);
			if ($header === false) {
				throw new RuntimeException("ヘッダ行を読み込めません");
			}

			// ヘッダを正規化
			$header = $this->normalizeHeader($header);

			// データ行を処理
			$lineNum = 1;
			while (($row = fgetcsv($handle)) !== false) {
				$lineNum++;

				if (empty($row[0])) {
					continue;
				}

				if (count($row) < count($header)) {
					// ヘッダ数とカラム数が合わない場合は詰める
					while (count($row) < count($header)) {
						$row[] = '';
					}
				}

				// キーとデータを結合
				$data = array_combine($header, array_slice($row, 0, count($header)));
				if ($data === false) {
					continue;
				}

				// 必須フィールドの確認
				$lat = trim($data['緯度'] ?? '');
				$lon = trim($data['経度'] ?? '');

				if (empty($lat) || empty($lon)) {
					continue;
				}

				try {
					$lat = floatval($lat);
					$lon = floatval($lon);
				} catch (Exception $e) {
					continue;
				}

				// 地点キーを生成（小数第3位で丸め）
				$locKey = $this->generateLocationKey($lat, $lon);

				// 継続観察登録地点かどうかを判定
				$isContinuous = $this->isContinuousPoint($data['継続観察登録地点'] ?? '');

				// データを準備
				$observationData = [
					'緯度' => $lat,
					'経度' => $lon,
					'調査時期' => $period,
					'都道府県' => trim($data['都道府県'] ?? ''),
					'市区町村' => trim($data['市区町村'] ?? ''),
					'撮影場所' => trim($data['撮影場所'] ?? ''),
					'撮影日時' => trim($data['撮影日時'] ?? ''),
					'夜空の明るさ' => floatval($data['夜空の明るさ'] ?? 0),
					'ばらつき' => floatval($data['ばらつき'] ?? 0),
				];

				if ($isContinuous) {
					// 継続観察地点
					if (!isset($this->rawContinuousData[$locKey])) {
						$this->rawContinuousData[$locKey] = [];
					}
					if (!isset($this->rawContinuousData[$locKey][$period])) {
						$this->rawContinuousData[$locKey][$period] = [];
					}
					$this->rawContinuousData[$locKey][$period][] = $observationData;
				} else {
					// 一般地点
					$this->generalData[] = $observationData;
				}
			}

			fclose($handle);
		} finally {
			// 一時ファイルを削除
			if (file_exists($tmpFile)) {
				unlink($tmpFile);
			}
		}
	}

	/**
	 * ヘッダを正規化
	 */
	private function normalizeHeader(array $header): array
	{
		$normalized = [];

		foreach ($header as $col) {
			$col = trim($col);

			// 複数行フィールドの場合、改行を含める
			$col = str_replace(["\r\n", "\n", "\r"], "", $col);

			// 正規化マッピング
			$mappings = [
				'/^No\.?$/' => 'No',
				'/^継続観察.*登録地点$/' => '継続観察登録地点',
				'/^夜空の.*明るさ/' => '夜空の明るさ',
			];

			$mapped = false;
			foreach ($mappings as $pattern => $replacement) {
				if (preg_match($pattern, $col)) {
					$normalized[] = $replacement;
					$mapped = true;
					break;
				}
			}

			if (!$mapped) {
				$normalized[] = $col;
			}
		}

		return $normalized;
	}

	/**
	 * 文字エンコーディングを判別
	 */
	private function detectEncoding(string $contents): string
	{
		// BOM チェック
		if (strpos($contents, "\xef\xbb\xbf") === 0) {
			return 'UTF-8';
		}

		// mb_detect_encoding を使用（複数の候補を指定）
		$detected = mb_detect_encoding($contents, ['UTF-8', 'SJIS', 'EUC-JP'], true);

		return $detected ?: 'SJIS';  // デフォルトは Shift-JIS
	}

	/**
	 * 地点キーを生成（緯度,経度）
	 */
	private function generateLocationKey(float $lat, float $lon): string
	{
		// 小数第3位で丸める
		$lat = round($lat, 3);
		$lon = round($lon, 3);
		return "{$lat},{$lon}";
	}

	/**
	 * 継続観察登録地点かどうかを判定
	 */
	private function isContinuousPoint(string $value): bool
	{
		$value = trim($value);
		return $value === '○' || $value === '〇';
	}

	/**
	 * 継続観察地点データを集計
	 */
	private function aggregateContinuousData(): array
	{
		$features = [];

		foreach ($this->rawContinuousData as $locKey => $periodData) {
			// 最初のデータから地点情報を取得
			$firstData = reset($periodData)[0];

			$timeSeries = [];

			// 調査時期ごとに平均化
			foreach ($periodData as $period => $observations) {
				if (empty($observations)) {
					continue;
				}

				$sumBrightness = 0;
				$sumVariance = 0;

				foreach ($observations as $obs) {
					$sumBrightness += $obs['夜空の明るさ'];
					$sumVariance += $obs['ばらつき'];
				}

				$count = count($observations);

				$timeSeries[] = [
					'調査時期' => $period,
					'観測回数' => $count,
					'夜空の明るさ_平均' => round($sumBrightness / $count, 2),
					'ばらつき_平均' => round($sumVariance / $count, 2),
				];
			}

			// 時系列データを調査時期でソート（新しい順）
			usort($timeSeries, function ($a, $b) {
				return strcmp($b['調査時期'], $a['調査時期']);
			});

			$feature = [
				'type' => 'Feature',
				'geometry' => [
					'type' => 'Point',
					'coordinates' => [
						floatval($firstData['経度']),
						floatval($firstData['緯度']),
					],
				],
				'properties' => [
					'都道府県' => $firstData['都道府県'],
					'市区町村' => $firstData['市区町村'],
					'撮影場所' => $firstData['撮影場所'] ?: null,
					'時系列データ' => $timeSeries,
				],
			];

			$features[] = $feature;
		}

		return $features;
	}

	/**
	 * GeoJSONをファイルに出力
	 */
	private function exportGeoJson(string $filename, array $features): void
	{
		// 一般地点用: Feature に変換
		if ($filename === 'general_points.geojson') {
			$featureArray = [];
			foreach ($features as $data) {
				$feature = [
					'type' => 'Feature',
					'geometry' => [
						'type' => 'Point',
						'coordinates' => [
							floatval($data['経度']),
							floatval($data['緯度']),
						],
					],
					'properties' => [
						'調査時期' => $data['調査時期'],
						'都道府県' => $data['都道府県'],
						'市区町村' => $data['市区町村'],
						'撮影場所' => $data['撮影場所'] ?: null,
						'撮影日時' => $data['撮影日時'] ?: null,
						'夜空の明るさ' => $data['夜空の明るさ'],
						'ばらつき' => $data['ばらつき'],
					],
				];
				$featureArray[] = $feature;
			}
			$features = $featureArray;
		}

		$geoJson = [
			'type' => 'FeatureCollection',
			'features' => $features,
		];

		$filePath = $this->outputDir . DIRECTORY_SEPARATOR . $filename;
		$json = json_encode($geoJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if (file_put_contents($filePath, $json) === false) {
			throw new RuntimeException("ファイル書き込みに失敗: {$filePath}");
		}
	}
}

// ===== 実行 =====
if (php_sapi_name() !== 'cli') {
	header('Content-Type: text/plain; charset=utf-8');
}

try {
	$converter = new StarrySkyDataConverter(__DIR__);
	$converter->execute();
} catch (Exception $e) {
	echo "エラー: " . $e->getMessage() . "\n";
	exit(1);
}
