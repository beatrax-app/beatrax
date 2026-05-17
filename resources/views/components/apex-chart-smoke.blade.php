{{-- Runtime canary that asserts ApexCharts is on the global before
     downstream chart components mount. Feature tests can render this
     stub alone to verify the JS bundle exposes ApexCharts without
     spinning up a full chart-rendering page. --}}
<div x-data x-init="if (! window.ApexCharts) { throw new Error('ApexCharts not loaded'); }"></div>
