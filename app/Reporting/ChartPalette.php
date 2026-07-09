<?php

declare(strict_types=1);

namespace App\Reporting;

/**
 * The one source of truth for dashboard chart colours (LB Slice 2). Every chart
 * widget reads from here so the whole dashboard shares one coherent, accessible
 * palette instead of each chart hand-picking — change a hue once, every chart
 * follows.
 *
 * The eight categorical hues are the validated colour-blind-safe set from the
 * dataviz reference palette (worst adjacent CVD ΔE 24.2 on the light surface — well
 * clear of the ≥12 target). They are tuned for the LIGHT chart surface the panel
 * shows by default; a dark-surface-optimised set is a follow-up (see the class note
 * below), not wired yet because the widgets render colours server-side and can't
 * yet see the viewer's theme.
 *
 * Two hard rules from the dataviz method:
 *  - categorical hues are used in FIXED ORDER, never cycled — a chart with more
 *    categories than hues folds its tail into a single "Other" slice (see
 *    DispositionMixChart) rather than repeating a colour;
 *  - single-series charts (a plain bar chart) get ONE colour, not a colour per bar
 *    — the bar is already identified by its axis label, so per-bar colour would add
 *    noise, not meaning.
 */
class ChartPalette
{
    /**
     * The fixed, colour-blind-safe categorical order (light surface). Index 0 is
     * used first, then 1, and so on — never shuffled, never cycled.
     *
     * @var array<int, string>
     */
    public const array CATEGORICAL = [
        '#2a78d6', // blue
        '#1baf7a', // aqua
        '#eda100', // yellow
        '#008300', // green
        '#4a3aa7', // violet
        '#e34948', // red
        '#e87ba4', // magenta
        '#eb6834', // orange
    ];

    /**
     * The single colour for one-series charts (bars over one measure). The app's
     * brand teal — a validated categorical slot — used solid so bars read clearly.
     */
    public const string PRIMARY = '#1baf7a';

    /**
     * The first $count categorical colours in fixed order, for a chart with that
     * many distinct categories. Callers MUST keep $count within the palette size
     * (fold the tail into "Other" first) — this never invents or cycles a hue.
     *
     * @return array<int, string>
     */
    public static function categorical(int $count): array
    {
        return array_slice(self::CATEGORICAL, 0, max(0, $count));
    }
}
