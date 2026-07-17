# Big File Uploads test suite

WordPress integration tests, run with PHPUnit 9 against a throwaway WordPress in Docker via
[`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

There is also a browser-level suite for the real chunked-upload integration, which PHPUnit cannot
reach; see [`e2e/README.md`](e2e/README.md).

## Running

```bash
composer install     # PHPUnit + the WordPress test library
npm install          # wp-env
npm run env:start    # boots WordPress + MySQL in Docker (slow the first time, pulls images)

npm run test         # the main suite
npm run test:iu      # the Infinite-Uploads-active suite (see below)
npm run test:ms      # the multisite suite (see below)
npm run test:all     # all three
```

Each test prints what it checks, with a green `✔` when it passes and a red `✘` — plus the diff —
when it doesn't:

```
Chunked upload assembly
 ✔ Chunks in order reassemble byte identical
 ✔ Chunk ending in a zero byte is not truncated
 ✘ Chunk consisting only of a zero byte is not dropped
   ├ Failed asserting that two strings are identical.
   ┊ --- Expected
   ┊ +++ Actual
```

The heading comes from the `@testdox` annotation on the test class; the line under it is the test
method name with the underscores taken out. So method names are the spec — write them as sentences
that read properly after `✔`, and keep the assertion message for the *why*.

`npm run test:dots` is the same run without any of that: plain progress dots and no colour, for CI
logs or anywhere the output is piped rather than watched.

Anything PHPUnit accepts can be passed through:

```bash
npm run test -- --filter Test_BFU_Chunk_Assembly
npm run test -- --testdox
```

Node 18+ is required by `wp-env`. If `node -v` reports something older, switch first
(`fnm use 18` / `nvm use 18`).

`npm run env:destroy` throws the whole environment away if the database gets into a bad state.

## Continuous integration

`.github/workflows/tests.yml` runs on every pull request and on pushes to `master`, in two jobs:

- **lint** — `php -l` over the plugin's production code on PHP 5.6, 7.0, 7.2, 7.4, 8.1 and 8.4. This
  is how the older PHP versions the plugin supports are covered: PHPUnit 9 needs PHP 7.3+ and cannot
  run below it, but a syntax check still catches any construct that would fail to parse on PHP 5.6.
- **test** — the full PHPUnit suite (all three configs) on PHP 7.4, 8.1 and 8.4, against a MySQL
  service and a real WordPress installed by `bin/install-wp-tests.sh`.

CI does not use `wp-env`; it uses the classic test-library layout via `WP_TESTS_DIR`, which
`bootstrap.php` supports directly. To reproduce a CI run locally without Docker/wp-env:

```bash
# Needs a MySQL you can create a throwaway database in, plus svn (for the WP test library).
bash bin/install-wp-tests.sh wordpress_test <user> <pass> 127.0.0.1 latest
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
vendor/bin/phpunit
vendor/bin/phpunit -c phpunit-iu-active.xml.dist
vendor/bin/phpunit -c phpunit-multisite.xml.dist
```

## Layout

| File | Covers |
| --- | --- |
| `test-upload-limits.php` | Which limit a user resolves to, MB/GB round-tripping, defaults for missing or malformed settings, and what reaches plupload / `upload_size_limit` / the block editor. |
| `test-chunk-assembly.php` | `append_chunk()` in isolation: byte-identity of the reassembled file, out-of-order and restarted uploads, temp file naming, and the stale chunk sweep. |
| `test-chunk-receiver.php` | The `bfu_chunker` endpoint end to end: when a file is published (and that a partial never is), the size-limit gate, auth, and both response paths. |
| `test-file-scan.php` | `Big_File_Uploads_File_Scan`: totals over a fixture tree, resumption across batches, symlinks, and unreadable directories. |
| `test-ajax-file-scan.php` | The `bfu_file_scan` endpoint, chiefly its path-traversal guard on `remaining_dirs`. |
| `test-settings-page.php` | `settings_page()` renders and saves; the subscribe modal's visibility rules. |
| `test-settings-page-iu-active.php` | The same page with Infinite Uploads active. `iu-active` group only. |
| `test-multisite.php` | Network capability, network-wide limits, per-site chunk isolation. `multisite` group only. |
| `includes/class-bfu-testcase.php` | Base class: resets plugin options between tests, scratch directories, fixture helpers. |

## Why there are three suites

Two things about the plugin are fixed before any test runs, so they need their own process rather
than their own test:

- **`iu-active`** — several branches key off `class_exists( 'Infinite_Uploads' )`, and a class
  cannot be undeclared once loaded. `phpunit-iu-active.xml.dist` boots through
  `tests/bootstrap-iu-active.php`, which declares a stub `Infinite_Uploads` before WordPress loads.
- **`multisite`** — the plugin resolves its capability once, in the constructor, from
  `is_multisite()`. `phpunit-multisite.xml.dist` sets `WP_TESTS_MULTISITE`, which makes the
  WordPress bootstrap install a network.

Each extra config runs only its own group; the main config excludes both.

## Conventions

- Test files are `test-*.php`; both configs match on that prefix, so the bootstraps and
  `includes/` are not picked up as test classes.
- Tests extend `BFU_TestCase`, which clears `tuxbfu_settings`, `tuxbfu_max_upload_size` and
  `tuxbfu_file_scan` around every test. Options set inside a test do not leak into the next one.
- Scratch directories come from `make_scratch_dir()` and are removed during teardown. Nothing
  writes to the real `wp-content/bfu-temp`; the chunk tests redirect it with the `bfu_temp_dir`
  filter.
- Tests that publish attachments call `clear_uploads()` at both ends. The database is rolled back
  between tests but the uploads directory is not, so a published file otherwise survives and breaks
  the next "nothing was published" assertion. Do not swap this for core's `remove_added_uploads()`:
  that ignores files already present when the run started, and deleting the year/month directories
  outright breaks `wp_upload_dir()`, which caches the directories it has created.
- A test that pins current behaviour rather than desired behaviour says so in a comment and uses
  the word "characterization" — see `test_duplicate_chunk_is_appended_twice`. Those are the ones to
  revisit, not to trust.
