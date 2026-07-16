# Big File Uploads test suite

WordPress integration tests, run with PHPUnit 9 against a throwaway WordPress in Docker via
[`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

## Running

```bash
composer install     # PHPUnit + the WordPress test library
npm install          # wp-env
npm run env:start    # boots WordPress + MySQL in Docker (slow the first time, pulls images)

npm run test         # the main suite
npm run test:iu      # the Infinite-Uploads-active suite (see below)
npm run test:all     # both
```

Anything PHPUnit accepts can be passed through:

```bash
npm run test -- --filter Test_BFU_Chunk_Assembly
npm run test -- --testdox
```

Node 18+ is required by `wp-env`. If `node -v` reports something older, switch first
(`fnm use 18` / `nvm use 18`).

`npm run env:destroy` throws the whole environment away if the database gets into a bad state.

## Layout

| File | Covers |
| --- | --- |
| `test-upload-limits.php` | Which limit a user resolves to, MB/GB round-tripping, defaults for missing or malformed settings, and what reaches plupload / `upload_size_limit` / the block editor. |
| `test-chunk-assembly.php` | Chunked upload assembly: byte-identity of the reassembled file, out-of-order and restarted uploads, temp file naming, and the stale chunk sweep. |
| `test-file-scan.php` | `Big_File_Uploads_File_Scan`: totals over a fixture tree, resumption across batches, symlinks, and unreadable directories. |
| `test-settings-page.php` | `settings_page()` renders and saves; the subscribe modal's visibility rules. |
| `test-settings-page-iu-active.php` | The same page with Infinite Uploads active. `iu-active` group only. |
| `includes/class-bfu-testcase.php` | Base class: resets plugin options between tests, scratch directories, fixture helpers. |

## Why there are two suites

Several branches key off `class_exists( 'Infinite_Uploads' )`. A class cannot be undeclared once
loaded, so both states can't be exercised in one PHP process. `phpunit-iu-active.xml.dist` boots
through `tests/bootstrap-iu-active.php`, which declares a stub `Infinite_Uploads` before WordPress
loads, and runs only the `iu-active` group. The main config excludes that group.

## Conventions

- Test files are `test-*.php`; both configs match on that prefix, so the bootstraps and
  `includes/` are not picked up as test classes.
- Tests extend `BFU_TestCase`, which clears `tuxbfu_settings`, `tuxbfu_max_upload_size` and
  `tuxbfu_file_scan` around every test. Options set inside a test do not leak into the next one.
- Scratch directories come from `make_scratch_dir()` and are removed during teardown. Nothing
  writes to the real `wp-content/bfu-temp`; the chunk tests redirect it with the `bfu_temp_dir`
  filter.
- A test that pins current behaviour rather than desired behaviour says so in a comment and uses
  the word "characterization" — see `test_duplicate_chunk_is_appended_twice`. Those are the ones to
  revisit, not to trust.
