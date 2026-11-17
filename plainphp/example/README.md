# example: lib.php を使った小さなアプリケーション

メモの一覧です。`pages.php` の各関数に、nophp のテンプレートで書いた場合の記述をコメントで添えています。

```
php -S 127.0.0.1:8900 -t plainphp/example        # http://127.0.0.1:8900/index.php
python3 -I plainphp/test/golden.py plainphp/example /tmp/run1 8901     # テスト（24ステップ）
APP_ENV=/tmp/run1/env.php php plainphp/check.php                      # 点検
```

`env.php` の `defaultuser`（sample）が最初に作られます。パスワードは、ログイン画面の「Forget password?」から
メールの URL で設定します（`mailcmd`）。メールを使えない環境では `$sys->defaultpass` を設定します。
