# Third-party notices

Stringcup itself is licensed under Apache-2.0 (see `LICENSE`). It bundles the
following third-party software, each of which remains under its own license.

---

## CodeIgniter 4 (`system/`)

The framework is vendored under `system/` and distributed under the MIT
License. Its full text is reproduced here as required:

```
The MIT License (MIT)

Copyright (c) 2014-2019 British Columbia Institute of Technology
Copyright (c) 2019-present CodeIgniter Foundation

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```

---

## Composer dependencies (`vendor/`)

### BSD-3-Clause

- `laminas/laminas-escaper` 2.18.0
- `mikey179/vfsstream` v1.6.12
- `nikic/php-parser` v5.7.0
- `phar-io/manifest` 2.0.4
- `phar-io/version` 3.2.1
- `phpunit/php-code-coverage` 11.0.11
- `phpunit/php-file-iterator` 5.1.0
- `phpunit/php-invoker` 5.0.1
- `phpunit/php-text-template` 4.0.1
- `phpunit/php-timer` 7.0.1
- `phpunit/phpunit` 11.5.46
- `sebastian/cli-parser` 3.0.2
- `sebastian/code-unit` 3.0.3
- `sebastian/code-unit-reverse-lookup` 4.0.1
- `sebastian/comparator` 6.3.2
- `sebastian/complexity` 4.0.1
- `sebastian/diff` 6.0.2
- `sebastian/environment` 7.2.1
- `sebastian/exporter` 6.3.2
- `sebastian/global-state` 7.0.2
- `sebastian/lines-of-code` 3.0.1
- `sebastian/object-enumerator` 6.0.1
- `sebastian/object-reflector` 4.0.1
- `sebastian/recursion-context` 6.0.3
- `sebastian/type` 5.1.3
- `sebastian/version` 5.0.2
- `theseer/tokenizer` 1.3.1

### ISC

- `paragonie/sodium_compat` v2.5.2

### MIT

- `brick/math` 0.14.1
- `clue/ndjson-react` v1.3.0
- `codeigniter/coding-standard` v1.8.8
- `composer/pcre` 3.3.2
- `composer/semver` 3.4.4
- `composer/xdebug-handler` 3.0.5
- `evenement/evenement` v3.0.2
- `fakerphp/faker` v1.24.1
- `fidry/cpu-core-counter` 1.3.0
- `friendsofphp/php-cs-fixer` v3.91.3
- `kint-php/kint` 6.1.0
- `myclabs/deep-copy` 1.13.4
- `nexusphp/cs-config` v3.26.4
- `predis/predis` v3.3.0
- `psr/container` 2.0.2
- `psr/event-dispatcher` 1.0.0
- `psr/http-message` 2.0
- `psr/log` 3.0.2
- `ramsey/collection` 2.1.1
- `ramsey/uuid` 4.9.1
- `react/cache` v1.2.0
- `react/child-process` v0.6.6
- `react/dns` v1.14.0
- `react/event-loop` v1.6.0
- `react/promise` v3.3.0
- `react/socket` v1.17.0
- `react/stream` v1.4.0
- `staabm/side-effects-detector` 1.0.5
- `symfony/console` v7.4.1
- `symfony/deprecation-contracts` v3.6.0
- `symfony/event-dispatcher` v7.4.0
- `symfony/event-dispatcher-contracts` v3.6.0
- `symfony/filesystem` v7.4.0
- `symfony/finder` v7.4.0
- `symfony/options-resolver` v7.4.0
- `symfony/polyfill-ctype` v1.33.0
- `symfony/polyfill-intl-grapheme` v1.33.0
- `symfony/polyfill-intl-normalizer` v1.33.0
- `symfony/polyfill-mbstring` v1.33.0
- `symfony/polyfill-php80` v1.33.0
- `symfony/polyfill-php81` v1.33.0
- `symfony/polyfill-php84` v1.33.0
- `symfony/process` v7.4.0
- `symfony/service-contracts` v3.6.1
- `symfony/stopwatch` v7.4.0
- `symfony/string` v7.4.0

---

## Notes

- All bundled licenses (MIT, BSD-3-Clause, ISC) are permissive and compatible
  with redistribution under Apache-2.0. None are copyleft.
- `vendor/` is committed deliberately: the deployed tree is the working tree,
  so there is no build step in which to run `composer install`.
- `paragonie/sodium_compat` is a development dependency only — it supplies a
  pure-PHP libsodium fallback for the test suite on hosts without
  `ext-sodium`. It is not used at runtime.
