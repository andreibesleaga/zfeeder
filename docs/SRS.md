# Software requirements specification

Every requirement zFeeder 2.0 is built to meet, with an identifier, so that the
rest of the documentation and the test suite can point at something specific.

Requirements are derived from `project/PLAN.md`: section 2 (what 1.6 guarantees
are preserved), section B5 (pinned 1.6 behaviours), B6 (security), B7 (pages,
endpoints and commands), B8 (templates and skins) and B9 (testing).

**How to read the "satisfied by" column.** It names a file that exists in this
repository and, where one exists, a test that fails if the requirement stops
holding. Where no automated test exists yet, the column says so. Nothing in this
document names a test that is not in the suite today.

**Suite status.** See [TEST-PLAN.md](TEST-PLAN.md) for the current per-suite
figures and how to regenerate them. As of 18 September 2026: 1,418 PHPUnit tests
across five suites, 90.62 % line coverage of `src/`, one class per security
control in `tests/Security/`, and three Playwright specs in `tests/e2e/specs/`
covering the public site, the template gallery and the admin panel. The rows
below that mark something as unproven are the ones that still are.

## Contents

- [Functional requirements](#functional-requirements)
  - [Subscriptions](#subscriptions-fr-01--fr-09)
  - [Fetching](#fetching-fr-10--fr-18)
  - [Parsing](#parsing-fr-19--fr-25)
  - [Rendering](#rendering-fr-26--fr-40)
  - [Embedding](#embedding-fr-41--fr-47)
  - [Administration](#administration-fr-48--fr-58)
  - [Command line](#command-line-fr-59--fr-69)
  - [Migration from 1.6](#migration-from-16-fr-70--fr-74)
- [Non-functional requirements](#non-functional-requirements)
- [Traceability matrix](#traceability-matrix)
- [Out of scope](#out-of-scope)

## Functional requirements

### Subscriptions (FR-01 – FR-09)

| ID | Requirement | Satisfied by |
|---|---|---|
| FR-01 | Subscriptions are grouped into named categories. A category name matches `/^[a-z0-9_-]{1,40}$/`. | `src/Subscription/Category.php`; `Unit\Storage\FlatSubscriptionStoreTest::testInvalidNamesAreRefused`, same on `SqliteSubscriptionStoreTest` |
| FR-02 | A subscription carries the OPML attributes 1.6 used: `xmlUrl`, `htmlUrl`, `text`/`title`, `description`, `position`, `refreshTime`, `showedItems`, `isSubscribed`, `language`. | `src/Subscription/Feed.php`; `Unit\Subscription\OpmlReaderTest::testMissingAttributesFallBackToTheLegacyDefaults` |
| FR-03 | OPML files written by zFeeder 1.x load without modification, including the case-insensitive attribute spellings and the 2004 defaults (60 minutes, 0 shown items, not subscribed). | `src/Subscription/Opml/Reader.php`; `Unit\Subscription\OpmlReaderTest::testEveryLegacyFileParses`, `testAttributeNamesAreMatchedWithoutRegardToCase`, `testMissingAttributesFallBackToTheLegacyDefaults`, `testIsSubscribedIsOnlyTrueForYes` |
| FR-04 | An outline without a `position` attribute is not a subscription and is skipped, as `opmlStartElement()` did in 1.6. | `Unit\Subscription\OpmlReaderTest::testOutlinesWithoutAPositionAreSkipped`, `testOutlinesWithoutAFeedUrlAreSkipped` |
| FR-05 | zFeeder writes OPML 2.0 with `dateModified`, `ownerName` and `ownerEmail` from configuration, escaping every field. | `src/Subscription/Opml/Writer.php`; `Unit\Subscription\OpmlWriterTest::testMarkupInFieldsIsEscaped` |
| FR-06 | Subscriptions can be stored in flat OPML files or in SQLite, chosen at runtime by `storage`. Both backends offer identical behaviour. | `src/Storage/StoreFactory.php`; `tests/Support/SubscriptionStoreContractTestCase.php` run by `Unit\Storage\FlatSubscriptionStoreTest` and `Unit\Storage\SqliteSubscriptionStoreTest`; `Unit\Storage\StoreFactoryTest` |
| FR-07 | Subscriptions can be moved between backends in either direction, and the copy is verified by re-reading the destination. | `src/Storage/Migrator.php`; `Unit\Storage\MigratorTest::testFlatToSqliteAndBackPreservesEverything`, `testVerificationFailsWhenTheDestinationDropsAFeed` |
| FR-08 | Any category can be exported as OPML 2.0, whichever backend is in use. | `SubscriptionStoreInterface::exportOpml()`; `tests/Support/SubscriptionStoreContractTestCase.php` |
| FR-09 | Writes to flat storage are atomic and locked: a reader never sees a half-written file. | `src/Storage/AtomicFile.php`; `Unit\Config\ConfigWriterTest::testSavingTwiceLeavesNoTemporaryOrPartialFiles` |

### Fetching (FR-10 – FR-18)

| ID | Requirement | Satisfied by |
|---|---|---|
| FR-10 | A feed is fetched only when its cached copy is older than the subscription's `refreshTime`. | `src/Storage/CacheEntry.php::isExpired()`; `Unit\Fetch\FeedFetcherTest::testNotExpiredEntryShortCircuitsWithoutAnyRequest` |
| FR-11 | `--force` (and the `force` query parameter on `/refresh`) ignores the interval. | `Unit\Fetch\FeedFetcherTest::testForceBypassesTheTimeToLive` |
| FR-12 | A refresh sends `If-None-Match` and `If-Modified-Since` when validators are cached; a 304 keeps the body and restarts the interval. | `src/Fetch/FeedFetcher.php`; `Unit\Fetch\FeedFetcherTest::testExpiredEntrySendsConditionalHeadersAndHandles304`, `testNoConditionalHeadersWhenNothingIsCached`, `testFreshFetchStoresBodyEtagAndLastModified` |
| FR-13 | Validators are not replayed after a redirect. | `Unit\Fetch\FeedFetcherTest::testValidatorsAreNotReplayedAfterARedirect` |
| FR-14 | A failed fetch keeps the previous cached body, records the reason, and does not reset the fetch time. | `FeedFetcher::failure()`; `Unit\Fetch\FeedFetcherTest::testServerErrorKeepsServingTheStaleEntry`, `testTransportFailureIsReportedAndKeepsTheStaleEntry` |
| FR-15 | A feed subscribed in more than one category is fetched once per run. | `FeedService::refresh()`, `FeedFetcher::fetchAll()`; `Unit\Fetch\FeedFetcherTest::testFetchAllReturnsOneResultPerDistinctFeed` |
| FR-16 | The user agent is configurable and is sent on every request. | `Config::userAgent()`; `Unit\Fetch\FeedFetcherTest::testFreshFetchSendsTheConfiguredUserAgent` |
| FR-17 | In `offline` refresh mode no socket is opened while rendering; the page shows what is in the cache. | `FeedService::loadOne()`; `Integration\Http\FeedServiceTest::testOfflineModeNeverOpensASocket` |
| FR-18 | Each fetch outcome is one of `cached`, `not-modified`, `not-expired` or `failed`, and reports itself in the 1.6 wording. | `src/Fetch/FetchResult.php::legacyLine()`; `Cli\RefreshCommandTest::testEveryFeedFetchedReportsTheLegacyLinesAndExitsZero`, `testNotExpiredFeedsAreReportedAsStillFresh`, `testAFailedFeedExitsOneAndKeepsTheLegacyWording` |

### Parsing (FR-19 – FR-25)

| ID | Requirement | Satisfied by |
|---|---|---|
| FR-19 | RSS 0.9, 0.91, 0.92, 1.0 (RDF), 2.0 and Atom 1.0 are read into one normalised model. | `src/Parse/FeedParser.php`, `src/Parse/Model/`; `Unit\Parse\FeedParserTest` (64 tests) |
| FR-20 | JSON Feed 1.1 is read into the same model. | `src/Parse/JsonFeedReader.php`; `Unit\Parse\JsonFeedReaderTest` (28 tests) |
| FR-21 | A feed missing titles, dates or items is rendered as best it can be rather than refused. | `Unit\Parse\FeedParserTest::testOddFeedsAreToleratedRatherThanRefused`, `Unit\Parse\JsonFeedReaderTest::testOddDocumentsAreToleratedRatherThanRefused` |
| FR-22 | The publication date is kept both parsed and exactly as the feed wrote it, so classic templates can print the original bytes. | `Item::$published` and `Item::$rawDate`, `src/Parse/DateParser.php`; `tests/Golden/ClassicTemplateGoldenTest.php` |
| FR-23 | An item identifier is the feed's own `guid` or `atom:id` where there is one. | `src/Parse/FeedParser.php`; `Unit\Parse\FeedParserTest` |
| FR-24 | A site URL can be turned into feed URLs by autodiscovery, resolving relative, root-relative, scheme-relative and `<base href>` forms, with conventional fallback paths when the page declares nothing. | `src/Parse/Autodiscovery.php`; `Unit\Parse\AutodiscoveryTest` (29 tests), fixtures in `tests/fixtures/html/` |
| FR-25 | A feed that cannot be parsed is logged and rendered as an empty channel; it does not fail the page. A cached body that will not parse is discarded and refetched exactly once. | `FeedService::parseQuietly()`, `FeedService::loadOne()`; `Integration\Http\FeedServiceTest::testAFeedThatWillNotParseDoesNotTakeThePageDownWithIt`, `testAnUnparseableCacheEntryIsDiscardedAndRefetchedOnce`, `testAFeedThatIsGenuinelyBrokenIsNotRefetchedRepeatedly` |

### Rendering (FR-26 – FR-40)

| ID | Requirement | Satisfied by |
|---|---|---|
| FR-26 | A template is split on `<!-- x -->` / `<!-- ENDx -->` for `header`, `channel`, `news`, `footer` and `between`, using 1.6's `substr()` rule: the opening marker is part of the chunk, the closing one is not. | `src/Render/TemplateEngine.php`; `Unit\Render\TemplateEngineTest::testChunkKeepsItsOpeningMarkerAndDropsTheClosingOne`, `testWhitespaceBeforeTheNextMarkerBelongsToThePreviousChunk` |
| FR-27 | The `zFeeder template header` section is emitted once per page and is optional. | `Unit\Render\TemplateEngineTest::testPageHeaderIsOptional` |
| FR-28 | A template missing a required marker is an error naming the template and the marker. | `Unit\Render\TemplateEngineTest::testMissingOpeningMarkerThrows`, `testMissingClosingMarkerThrows`, `testClosingMarkerBeforeOpeningMarkerThrows` |
| FR-29 | The fifteen 1.6 tokens exist, with 1.6's names and substitution order. No token is ever removed. | `src/Render/Tokens.php::LEGACY`; `Unit\Render\FiltersTest::testTokenTable` |
| FR-30 | Nine tokens are added in 2.0: `author`, `summary`, `content`, `enclosure`, `itemdate_iso`, `itemdate_rel`, `feedid`, `position`, `set`. They are HTML-escaped by default. | `src/Render/Tokens.php::MODERN`; `Unit\Render\FiltersTest::testNewTokensAreHtmlEscapedByDefault`, `Unit\Render\RendererTest::testTheNineNewTokensAreSuppliedAndEscapedByDefault` |
| FR-31 | Three filters exist and no more: `raw`, `trunc:N`, `date:"format"`. There is no expression language. | `src/Render/Filters.php`; `Unit\Render\FiltersTest::testKnownFilterList`, `testUnknownOrMalformedFilterThrows`, `testFiltersChain` |
| FR-32 | An unknown token is printed literally, as 1.6 printed it. | `Unit\Render\FiltersTest::testUnknownTokensAreLeftLiteralExactlyAs16Did` |
| FR-33 | Braces in CSS or JavaScript inside a template are not mistaken for tokens. | `Unit\Render\FiltersTest::testCssAndJavascriptBracesAreNotMistakenForTokens` |
| FR-34 | Feed content is never re-scanned for tokens after substitution. | `Renderer::renderChunk()` — the 2.0 pass runs before the legacy pass; `Unit\Render\RendererTest::testTokensInsideFeedTextAreNeverExpandedAgain` |
| FR-35 | `channel_location` (`top`, `bottom`, `none`) and `channel_one_bar` reproduce 1.6's channel-bar placement. | `Renderer::renderFeed()`; `Unit\Render\RendererTest::testChannelLocationNoneDrawsNoBar`; goldens `simplegray.goldenA.chan-*.html` |
| FR-36 | `showedItems` limits items using 1.6's break condition, and `zfmore` lifts the limit for one feed only. | `Unit\Render\RendererTest::testShowedItemsLimitMatchesThe16BreakCondition`, `testMoreLiftsTheLimitForThatFeedOnly`; golden `simplegray.goldenC.more0.html` |
| FR-37 | `zfposition` selects feeds and renders them in the order the request gave. | `RenderRequest::positionFilter()`, `Renderer::inRequestedOrder()`; `Unit\Render\RendererTest::testPositionFilterSelectsAndOrders`; golden `simplegray.goldenA.pos2.html` |
| FR-38 | The "powered by zFeeder" line is the same bytes as 1.6 and is suppressed by `zf_link=off` or by `powered_by`. | `Renderer::POWERED_BY`; `Unit\Render\RendererTest::testPoweredByIsAppendedWhenBothConfigAndRequestAllowIt`; golden `simplegray.goldenA.nolink.html` |
| FR-39 | A requested category that does not exist falls back to the default, then to the first existing category; an empty category renders 1.6's " No feeds." | `FeedService::resolveCategory()`, `Renderer::NO_FEEDS`; `Unit\Render\RendererTest::testAnEmptyCategoryRendersThe16Message` |
| FR-40 | Item bodies are sanitised and truncated to `max_description_chars`; `allow_html_in_items` off reduces them to escaped text. | `Renderer::itemBody()`; `Unit\Render\RendererTest::testMaxDescriptionCharsIsApplied`, `testAllowHtmlInItemsOffFlattensAndEscapes`, `Unit\Render\TruncatorTest` (14 tests) |

### Embedding (FR-41 – FR-47)

| ID | Requirement | Satisfied by |
|---|---|---|
| FR-41 | `zfeeder()` returns rendered HTML; `zfeeder_echo()` prints it; `zfeeder_feeds()` returns the parsed channels. | `src/Embed/functions.php`, `src/Embed/Embed.php`; `Integration\Http\EmbedEndpointsTest::testTheIncludeFunctionProducesTheSameHtmlAsTheEndpoint`, `testTheChannelHelperReturnsParsedFeeds` |
| FR-42 | `include 'zfeeder.php'` prints the output, reading `zfcategory`, `zftemplate`, `zfposition`, `zfmore` and `zf_link` from the query string, as 1.6 did. | `zfeeder.php`; `Integration\Http\EmbedEndpointsTest::testEmbedAcceptsBothTheModernAndThe2004ParameterNames`, `testThePoweredByLineCanBeTurnedOffPerRequestAsIn2004` |
| FR-43 | An embedded render that throws never breaks the host page: it returns an HTML comment in production. | `Embed::render()`; `Integration\Http\EmbedEndpointsTest::testAnEmbeddedBlockNeverBreaksThePageThatHostsIt` |
| FR-44 | `GET /embed` returns an HTML fragment with the same content a PHP include would produce, accepting both the prefixed and unprefixed parameter spellings. | `src/Http/PublicController.php::embed()`; `Integration\Http\EmbedEndpointsTest::testEmbedReturnsAFragmentRatherThanAWholeDocument`, `testEmbedAcceptsBothTheModernAndThe2004ParameterNames`, `testEmbedRestrictsToTheRequestedPositions` |
| FR-45 | `GET /api/feeds` returns the parsed channels as JSON, limited to each subscription's `showedItems`, and is 404 when `api_enabled` is off. | `PublicController::api()`; `Integration\Http\EmbedEndpointsTest::testTheJsonApiReturnsTheParsedFeeds`, `testTheJsonApiCanBeTurnedOff`, `testTheJsonApiLeaksNoConfiguration` |
| FR-46 | `GET /api/opml/{category}` returns the category as OPML, and is 404 unless `opml_export_public` is on. | `PublicController::opml()`; `Integration\Http\EmbedEndpointsTest::testOpmlExportIsPrivateUnlessItIsTurnedOn`, `testOpmlExportOfAnUnknownCategoryIsNotFound` |
| FR-47 | Cross-origin headers on `/embed` and `/api/feeds` are sent only for origins listed in `embed_cors_origins`; `frame-ancestors` comes from `embed_frame_ancestors`. | `src/Http/SecurityHeaders.php::forEmbed()`; `Security\S12EmbedCorsTest` (22 tests), `Integration\Http\HttpHardeningTest::testTheEmbedEndpointAnswersCrossOriginOnlyForAConfiguredOrigin`, `testTheEmbedFrameAncestorsPolicyIsConfigurable` |

### Administration (FR-48 – FR-58)

| ID | Requirement | Satisfied by |
|---|---|---|
| FR-48 | The panel has the screens 1.6 had: main, add new, subscriptions, config, import feed list, updates, plus sign-in and sign-out. | `src/Admin/AdminRoutes.php`, `src/Admin/Controller/`; `Integration\Admin\ScreenAccessTest::testSignedInVisitorSeesTheScreen`, `testTheMenuMarksTheCurrentScreen` |
| FR-49 | Reading and writing are different routes: a GET never changes data. | `AdminRoutes::register()`; `Integration\Admin\CsrfProtectionTest::testTheListAboveCoversEveryPostRoute` |
| FR-50 | Sign-in requires the configured user name and a password verifying against the Argon2id hash; an unset hash disables the panel. | `src/Admin/Auth/SessionAuth.php`, `src/Admin/Auth/PasswordHasher.php`; `Integration\Admin\LoginTest::testCorrectCredentialsSignTheAdministratorIn`, `testAWrongPasswordIsRefusedWithoutSayingWhy`, `testAnUnknownUserGetsExactlyTheSameAnswer`; `Integration\Admin\ScreenAccessTest::testThePanelIsUnavailableWithoutAPassword`, `testThePanelCanBeSwitchedOffEntirely` |
| FR-51 | Repeated failed sign-ins are refused for `login_window_seconds` after `login_max_attempts`. | `src/Admin/Auth/RateLimiter.php`; `Integration\Admin\LoginTest::testTooManyAttemptsAreThrottled`, `testThrottlingRefusesEvenTheCorrectPassword` |
| FR-52 | Every mutating request carries a CSRF token; a missing token is treated as a wrong one. | `src/Admin/Auth/Csrf.php`, `AbstractController::csrfValid()`; `Integration\Admin\CsrfProtectionTest::testAMissingTokenIsRefused`, `testAWrongTokenIsRefused`, `testAnEmptySessionHasNoTokenToMatch`, `testTheListAboveCoversEveryPostRoute` |
| FR-53 | "Add new" accepts a site URL (autodiscovery) or a feed URL (preview), and subscribes the result to a chosen category. | `src/Admin/Controller/AddFeedController.php`; `Integration\Admin\AddFeedScreenTest::testAutodiscoveryListsWhatThePageDeclares`, `testAFeedAddressIsFetchedAndPreviewed`, `testSubscribingAppendsToTheChosenCategory`, `testSubscribingTwiceToTheSameFeedIsRefused` |
| FR-54 | "Subscriptions" lists position, subscribed, channel, refresh interval and shown items, and can save, delete and reorder. | `src/Admin/Controller/SubscriptionsController.php`, `templates-admin/shared/subscriptions.twig`; `Integration\Admin\SubscriptionsScreenTest::testTheTableListsEverySubscription`, `testSavingChangesTheStore`, `testDeletingSelectedRowsRemovesThemAndRenumbersTheRest`, `testARowCanBeMovedUp`, `testARowCanBeMovedDown` |
| FR-55 | "Config" offers every option the schema marks `adminEditable`; an option set by the environment is shown read-only, and a secret is never printed back. | `src/Admin/Controller/ConfigController.php`, `Config::isLockedByEnvironment()`, `Schema::SECRET_KEYS`; `Integration\Admin\ConfigScreenTest::testEveryOptionIsOnTheScreen`, `testAnOptionTheEnvironmentOwnsIsShownLockedAndNotSaved`, `testTheSecretsAreNeverPrintedBack`, `testASavedValueSurvivesTheRoundTrip` |
| FR-56 | "Import" accepts an OPML file upload of at most 1 MiB or a URL, and appends or replaces a category. | `src/Admin/Controller/ImportController.php`; `Integration\Admin\ImportScreenTest::testAnUploadedListIsImported`, `testAListCanBeImportedFromAnAddress`, `testImportingCanAppendOrReplace`, `testAnOversizedUploadIsRefused` |
| FR-57 | The panel renders in either the `classic` or the `modern` skin, selected by `admin_skin`. | `templates-admin/classic/layout.twig`, `templates-admin/modern/layout.twig`, `src/Admin/View/TwigFactory.php`; `Integration\Admin\ScreenAccessTest::testBothSkinsRenderTheSameScreens` |
| FR-58 | With `demo_mode` on, every unsafe method is refused except sign-in and sign-out. | `src/Admin/Middleware/DemoMode.php`; `Integration\Admin\DemoModeTest::testEveryWriteIsRefused`, `testScreensAreStillReadable`, `testTheVisitorCanStillSignInAndOut`, `testTheSubscriptionListIsUntouched` |

### Command line (FR-59 – FR-69)

Every command below is present in `php bin/zfeeder list` and is exercised by
`tests/Cli/` through Symfony's `CommandTester`, with the application assembled
the way `bin/zfeeder` assembles it.

| ID | Requirement | Satisfied by |
|---|---|---|
| FR-59 | `refresh` fetches every subscription, or one category with `--category`, ignoring the interval with `--force`, reporting as JSON with `--json`. | `src/Cli/Command/RefreshCommand.php`; `Cli\RefreshCommandTest::testEveryFeedFetchedReportsTheLegacyLinesAndExitsZero`, `testJsonReportCarriesTheOutcomeOfEveryFeed`, `testAFeedSubscribedTwiceIsFetchedOnce` |
| FR-60 | Two refreshes cannot overlap: a held lock exits 0 with a message. | `RefreshCommand::acquireLock()`; `Cli\RefreshCommandTest::testASecondRunFindsTheLockHeldAndExitsZeroWithoutFetching`, `testTheLockIsReleasedSoTheNextRunProceeds` |
| FR-61 | `refresh` exits 0 when everything succeeded, 1 when a feed failed, 2 when the named category does not exist. | `RefreshCommand::execute()`; `Cli\RefreshCommandTest::testAFailedFeedExitsOneAndKeepsTheLegacyWording`, `testAnUnknownCategoryIsAUsageError` |
| FR-62 | `list-feeds` prints every category and subscription with its last fetch, as tables or with `--json`. | `src/Cli/Command/ListFeedsCommand.php`; `Cli\ListFeedsCommandTest::testTableShowsSubscriptionsAndTheirCacheState`, `testJsonJoinsTheSubscriptionWithItsLastFetch` |
| FR-63 | `add <url>` subscribes a feed, with `--category`, `--items`, `--refresh`, `--title` and `--no-verify`. | `src/Cli/Command/AddFeedCommand.php`; `Cli\AddFeedCommandTest::testFetchesTheFeedAndTakesItsTitle`, `testNoVerifyStoresTheFeedWithoutAnyRequest`, `testTakesTheNextPositionAndKeepsTheExistingFeeds` |
| FR-64 | `import <source>` reads an OPML file or URL, with `--category` and `--replace`. | `src/Cli/Command/ImportCommand.php`; `Cli\ImportCommandTest::testImportsFromAFileAndAppends`, `testImportsFromAUrlThroughTheHttpClient`, `testReplaceThrowsAwayTheOldList` |
| FR-65 | `export` writes OPML to standard output or to `--output`, for one `--category` or all. | `src/Cli/Command/ExportCommand.php`; `Cli\ExportCommandTest::testOneCategoryGoesToStandardOutputAsOpml`, `testWritesToAFileWhenAskedTo`, `testEveryCategoryIsMergedAndRenumbered` |
| FR-66 | `migrate --to=flat\|sqlite` moves both stores, with `--verify`, `--dry-run` and `--ensure-schema`. | `src/Cli/Command/MigrateCommand.php`, `src/Storage/Migrator.php`; `Cli\MigrateCommandTest::testFlatToSqliteAndBackRoundTripsARealCategorySet`, `testDryRunWritesNothing`, `testEnsureSchemaCreatesTheDatabaseAndIsSafeToRepeat` |
| FR-67 | `check-config` prints the effective value of every option and where it came from, with `--json`, never printing a secret. | `src/Cli/Command/CheckConfigCommand.php`, `Config::sourceOf()`; `Cli\CheckConfigCommandTest::testAWorkingInstallationPassesEveryCheck`, `testTheSecretsAreNeverPrintedOnlyWhetherTheyAreSet`, `testJsonReportIsRedactedToo` |
| FR-68 | `hash-password` prints an Argon2id hash, prompting twice without echo when no argument is given. | `src/Cli/Command/HashPasswordCommand.php`; `Cli\HashPasswordCommandTest::testPromptsTwiceAndAcceptsMatchingAnswers`, `testTheHashVerifiesAgainstThePassword`, `testAShortPasswordIsRefused` |
| FR-69 | `purge` removes cache entries `--older-than` a duration, or `--all`, with `--dry-run`. | `src/Cli/Command/PurgeCommand.php`; `Cli\PurgeCommandTest::testOlderThanRemovesOnlyTheEntriesPastTheCutoff`, `testAllIgnoresTheAgeEntirely`, `testDryRunReportsTheSameSetButRemovesNothing` |

### Migration from 1.6 (FR-70 – FR-74)

| ID | Requirement | Satisfied by |
|---|---|---|
| FR-70 | `legacy-import <path>` reads a 1.x `newsfeeds/` directory: its categories, its settings and its templates. | `src/Cli/Command/LegacyImportCommand.php`; `Cli\LegacyImportCommandTest::testImportsEveryCategoryOfTheReal2004Installation`, `testMapsTheConfigPhpDefinesToTheNewKeys`, `testEverySubscriptionIsRewrittenAsValidOpml20`, `testCopiesAUserTemplateAndRefusesABrokenOne`, `testDryRunWritesNothingAtAll` |
| FR-71 | `legacy-import --cache` also imports the 1.x cache, so the first page load is warm. | `src/Cli/Command/LegacyImportCommand.php`, `src/Legacy/LegacyCacheLocator.php`; `Cli\LegacyImportCommandTest::testImportsThe16CacheWhenAsked` |
| FR-72 | A 1.6 cache file can be located from its feed URL using the 2004 name-mangling rule. | `Unit\Legacy\LegacyCacheLocatorTest::testFindReturnsTheFileAZfeederSixteenWouldHaveWritten`, `testTheFileNameMatchesTheNineteenNinetiesScheme` |
| FR-73 | The 1.6 MD5 password cannot be migrated; an installation without a new hash has no working panel. | `PasswordHasher`, `Schema` (`admin_password_hash` default empty); `Cli\LegacyImportCommandTest::testReportsThatThePasswordCannotBeMigrated`, `Integration\Admin\ScreenAccessTest::testThePanelIsUnavailableWithoutAPassword`. See [UPGRADING-FROM-1.6.md](UPGRADING-FROM-1.6.md) |
| FR-74 | Output from the classic template set is byte-identical to zFeeder 1.6's output for the same inputs. | `tests/Golden/ClassicTemplateGoldenTest.php` against 49 files recorded by `tools/record-goldens.sh` |

## Non-functional requirements

| ID | Area | Requirement | Acceptance criterion | Status |
|---|---|---|---|---|
| NFR-01 | Security | Every control S1–S18 of `project/PLAN.md` B6 is implemented. | One class per control in `tests/Security/`, `S01…` to `S18…`, 484 tests; each row of the control table in [THREAT-MODEL.md](THREAT-MODEL.md) names the file and the tests. | Implemented and tested. The classes were not run against a build with the controls removed; [TEST-PLAN.md](TEST-PLAN.md) records that gap |
| NFR-02 | Security | A security fix ships with a test that fails without it. | Stated in [CONTRIBUTING.md](../CONTRIBUTING.md); enforced by review. | Policy |
| NFR-03 | Security | No forbidden construct appears in `src/`, `bin/` or `public/`. | `./tools/check-forbidden.sh` exits 0. | Enforced in CI |
| NFR-04 | Security | No known vulnerability in a declared dependency. | `composer audit` exits 0 in the **Dependency and secret scan** job. | Enforced in CI |
| NFR-05 | Security | No secret in the repository. | `gitleaks/gitleaks-action@v2` reports nothing. | Enforced in CI |
| NFR-06 | Security | A software bill of materials accompanies each release. | `php tools/sbom.php` writes CycloneDX 1.5; `.github/workflows/release.yml` attaches it. | Implemented |
| NFR-07 | Accessibility | The `modern` template set and both admin skins meet WCAG 2.2 AA. | axe reports zero violations on every page. | **Met for the modern set, the demonstration site and the default admin skin**: `public.spec.js`, `templates.spec.js` and `admin.spec.js` run axe across three Playwright projects, 258 tests, zero violations. The `classic` admin skin is covered only by `build/check-admin.mjs`, which is not in CI. See [ACCESSIBILITY.md](ACCESSIBILITY.md) |
| NFR-08 | Accessibility | The `classic` set reproduces 2004 markup and is exempt. | `tests/e2e/specs/templates.spec.js` loads the classic set and asserts rendering only, and asserts `<font` is still present in `classic/bluelogos`; axe is never run on it. See [ACCESSIBILITY.md](ACCESSIBILITY.md). | Documented exemption, enforced by omission |
| NFR-09 | Performance | Rendering opens no socket when every cached copy is fresh. | `Unit\Fetch\FeedFetcherTest::testNotExpiredEntryShortCircuitsWithoutAnyRequest` | Met |
| NFR-10 | Performance | A single feed response cannot exceed `fetch_max_bytes` (default 2 MiB) or `fetch_timeout` (default 10 s). | `Unit\Fetch\FeedFetcherTest::testBodyLargerThanTheLimitIsAbandoned`, `testBodyExactlyAtTheLimitIsAccepted`, `testAnAnnouncedOversizeIsRefusedBeforeReading`, `testTheConfiguredTimeoutIsPassedToTheTransport` | Met |
| NFR-11 | Performance | An oversized response is abandoned while streaming, not after buffering. | `FeedFetcher::readBounded()`; the tests above. | Met |
| NFR-12 | Portability | PHP 8.3 and 8.4, with `dom`, `json`, `libxml`, `mbstring` and `simplexml`. SQLite needs `pdo_sqlite`. | The `tests` matrix in `.github/workflows/ci.yml` runs PHP 8.3 and 8.4 × flat and sqlite. | Met |
| NFR-13 | Portability | No build step and no database server. Runs from an archive on shared hosting. | `tools/build-dist.sh` produces an archive containing `vendor/`. See [DEPLOYMENT.md](DEPLOYMENT.md). | Met |
| NFR-14 | Portability | Configuration is available three ways — environment, JSON file, admin panel — with the environment winning. | `Unit\Config\ConfigLoaderTest::testTheEnvironmentOverridesTheFile`, `testTheFileOverridesTheDefaults`, `testWithoutAFileOrEnvironmentEverythingIsTheSchemaDefault` | Met |
| NFR-15 | Maintainability | Architecture layers are enforced, not described. | `vendor/bin/deptrac analyse --config-file=deptrac.yaml` exits 0. | Enforced in CI |
| NFR-16 | Maintainability | Static analysis at PHPStan level 8 with strict rules. | `vendor/bin/phpstan analyse` exits 0. | Enforced in CI |
| NFR-17 | Maintainability | PSR-12 coding standard. | `vendor/bin/php-cs-fixer fix --dry-run --diff` exits 0. | Enforced in CI |
| NFR-18 | Maintainability | Line coverage of `src/` is at least 90 per cent. | `php tools/coverage-gate.php coverage.xml 90` on the PHP 8.3 / flat matrix leg. | Met: 90.62 % (4,896 of 5,403 lines) on 18 September 2026 |
| NFR-19 | Maintainability | `docs/CONFIGURATION.md` is generated from `Config\Schema` and cannot drift. | `php bin/zfeeder docs:config --check` exits 0; `Cli\DocsConfigCommandTest::testCheckPassesAgainstTheCommittedFile`, `testCheckFailsWhenTheFileHasDrifted`. | Implemented |
| NFR-20 | Compatibility | Classic template output is byte-identical to 1.6 for the recorded corpus. The comparison is `assertSame` on the whole string, never whitespace-normalised. | `Golden\ClassicTemplateGoldenTest::testGoldenIsReproducedByteForByte`, 49 cases. | Met |
| NFR-21 | Compatibility | All fourteen classic templates are covered by goldens. | `Golden\ClassicTemplateGoldenTest::testEveryClassicTemplateIsCovered` | Met |
| NFR-22 | Compatibility | No 1.6 token is ever removed. | `Unit\Render\FiltersTest::testTokenTable`; stated in [CONTRIBUTING.md](../CONTRIBUTING.md). | Met |
| NFR-23 | Compatibility | The offline refresh report keeps 1.6's wording. | `Cli\RefreshCommandTest::testEveryFeedFetchedReportsTheLegacyLinesAndExitsZero`, `testNotExpiredFeedsAreReportedAsStillFresh` | Met |
| NFR-24 | Observability | `GET /healthz` returns JSON with `status`, `version`, `storage` and per-check results, and 503 when the data directory is not writable. | `Integration\Http\EmbedEndpointsTest::testHealthReportsTheVersionAndBackend`. See [OBSERVABILITY.md](OBSERVABILITY.md). | Met |
| NFR-25 | Observability | One log line per record: UTC timestamp, level, interpolated message, JSON context. Level threshold from `log_level`. | `src/Log/FileLogger.php`; `Integration\Http\FeedServiceTest::testTheLoggerWritesWhereConfigurationSaysAndRespectsTheLevel` | Implemented |
| NFR-26 | Observability | The log can be sent to standard output for containers. | `log_path` accepts `php://stdout`. | Implemented |
| NFR-27 | Determinism | No test opens a network connection. Time is injected wherever a decision depends on it. | `MockHttpClient` in `Unit\Fetch\FeedFetcherTest`; injected resolver in `Unit\Fetch\UrlGuardTest`; clock parameters on `Kernel`, `FeedFetcher`, `SessionAuth` and `RateLimiter`. | Met |
| NFR-28 | Error handling | No stack trace, file path or exception message reaches a client in production. A refusal below 500 is logged at notice level; a `TemplateException` is a 404. | `src/Http/ErrorHandler.php`; `Security\S16ErrorHandlerTest` (22 tests), `Integration\Http\HttpHardeningTest::testProductionErrorsRevealNothingAboutTheServer` | Implemented |

## Traceability matrix

Requirement → implementation → proof. Every row now names a test. The remaining
honest gaps are listed in [TEST-PLAN.md](TEST-PLAN.md#known-gaps) rather than
here, because they are gaps in *how* things are proven rather than requirements
with no proof at all.

| Requirement | Implemented in | Proven by |
|---|---|---|
| FR-01, FR-02 | `src/Subscription/Category.php`, `src/Subscription/Feed.php` | `Unit\Storage\FlatSubscriptionStoreTest`, `Unit\Storage\SqliteSubscriptionStoreTest` |
| FR-03, FR-04 | `src/Subscription/Opml/Reader.php` | `Unit\Subscription\OpmlReaderTest` (36 tests) |
| FR-05 | `src/Subscription/Opml/Writer.php` | `Unit\Subscription\OpmlWriterTest` (22 tests) |
| FR-06, FR-08 | `src/Storage/StoreFactory.php`, both adapters | `tests/Support/SubscriptionStoreContractTestCase.php`, `tests/Support/CacheStoreContractTestCase.php`, `Unit\Storage\StoreFactoryTest` |
| FR-07 | `src/Storage/Migrator.php` | `Unit\Storage\MigratorTest` (5 tests) |
| FR-09 | `src/Storage/AtomicFile.php` | `Unit\Config\ConfigWriterTest`, `Unit\Storage\FlatCacheStoreTest` |
| FR-10 – FR-16 | `src/Fetch/FeedFetcher.php`, `src/Storage/CacheEntry.php` | `Unit\Fetch\FeedFetcherTest` (34 tests) |
| FR-18 | `src/Fetch/FetchResult.php` | `Cli\RefreshCommandTest` |
| FR-17, FR-25 | `src/FeedService.php` | `Integration\Http\FeedServiceTest` (17 tests) |
| FR-19 – FR-23 | `src/Parse/FeedParser.php`, `src/Parse/JsonFeedReader.php`, `src/Parse/DateParser.php` | `Unit\Parse\FeedParserTest` (64), `Unit\Parse\JsonFeedReaderTest` (28) |
| FR-24 | `src/Parse/Autodiscovery.php` | `Unit\Parse\AutodiscoveryTest` (29 tests) |
| FR-26 – FR-28 | `src/Render/TemplateEngine.php`, `src/Render/Template.php` | `Unit\Render\TemplateEngineTest` (16 tests) |
| FR-29 – FR-34 | `src/Render/Tokens.php`, `src/Render/Filters.php` | `Unit\Render\FiltersTest` (20 tests), `Unit\Render\RendererTest` |
| FR-35 – FR-39 | `src/Render/Renderer.php`, `src/Render/RenderRequest.php` | `Unit\Render\RendererTest` (14 tests), `Golden\ClassicTemplateGoldenTest` |
| FR-40 | `src/Render/Sanitizer.php`, `src/Render/Truncator.php` | `Unit\Render\SanitizerTest` (23), `Unit\Render\TruncatorTest` (14), `Security\S05SanitizerTest` (87) |
| FR-41 – FR-46 | `src/Embed/`, `zfeeder.php`, `src/Http/PublicController.php` | `Integration\Http\EmbedEndpointsTest` (16 tests), `Integration\Http\DemoSiteTest` (12 tests), `tests/e2e/specs/public.spec.js` |
| FR-47 | `src/Http/SecurityHeaders.php::forEmbed()` | `Security\S12EmbedCorsTest` (22), `Integration\Http\HttpHardeningTest` (13) |
| FR-48 – FR-58 | `src/Admin/` | `tests/Integration/Admin/`: `LoginTest`, `ScreenAccessTest`, `CsrfProtectionTest`, `DemoModeTest`, `AddFeedScreenTest`, `SubscriptionsScreenTest`, `ConfigScreenTest`, `ImportScreenTest`; `tests/e2e/specs/admin.spec.js` in a real browser |
| FR-59 – FR-69 | `src/Cli/` | `tests/Cli/`: one test class per command, except `seed` (see [TEST-PLAN.md](TEST-PLAN.md#known-gaps)) |
| FR-70, FR-71 | `src/Cli/Command/LegacyImportCommand.php`, `src/Legacy/` | `Cli\LegacyImportCommandTest` (10 tests) |
| FR-72 | `src/Legacy/LegacyCacheLocator.php` | `Unit\Legacy\LegacyCacheLocatorTest` (11 tests) |
| FR-74, NFR-20, NFR-21 | `src/Render/Renderer.php` + `templates/classic/` | `Golden\ClassicTemplateGoldenTest` (51 tests) |
| NFR-01 | see [THREAT-MODEL.md](THREAT-MODEL.md) | `tests/Security/` S01–S18 (484); plus `Unit\Fetch\UrlGuardTest` (62), `Unit\Render\SanitizerTest` (23), `Unit\Render\TemplateLocatorTest` (16), `Unit\Config\ConfigLoaderTest` (40) |
| NFR-03 – NFR-06 | `tools/check-forbidden.sh`, `tools/sbom.php` | `.github/workflows/ci.yml`, `.github/workflows/release.yml` |
| NFR-12, NFR-14 | `src/Config/` | `Unit\Config\ConfigLoaderTest` (40 tests), the CI matrix |
| NFR-15 – NFR-18 | `deptrac.yaml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `tools/coverage-gate.php` | `.github/workflows/ci.yml`, job **Lint and static analysis** |
| NFR-19 | `src/Cli/Command/DocsConfigCommand.php` | `php bin/zfeeder docs:config --check` |
| NFR-24 – NFR-26 | `src/Http/PublicController.php::health()`, `src/Log/FileLogger.php` | `Integration\Http\FeedServiceTest::testTheLoggerWritesWhereConfigurationSaysAndRespectsTheLevel`, `tests/e2e/specs/public.spec.js` (`/healthz` reports version and backend); `/healthz` is also polled by `tools/run-e2e.sh` and by the image smoke test |
| NFR-27 | `Kernel`, `FeedFetcher`, `SessionAuth`, `RateLimiter` clock parameters; `ArraySession`; `MockHttpClient` | The suites named above |

## Out of scope

These are not requirements, now or in 2.x. The reasoning is in
[ROADMAP.md](ROADMAP.md); the list matches `project/PLAN.md` sections 1 and 14.

| Not doing | Why |
|---|---|
| Read and unread state | It needs per-reader identity, which needs accounts |
| Full-text search across items | It needs an index and a retention policy; the cache holds only the latest body of each feed |
| More than one user account | The panel is a single-administrator tool, as 1.6 was |
| A database server (MySQL, PostgreSQL) | The product's identity is that it runs where nothing is installed |
| A JavaScript framework | Five server-rendered screens; htmx is vendored and there is no build step |
| WAP and WML output | The transport no longer exists. `legacy/zfeeder-1.6/newsfeeds/wap.php` is kept as history |
| HTML frames | Replaced by the CSS-grid aggregator page |
| Server HTTP Basic authentication as a login mode | Left to the web server; the panel implements one authentication system |
| A third template set | `Render\TemplateLocator::SETS` is a closed list of `classic` and `modern` |
| Live-network tests | Every fixture is recorded; see [TEST-PLAN.md](TEST-PLAN.md) |
