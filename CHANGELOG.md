# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
### Added
- Add bq_db{begin,commit,rollback} for hook, and add a sample to avoid the sqlite WAL transaction(tables_beginimmediatesample240827a.php).
- Empty password on the change password page now means login via the URL sent by email.
### Changed
- php8 support.
- Use PDO method for begin/commit/rollback(index.php), fix double-commit.
- log_die() send to errorlog and report instead of browser.
- logirecord::v_ismaillogin is now removed. You can login without password by entering blank password on the Change Password page.

## [0.0.2] - 2023-12-29
### Added
- Add syntax diagram(index.php, index.html).
### Changed
- Move functions from index.php to builtinfunc.php, loginrecord.php, importer.php. Please include these files from tables.php.

## [0.0.1] - 2023-03-30 [YANKED]
### Security
- Security fix: command injection when send a password setting mail.
### Added
- Security workaround sample: env_patchsample230330a.php
