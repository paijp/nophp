# PHP 版から Python 版への切り替え手順

Python 版は、PHP 版と同じ HTML テンプレート（res/*.html）と同じ SQLite データベースをそのまま使えます。
`.../index.php` と同じ URL に配置すると、URL もログイン Cookie も PHP 版と共通になるため、
利用者はログインし直さずにそのまま使い続けられます。

## 1. 事前準備

```
dnf install httpd python3.12 python3.12-mod_wsgi
```

### env.php → env.py
`sys.defaultuser`、`sys.sqlpath`、`sys.htmlbase`、`sys.mailcmd` などを env.py に書き写します。

- `sys.htmlbase` は app.wsgi のあるディレクトリからの相対パスです（PHP 版の res を使う場合は `"../res/"` など）。
- `?mode=sql` を使う場合は `sys.auth_pass` も設定します（値は PHP 版と同じでかまいません）。

### tables.php → tables.py
- `class xxx_table extends a_table` → `class xxx_table(a_table)`
- `new xxx_table();` などトップレベルの文 → `tables(sys)` 関数の中に書きます（リクエストごとに実行されます）。
- `$sys->mid = "master0";` → `sys.mid = "master0"`
- `function bq_xxx__a__b($a, $b)` → `@bqfunc` を付けた `def bq_xxx__a__b(a, b):`。戻り値は `array(...)` の代わりに `[...]` です。
- **getconfig() の文字列は PHP 版と完全に同じにしてください。** PHP のヒアドキュメントは `EOO;` の直前の改行を含みません。
  違いがあると、初回アクセス時に全テーブルの再作成（alter table と全レコードの update）が走ります。
- 値の比較など PHP 固有の挙動が必要な箇所では、phpcompat の `to_int` / `to_str` / `loose_eq` などを使います。

### 確認
テスト用の環境で、PHP 版と Python 版に同じ操作をして応答を比べます（それぞれ別の DB を使います）。

```
python3.12 tests/semantics.py php
python3.12 tests/compare.py http://host/nophp/index.php http://host/nophp-py g0000 g0001 ...
```

## 2. 切り替え

1. データベースをバックアップします。
2. Apache の設定で、PHP の index.php を Python 版に置き換えます（`/nophp` は実際の配置場所に合わせます）。

```
WSGIDaemonProcess nophppy user=apache group=apache processes=4 threads=1 python-path=/path/to/py
WSGIScriptAlias /nophp/index.php /path/to/py/app.wsgi process-group=nophppy application-group=%{GLOBAL}
WSGIPassAuthorization On
```

3. `apachectl configtest && systemctl reload httpd`

`processes` は PHP-FPM の子プロセス数を目安に設定します。
`threads` は1のままにしてください。PHP 版と同じく、1つのリクエストを1つのプロセスで処理する前提です。

## 3. 切り戻し

WSGIScriptAlias の行を削除（またはコメントアウト）して、Apache を reload します。
データベースと Cookie は PHP 版と共通なので、そのまま PHP 版に戻ります。

## 検証済みの事項（Rocky Linux 9 コンテナ、PHP 8.0.30 / Python 3.12.15）

- サンプルの全ページで、表示とフォーム送信の結果（HTML、リダイレクト、DB の内容）が PHP 版と一致すること。
- PHP 版で作った DB を Python 版で開いても、テーブルが再作成されないこと。
- PHP 版でログインしたセッションのまま Python 版を使えること。Python 版で書き込んだデータを PHP 版で読めること。Python 版でログアウトすると PHP 版のセッションも無効になること。
- 処理速度：PHP 版の約7〜8割（同じマシン、4並列、processes=2 で測定）。

## 未対応

- デバッグログ（debuglog ディレクトリ）、カバレッジ、ログ表示。後から実装できるよう、呼び出し口を debuglog.py に用意してあります。
  PHP 版で debuglog ディレクトリを作っていても、Python 版ではログは出力されません。
- MySQL（PHP 版でも未検証）。
