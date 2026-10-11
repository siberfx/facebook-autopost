# Changelog

All notable changes to `siberfx/facebook-autopost` are documented here. This project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Connect one Facebook Page with Facebook Login. The Page is chosen from the Pages the account manages (`FACEBOOK_PAGE_ID`, or the first one).
- Auto-post a model to the Page feed when it goes live, at most once, switched on with `FACEBOOK_AUTO_POST`.
- Manual share with `Facebook::share()`, `Facebook::queue()`, the JSON and signed routes, and the share button.
- Ready-made connection card and share button (Tailwind or Bootstrap 5, Blade or Livewire); headless mode with `FACEBOOK_UI=false`.
- Artisan commands: `facebook:install`, `facebook:status`, `facebook:disconnect`, `facebook:share`, `facebook:check-token`, `facebook:test-notification`.
- Telegram, Slack and email notifications when a post goes out, an automatic share gives up, or the Page token is about to expire.
- Events `Connected`, `Disconnected`, `Shared`, `ShareFailed` and `TokenExpiringSoon`.
- Encrypted token storage in `facebook_connections`; share history in `facebook_posts`.
