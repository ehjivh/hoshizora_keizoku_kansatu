import os
import glob
import csv
import json
import re
from datetime import datetime, timezone, timedelta
from collections import defaultdict
import io

class CsvToGeoJsonConverter:
    """
    星空の明るさ調査 CSV to GeoJSON 変換プログラム (Python版)
    """

    def __init__(self, base_dir):
        self.input_dir = os.path.join(base_dir, 'csv')
        self.output_dir = os.path.join(base_dir, 'geojson')
        self.all_features = []
        self.raw_continuous_data = defaultdict(lambda: defaultdict(list))
        self.jst = timezone(timedelta(hours=9))

        if not os.path.exists(self.output_dir):
            os.makedirs(self.output_dir)

    def execute(self):
        files = glob.glob(os.path.join(self.input_dir, '*.csv'))
        if not files:
            print(f"No CSV files found in the {self.input_dir} directory.")
            return

        for file in files:
            self.process_file(file)

        self.export_combined_geojson()
        self.export_continuous_geojson()
        print("Done.")

    def process_file(self, file_path):
        filename = os.path.basename(file_path)
        print(f"Processing {filename}...")

        period = self.extract_period(filename)
        data = self.read_file_contents(file_path)
        if data is None:
            print(f"Failed to read {file_path}")
            return

        stream = io.StringIO(data)
        reader = csv.reader(stream)
        
        try:
            headers = next(reader)
        except StopIteration:
            return

        headers = self.normalize_headers(headers)
        lat_idx, lon_idx = self.find_coordinate_indices(headers)

        features = []
        for row in reader:
            if not any(row):
                continue

            feature = self.create_feature(row, headers, lat_idx, lon_idx, period)
            if feature is not None:
                features.append(feature)
                self.all_features.append(feature)
                self.collect_continuous_data(feature, period)

        output_filename = re.sub(r'(?i)\.csv$', '.geojson', filename)
        self.save_geojson(output_filename, features)
        print(f"Saved {len(features)} features to {output_filename}")

    def extract_period(self, filename):
        match = re.search(r'^result_(\d{4}_[SW])\.csv$', filename, re.IGNORECASE)
        if match:
            return match.group(1)
        return ''

    def read_file_contents(self, file_path):
        # 日本語環境に配慮し、utf-8-sig (BOM付き対応), utf-8, cp932 (Shift-JIS), euc_jp の順でデコードを試みる
        encodings = ['utf-8-sig', 'utf-8', 'cp932', 'euc_jp']
        for enc in encodings:
            try:
                with open(file_path, 'r', encoding=enc) as f:
                    return f.read()
            except UnicodeDecodeError:
                continue
        return None

    def normalize_headers(self, headers):
        normalized = []
        for h in headers:
            h = re.sub(r'[\r\n]+', ' ', h).strip()
            if re.match(r'^No\.?$', h):
                h = 'No'
            elif re.search(r'^継続観察.*登録地点$', h):
                h = '継続観察登録地点'
            elif re.search(r'^夜空の.*明るさ', h):
                h = '夜空の明るさ'
            normalized.append(h)
        return normalized

    def find_coordinate_indices(self, headers):
        lat_idx, lon_idx = None, None
        try:
            lat_idx = headers.index('緯度')
        except ValueError:
            for i, h in enumerate(headers):
                if '緯度' in h:
                    lat_idx = i
                    break
            if lat_idx is None: lat_idx = 5

        try:
            lon_idx = headers.index('経度')
        except ValueError:
            for i, h in enumerate(headers):
                if '経度' in h:
                    lon_idx = i
                    break
            if lon_idx is None: lon_idx = 6

        return lat_idx, lon_idx

    def create_feature(self, row, headers, lat_idx, lon_idx, period):
        try:
            lat_str = row[lat_idx].strip() if lat_idx < len(row) else ''
            lon_str = row[lon_idx].strip() if lon_idx < len(row) else ''
            lat = float(lat_str)
            lon = float(lon_str)
        except (IndexError, ValueError):
            return None

        properties = {}
        if period:
            properties['調査時期'] = period

        for i, val in enumerate(row):
            key = headers[i] if i < len(headers) and headers[i] != '' else f"Column_{i}"
            if key == '撮影日時':
                val = self.format_to_iso8601(val, period)
            properties[key] = val

        return {
            "type": "Feature",
            "geometry": {
                "type": "Point",
                "coordinates": [lon, lat]
            },
            "properties": properties
        }

    def format_to_iso8601(self, date_str, period):
        date_str = date_str.strip()
        if not date_str:
            return ''

        # 8月2日 9:01 PM のように年抜けの場合の補完
        m = re.match(r'^(\d+)月(\d+)日\s+(\d+):(\d+)\s+(AM|PM)$', date_str, re.IGNORECASE)
        if m:
            year = period[:4] if len(period) >= 4 else "2018"
            month, day, hour, minute, ampm = m.groups()
            hour = int(hour)
            if ampm.upper() == 'PM' and hour < 12:
                hour += 12
            if ampm.upper() == 'AM' and hour == 12:
                hour = 0
            date_str = f"{year}-{int(month):02d}-{int(day):02d} {hour:02d}:{int(minute):02d}:00"

        # 日時パース (よくあるフォーマット)
        formats = [
            "%Y-%m-%d %H:%M:%S",
            "%Y/%m/%d %H:%M:%S",
            "%Y/%m/%d %H:%M",
            "%Y/%m/%d %I:%M %p"
        ]

        for fmt in formats:
            try:
                dt = datetime.strptime(date_str, fmt)
                dt = dt.replace(tzinfo=self.jst)
                return dt.isoformat()
            except ValueError:
                continue
        
        return date_str

    def collect_continuous_data(self, feature, period):
        properties = feature['properties']
        c_val = properties.get('継続観察登録地点', '').strip()
        
        if c_val in ('○', '〇'):
            coords = feature['geometry']['coordinates']
            loc_key = f"{round(coords[1], 3)},{round(coords[0], 3)}"
            self.raw_continuous_data[loc_key][period].append(properties)

    def export_combined_geojson(self):
        if not self.all_features:
            return

        print(f"\nGenerating all_points.geojson with {len(self.all_features)} features...")
        self.save_geojson('all_points.geojson', self.all_features)
        print("Saved combined features to all_points.geojson")

    def export_continuous_geojson(self):
        continuous_features = []

        for loc_key, period_data in self.raw_continuous_data.items():
            first_data = next(iter(period_data.values()))[0]
            time_series = []

            for p, observations in period_data.items():
                if not observations:
                    continue

                sum_brightness = 0.0
                sum_variance = 0.0
                valid_count = 0

                for obs in observations:
                    bikou = obs.get('備考', '').strip()
                    if bikou != '':
                        continue
                    
                    try:
                        brightness = float(obs.get('夜空の明るさ', 0))
                    except ValueError:
                        brightness = 0.0
                        
                    try:
                        variance = float(obs.get('ばらつき', 0))
                    except ValueError:
                        variance = 0.0

                    sum_brightness += brightness
                    sum_variance += variance
                    valid_count += 1

                if valid_count == 0:
                    continue

                time_series.append({
                    '調査時期': p,
                    '観測回数': valid_count,
                    '夜空の明るさ_平均': round(sum_brightness / valid_count, 2),
                    'ばらつき_平均': round(sum_variance / valid_count, 2)
                })

            if not time_series:
                continue

            time_series.sort(key=lambda x: x['調査時期'], reverse=True)

            try:
                lat = float(first_data.get('緯度', 0))
                lon = float(first_data.get('経度', 0))
            except ValueError:
                continue

            continuous_features.append({
                'type': 'Feature',
                'geometry': {
                    'type': 'Point',
                    'coordinates': [lon, lat]
                },
                'properties': {
                    '都道府県': first_data.get('都道府県'),
                    '市区町村': first_data.get('市区町村') or first_data.get('市町村'),
                    '撮影場所': first_data.get('撮影場所') or None,
                    '時系列データ': time_series
                }
            })

        if continuous_features:
            print(f"Generating continuous_points.geojson with {len(continuous_features)} features...")
            self.save_geojson('continuous_points.geojson', continuous_features)
            print("Saved continuous points to continuous_points.geojson")

    def save_geojson(self, filename, features):
        geojson = {
            "type": "FeatureCollection",
            "features": features
        }
        file_path = os.path.join(self.output_dir, filename)
        with open(file_path, 'w', encoding='utf-8') as f:
            json.dump(geojson, f, ensure_ascii=False, indent=4)

if __name__ == '__main__':
    converter = CsvToGeoJsonConverter(os.path.dirname(os.path.abspath(__file__)))
    converter.execute()

