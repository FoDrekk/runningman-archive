<?php
// ============================================================
// RmValidator — the gate between "a source returned HTTP 200" and
// "this is real Running Man data".
//
// Every check here exists because a source has, at some point,
// returned exactly the garbage it rejects: an episode number of
// "Home", a synopsis made of navigation links, a guest list
// containing the literal word "Guests", a title that is really the
// paginated index page, an air date from the site footer's
// copyright line.
//
// check() returns a verdict rather than throwing, so a single bad
// field never discards the good fields alongside it.
// ============================================================
require_once __DIR__ . '/DataNormalizer.php';

class RmValidator
{
    /** Words that reveal page furniture rather than episode prose. */
    private const NAV_WORDS = [
        'sign in','log in','login','register','subscribe','cookie','privacy policy','terms of service',
        'all rights reserved','copyright ©','404','page not found','javascript is disabled',
        'enable javascript','skip to content','main menu','navigation','advertisement',
        'click here','read more »','您','403 forbidden','access denied','just a moment',
        // Site status pages: whatever field these land in, they are the
        // site talking about itself, not about an episode.
        'under maintenance','site maintenance','temporarily unavailable','service unavailable',
        'coming soon','be right back','checking your browser','are you a robot',
        'no results found','nothing found','something went wrong',
    ];

    /** MyDramaList falls back to this show-level blurb when it has no episode text. */
    private const GENERIC_SYNOPSIS = '/members?\s+must\s+compete\s+in\s+a\s+series\s+of\s+games?\s+and\s+missions?\s+to\s+win\s+the\s+race/i';

    public static function ok($value, array $extra = []): array {
        return ['valid' => true, 'value' => $value, 'reason' => null] + $extra;
    }
    public static function fail(string $reason): array {
        return ['valid' => false, 'value' => null, 'reason' => $reason];
    }

    /** Dispatch by field name. Unknown fields pass through a generic text check. */
    public static function check(string $field, $value, array $ctx = []): array {
        return match ($field) {
            'episode_number' => self::episodeNumber($value),
            'title'          => self::title($value, (int)($ctx['episode_number'] ?? 0)),
            'title_ko'       => self::altTitle($value),
            'air_date'       => self::airDate($value, $ctx),
            'synopsis'       => self::synopsis($value),
            'guests'         => self::guests($value),
            'tags'           => self::tags($value),
            'location'       => self::location($value),
            'image_url'      => self::imageUrl($value),
            'mission', 'teams', 'results', 'theme', 'special_notes' => self::shortText($field, $value),
            default          => self::shortText($field, $value),
        };
    }

    public static function episodeNumber($v): array {
        if (is_string($v)) $v = trim($v);
        if (!is_numeric($v)) return self::fail('Episode number is not numeric: ' . self::snippet($v));
        $n = (int)$v;
        if ((string)$n !== (string)(is_string($v) ? ltrim($v, '0') ?: '0' : $n) && !is_int($v) && !ctype_digit((string)$v)) {
            return self::fail('Episode number is not a clean integer: ' . self::snippet($v));
        }
        if ($n < 1 || $n > 2000) return self::fail("Episode number $n is outside the plausible range 1–2000");
        return self::ok($n);
    }

    public static function title($v, int $epNum = 0): array {
        if (!is_string($v)) return self::fail('Title is not a string');
        $t = RmNormalizer::text($v);
        if ($t === null || mb_strlen($t) < 4) return self::fail('Title too short: ' . self::snippet($v));
        if (mb_strlen($t) > 300) return self::fail('Title exceeds 300 chars (probably page text, not a title)');
        if (self::looksLikeNav($t)) return self::fail('Title looks like site navigation: ' . self::snippet($t));
        if (preg_match('/Episodes?\s*[-–]\s*Page\s*\d+/i', $t)) return self::fail('Title is the paginated episode index, not an episode');
        if (preg_match('/^\s*(?:home|episodes?|search|login|404|error)\s*$/i', $t)) return self::fail('Title is a nav label: ' . self::snippet($t));
        if (preg_match('/<[a-z][^>]*>/i', $v)) return self::fail('Title contains raw HTML');

        $clean = $epNum > 0 ? RmNormalizer::title($t, $epNum) : $t;
        // A title that reduces to the bare "Episode #NNN" placeholder adds
        // nothing over what the archive already generates for itself.
        if ($epNum > 0 && RmNormalizer::titleDescriptor($clean) === null) {
            return self::fail('Title carries no descriptor beyond the episode number');
        }
        return self::ok($clean);
    }

    public static function altTitle($v): array {
        if (!is_string($v)) return self::fail('Alternate title is not a string');
        $t = RmNormalizer::text($v);
        if ($t === null || mb_strlen($t) < 2) return self::fail('Alternate title too short');
        if (mb_strlen($t) > 300) return self::fail('Alternate title too long');
        if (self::looksLikeNav($t)) return self::fail('Alternate title looks like navigation');
        return self::ok($t);
    }

    public static function airDate($v, array $ctx = []): array {
        if (!is_string($v) && !is_numeric($v)) return self::fail('Air date is not text');
        $d = RmNormalizer::date((string)$v);
        if ($d === null) return self::fail('Unparseable air date: ' . self::snippet($v));

        // Running Man's first broadcast was 2010-07-11; nothing before that
        // is an air date, and nothing more than a couple of months ahead is
        // either (that is a scheduling placeholder or a mis-parse).
        if ($d < '2010-07-01') return self::fail("Air date $d predates the show's first broadcast");
        $limit = date('Y-m-d', strtotime('+60 days'));
        if ($d > $limit) return self::fail("Air date $d is implausibly far in the future");

        // Cross-check against the episode's expected broadcast year when known.
        if (!empty($ctx['expected_year'])) {
            $y = (int)substr($d, 0, 4);
            if (abs($y - (int)$ctx['expected_year']) > 1) {
                return self::fail("Air date $d is in " . $y . ' but this episode belongs to ' . $ctx['expected_year']);
            }
        }
        return self::ok($d);
    }

    public static function synopsis($v): array {
        if (!is_string($v)) return self::fail('Synopsis is not a string');
        $s = RmNormalizer::paragraphText($v);
        if ($s === null || $s === '') return self::fail('Synopsis is empty');
        if (strip_tags($s) !== $s) $s = RmNormalizer::paragraphText(strip_tags($s));

        $min = (int)rmScrapeConfig('safety.min_synopsis_chars', 15);
        $max = (int)rmScrapeConfig('safety.max_synopsis_chars', 4000);
        $len = mb_strlen((string)$s);
        if ($len < $min) return self::fail("Synopsis too short ($len chars)");
        if ($len > $max) return self::fail("Synopsis too long ($len chars) — probably whole-page text");
        if (preg_match(self::GENERIC_SYNOPSIS, (string)$s)) return self::fail('Synopsis is the show-level generic blurb, not episode-specific');
        if (self::looksLikeNav((string)$s)) return self::fail('Synopsis contains navigation/boilerplate text');
        if (preg_match('/^\s*(?:watch|stream|download)\b.{0,40}(?:online|free|hd)\b/i', (string)$s)) {
            return self::fail('Synopsis is a streaming-site tagline');
        }

        // Menu dumps have very low lexical variety and lots of short tokens.
        $words = preg_split('/\s+/u', (string)$s) ?: [];
        if (count($words) >= 25) {
            $uniq = count(array_unique(array_map(fn($w) => mb_strtolower($w, 'UTF-8'), $words)));
            if ($uniq / count($words) < 0.35) return self::fail('Synopsis is highly repetitive — looks like a link list, not prose');
        }
        if (substr_count((string)$s, '|') > 6 || substr_count((string)$s, '•') > 8) {
            return self::fail('Synopsis is a delimiter-separated menu, not prose');
        }
        return self::ok($s);
    }

    public static function guests($v): array {
        if (!is_array($v)) return self::fail('Guest list is not an array');
        $max = (int)rmScrapeConfig('safety.max_guests_per_ep', 30);
        $out = []; $rejected = [];
        foreach ($v as $raw) {
            $name = RmNormalizer::guestName(is_string($raw) ? $raw : (string)($raw['name'] ?? ''));
            if ($name === null) { $rejected[] = self::snippet($raw); continue; }
            if (preg_match('/\d{4}/', $name)) { $rejected[] = $name; continue; }   // a year, not a person
            $out[RmNormalizer::guestKey($name)] = $name;
        }
        $out = array_values($out);
        if (!$out) return self::fail('No usable guest names' . ($rejected ? ' (rejected: ' . implode(', ', array_slice($rejected, 0, 3)) . ')' : ''));
        if (count($out) > $max) return self::fail(count($out) . " guests is above the sane maximum of $max — probably a cast list page");
        return self::ok($out, ['rejected' => $rejected]);
    }

    public static function tags($v): array {
        if (!is_array($v)) return self::fail('Tags is not an array');
        $out = [];
        foreach ($v as $t) { $c = RmNormalizer::tag(is_string($t) ? $t : null); if ($c !== null) $out[mb_strtolower($c, 'UTF-8')] = $c; }
        $out = array_slice(array_values($out), 0, 20);
        return $out ? self::ok($out) : self::fail('No usable tags');
    }

    public static function location($v): array {
        if (is_array($v)) $v = $v['name'] ?? null;
        if (!is_string($v)) return self::fail('Location is not text');
        $loc = RmNormalizer::location($v);
        if ($loc === null) return self::fail('Unusable location: ' . self::snippet($v));
        if (self::looksLikeNav($loc['name'])) return self::fail('Location looks like navigation text');
        if (mb_strlen($loc['name']) > 120) return self::fail('Location name too long');
        // The VALUE is the canonical name — the same shape the episodes
        // table stores — so comparison, diffing and writing all agree on
        // one representation. The derived city/country/overseas parts are
        // returned alongside for callers that want them; the writer
        // re-derives them from the name and uses them only to fill blanks
        // on an existing locations row, never to overwrite one.
        return self::ok($loc['name'], ['parts' => $loc]);
    }

    public static function imageUrl($v): array {
        if (!is_string($v)) return self::fail('Image URL is not a string');
        $u = RmNormalizer::url($v);
        if ($u === null) return self::fail('Malformed image URL: ' . self::snippet($v));
        if (preg_match('~/(?:logo|placeholder|default|no[-_]?image|blank|spacer|avatar)[-_.]~i', $u)) {
            return self::fail('Image URL points at a placeholder/logo asset');
        }
        return self::ok($u);
    }

    public static function shortText(string $field, $v): array {
        if ($v === null) return self::fail("$field is null");
        if (is_array($v)) $v = implode(', ', array_filter($v, 'is_scalar'));
        if (!is_string($v)) return self::fail("$field is not text");
        $t = RmNormalizer::text($v);
        if ($t === null || mb_strlen($t) < 2) return self::fail("$field is empty");
        if (mb_strlen($t) > 300) $t = mb_substr($t, 0, 300);
        if (self::looksLikeNav($t)) return self::fail("$field looks like navigation text");
        if (preg_match('/<[a-z][^>]*>/i', $t)) return self::fail("$field contains raw HTML");
        return self::ok($t);
    }

    /** Content-level check on a downloaded image: is this really an image? */
    public static function imageBytes(?string $bytes, string $contentType = ''): array {
        if ($bytes === null || $bytes === '') return self::fail('Empty image response');
        $cfg = rmScrapeConfig('thumbnail');
        if (strlen($bytes) < (int)$cfg['min_bytes']) return self::fail('Image is only ' . strlen($bytes) . ' bytes — too small to be a real thumbnail');
        // HTML masquerading as an image: error pages served with a 200.
        if (preg_match('/^\s*(?:<!doctype|<html|<\?xml|\{)/i', substr($bytes, 0, 64))) {
            return self::fail('Response is HTML/JSON, not an image (source served an error page)');
        }
        if ($contentType !== '' && !preg_match('~^image/~i', $contentType) && !preg_match('~^application/octet-stream~i', $contentType)) {
            return self::fail("Content-Type is '$contentType', not an image");
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) return self::fail('Bytes are not a decodable image');
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        if ($w < (int)$cfg['min_width'] || $h < (int)$cfg['min_height']) {
            return self::fail("Image is {$w}×{$h} — below the {$cfg['min_width']}×{$cfg['min_height']} minimum (icon or tracking pixel)");
        }
        // A real episode still is never a thin strip — a banner ad, a
        // header sliver, a CSS sprite sheet. Genuine photos and posters
        // land nowhere near these bounds; something that does is not a
        // thumbnail candidate whatever its raw pixel count says.
        $ratio = $w / max(1, $h);
        $minR  = (float)($cfg['min_aspect_ratio'] ?? 0.3);
        $maxR  = (float)($cfg['max_aspect_ratio'] ?? 3.5);
        if ($ratio < $minR || $ratio > $maxR) {
            return self::fail("Image is {$w}×{$h} (aspect ratio " . round($ratio, 2) . ") — outside the {$minR}–{$maxR} sane range for a thumbnail");
        }
        return self::ok($bytes, ['width' => $w, 'height' => $h, 'mime' => $info['mime'] ?? $contentType]);
    }

    private static function looksLikeNav(string $s): bool {
        $l = mb_strtolower($s, 'UTF-8');
        foreach (self::NAV_WORDS as $w) if (str_contains($l, $w)) return true;
        return false;
    }

    private static function snippet($v): string {
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        $s = trim(preg_replace('/\s+/u', ' ', (string)$v));
        return mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s;
    }
}
