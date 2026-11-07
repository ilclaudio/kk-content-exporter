# Unit tests (PHPUnit + Brain Monkey, no WordPress)

Fast tests of pure logic and of the consistency of the release files. WordPress is not loaded: its
functions and hooks are mocked per test with [Brain Monkey](https://giuseppe-mazzapica.gitbook.io/brain-monkey/).

## Requirements

PHP 8.3+ and `composer install`. Nothing else: no database, no WordPress.

## Running

```sh
composer run test:unit
composer run test:unit -- --filter KKCE_Plugin_Unit_Test
```

Configuration: `phpunit.xml` (suite `unit`, files `*Test.php` under `tests/unit/`), bootstrap
`tests/bootstrap-unit.php`. The `pre-commit` hook runs this suite.

## How it works

- `tests/bootstrap-unit.php` defines `ABSPATH` (the plugin files exit without it) and the constants of
  the main file, loads Composer's autoloader and the plugin autoloader. It does **not** include
  `kk-content-exporter.php`, which registers hooks when it is loaded.
- Every test class extends `KKCE_Unit_Test_Case` (`tests/unit/support/class-kkce-unit-test-case.php`),
  which starts and resets Brain Monkey around each test and stubs the translation and escaping
  functions so that they return their input.
- Mock functions with `Brain\Monkey\Functions\when( 'fn' )->justReturn( ... )` or set expectations with
  `Functions\expect( 'fn' )->once()->with( ... )`; check hooks with `has_action()` / `has_filter()`.

## Adding a test

1. Create `tests/unit/KKCE_<Area>_Unit_Test.php` with a class of the same name extending
   `KKCE_Unit_Test_Case`; one file per area.
2. Name methods `test_<behavior>` and give each a one-line docblock describing the behavior.
3. Pure logic (mapping, normalization, validation) is tested without mocks: pass the data as
   arguments. Use Brain Monkey only for the wiring layer (hook registration, simple read APIs in
   source providers).
4. If the code under test needs a real database, real hooks firing, roles or REST dispatch, write an
   integration test instead: never mock `WP_Query`, `$wpdb` or the REST server.
