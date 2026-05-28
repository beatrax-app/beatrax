import ApexCharts from 'apexcharts';
import { palette } from './palette.js';

// Stamp ApexCharts on the global so Alpine `x-init` handlers in
// chart-rendering Blade components can call `new window.ApexCharts(...)`
// without re-importing the module on every component instance.
window.ApexCharts = ApexCharts;

// Adjust ApexCharts options for the active theme so chart lines,
// grid lines, and axis labels stay legible when `<html class="dark">`
// flips the page into dark mode. Server-rendered options bake in
// slate-900 line colors that vanish against slate-950 backgrounds —
// this helper swaps them at hydration time. Mutates and returns the
// same object for chained use.
window.beatraxApplyChartTheme = function (options) {
    const isDark = document.documentElement.classList.contains('dark');
    if (!isDark || !options || typeof options !== 'object') {
        return options;
    }
    const slate300 = '#cbd5e1';
    const slate700 = '#334155';
    const emerald400 = '#34d399';
    const rose400 = '#fb7185';
    const lineColorMap = {
        '#0f172a': emerald400,
        '#047857': emerald400,
        '#be123c': rose400,
    };
    if (Array.isArray(options.colors)) {
        options.colors = options.colors.map((c) => lineColorMap[(c || '').toLowerCase()] || c);
    }
    options.grid = Object.assign({}, options.grid || {}, { borderColor: slate700 });
    const tintLabels = (axis) => {
        if (!axis) return axis;
        const labels = axis.labels || {};
        return Object.assign({}, axis, {
            labels: Object.assign({}, labels, {
                style: Object.assign({}, labels.style || {}, { colors: slate300 }),
            }),
        });
    };
    options.xaxis = tintLabels(options.xaxis);
    options.yaxis = tintLabels(options.yaxis);
    options.tooltip = Object.assign({}, options.tooltip || {}, { theme: 'dark' });
    return options;
};

// Register the command palette Alpine factory. The
// x-data="palette(registry, recent)" directive inside the
// CommandPaletteModal Blade view resolves through this
// registration. Wraps Fuse.js with the configured weights +
// threshold + ignoreLocation.
document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('palette', palette);
    }
});

