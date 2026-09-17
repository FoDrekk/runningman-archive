"""
benchmark/report.py — turns a BenchmarkRunner.run() result into (a) a
structured summary dict (added into the JSON output) and (b) a human-
readable Markdown report answering the benchmark's 9 questions (A-I).

UNSUPPORTED fields are reported explicitly, never silently omitted —
e.g. NO current adapter extracts location or team/result data at all;
the report says so directly rather than leaving a blank.
"""
from __future__ import annotations

SOURCES = ("fandom", "wikipedia", "tvmaze", "sbs")

# Per Phase 18's field list. "location"/"team_result" have NO current
# adapter support at all (see sources/*.py — none populates
# extracted_synopsis or any location/team-result equivalent) — reported
# as UNSUPPORTED_BY_ALL_ADAPTERS, an explicit finding, not a gap in the
# benchmark itself.
UNSUPPORTED_FIELDS = ("location", "team_result")


def summarize(result: dict) -> dict:
    per_episode = result["per_episode"]
    n_episodes = len(per_episode)

    accessibility = {src: {"accessible": 0, "total": n_episodes} for src in ("fandom", "wikipedia")}
    field_coverage = {
        src: {"episode_number": 0, "air_date": 0, "title": 0, "guests": 0, "thumbnail": 0}
        for src in ("fandom", "wikipedia")
    }
    for rec in per_episode:
        for src in ("fandom", "wikipedia"):
            r = rec[src]
            if r.get("accessible"):
                accessibility[src]["accessible"] += 1
            if r.get("episode_number_confirmed"):
                field_coverage[src]["episode_number"] += 1
            if r.get("air_date"):
                field_coverage[src]["air_date"] += 1
            if r.get("title"):
                field_coverage[src]["title"] += 1
            if r.get("guests"):
                field_coverage[src]["guests"] += 1
            if r.get("thumbnail_available"):
                field_coverage[src]["thumbnail"] += 1

    tvmaze_corroborated = sum(1 for rec in per_episode if (rec["tvmaze"] or {}).get("corroborated_by_air_date"))
    tvmaze_targetable = sum(1 for rec in per_episode if (rec["tvmaze"] or {}).get("targetable"))
    sbs_applicable = sum(1 for rec in per_episode if (rec["sbs"] or {}).get("applicable"))
    sbs_matched = sum(1 for rec in per_episode if (rec["sbs"] or {}).get("matched"))

    date_agreements = [rec["cross_source_agreement"]["air_date"] for rec in per_episode]
    title_agreements = [rec["cross_source_agreement"]["title"] for rec in per_episode]
    date_both_present = sum(1 for a in date_agreements if a["both_present"])
    date_agree = sum(1 for a in date_agreements if a["agree"])
    title_both_present = sum(1 for a in title_agreements if a["both_present"])
    title_agree = sum(1 for a in title_agreements if a["agree"])

    res = result["aggregate_engine_report"]["resolution"]
    failure_tally: dict[str, int] = {}
    for f in res.get("failures", []):
        failure_tally[f["type"]] = failure_tally.get(f["type"], 0) + 1
    for rec in per_episode:
        for src in ("fandom", "wikipedia"):
            fail = rec[src].get("failure")
            if fail:
                failure_tally[fail] = failure_tally.get(fail, 0) + 1

    return {
        "sample_size": n_episodes,
        "accessibility": accessibility,
        "field_coverage": field_coverage,
        "unsupported_fields_by_all_adapters": list(UNSUPPORTED_FIELDS),
        "tvmaze": {
            "targetable_for_canonical_numbering": tvmaze_targetable,  # must always be 0 — see registry.py
            "air_date_corroborations": tvmaze_corroborated,
            "sample_size": n_episodes,
        },
        "sbs": {
            "applicable_sample_points": sbs_applicable,  # SBS has no historical archive; only ever ~1
            "matched_when_applicable": sbs_matched,
            "sample_size": n_episodes,
        },
        "cross_source_agreement": {
            "air_date": {"both_present": date_both_present, "agree": date_agree},
            "title": {"both_present": title_both_present, "agree": title_agree},
        },
        "aggregate_resolution": {
            "latest_episode": res.get("latest_episode"),
            "insufficient_evidence": res.get("insufficient_evidence"),
            "conflicts": len(res.get("conflicts", [])),
            "evidence_confidences": [
                {"source": e["source"], "field": e["field"], "confidence": e["confidence"]}
                for e in res.get("latest_episode_evidence", [])
            ],
        },
        "failure_classification_tally": failure_tally,
        "timings": result["timings"],
    }


def render_markdown(result: dict, summary: dict) -> str:
    lines: list[str] = []
    w = lines.append

    w("# Python Research Engine Benchmark — Phase 18")
    w("")
    mode_banner = "🔴 FIXTURE-BASED RUN" if result["mode"] == "fixture" else "🟢 LIVE RUN"
    w(f"**{mode_banner}** — {result['mode_note']}")
    w("")
    w(f"Generated: {result['timestamp']}")
    w(f"Sample episodes: {', '.join(str(n) for n in result['sample_episodes'])}")
    w(f"Canonical episode-number sources (unchanged by this benchmark): {', '.join(result['canonical_episode_sources'])}")
    w("")

    w("## 1-8: Per-source accessibility and field coverage")
    w("")
    w(f"Sample size: {summary['sample_size']} episodes.")
    w("")
    w("| Source | Accessible | Episode # confirmed | Air date | Title | Guests | Thumbnail |")
    w("|---|---|---|---|---|---|---|")
    for src in ("fandom", "wikipedia"):
        a = summary["accessibility"][src]
        fc = summary["field_coverage"][src]
        w(f"| {src} | {a['accessible']}/{a['total']} | {fc['episode_number']}/{summary['sample_size']} "
          f"| {fc['air_date']}/{summary['sample_size']} | {fc['title']}/{summary['sample_size']} "
          f"| {fc['guests']}/{summary['sample_size']} | {fc['thumbnail']}/{summary['sample_size']} |")
    w("")
    w(f"**TVmaze** is intentionally NOT in the table above: it is non-canonical for episode numbering "
      f"(`registry.CANONICAL_EPISODE_SOURCES` excludes it) and cannot be targeted by episode number at all. "
      f"Of {summary['tvmaze']['sample_size']} sample episodes with a canonical air_date, "
      f"{summary['tvmaze']['air_date_corroborations']} were corroborated by TVmaze's own (non-canonical) data "
      f"purely on matching air_date — never on episode number. "
      f"Targetable-for-canonical-numbering count: **{summary['tvmaze']['targetable_for_canonical_numbering']}** "
      f"(must always be 0).")
    w("")
    w(f"**SBS** has no historical archive — it can only ever speak to whichever episode is CURRENTLY on its "
      f"program page. Of {summary['sbs']['sample_size']} sample episodes, only "
      f"{summary['sbs']['applicable_sample_points']} was applicable (the one marked current), and it matched "
      f"in {summary['sbs']['matched_when_applicable']} of those.")
    w("")
    w("**Location and team/result extraction are UNSUPPORTED BY EVERY CURRENT ADAPTER** — no adapter in "
      "`sources/*.py` populates any location or team/result equivalent field. This is an explicit finding, "
      "not a benchmark gap (measures 6-7 of the brief).")
    w("")

    w("## 9: Cross-source agreement (Fandom vs Wikipedia, where both have data)")
    w("")
    ca = summary["cross_source_agreement"]
    w(f"- Air date: both sources had data for {ca['air_date']['both_present']} episode(s); "
      f"agreed in {ca['air_date']['agree']} of those.")
    w(f"- Title: both sources had data for {ca['title']['both_present']} episode(s); "
      f"agreed in {ca['title']['agree']} of those (titles are commonly worded differently between "
      f"a wiki summary and an encyclopedia entry — disagreement here is expected, not alarming).")
    w("")
    w("| Episode | Fandom date | Wikipedia date | Dates agree | Fandom title | Wikipedia title | Titles agree |")
    w("|---|---|---|---|---|---|---|")
    for rec in result["per_episode"]:
        d = rec["cross_source_agreement"]["air_date"]
        t = rec["cross_source_agreement"]["title"]
        w(f"| {rec['episode_number']} | {d['fandom'] or '—'} | {d['wikipedia'] or '—'} | {'✅' if d['agree'] else ('—' if not d['both_present'] else '❌')} "
          f"| {t['fandom'] or '—'} | {t['wikipedia'] or '—'} | {'✅' if t['agree'] else ('—' if not t['both_present'] else '❌')} |")
    w("")

    w("## 10-11: Aggregate resolution result and confidence (current-state pass, unmodified engine)")
    w("")
    ar = summary["aggregate_resolution"]
    w(f"- `latest_episode`: **{ar['latest_episode']}**")
    w(f"- `insufficient_evidence`: {ar['insufficient_evidence']}")
    w(f"- Conflicts flagged: {ar['conflicts']}")
    if ar["evidence_confidences"]:
        w("")
        w("| Source | Field | Confidence |")
        w("|---|---|---|")
        for e in ar["evidence_confidences"]:
            w(f"| {e['source']} | {e['field']} | {e['confidence']:.2f} |")
    w("")

    w("## 12: Failure classification tally")
    w("")
    if summary["failure_classification_tally"]:
        w("| Failure type | Count |")
        w("|---|---|")
        for k, v in sorted(summary["failure_classification_tally"].items()):
            w(f"| {k} | {v} |")
    else:
        w("None recorded.")
    w("")

    w("## 13: Runtime")
    w("")
    w("| Phase | Milliseconds |")
    w("|---|---|")
    for k, v in summary["timings"].items():
        w(f"| {k} | {v} |")
    w("")
    if result["mode"] == "fixture":
        w("**Note:** these are LOCAL fixture-parsing timings (HTML/JSON parsing + normalization only, no "
          "real network latency) — they measure the engine's own processing cost, not real-world source "
          "response time. A live run's timings would additionally include actual HTTP round-trip time.")
    w("")

    w("## Findings")
    w("")
    w("### A. Which sources are actually useful?")
    w(f"Fandom and Wikipedia are the only two sources this POC treats as canonical for episode numbering, "
      f"and both extracted real per-episode data in this sample "
      f"({summary['field_coverage']['fandom']['episode_number']}/{summary['sample_size']} and "
      f"{summary['field_coverage']['wikipedia']['episode_number']}/{summary['sample_size']} respectively). "
      f"TVmaze and SBS are useful only in narrow, non-canonical roles (see B).")
    w("")
    w("### B. Which fields can each source reliably provide?")
    w("- **Fandom**: episode number (via page-naming convention), air date, title, guests — when a page exists.")
    w("- **Wikipedia**: episode number (via table column), air date, title — no guest data (not in the table).")
    w("- **TVmaze**: air date, title, thumbnail — as non-canonical corroboration only, never episode number.")
    w("- **SBS**: episode number only, and only for whatever is CURRENTLY live on its program page.")
    w("- **No adapter provides location or team/result data.**")
    w("")
    w("### C. Where do sources disagree?")
    w(f"Titles disagree far more often than air dates ({ca['title']['agree']}/{ca['title']['both_present']} vs "
      f"{ca['air_date']['agree']}/{ca['air_date']['both_present']} agreement where both had data) — expected, "
      f"since a wiki editor's episode title and an encyclopedia's title are independently worded. Coverage GAPS "
      f"also disagree: which specific episodes each source happens to have a page/row for is not the same set "
      f"(see the per-episode table above) — this sample deliberately includes such gaps.")
    w("")
    w("### D. How often can the engine resolve an episode without guessing?")
    w(f"In the aggregate current-state pass: {'a specific latest_episode was resolved' if ar['latest_episode'] is not None else 'the engine correctly returned INSUFFICIENT_EVIDENCE rather than guessing'}. "
      f"Per the fixed historical sample, resolution is only possible when at least one CANONICAL source "
      f"(Fandom or Wikipedia) has both the number and a confirming air date for that specific episode — "
      f"this was true for {summary['field_coverage']['fandom']['air_date'] + summary['field_coverage']['wikipedia']['air_date']} "
      f"of {summary['sample_size'] * 2} (source, episode) pairs checked.")
    w("")
    w("### E. What are the common failure modes?")
    if summary["failure_classification_tally"]:
        for k, v in sorted(summary["failure_classification_tally"].items()):
            w(f"- `{k}`: {v} occurrence(s).")
    else:
        w("- None in this run.")
    w("- Structural, not transient: a missing Fandom page or an episode absent from a Wikipedia year table "
      "is a genuine coverage gap, not a parser bug — see the per-episode table.")
    w("")
    w("### F. What is the runtime cost?")
    total_ms = sum(summary["timings"].values())
    w(f"Total measured phase time: {total_ms:.1f} ms "
      f"({'fixture-local parsing only' if result['mode'] == 'fixture' else 'including real network round-trips'}).")
    w("")
    w("### G. What parts of the existing PHP system would potentially benefit from Python?")
    w("_Left for human judgement — see 'Do NOT make the migration decision automatically' in README.md. "
      "This benchmark supplies the evidence (coverage/agreement/failure data above); it does not conclude "
      "a migration recommendation._")
    w("")
    w("### H. What parts should remain in PHP?")
    w("_Same as G — evidence only, no automatic recommendation._")
    w("")
    w("### I. Is there enough evidence to justify a migration, partial integration, or no migration?")
    w("_This benchmark deliberately does NOT answer this question — see README.md's 'Scope' and the task's "
      "explicit instruction not to make the migration decision automatically. A human should read sections "
      "1-13 and A-F above and decide._")
    w("")

    return "\n".join(lines)
