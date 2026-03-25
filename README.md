# Arduino CLI JSON Fetcher (PHP)

PHPで `arduino-cli board listall --json` を実行し、
取得したJSONを「オリジナル(raw)」と「整形済み(formatted)」として保存し、
整形済みJSONを標準出力に出す簡易ツールです。

## Page
- https://tanakamasayuki.github.io/arduino-cli-helper/
- https://tanakamasayuki.github.io/arduino-cli-helper/sourcebackup.html

上記に作成したページがあります。

- `sourcebackup.html` は Arduino ファームウェア側の `sourcebackup::writeArchiveBase64To(Serial)` が出力した base64 を貼り付け、ZIP を復元してダウンロードするためのページです。
- base64 化されているのは ZIP 本体なので、ZIP に含まれるファイル名やフォルダ構造もそのまま復元できます。

## 前提
- `arduino-cli` がPATHで実行可能であること
- PHP 8.0+ が利用可能であること

## 使い方

CLIから次を実行します:

```bash
php run.php
```

成功すると、以下にファイルが保存されます（常に上書き）。
- オリジナル: `storage/raw/board_listall.json`
- 整形済み: `storage/formatted/board_listall.json`

標準出力には整形済みJSON（pretty-print, UTF-8, スラッシュ非エスケープ）が出力されます。

整形済みJSONの内容は、取得データから抽出した「`fqbn` をキー、`name` を値」にしたマップです。例:

```json
{
  "arduino:avr:uno": "Arduino Uno",
  "arduino:avr:pro": "Arduino Pro or Pro Mini"
}
```

## ボード詳細の保存
- `board listall` で得た全ての `fqbn` に対して、
  `arduino-cli board details -b <fqbn> --json` を実行し、結果を保存します。
- 保存先（常に上書き）
  - オリジナル: `storage/raw/details/<safe-fqbn>.json`
  - 整形済み: `storage/formatted/details/<safe-fqbn>.json`
- `<safe-fqbn>` はファイル名として安全な形に変換しています（英数字・`._-` 以外は `_` に置換）。

## すべての details の集約出力
- 取得したすべての FQBN についての details から、`name` と `config_options` のみを抽出し、
  `fqbn` をキーにしたマップを生成します。
- 保存先（常に上書き）
  - 整形済み: `storage/formatted/board_details.json`
- 標準出力: 上記の集約JSONを出力します。
- 例:

```json
{
  "arduino:avr:uno": {
    "name": "Arduino Uno",
    "config_options": [
      { "option": "Clock", "values": [ {"value": "16MHz", "is_default": true} ] }
    ]
  },
  "arduino:avr:pro": {
    "name": "Arduino Pro or Pro Mini",
    "config_options": []
  }
}
```

## HTMLビューア（検索/フィルタ）
- ファイル: `web/index.html`
- 内容: `storage/formatted/board_details.json` を読み込み、`fqbn` と `name` の一覧を表示。
  テキストボックスに部分一致の文字列を入力すると一覧がフィルタリングされます。
  - リスト項目をクリックすると検索欄にその `fqbn` が入力されます。
  - 検索欄の文字列が `fqbn` に完全一致した場合、一覧はその1件のみ表示され、
    直下に `config_options` をラジオボタンで選択できるUIが表示されます（該当が無い場合は非表示）。

### 表示方法
1) まずデータ生成: `php run.php`
2) 簡易サーバで配信（推奨）:

```bash
php -S 127.0.0.1:8000 -t .
```

3) ブラウザで `http://127.0.0.1:8000/web/index.html` を開く

注意: 直接 `file://` で開くとブラウザの制約でJSON取得に失敗することがあります。上記の簡易サーバで配信してください。

## Source Backup ZIP Extractor
- ファイル: `docs/sourcebackup.html`
- 内容: `sourcebackup::writeArchiveBase64To(Serial)` の出力を貼り付けると、ZIP を復元し、含まれるファイル一覧を表示してダウンロードできます。
- 対応言語:
  - English
  - 日本語
  - 中文
  - Deutsch
  - Français
  - Italiano
  - Español
- ブラウザ言語から初期表示言語を自動選択し、ページ右上のプルダウンから手動切り替えもできます。
- ZIP 内に共通のルートフォルダがある場合、一覧表示ではその共通部分を省略し、ダウンロード時のファイル名は `<Root Folder>.zip` になります。

## 設定（任意）
- 環境変数 `ARDUINO_CLI_CMD` で `arduino-cli` コマンド名/パスを上書きできます。

例:

```bash
ARDUINO_CLI_CMD="arduino-cli" php run.php
```

## 備考
- JSONのデコードに失敗した場合でも、オリジナルは `storage/raw` に保存します。
  また整形済みはフォールバックとしてオリジナル内容をそのまま保存します。

## 変更点（保存先の見直し）
- formatted への保存は廃止しました。
- `board_details.json` は `web/board_details.json` に直接保存・上書きします。
- HTML ビューアは `web/board_details.json` を読み込みます。

## ライブラリ一覧の取得（library_index.json → JSON）
- 実行: `php libraries.php`
- 処理内容:
  - `http://downloads.arduino.cc/libraries/library_index.json` をダウンロード
  - ライブラリごとの最新バージョンを採用し、バージョン履歴は重複を除いて新しい順に整列
  - `name`, `version`, `versions`, `author`, `maintainer`, `website`, `category`, `architectures`, `types`, `repository`, `dependencies` を抽出
  - 生成したデータを pretty-print で `docs/libraries.json` に保存（同じ内容を標準出力にも出力）
- 前提:
  - ネットワークにアクセスできること
  - PHP の cURL 拡張（未インストールの場合は `file_get_contents` でフォールバック取得）
