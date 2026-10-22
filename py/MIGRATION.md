# PHP 版から Python 版への切り替え手順

Python 版は、PHP 版と同じ HTML テンプレート（res/*.html）と同じ SQLite データベースをそのまま使えます。
`.../index.php` と同じ URL に配置すると、URL もログイン Cookie も PHP 版と共通になるため、
利用者はログインし直さずにそのまま使い続けられます。

以下は、Rocky Linux 9 + Apache + php-fpm で `/var/www/html/app/index.php` として動いている nophp を、
同じ URL のまま Python 版に切り替える手順です（ix を5台のサーバで切り替えたときの手順です）。
`app` は実際のディレクトリ名に読み替えてください。

## 構成

nophp のプログラムは1か所（`/var/www/nophp-py/`）に置き、サイトごとに次の2つのファイルを置きます。

```
/var/www/nophp-py/
    nophp.py engine.py phpcompat.py loginrecord.py ...   共通（nophp/py/*.py）
    tables_app.py      サイトの tables.php
    env_app.py         サイトの env.php、兼 WSGI の入口（root:apache 640）
    tables_chatmail.py
    env_chatmail.py
```

`env_app.py` は設定 `env(sys)` と、入口 `application = nophp.make_application(env, tables_app, WORKDIR)` を持ちます。
Apache の `WSGIScriptAlias` は `env_app.py` を指します。`tables_app.tables(sys)` は、tables.php が `require("env.php")` していた位置で
`sys.env(sys)` を呼びます。

## 1. tables.php → tables_app.py、env.php → env_app.py

### tables.php → tables_app.py
- `class xxx_table extends a_table` → `class xxx_table(a_table)`
- `new xxx_table();` などトップレベルの文 → `tables(sys)` 関数の中に書きます（リクエストごとに実行されます）。
- `require("env.php");` → `sys.env(sys)`
- `$sys->mid = "master0";` → `sys.mid = "master0"`
- `function bq_xxx__a__b($a, $b)` → `@bqfunc` を付けた `def bq_xxx__a__b(a, b):`。戻り値は `array(...)` の代わりに `[...]` です。
- **getconfig() の文字列は PHP 版と完全に同じにしてください。** PHP のヒアドキュメントは `EOO;` の直前の改行を含みません。
  違いがあると、初回アクセス時に全テーブルの再作成（alter table と全レコードの update）が走ります。
- 値の比較など PHP 固有の挙動が必要な箇所では、phpcompat の `to_int` / `to_str` / `loose_eq` などを使います。
- 結果を使わない外部への HTTP 送信（Slack/Zulip/LINE などへの通知）は、`engine.later(関数, 引数...)` で
  応答の後に回してください。DB の書き込みロックを持ったまま外部の応答を待つと、他のリクエストが止まります。

### env.php → env_app.py
秘密の値（API キー、パスワード）をチャットや git に出さないよう、サーバ上で env.php から生成します。

```bash
sudo php tools/genenv.php /var/www/html/app/env.php tables_app /var/www/html/app | sudo tee /var/www/nophp-py/env_app.py >/dev/null
sudo sed -E 's/= .*/= .../' /var/www/nophp-py/env_app.py      # 値を伏せて項目だけ確認
```

2番目の引数は tables のモジュール名、3番目は PHP 版の `index.php` があったディレクトリ（`WORKDIR`）です。
`res/` などの相対パスは、PHP 版と同じくこのディレクトリからの相対になります。
`$sys->xxx = ...;` の設定だけが変換されます。`require()` や `header()` など、それ以外の文は必要に応じて書き足してください。

### 確認
テスト用の環境で、PHP 版と Python 版に同じ操作をして応答を比べます（それぞれ別の DB を使います）。

```
python3.12 tests/semantics.py php
python3.12 tests/compare.py http://host/nophp/index.php http://host/nophp-py g0000 g0001 ...
```

## 2. インストールとバックアップ

```bash
sudo dnf install -y python3.12 python3.12-mod_wsgi      # mod_wsgi は httpd の再起動で読み込まれます
B=/home/rocky/backup-app-$(date +%y%m%d%H%M); mkdir -p $B
sudo cp /var/www/db/app.sq3 $B/
sudo tar czf $B/files.tgz -C /var/www/html app
sudo cp -r /etc/httpd/conf.d $B/httpd-conf.d
```

## 3. プログラムの配置

Python のファイルはドキュメントルートの外に置きます（`.py` が Web から見えないようにするため）。

```bash
sudo mkdir -p /var/www/nophp-py
sudo cp nophp/py/*.py /var/www/nophp-py/          # 共通（env.py と tables.py はサンプルなので不要）
sudo rm /var/www/nophp-py/env.py /var/www/nophp-py/tables.py
sudo cp tables_app.py /var/www/nophp-py/          # env_app.py は上で生成したもの
sudo chown -R root:apache /var/www/nophp-py
sudo chmod 640 /var/www/nophp-py/env_*.py
```

開発用に、`python3 env_app.py 8000` で `http://localhost:8000/index.php/nofmt/xxx.html` として動かせます。

## 4. Apache の設定

`/etc/httpd/conf.d/app-wsgi.conf` を作成します（VirtualHost で囲まないこと）。
certbot が作った `conf/httpd-le-ssl.conf` などは書き換えられることがあるので、Python 版の設定は conf.d の別ファイルにします。
VirtualHost の外に書くと、:443 と :80 の両方に効きます。

```apache
# nophp Python version of /app (replaces php-fpm).
WSGIDaemonProcess app user=apache group=apache processes=4 threads=1 python-path=/var/www/nophp-py home=/var/www/html/app lang=en_US.UTF-8 locale=en_US.UTF-8
WSGIScriptAlias /app/index.php /var/www/nophp-py/env_app.py process-group=app application-group=%{GLOBAL}
WSGIPassAuthorization On
<Directory /var/www/nophp-py>
	Require all granted
</Directory>
```

- `WSGIScriptAlias` を `.../index.php` にすると、`/app/`（DirectoryIndex 経由）、`/app/index.php/nofmt/xxx.html`、
  `/app//index.php/...` がすべて Python 版に来ます。PHP 版の `index.php` は消さずに置いたままで構いません。
- `threads=1` は必須です。同時に処理できる数は `processes` で増やします（php-fpm の子プロセス数が目安、メモリ 512MB なら 4 程度）。
- `lang` / `locale` は必須です。ないと ASCII で動くため、件名が日本語のメールコマンドなどが失敗します。
- **サイトごとに別の `WSGIDaemonProcess` が必要です**（orbbs と違い、1つのデーモンを共有できません）。
  nophp は PHP と同じくクラスや `bq_*` 関数を名前で登録するので、`login_table` などが別のサイトのものとぶつかります。
  同じプロセスに2つのサイトが来た場合は、`make_application()` がエラーにします。
  サイトを追加するときは、`WSGIDaemonProcess`（名前と `home`）と `WSGIScriptAlias`（URL、`env_xxx.py`、`process-group`）をコピーして書き換えます。
- `env_app.py` を編集すると、そのサイトだけが自動で読み込み直されます。`tables_app.py` や共通の `*.py` を変えたときは
  `env_app.py` を touch するか、httpd を reload します。
- あるアプリが別のアプリを `https://localhost/...` で呼ぶ場合（wifi0 → ix2401 など）も、デーモンを分けてください。

### 応答の Content-Length
Python 版は応答に必ず `Content-Length` を付けます（chunked にしません）。chunked を受け付けないデバイス向けに
`SetEnv ap_trust_cgilike_cl` を設定しているサーバでも、そのまま使えます。

## 5. 切り替えと確認

```bash
sudo apachectl configtest && sudo systemctl restart httpd     # 初回は restart（mod_wsgi の読み込み）
H=$(hostname)
curl -sk -o /dev/null -w "%{http_code}\n" https://localhost/app/index.php/nofmt/home.html -H "Host: $H"   # 200
curl -sk -i https://localhost/app/ -H "Host: $H" | grep -i "content-length\|transfer-encoding"           # Content-Length のみ
curl -sk -o /dev/null -w "%{http_code}\n" https://localhost/app/env.php -H "Host: $H"                    # 503（PHP は動いていない）
sudo tail /var/log/httpd/error_log        # Python のエラーは [wsgi:error] として出ます
```

2回目以降の更新（*.py の差し替え）は、`env_app.py` を touch するか httpd を reload します。

SELinux が有効な場合: mod_wsgi のプロセスは php-fpm と同じ `httpd_t` で動くので、
PHP 版で DB への書き込みやメール送信ができていたなら追加の設定は要りません。

## 6. PHP の無効化

```bash
sudo systemctl disable --now php-fpm
sudo systemctl mask php-fpm
```

PHP のパッケージとファイルはそのまま残します。

- mask しているので、パッケージの更新や再起動で php-fpm が起動することはありません。
- `conf.d/php.conf` は `.php` へのリクエストを php-fpm に回し続けるので、残っている `.php` にアクセスされても 503 になり、ソースは見えません。
- `php-cli` は残るので、cron などのコマンドラインの PHP はそのまま動きます。
- 将来 `dnf remove 'php*'` などで `php.conf` が無くなると、残っている `.php`（`env.php` を含む）がテキストとして配信されてしまいます。
  PHP を削除する場合は、先に `.php` ファイルをドキュメントルートの外へ移すか、次の設定を conf.d に追加してください。

```apache
<FilesMatch "\.(php|phar|phtml)$">
	Require all denied
</FilesMatch>
```

## 7. 切り戻し

```bash
sudo rm /etc/httpd/conf.d/app-wsgi.conf
sudo systemctl unmask php-fpm && sudo systemctl enable --now php-fpm
sudo systemctl restart httpd
```

データベースと Cookie は PHP 版と共通なので、そのまま PHP 版に戻ります。

## 以前の構成（app.wsgi + env.py + tables.py）からの移行

以前は、サイトごとに nophp のファイル一式と `app.wsgi`、`env.py`（`def env(sys)`）、`tables.py` を1つのディレクトリに置き、
`WSGIScriptAlias` で `app.wsgi` を指していました。この構成も、新しい `nophp.py` と `app.wsgi` で引き続き動きます
（`tables.py` の `env(sys)` の呼び出しを `sys.env(sys)` に、`from env import env` を削除する必要があります）。

新しい構成にするには:

1. `/var/www/nophp-py/` に共通のファイルを置き、`tables.py` を `tables_app.py` として置きます（`sys.env(sys)` に修正済みのもの）。
2. 今の `env.py` の内容に、先頭の `import nophp` / `import tables_app` と `WORKDIR`、末尾の `application = ...` を加えて
   `env_app.py` として置きます（`app.wsgi` の `WORKDIR` の値を使います）。
3. conf.d の `python-path` と `WSGIScriptAlias` の行を書き換えて、`apachectl configtest && systemctl reload httpd`。

## 検証済みの事項（Rocky Linux 9 コンテナ、PHP 8.0.30 / Python 3.12.15）

- サンプルの全ページで、表示とフォーム送信の結果（HTML、リダイレクト、DB の内容）が PHP 版と一致すること。
- PHP 版で作った DB を Python 版で開いても、テーブルが再作成されないこと。
- PHP 版でログインしたセッションのまま Python 版を使えること。Python 版で書き込んだデータを PHP 版で読めること。Python 版でログアウトすると PHP 版のセッションも無効になること。
- 新しい構成（`env_xxx.py` が入口）と以前の構成（`app.wsgi`）のどちらでも、サンプルの全ページ、chatmail の実ページ、ix のデバイス通信が PHP 版と一致すること。
- 処理速度：PHP 版の約7〜8割（同じマシン、4並列、processes=2 で測定）。

## 未対応

- デバッグログ（debuglog ディレクトリ）、カバレッジ、ログ表示。後から実装できるよう、呼び出し口を debuglog.py に用意してあります。
  PHP 版で debuglog ディレクトリを作っていても、Python 版ではログは出力されません。
- MySQL（PHP 版でも未検証）。
