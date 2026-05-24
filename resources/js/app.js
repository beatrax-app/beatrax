import ApexCharts from 'apexcharts';
import { palette } from './palette.js';

// Stamp ApexCharts on the global so Alpine `x-init` handlers in
// chart-rendering Blade components can call `new window.ApexCharts(...)`
// without re-importing the module on every component instance.
window.ApexCharts = ApexCharts;

// Register the command palette Alpine factory (16-08). The
// `x-data="palette(registry, recent)"` directive inside the
// `CommandPaletteModal` Blade view resolves through this
// registration. Wraps Fuse.js with the LOCKED weights + threshold +
// ignoreLocation per UI-SPEC § Component inventory.
document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('palette', palette);
    }
});

