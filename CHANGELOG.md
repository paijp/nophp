# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
### Fixed
- When the value of an input tag or the content of a textarea tag is automatically embedded from the database, if it contains backquotes, the backquote and one character immediately following it are lost.


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
