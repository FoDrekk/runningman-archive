<?php
// ============================================================
// RmScrapingEngine — the orchestrator.
//
// The pipeline, in the order the archive needs it:
//
//   detect gaps → pick only the sources that fill them
//   → fetch each source independently (failures isolated per source)
//   → normalise → validate → resolve field-by-field with confidence
//   → diff against the database → apply safety guards
//   → write inside a transaction (or, in dry-run, write nothing)
//   → record provenance, changes, health, thumbnails, review flags
//
// Every stage is separately testable and separately loggable, which is
// the difference between "the scraper broke" and "myrunningman's
// location selector stopped matching on 2026-08-30 at 02:14".
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/DataNormalizer.php';
require_once __DIR__ . '/DataValidator.php';
require_once __DIR__ . '/FieldResolver.php';
require_once __DIR__ . '/DiffEngine.php';
require_once __DIR__ . '/Provenance.php';
require_once __DIR__ . '/SourceHealth.php';
require_once __DIR__ . '/SourceRegistry.php';
require_once __DIR__ . '/ThumbnailEngine.php';
require_once __DIR__ . '/MissingData.php';
require_once __DIR__ . '/Integrity.php';
require_once __DIR__ . '/RunLog.php';

class RmScrapingEngine
{
    private ?PDO $db;
    private RmSourceRegistry  $registry;
    private RmFieldResolver   $resolver;
    private RmDiffEngine      $differ;
    private RmProvenance      $prov;
    private RmSourceHealth    $health;
    private RmMissingData     $missing;
    private RmThumbnailEngine $thumbs;
    private RmGuestResolver   $guests;
    private RmDuplicateDetector $dupes;
    private ?RmScrapeRun      $run = null;
    private ?int              $latestKnown = null;

    public function __construct(?PDO $db = null)
    {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
        $this->registry = RmSourceRegistry::instance();
        $this->resolver = new RmFieldResolver();
        $this->differ   = new RmDiffEngine();
        $this->prov     = new RmProvenance($this->db);
        $this->health   = RmSourceHealth::instance();
        $this->missing  = new RmMissingData($this->db);
        $this->thumbs   = new RmThumbnailEngine($this->db);
        $this->guests   = new RmGuestResolver($this->db);
        $this->dupes    = new RmDuplicateDetector($this->db);
    }

    public function registry(): RmSourceRegistry { return $this->registry; }
    public function missingData(): RmMissingData { return $this->missing; }
    public function thumbnails(): RmThumbnailEngine { return $this->thumbs; }
    public function run(): ?RmScrapeRun { return $this->run; }

    /**
     * Every log line in the engine goes through here, so a standalone
     * episode sync is just as traceable as one inside a run — the only
     * difference is that its rows carry a NULL run_id.
     */
    /** Set for the duration of a read-only observation (the admin trace). */
    private bool $readOnly = false;

    private function log(string $event, string $message = '', array $ctx = []): void
    {
        if ($this->readOnly) return;
        if ($this->run) $this->run->log($event, $message, $ctx);
        else RmScrapeRun::note($event, $message, $ctx);
    }

    private function tally(string $key, int $n = 1): void
    {
        if ($this->run) $this->run->count($key, $n);
    }

    // ────────────────────────────────────────────────────────────
    // STAGE 1 — COLLECT
    // ────────────────────────────────────────────────────────────
    /**
     * Fetch one episode from the sources that can supply $wantFields.
     * A source failing here is contained: it is recorded and skipped,
     * never allowed to abort the other sources or the episode.
     *
     * @return array{payloads:array<string,array>, meta:array<string,array>}
     */
    public function collect(int $epNum, array $wantFields = [], array $opt = []): array
    {
        $ctx = [
            'year'         => rmYear($epNum),
            'latest'       => $this->latestKnown,
            'bypass_cache' => !empty($opt['bypass_cache']),
        ];

        // A dry run must leave no trace of data it did not save; a
        // read-only observation must leave no trace at all, including
        // health — tracing an episode to diagnose a problem must never
        // be the thing that puts a source into a cool-down.
        $readOnly   = !empty($opt['read_only']);
        $recordProv = !$readOnly && empty($opt['dry_run']);
        $this->readOnly = $readOnly;

        // Selection ignores health: a source in a cool-down is still worth
        // ASKING, because its answer may already be cached. What the
        // cool-down forbids is opening a connection to it, and that is
        // enforced per-request below.
        $sources = !empty($opt['sources'])
            ? array_values(array_filter((array)$opt['sources'], fn($s) => $this->registry->usable($s, true)))
            : ($wantFields
                ? $this->registry->sourcesForFields($wantFields, true)
                : array_keys($this->registry->active(true)));

        $payloads = []; $meta = [];

        foreach ($sources as $name) {
            $adapter = $this->registry->get($name);
            if (!$adapter) continue;
            if (!$adapter->fields()) continue;   // enrichment-only source (Wikidata)

            $suppressed = empty($opt['ignore_health']) && $this->health->isSuppressed($name);
            $adapter->setCacheOnly($suppressed);

            $t0 = microtime(true);
            try {
                $data = $adapter->episode($epNum, $ctx);
            } catch (Throwable $e) {
                // An adapter throwing is a bug in that adapter, not a
                // reason to lose the other sources' data.
                $data = ['_url'=>'', '_status'=>'adapter_error', '_error'=>get_class($e) . ': ' . $e->getMessage(),
                         '_error_class'=>'adapter_exception', '_ms'=>(int)round((microtime(true)-$t0)*1000)];
            }

            $status = (string)($data['_status'] ?? 'unknown');
            $fields = array_values(array_filter(array_keys($data), fn($k) => !str_starts_with($k, '_')));

            // A suppressed source that produced nothing was never asked;
            // say that, rather than reporting a fetch failure it did not
            // actually suffer.
            if ($suppressed && !$fields) {
                $status = 'suppressed';
                $data['_error'] = 'In a health cool-down and nothing cached for this episode — not contacted';
                $data['_error_class'] = RmHttpClient::CLASS_COOLING_DOWN;
            }

            $meta[$name] = [
                'status'  => $status,
                'url'     => $data['_url'] ?? null,
                'error'   => $data['_error'] ?? null,
                'class'   => $data['_error_class'] ?? null,
                'http'    => $data['_http'] ?? null,
                'ms'      => (int)($data['_ms'] ?? 0),
                'fields'  => $fields,
                'cached'  => !empty($data['_cached']),
                'version' => $adapter->parserVersion(),
            ];

            // Health: distinguish "worked", "reachable but produced
            // nothing", "structure changed", and "couldn't reach it".
            $outcome = match (true) {
                $status === 'ok' && $fields !== []      => 'success',
                $status === 'parser_warning'            => 'parser_warning',
                // Reached the page, but its content is rendered client-side
                // so nothing could be extracted. A real problem needing a
                // real fix — but not a fetch failure, and not healthy.
                $status === 'needs_javascript'          => 'parser_warning',
                // Reached the source but parsed nothing: a parser problem,
                // not a transport failure. Calling it a failure would hide
                // the one signal that says "our selectors are stale".
                $status === 'ok'                        => 'parser_warning',
                // Never contacted: a cool-down, a disabled source, or one
                // that supplies no episode fields at all. Counting these
                // as failures blames sources for work they were never
                // asked to do.
                in_array($status, ['suppressed','disabled','not_applicable','skipped'], true) => 'skipped',
                // Contacted and healthy, but has nothing for THIS episode.
                in_array($status, ['empty','missing_episode'], true) => 'empty',
                default                                 => 'failure',
            };
            if ($readOnly) {
                // observed only — nothing recorded
            } elseif ($suppressed) {
                // Not contacted, so this run learned nothing about the
                // source's health either way. Leave the cool-down to
                // elapse on its own rather than clearing it on the
                // strength of a cached read.
                $meta[$name]['suppressed'] = true;
            } elseif ($outcome !== 'skipped') {
                $this->health->record($name, $outcome, [
                    'error_class'    => $data['_error_class'] ?? null,
                    'error'          => $data['_error'] ?? null,
                    'ms'             => (int)($data['_ms'] ?? 0),
                    'parser_version' => $adapter->parserVersion(),
                ]);
            }

            if ($recordProv) $this->prov->recordSource($epNum, $name, [
                'url'            => $data['_url'] ?? null,
                'status'         => $status,
                'http_status'    => $data['_http'] ?? null,
                'parser_version' => $adapter->parserVersion(),
                'fields'         => $fields,
                'content_hash'   => $data['_hash'] ?? null,
                'ms'             => (int)($data['_ms'] ?? 0),
            ]);

            $line = $outcome === 'success'
                ? 'fetched (' . implode(', ', $fields) . ')'
                  . (!empty($data['_cached']) ? ' [cache]' : '')
                  . ($suppressed ? ' [cooling down — served from cache, not contacted]' : '')
                : ($data['_error'] ?? $status);
            $this->log('source.' . $outcome, $line,
                ['episode'=>$epNum,'source'=>$name,'ms'=>(int)($data['_ms'] ?? 0),
                 'level'=>match ($outcome) {
                     'success', 'skipped' => 'info',
                     'failure'            => 'error',
                     default              => 'warning',
                 }]);
            $this->tally(match ($outcome) {
                'success'        => 'src_ok',
                'failure'        => 'src_failed',
                'parser_warning' => 'src_warned',
                'skipped'        => 'src_skipped',
                default          => 'src_empty',   // reachable, healthy, nothing for this episode
            });

            if ($fields) {
                $payload = [];
                foreach ($fields as $f) $payload[$f] = $data[$f];
                $payload['_url'] = $data['_url'] ?? null;
                $payloads[$name] = $payload;
            }

            if ($status === 'parser_warning' && $recordProv) {
                $this->prov->flag('parser_warning', 'source', null, $epNum,
                    "$name: " . ($data['_error'] ?? 'reachable but produced no fields'));
            }
        }

        // Account for every registered source, so the report never leaves
        // one silently unexplained. Three distinct reasons to not contact
        // a source, and they must not look alike:
        //   suppressed  — in an automatic health cool-down
        //   disabled    — switched off in config, or missing its API key
        //   skipped     — simply not needed for the fields being filled
        foreach ($this->registry->all() as $name => $a) {
            if (isset($meta[$name]) || !$a->fields()) continue;

            if (!$a->isEnabled()) {
                $reason = in_array($name, ['tmdb','tvdb'], true)
                    ? 'No API key configured — optional source, skipped at no cost'
                    : 'Disabled in config/scraping.php';
                $meta[$name] = ['status'=>'disabled','url'=>null,'error'=>$reason,
                                'class'=>'not_configured','http'=>null,'ms'=>0,'fields'=>[],'cached'=>false,'version'=>$a->parserVersion()];
            } elseif (empty($opt['ignore_health']) && $this->health->isSuppressed($name)) {
                $h = $this->health->get($name);
                $meta[$name] = ['status'=>'suppressed','url'=>null,
                                'error'=>'In an automatic cool-down until ' . ($h['suppressed_until'] ?? '?')
                                       . ' after repeated failures (' . ($h['last_error'] ?? 'no detail') . ')',
                                'class'=>'cooling_down','http'=>null,'ms'=>0,'fields'=>[],'cached'=>false,'version'=>$a->parserVersion()];
                $this->log('source.suppressed', 'skipped while cooling down after repeated failures',
                           ['episode'=>$epNum,'source'=>$name,'level'=>'warning']);
            } else {
                $meta[$name] = ['status'=>'skipped','url'=>null,'error'=>'Not needed for the requested fields',
                                'class'=>null,'http'=>null,'ms'=>0,'fields'=>[],'cached'=>false,'version'=>$a->parserVersion()];
            }
            $this->tally('src_skipped');
        }

        return ['payloads' => $payloads, 'meta' => $meta];
    }

    // ────────────────────────────────────────────────────────────
    // STAGE 2 — PLAN (collect → resolve → diff). Writes nothing.
    // ────────────────────────────────────────────────────────────
    public function plan(int $epNum, array $opt = []): array
    {
        $existing = $this->missing->currentValues($epNum);
        $isNew    = empty($existing);

        $wanted = (array)($opt['fields'] ?? []);
        if (!$wanted) {
            // Only ask for what is actually missing, unless told otherwise.
            $wanted = (!empty($opt['all_fields']) || $isNew)
                ? array_keys((array)rmScrapeConfig('field_priority', []))
                : $this->fieldsForGaps($this->missing->gaps($epNum, $existing));
        }
        // With thumbnails skipped, image_url must not enter the diff:
        // proposing a change nothing will act on makes every re-sync look
        // like it has pending work forever.
        if (!empty($opt['skip_thumbnail'])) $wanted = array_values(array_diff($wanted, ['image_url']));

        if (!$wanted) {
            return ['episode'=>$epNum,'skipped'=>true,'reason'=>'Episode is already complete — no sources contacted',
                    'is_new'=>false,'changes'=>[],'apply'=>[],'resolved'=>[],'meta'=>[],'warnings'=>[],
                    'summary'=>['total_applied'=>0],'existing'=>$existing];
        }

        // A caller that has already collected (the research service does,
        // so it can build evidence before deciding) passes the result
        // through rather than making every source answer the same
        // question twice in one episode.
        $collected = (isset($opt['collected']) && is_array($opt['collected']) && isset($opt['collected']['meta']))
            ? $opt['collected']
            : $this->collect($epNum, $wanted, $opt);
        if (!$collected['payloads']) {
            // Name the actual obstacle. "No data" is not a diagnosis, and
            // "every source is in a cool-down" needs a completely different
            // response from "this episode isn't covered anywhere".
            $byStatus = [];
            foreach ($collected['meta'] as $m) $byStatus[$m['status']] = ($byStatus[$m['status']] ?? 0) + 1;
            $contacted = array_sum(array_diff_key($byStatus, array_flip(['skipped','disabled','suppressed'])));
            $reason = match (true) {
                ($byStatus['suppressed'] ?? 0) > 0 && $contacted === 0 =>
                    'Every usable source is in an automatic cool-down after repeated failures — clear cool-downs in the Scraper Control Centre once the cause is fixed',
                ($byStatus['needs_javascript'] ?? 0) > 0 && ($byStatus['parser_warning'] ?? 0) === 0 =>
                    'Reached the source, but its content is rendered by JavaScript and cannot be read from the served HTML ('
                    . ($byStatus['needs_javascript']) . ' affected) — capture the real endpoint with tools/capture_source.php',
                ($byStatus['parser_warning'] ?? 0) > 0 =>
                    'Sources were reachable but produced no fields — their page structure may have changed (' . ($byStatus['parser_warning']) . ' affected)',
                ($byStatus['fetch_failed'] ?? 0) > 0 && $contacted === ($byStatus['fetch_failed'] ?? 0) =>
                    'No source could be reached — check network connectivity and source health',
                ($byStatus['missing_episode'] ?? 0) > 0 =>
                    'No source lists this episode yet (' . ($byStatus['missing_episode']) . ' checked and reported it missing)',
                ($byStatus['empty'] ?? 0) > 0 =>
                    'Sources were reachable but none carries the fields this episode is missing',
                default => 'No source returned usable data for this episode',
            };
            // Distinguish "could not check" from "checked, nothing new".
            //   · a NEW episode with no data anywhere is a real failure:
            //     the run set out to create it and could not.
            //   · an EXISTING episode is untouched and intact. If sources
            //     were reachable and simply had nothing for it, that is a
            //     coverage gap — a skip, not a failure.
            //   · but if every source that was contacted failed at the
            //     transport level, the run genuinely could not check, and
            //     that stays a failure however intact the episode is.
            $transportFailed = ($byStatus['fetch_failed'] ?? 0) + ($byStatus['blocked'] ?? 0)
                             + ($byStatus['rate_limited'] ?? 0) + ($byStatus['adapter_error'] ?? 0);
            $couldNotCheck   = $contacted > 0 && $transportFailed === $contacted;
            $isFailure       = $isNew || $couldNotCheck || $contacted === 0;

            $warnings = [];
            if (($byStatus['parser_warning'] ?? 0) > 0) {
                $warnings[] = ['field' => '*', 'type' => 'parser_warning',
                               'message' => ($byStatus['parser_warning']) . ' source(s) loaded but parsed nothing — selectors may be stale'];
            }
            $warnings[] = ['field' => '*', 'type' => $isFailure ? 'no_data' : 'no_new_data',
                           'message' => $isFailure
                               ? 'No source returned usable data — existing data left untouched'
                               : 'No source had data for the missing fields — the episode is unchanged and intact'];

            return ['episode'=>$epNum,
                    'skipped'=>!$isFailure, 'failed'=>$isFailure,
                    'reason'=>$isFailure ? $reason : ($reason . ' — episode left unchanged'),
                    'is_new'=>$isNew,'changes'=>[],'apply'=>[],'resolved'=>[],'meta'=>$collected['meta'],
                    'warnings'=>$warnings,
                    'source_summary'=>$byStatus,
                    'summary'=>['total_applied'=>0],'existing'=>$existing];
        }

        $resolved = $this->resolver->resolve($collected['payloads'], [
            'episode_number' => $epNum,
            'expected_year'  => rmYear($epNum),
        ]);
        // Restrict to the fields we actually set out to fill.
        $resolved = array_intersect_key($resolved, array_flip($wanted));

        // Tell the diff engine WHERE the current values came from, so it
        // can stop a weaker class of source from overwriting a stronger
        // one's work.
        $opt['existing_sources'] = $this->prov->fieldSources($epNum);

        $diff = $this->differ->diff($existing, $resolved, $opt);

        return [
            'episode'  => $epNum,
            'skipped'  => false,
            'is_new'   => $isNew,
            'wanted'   => $wanted,
            'resolved' => $resolved,
            'changes'  => $diff['changes'],
            'apply'    => $diff['apply'],
            'warnings' => $diff['warnings'],
            'summary'  => $diff['summary'],
            'meta'     => $collected['meta'],
            'payloads' => $collected['payloads'],
            'existing' => $existing,
        ];
    }

    /** Map missing DB fields onto the engine's source field names. */
    private function fieldsForGaps(array $gaps): array
    {
        $map = [
            'title'=>['title','title_ko'], 'air_date'=>['air_date'], 'synopsis'=>['synopsis'],
            'mission'=>['mission'], 'teams'=>['teams'], 'results'=>['results'],
            'location'=>['location'], 'guests'=>['guests'], 'thumbnail'=>['image_url'],
        ];
        $out = [];
        foreach ($gaps as $g) foreach ($map[$g] ?? [] as $f) $out[$f] = true;
        // Tags are cheap: they ride along with the location source anyway.
        if (isset($out['location'])) $out['tags'] = true;
        return array_keys($out);
    }

    // ────────────────────────────────────────────────────────────
    // STAGE 3 — APPLY
    // ────────────────────────────────────────────────────────────
    /**
     * Persist a plan. With dry_run the plan is returned unchanged and
     * NOTHING is written — same code path, same decisions, no writes.
     */
    public function apply(array $plan, array $opt = []): array
    {
        $epNum  = (int)$plan['episode'];
        $dryRun = !empty($opt['dry_run']);
        $plan['dry_run'] = $dryRun;
        $plan['written'] = [];
        $plan['thumbnail'] = null;

        if (!empty($opt['read_only'])) { $plan['dry_run'] = true; $plan['read_only'] = true; return $plan; }
        if (!empty($plan['skipped']) || !empty($plan['failed'])) return $plan;
        if ($this->db === null) { $plan['failed'] = true; $plan['reason'] = 'No database connection'; return $plan; }

        $apply = (array)$plan['apply'];
        $isNew = !empty($plan['is_new']);

        // Duplicate suspicion is checked BEFORE inserting a new episode.
        if ($isNew) {
            $suspects = $this->dupes->check($epNum, [
                'title'    => $apply['title'] ?? null,
                'air_date' => $apply['air_date'] ?? null,
                'guests'   => $apply['guests'] ?? [],
                'source_urls' => array_map(fn($m) => $m['url'] ?? null, (array)$plan['meta']),
            ]);
            if ($suspects) {
                $plan['duplicate_suspects'] = $suspects;
                if (!$dryRun) $this->dupes->flagAll($epNum, $suspects);
                $this->log('duplicate.suspected',
                    'Possible duplicate of EP' . implode(', EP', array_column($suspects, 'episode_number')) . ' — saved anyway, flagged for review',
                    ['episode'=>$epNum, 'level'=>'warning']);
            }
        }

        if ($dryRun) {
            // Still resolve what the thumbnail step WOULD do, without fetching.
            if (!empty($apply['image_url'])) $plan['thumbnail'] = ['ok'=>null,'reason'=>'Dry run — thumbnail not downloaded','url'=>$apply['image_url']];
            return $plan;
        }

        $inTx = false;
        try {
            if (!$this->db->inTransaction()) { $this->db->beginTransaction(); $inTx = true; }

            $epId = $isNew ? $this->insertEpisode($epNum, $apply) : (int)($plan['existing']['episode_id'] ?? 0);
            if (!$epId) throw new RuntimeException("Could not resolve episode_id for EP$epNum");

            $written = $isNew ? array_keys($apply) : $this->updateEpisode($epNum, $epId, $apply);

            // Guests — additive, identity-resolved, never bulk-deleted.
            if (!empty($apply['guests'])) {
                $added = 0; $reviews = [];
                foreach ((array)$apply['guests'] as $name) {
                    $r = $this->guests->resolve((string)$name, $epNum, $plan['resolved']['guests']['source'] ?? null);
                    if (!$r['guest_id']) { if ($r['review']) $reviews[] = $r['review']; continue; }
                    $this->db->prepare('INSERT IGNORE INTO episode_guests (episode_id, guest_id) VALUES (?,?)')
                             ->execute([$epId, $r['guest_id']]);
                    if ($r['created']) $added++;
                    if ($r['review']) $reviews[] = $r['review'];
                }
                $plan['guest_reviews'] = $reviews;
                if ($added) $this->log('guests.new', "$added new guest record(s) created", ['episode'=>$epNum]);
            }

            // Tags — additive.
            if (!empty($apply['tags'])) {
                foreach ((array)$apply['tags'] as $tag) {
                    $t = RmNormalizer::tag((string)$tag);
                    if ($t === null) continue;
                    $s = $this->db->prepare('SELECT tag_id FROM tags WHERE name=?');
                    $s->execute([$t]);
                    $tid = $s->fetchColumn();
                    if (!$tid) { $this->db->prepare('INSERT INTO tags (name) VALUES (?)')->execute([$t]); $tid = (int)$this->db->lastInsertId(); }
                    $this->db->prepare('INSERT IGNORE INTO episode_tags (episode_id, tag_id) VALUES (?,?)')->execute([$epId, (int)$tid]);
                }
            }

            if ($inTx) { $this->db->commit(); $inTx = false; }
            $plan['written'] = $written;

            // ── Post-commit work: provenance, alt titles, thumbnail ──
            foreach ((array)$plan['resolved'] as $field => $r) {
                if (($r['value'] ?? null) !== null) $this->prov->recordField($epNum, $field, $r);
                if (($r['confidence'] ?? null) === RmFieldResolver::CONFLICT) {
                    $this->prov->flag('field_conflict', 'episode', $epId, $epNum,
                        "Field '$field': " . ($r['source'] ?? '?') . ' says "' . mb_substr((string)$this->flatten($r['value']), 0, 80) . '" but ' .
                        implode('; ', array_map(fn($c) => ($c['source'] ?? '?') . ' says "' . mb_substr((string)$c['value'], 0, 60) . '"', (array)$r['conflicts'])));
                }
            }
            $this->prov->recordChanges($epNum, (array)$plan['changes'], $this->run?->id);

            if (!empty($plan['resolved']['title_ko']['value'])) {
                $this->prov->recordAltTitle($epNum, (string)$plan['resolved']['title_ko']['value'], 'ko',
                                            $plan['resolved']['title_ko']['source'] ?? null);
            }
            foreach ((array)($plan['resolved']['guests']['review'] ?? []) as $rev) {
                $this->prov->flag('similar_guest', 'episode', $epId, $epNum,
                    ($rev['a'] ?? '?') . ' vs ' . ($rev['b'] ?? '?') . ' — ' . ($rev['reason'] ?? 'similar names'));
            }

            if (empty($opt['skip_thumbnail'])) {
                $plan['thumbnail'] = $this->handleThumbnail($epNum, $plan);
            }

            @unlink(sys_get_temp_dir() . '/rm_stats.json');   // existing site stats cache
            return $plan;

        } catch (Throwable $e) {
            if ($inTx && $this->db->inTransaction()) $this->db->rollBack();
            $plan['failed'] = true;
            $plan['reason'] = 'Save failed: ' . $e->getMessage();
            $this->log('episode.save_failed', $e->getMessage(), ['episode'=>$epNum, 'level'=>'error']);
            return $plan;
        }
    }

    private function flatten($v): string
    {
        return is_array($v) ? implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v)) : (string)$v;
    }

    private function insertEpisode(int $epNum, array $apply): int
    {
        $year   = rmYear($epNum);
        $padded = str_pad((string)$epNum, 3, '0', STR_PAD_LEFT);

        $y = $this->db->prepare('SELECT year_id FROM years WHERE year_label=?');
        $y->execute([$year]);
        $yrId = (int)$y->fetchColumn();
        if (!$yrId) {
            $this->db->prepare('INSERT INTO years (year_label, total_eps) VALUES (?,0)')->execute([$year]);
            $yrId = (int)$this->db->lastInsertId();
        }

        $locId = isset($apply['location']) ? $this->resolveLocation($apply['location']) : null;
        $cols = ['episode_number','year_id','title','air_date','runtime_minutes','synopsis','main_mission','location_id','verification_required'];
        $vals = [$epNum, $yrId, $apply['title'] ?? "Episode #$padded", $apply['air_date'] ?? null, 90,
                 $apply['synopsis'] ?? null, $apply['mission'] ?? null, $locId, 1];

        if ($this->teamsResultsExist()) {
            array_splice($cols, 7, 0, ['teams','results']);
            array_splice($vals, 7, 0, [$apply['teams'] ?? null, $apply['results'] ?? null]);
        }
        $this->db->prepare('INSERT INTO episodes (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                 ->execute($vals);
        $epId = (int)$this->db->lastInsertId();

        $this->db->prepare('UPDATE years SET total_eps=(SELECT COUNT(*) FROM episodes WHERE year_id=?) WHERE year_id=?')
                 ->execute([$yrId, $yrId]);
        return $epId;
    }

    /** @return string[] the columns actually written */
    private function updateEpisode(int $epNum, int $epId, array $apply): array
    {
        $map = ['title'=>'title','air_date'=>'air_date','synopsis'=>'synopsis',
                'mission'=>'main_mission','special_notes'=>'special_notes'];
        if ($this->teamsResultsExist()) { $map['teams'] = 'teams'; $map['results'] = 'results'; }

        $sets = []; $params = []; $written = [];
        foreach ($map as $field => $col) {
            if (!array_key_exists($field, $apply)) continue;
            $v = $apply[$field];
            if ($v === null || $v === '') continue;      // never write emptiness over data
            $sets[] = "$col = ?"; $params[] = $v; $written[] = $field;
        }
        if (array_key_exists('location', $apply)) {
            $locId = $this->resolveLocation($apply['location']);
            if ($locId) { $sets[] = 'location_id = ?'; $params[] = $locId; $written[] = 'location'; }
        }
        if ($sets) {
            $params[] = $epId;
            $this->db->prepare('UPDATE episodes SET ' . implode(', ', $sets) . ' WHERE episode_id = ?')->execute($params);
        }
        return $written;
    }

    private function teamsResultsExist(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try { $this->db->query('SELECT teams, results FROM episodes LIMIT 1'); return $has = true; }
        catch (Throwable $e) { return $has = false; }
    }

    /**
     * Find or create a location row. Normalised parts (city / country /
     * overseas flag) are only ever ADDED to an existing row that lacks
     * them — nothing already recorded, including lat/long, is touched.
     */
    private function resolveLocation($value): ?int
    {
        $loc = is_array($value) ? $value : RmNormalizer::location((string)$value);
        if (!$loc || empty($loc['name'])) return null;
        $name = $loc['name'];

        try {
            // Exact match first, then identity-key match so "Seoul City"
            // and "Seoul, South Korea" don't create two rows for one place.
            $s = $this->db->prepare('SELECT location_id FROM locations WHERE name = ? LIMIT 1');
            $s->execute([$name]);
            $id = $s->fetchColumn();

            if (!$id) {
                $key = RmNormalizer::locationKey($name);
                $s = $this->db->prepare('SELECT location_id, name FROM locations WHERE name LIKE ? LIMIT 50');
                $s->execute([mb_substr($name, 0, 4) . '%']);
                foreach ($s->fetchAll() as $row) {
                    if (RmNormalizer::locationKey((string)$row['name']) === $key) { $id = (int)$row['location_id']; break; }
                }
            }

            if (!$id) {
                $this->db->prepare('INSERT INTO locations (name, city, country, is_overseas) VALUES (?,?,?,?)')
                         ->execute([$name, $loc['city'] ?? null, $loc['country'] ?? null, (int)($loc['is_overseas'] ?? 0)]);
                return (int)$this->db->lastInsertId();
            }

            // Enrich blanks only.
            $cur = $this->db->prepare('SELECT city, country FROM locations WHERE location_id=?');
            $cur->execute([$id]);
            $row = $cur->fetch() ?: [];
            $sets = []; $params = [];
            if (empty($row['city']) && !empty($loc['city']))       { $sets[] = 'city = ?';    $params[] = $loc['city']; }
            if (empty($row['country']) && !empty($loc['country'])) { $sets[] = 'country = ?'; $params[] = $loc['country'];
                                                                     $sets[] = 'is_overseas = ?'; $params[] = (int)($loc['is_overseas'] ?? 0); }
            if ($sets) { $params[] = $id; $this->db->prepare('UPDATE locations SET ' . implode(', ', $sets) . ' WHERE location_id = ?')->execute($params); }
            return (int)$id;
        } catch (Throwable $e) { return null; }
    }

    /**
     * Thumbnail acquisition with fallback across EVERY source that offered
     * an image, in image_url priority order — the resolver's winner first,
     * then the rest. One source serving a dead link or an HTML error page
     * no longer means the episode goes without a thumbnail.
     */
    private function handleThumbnail(int $epNum, array $plan): ?array
    {
        $candidates = []; $seen = [];
        $add = function (?string $url, string $src) use (&$candidates, &$seen) {
            $u = RmNormalizer::url($url);
            if ($u === null || isset($seen[$u])) return;
            $seen[$u] = true;
            $candidates[] = ['url' => $u, 'source' => $src];
        };

        // The resolved winner leads.
        if (!empty($plan['resolved']['image_url']['value'])) {
            $add((string)$plan['resolved']['image_url']['value'], (string)($plan['resolved']['image_url']['source'] ?? 'unknown'));
        }
        // Then every other offer, in this field's configured priority order.
        foreach ((array)rmScrapeConfig('field_priority.image_url', []) as $src) {
            $offer = $plan['payloads'][$src]['image_url'] ?? null;
            if (is_string($offer)) $add($offer, $src);
        }
        if (!$candidates) return null;

        $res = $this->thumbs->acquire($epNum, rmYear($epNum), $candidates);
        if ($res['ok'] && $res['path'] && !$res['skipped']) {
            $this->thumbs->link($epNum, $res['path'], $res['url']);
        }
        $this->log($res['ok'] ? 'thumbnail.ok' : 'thumbnail.failed',
            $res['reason'] ?? ($res['ok'] ? 'verified and stored' : 'no usable image'),
            ['episode'=>$epNum,'source'=>$res['source'],'level'=>$res['ok'] ? 'info' : 'warning']);
        return $res;
    }

    // ────────────────────────────────────────────────────────────
    // STAGE 4 — SINGLE EPISODE, END TO END
    // ────────────────────────────────────────────────────────────
    public function syncEpisode(int $epNum, array $opt = []): array
    {
        $t0 = microtime(true);
        $this->log('episode.start', "EP$epNum started", ['episode'=>$epNum]);

        $plan   = $this->plan($epNum, $opt);
        $result = $this->apply($plan, $opt);
        $ms     = (int)round((microtime(true) - $t0) * 1000);
        $result['ms'] = $ms;

        if (!empty($result['skipped'])) {
            $this->tally('skipped');
            $this->log('episode.skipped', (string)($result['reason'] ?? 'already complete'), ['episode'=>$epNum,'ms'=>$ms]);
        } elseif (!empty($result['failed'])) {
            $this->tally('failed');
            $this->log('episode.failed', (string)($result['reason'] ?? 'unknown failure'), ['episode'=>$epNum,'ms'=>$ms,'level'=>'error']);
        } else {
            $applied = (int)($result['summary']['total_applied'] ?? 0);
            if (!empty($result['is_new'])) $this->tally('added'); elseif ($applied > 0) $this->tally('updated'); else $this->tally('skipped');
            $this->log('episode.saved',
                (!empty($result['is_new']) ? 'added' : ($applied ? "updated ($applied field" . ($applied === 1 ? '' : 's') . ')' : 'no changes needed'))
                . (!empty($opt['dry_run']) ? ' [DRY RUN — nothing written]' : ''),
                ['episode'=>$epNum,'ms'=>$ms]);
        }
        $this->tally('checked');
        return $result;
    }

    // ────────────────────────────────────────────────────────────
    // STAGE 5 — A WHOLE RUN
    // ────────────────────────────────────────────────────────────
    /**
     * @param string $mode latest|single|range|missing|failed|unstable|thumbnails|full
     */
    public function runMode(string $mode, array $opt = []): array
    {
        $dryRun = !empty($opt['dry_run']);
        $this->run = new RmScrapeRun($mode, ['dry_run' => $dryRun, 'scope' => $opt['scope'] ?? null]);

        // Latest-episode detection feeds both the targeting and the cache TTLs.
        if (in_array($mode, ['latest','missing','full'], true) || !empty($opt['detect_latest'])) {
            $det = RmLatestEpisode::detect();
            $this->latestKnown = $det['latest'];
            $opt['latest'] = $det['latest'];
            $this->run->log('latest.detected',
                'EP' . ($det['latest'] ?? '?') . ' — ' . $det['note'],
                ['level' => $det['conflict'] ? 'warning' : 'info']);
            if ($det['conflict']) $this->run->warn('latest.conflict', $det['note']);
        }

        $targets = $this->missing->targetsFor($mode, $opt);
        $queue   = $targets['episodes'];
        $perEpFields = $targets['fields'];

        $this->run->log('run.start', $targets['label'] . ' — ' . count($queue) . ' episode(s) queued' . ($dryRun ? ' [DRY RUN]' : ''));

        $results = [];
        $deadline = !empty($opt['time_limit']) ? microtime(true) + (float)$opt['time_limit'] : null;

        foreach ($queue as $i => $epNum) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $this->run->warn('run.time_limit', 'Time limit reached — remaining episodes checkpointed for resume');
                $this->run->checkpoint($epNum, array_slice($queue, $i));
                $summary = $this->run->finish('running', 'Paused at the time limit; resume from the checkpoint');
                return ['summary'=>$summary,'results'=>$results,'targets'=>$targets,'paused'=>true];
            }

            $epOpt = $opt;
            if (!empty($perEpFields[$epNum])) $epOpt['fields'] = $this->fieldsForGaps($perEpFields[$epNum]);
            $results[$epNum] = $this->syncEpisode($epNum, $epOpt);
            $this->run->checkpoint($epNum, array_slice($queue, $i + 1));
        }

        $summary = $this->run->finish('completed');
        $c = $summary['counts'];
        $this->run->log('run.finish', "checked {$c['checked']} · added {$c['added']} · updated {$c['updated']} · skipped {$c['skipped']} · failed {$c['failed']}");
        return ['summary'=>$summary,'results'=>$results,'targets'=>$targets,'paused'=>false];
    }

    /** Continue an interrupted run from its stored cursor. */
    public function resume(array $runRow, array $opt = []): array
    {
        $queue = json_decode((string)($runRow['cursor_state'] ?? ''), true);
        if (!is_array($queue) || !$queue) return ['summary'=>null,'results'=>[],'resumed'=>false,'reason'=>'Nothing left to resume'];
        RmScrapeRun::markAborted((int)$runRow['run_id'], 'Superseded by a resume run');

        $this->run = new RmScrapeRun((string)$runRow['mode'], ['dry_run'=>!empty($runRow['dry_run']),'scope'=>'resume of run #' . $runRow['run_id']]);
        $this->run->log('run.resume', 'Resuming run #' . $runRow['run_id'] . ' with ' . count($queue) . ' episode(s) remaining');

        $results = [];
        foreach ($queue as $i => $epNum) {
            $results[(int)$epNum] = $this->syncEpisode((int)$epNum, $opt);
            $this->run->checkpoint((int)$epNum, array_slice($queue, $i + 1));
        }
        return ['summary'=>$this->run->finish('completed'),'results'=>$results,'resumed'=>true];
    }

    /** Dry run: what WOULD change. Writes no episode data or provenance. */
    public function dryRun(int $epNum, array $opt = []): array
    {
        return $this->syncEpisode($epNum, $opt + ['dry_run' => true, 'all_fields' => true]);
    }

    /**
     * Pure observation for the admin trace: contacts the sources,
     * computes the entire plan, and writes NOTHING — not episode data,
     * not provenance, not source health, not a log row. Diagnosing an
     * episode must never change the archive or the engine's own state.
     */
    public function trace(int $epNum, array $opt = []): array
    {
        $plan = $this->plan($epNum, $opt + ['read_only' => true, 'dry_run' => true, 'all_fields' => true]);
        $this->readOnly = false;
        $plan['dry_run'] = true;
        $plan['read_only'] = true;
        return $plan;
    }
}
