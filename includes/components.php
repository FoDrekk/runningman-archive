<?php
// ============================================================
// Reusable public UI components (PR #5).
//
// Plain functions returning HTML strings — no templating engine, to
// stay consistent with the rest of this PHP codebase. Every page that
// renders an episode card, a rail, a theme/year tile or an empty state
// calls one of these instead of repeating the markup, so a visual
// change happens in one place.
// ============================================================
require_once __DIR__ . '/functions.php';

/** Strip the "Episode #NNN - " prefix that's stored as part of the title. */
function displayTitle(?string $title): string {
    $title = $title ?? '';
    if (preg_match('/^Episode\s*#\d+\s*-\s*(.+)$/i', $title, $m)) return $m[1];
    if (preg_match('/^Episode\s*#\d+$/i', $title)) return 'Running Man';
    return $title !== '' ? $title : 'Running Man';
}

/**
 * A single episode card. $opts:
 *   synopsis (bool)  show a short excerpt when available
 *   meta     (bool)  show theme/special/unverified badges (default true)
 *   eager    (bool)  skip loading="lazy" (use for above-the-fold cards)
 */
function renderEpisodeCard(array $ep, array $opts = []): string {
    $n     = str_pad((string)$ep['episode_number'], 3, '0', STR_PAD_LEFT);
    $src   = thumbSrc($ep);
    $title = displayTitle($ep['title'] ?? '');
    $loading = !empty($opts['eager']) ? '' : ' loading="lazy"';
    $showMeta = $opts['meta'] ?? true;

    $thumb = $src
        ? '<img src="' . h($src) . '" alt="' . h($title) . ' — Episode #' . $n . '"' . $loading . '>'
        : '<div class="thumb-ph" aria-hidden="true">R</div>';

    $badges = '';
    if ($showMeta) {
        if (!empty($ep['is_special'])) {
            $label = !empty($ep['special_type']) ? ucwords(str_replace('_', ' ', $ep['special_type'])) : 'Special';
            $badges .= '<span class="badge b-yel">⭐ ' . h($label) . '</span>';
        }
        if (!empty($ep['theme_name'])) $badges .= '<span class="badge b-gray">' . h($ep['theme_name']) . '</span>';
        if (!empty($ep['verification_required'])) $badges .= '<span class="badge b-warn">Unverified</span>';
    }

    $excerpt = '';
    if (!empty($opts['synopsis']) && !empty($ep['synopsis'])) {
        $excerpt = '<div class="ep-excerpt">' . h(mb_strimwidth((string)$ep['synopsis'], 0, 110, '…')) . '</div>';
    }

    return '<div class="card ep-card" data-href="' . h(episodeUrl((int)$ep['episode_number'])) . '" tabindex="0" role="link" aria-label="Episode ' . $n . ($title !== 'Running Man' ? ': ' . h($title) : '') . '">'
         . '<div class="ep-thumb">' . $thumb . '<span class="ep-num-badge">EP' . $n . '</span></div>'
         . '<div class="ep-body">'
         .   '<span class="ep-num">Episode #' . $n . '</span>'
         .   '<div class="ep-title">' . h($title) . '</div>'
         .   '<div class="ep-date">' . h($ep['air_date'] ?? '') . '</div>'
         .   $excerpt
         .   ($badges !== '' ? '<div class="ep-meta">' . $badges . '</div>' : '')
         . '</div></div>';
}

/**
 * A horizontal scrollable rail of episode cards (or theme/special cards
 * via $cardFn) with a heading and an optional "view all" link.
 */
function renderRail(string $title, array $items, ?string $viewAllUrl = null, ?callable $cardFn = null, string $emptyMsg = 'Nothing here yet.'): string {
    $cardFn ??= fn($ep) => renderEpisodeCard($ep, ['synopsis' => false]);
    $head = '<div class="rail-head"><h2 class="sec-h" style="margin-bottom:0">' . h($title) . '</h2>'
          . ($viewAllUrl ? '<a href="' . h($viewAllUrl) . '" class="btn btn-ghost btn-sm">View all →</a>' : '')
          . '</div>';
    if (!$items) {
        return $head . '<p class="rail-empty">' . h($emptyMsg) . '</p>';
    }
    $cards = '';
    foreach ($items as $item) $cards .= $cardFn($item);
    return $head . '<div class="rail-viewport"><div class="rail">' . $cards . '</div></div>';
}

function renderThemeCard(array $theme): string {
    $thumb = !empty($theme['thumb'])
        ? '<div class="ep-thumb"><img src="' . h($theme['thumb']) . '" alt="" loading="lazy"></div>'
        : '';
    return '<a href="' . h(bp() . '/search.php?theme_id=' . (int)$theme['theme_id']) . '" class="theme-card">'
         . $thumb
         . '<div class="tc-name">' . h($theme['name']) . '</div>'
         . (!empty($theme['description']) ? '<div class="tc-desc">' . h($theme['description']) . '</div>' : '')
         . '<span class="badge b-blue">' . (int)$theme['episode_count'] . ' episode' . ((int)$theme['episode_count'] === 1 ? '' : 's') . '</span>'
         . '</a>';
}

function renderYearCard(array $y): string {
    return '<a href="' . h(bp() . '/search.php?year=' . h((string)$y['year_label'])) . '" class="year-card">'
         . '<div class="yr">' . h((string)$y['year_label']) . '</div>'
         . '<div class="yr-range">EP' . str_pad((string)$y['first_ep'], 3, '0', STR_PAD_LEFT) . '–EP' . str_pad((string)$y['last_ep'], 3, '0', STR_PAD_LEFT) . '</div>'
         . '<div class="yr-count">' . (int)$y['total_episodes'] . ' episodes</div>'
         . (!empty($y['specials']) ? '<div style="font-size:.68rem;color:var(--t4);margin-top:.18rem">' . (int)$y['specials'] . ' specials</div>' : '')
         . '</a>';
}

/** Empty/error state used across every data-driven public section. */
function renderEmptyState(string $icon, string $title, string $message, ?string $ctaHtml = null): string {
    return '<div class="empty-state"><span class="ei" aria-hidden="true">' . $icon . '</span>'
         . '<h3>' . h($title) . '</h3><p>' . h($message) . '</p>'
         . ($ctaHtml ?? '') . '</div>';
}

/** The subtle archive-wide stats strip. Only ever fed real counts. */
function renderStatsSection(array $stats): string {
    $tiles = [
        ['num' => $stats['episodes'], 'lbl' => 'Episodes'],
        ['num' => $stats['guests'],   'lbl' => 'Guests'],
        ['num' => $stats['themes'],   'lbl' => 'Themes'],
        ['num' => $stats['years'],    'lbl' => 'Years'],
        ['num' => $stats['specials'], 'lbl' => 'Specials'],
    ];
    $html = '<div class="stats-section">';
    foreach ($tiles as $t) {
        $html .= '<div class="stat-tile"><span class="num">' . number_format((int)$t['num']) . '</span><span class="lbl">' . h($t['lbl']) . '</span></div>';
    }
    return $html . '</div>';
}

/** A skeleton loading placeholder grid — used only where content loads client-side. */
function renderSkeletonGrid(int $count = 8): string {
    $card = '<div class="skeleton-card"><div class="sk-thumb"></div><div class="sk-line"></div><div class="sk-line short"></div></div>';
    return '<div class="skeleton-row">' . str_repeat($card, $count) . '</div>';
}
