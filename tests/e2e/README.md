# End-to-end tests (Playwright)

Browser tests for the one path that only exists in a browser: plupload slicing a real file and the
server reassembling it. The PHPUnit suites drive the chunk endpoint with a hand-built `$_FILES`,
which proves the server half in isolation; only a real browser proves that plupload — configured by
the plugin's own `filter_plupload_settings()` — slices a file the way the server then reassembles.

## Running

```bash
composer install        # first time only, for the WordPress test library the PHPUnit suites use
npm install             # first time only
npx playwright install chromium

npm run env:start       # the dev site these tests drive, at http://localhost:8888
npm run test:e2e
```

Other entry points:

```bash
npm run test:e2e:headed   # watch it happen in a real browser window
npm run test:e2e:ui       # Playwright's interactive UI, for debugging a failure
npm run test:e2e -- --grep "uploads intact"   # one test
```

Node 18+ is required. A failing run leaves a trace, screenshot and video under `test-results/`;
open the trace with `npx playwright show-trace test-results/<...>/trace.zip`.

## How the "larger than the limit" scenario is set up

The whole point is a file too big to send in one request. Out of the box the wp-env container
allows 1GB uploads, so nothing would ever chunk. `tests/e2e/php-limits.htaccess`, mapped over the
site root by `.wp-env.json`, caps the **server** at 2MB per request — the sort of limit a cheap
shared host imposes, and exactly what Big File Uploads exists to work around.

So there are two different ceilings, and the two tests each lean on one:

- **Server limit (2MB, fixed by the .htaccess):** a 5MB upload cannot reach PHP in one request.
  `a file larger than the server limit uploads intact via chunking` sets BFU's own limit high
  (100MB), uploads 5MB, and asserts it arrived in several sub-2MB chunks and was reassembled
  byte-for-byte. This is the real integration.
- **BFU's configured limit (set through the settings UI):** `a file larger than the configured
  limit is refused` sets BFU to 3MB and confirms plupload refuses a 5MB file in the browser,
  before a single chunk is sent.

If you change the 2MB value in the `.htaccess`, restart with `npm run env:start` for it to take
effect, and keep it below the 5MB fixture in `chunked-upload.spec.js`.

A third test, `a large image still rides the chunked path, not client-side media processing`,
uploads a ~3.7MB PNG and asserts it was chunked by BFU (not sent over the REST media endpoint) and
stored byte-for-byte. WordPress 7.1 added client-side media processing, which resizes/converts
images in the browser and uploads them over REST — but only in the block editor, not the media
library. This test is the guard against a future release extending that to the media library and
quietly taking image uploads away from BFU.

To test against a specific WordPress version locally, pin it with a personal (git-ignored)
`.wp-env.override.json` and restart:

```json
{ "core": "https://wordpress.org/wordpress-7.1.zip" }
```

```bash
npm run env:start        # re-provisions both sites on the pinned version
npm run test:all         # PHPUnit, all three suites
npm run test:e2e         # the browser suite
```

Delete the override file and restart to go back to the latest release.

## Notes for anyone extending these

A few things here were non-obvious enough to be worth writing down:

- **Set the unit before the amount.** `assets/js/admin.js` converts the number field whenever the
  MB/GB `<select>` fires `change`, so filling `3` and then choosing MB saves 3072MB, not 3MB. A
  person never hits this (re-selecting the current option fires no event); `selectOption` always
  does. `setUploadLimit()` in `helpers.js` orders it correctly.
- **A chunk's body cannot be read.** It is `multipart/form-data`, and `postData()`,
  `postDataBuffer()` and `sizes().requestBodySize` all come back null/0 for an uploaded file. Each
  of those fails by reporting *zero chunks*, which would make "chunking happened" silently
  unfalsifiable. `recordChunkUploads()` reads `content-length` from `allHeaders()` instead.
- **Upload success is the Edit link, not the element id.** The media item keeps plupload's
  client-side uid (`media-item-o_1abc…`) throughout; the numeric attachment id only appears in the
  `a.edit-attachment` href once the server has accepted the finished file.
- **Target plupload's file input, not `#async-upload`.** The latter belongs to the no-JS uploader
  and posts straight to `async-upload.php`, never touching this plugin — using it would make the
  test a no-op.
