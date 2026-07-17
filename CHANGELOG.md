# Changelog

All notable changes to this project will be documented in this file.

## [1.0.2](https://github.com/jorisnoo/craft-blurhash/releases/tag/v1.0.2) (2026-07-17)

### Code Refactoring

- use Guzzle for thumbnail downloads with error handling and referrer headers ([6cc9785](https://github.com/jorisnoo/craft-blurhash/commit/6cc9785732114a3f240ee41d70987f1e388fad82))
## [1.0.1](https://github.com/jorisnoo/craft-blurhash/releases/tag/v1.0.1) (2026-07-10)

### Chores

- tidy composer.json metadata, switch CI to tests.yml, add justfile ([7fdd9ad](https://github.com/jorisnoo/craft-blurhash/commit/7fdd9ad668040ead9b891c30e3604f3175b98fb0))
- **deps:** bump actions/checkout from 6 to 7 ([5b75a65](https://github.com/jorisnoo/craft-blurhash/commit/5b75a65144f6cdc8825fa0125ea652e647a7a9fc))
## [1.0.0](https://github.com/jorisnoo/craft-blurhash/releases/tag/v1.0.0) (2026-05-12)

### Features

- **blurhash:** use pre-built Bunny Stream blurhashes when available ([c7f7cfc](https://github.com/jorisnoo/craft-blurhash/commit/c7f7cfcdfa289453c2ca5772b5b9dd42d81b9217))
- **blurhash:** exclude recently created assets from stats ([02ee033](https://github.com/jorisnoo/craft-blurhash/commit/02ee0331d138e14ffccd93406411dfa3603c1a7b))
- **blurhash:** add Bunny Stream video support ([609985a](https://github.com/jorisnoo/craft-blurhash/commit/609985a571616d2e9a734016df35fc6a0a129684))
- **blurhash:** add smart computation skipping with force refresh ([ad5418e](https://github.com/jorisnoo/craft-blurhash/commit/ad5418efab76bf9ea891a6fff491e4f105e13140))
- **health:** add missing blurhash health check ([d642535](https://github.com/jorisnoo/craft-blurhash/commit/d6425354f87ff11827c0c6129922ddd720be1892))
- add plugin settings and on-demand blurhash computation ([1d0fbd0](https://github.com/jorisnoo/craft-blurhash/commit/1d0fbd065ee26f59bb3a123cf9aff634411b0e9b))
- **blurhash:** display missing assets table in admin utility ([32ba31c](https://github.com/jorisnoo/craft-blurhash/commit/32ba31c6c8b88db924ddb58ad252f992c89db916))
- add blurhash utility UI for admin panel ([bf85e25](https://github.com/jorisnoo/craft-blurhash/commit/bf85e25932ee9857a74b9a9fbc94657eb0d0eb91))
- add null handling to blurhash service methods ([ff77bfb](https://github.com/jorisnoo/craft-blurhash/commit/ff77bfbd0796b673bcac50e925ff849edc8adcd8))

### Bug Fixes

- **blurhash:** require volume ID for processable images ([a242497](https://github.com/jorisnoo/craft-blurhash/commit/a2424974b811be22c874ea84bcf200378b97a52b))
- **blurhash:** properly manage GD resource lifecycle in image encoding ([481a450](https://github.com/jorisnoo/craft-blurhash/commit/481a450d97649417a619573242b0430b8f882a7e))
- **blurhash:** exclude null blurhashes from query and rename variable ([138440b](https://github.com/jorisnoo/craft-blurhash/commit/138440b72e51727429ddf2af53f393578940913f))
- **install:** prevent recreating blurhash table if it exists ([5056d1c](https://github.com/jorisnoo/craft-blurhash/commit/5056d1cd54704c19a4bcd42646b7a9d6bfd34721))

### Code Refactoring

- **blurhash:** extract stats calculation into service method ([37de0eb](https://github.com/jorisnoo/craft-blurhash/commit/37de0eb1b2b41d7587fd9810830f1c5fab4c2ced))
- **blurhash:** extract thumbnail creation and optimize transparency detection ([3ba7ad5](https://github.com/jorisnoo/craft-blurhash/commit/3ba7ad5325dbfbe1f7007331e8234c87359f4223))
- **blurhash:** centralize error handling in service and simplify image encoding ([63e4078](https://github.com/jorisnoo/craft-blurhash/commit/63e4078a90c093c75870bc2bbcd2164773a5006e))
- **blurhash:** add caching and extract resolve method ([333bbc1](https://github.com/jorisnoo/craft-blurhash/commit/333bbc11ab3cd55d076d7bb064b61ff613326b69))
- **blurhash:** remove thumbnail column from missing assets table ([a3594a8](https://github.com/jorisnoo/craft-blurhash/commit/a3594a855221ab07f6790e0b1f7afbc594f558c4))
- **BlurhashUtility:** replace database queries with PHP-based filtering ([b26fcbd](https://github.com/jorisnoo/craft-blurhash/commit/b26fcbd6a171726f5ddde343aabeb5b39a09830e))
- extract allowed mime types constant and add mime type filtering ([3c651b7](https://github.com/jorisnoo/craft-blurhash/commit/3c651b7df56fe3eb50e6dd0c0ea2fed15394da85))
- implement batch processing for blurhash computation ([199e442](https://github.com/jorisnoo/craft-blurhash/commit/199e442c199d8610397455fa4bd6c9ee80e194dd))

### Tests

- add phpunit configuration and architecture test ([12f31fe](https://github.com/jorisnoo/craft-blurhash/commit/12f31fe7d6ad2eb5d6c4203f9015e974ca9ce3dc))

### Chores

- initialize project with configuration, documentation, and workflows ([72c9288](https://github.com/jorisnoo/craft-blurhash/commit/72c9288441801815e2ae432dd540c11b06884ccf))
