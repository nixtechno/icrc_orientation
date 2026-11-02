// Draws the programme report charts with ApexCharts. Each chart reads its figures from data-chart; the table under
// every chart holds the same figures, so nothing depends on hover. One brand hue for the measure, neutral grey for the rest.
(() => {
  'use strict';
  const COLORS = { primary: '#1f7a4d', rest: '#c5cfc9', grid: '#e4e9e5', axis: '#63766e', ink: '#182e28' };

  const options = (spec, font) => {
    const horizontal = Boolean(spec.horizontal);
    const series = spec.series
      ? spec.series.map((s) => ({ name: s.name, data: s.values }))
      : [{ name: spec.name, data: spec.values }];
    const unit = spec.unit || '';
    const rows = spec.categories.length;
    const height = horizontal ? Math.max(140, rows * 38 + 70) : 280;

    return {
      chart: {
        type: 'bar', height, stacked: Boolean(spec.stacked), fontFamily: font, foreColor: COLORS.axis,
        toolbar: { show: false }, zoom: { enabled: false }, animations: { speed: 350, animateGradually: { enabled: false } },
      },
      series,
      colors: spec.series ? [COLORS.primary, COLORS.rest] : [COLORS.primary],
      plotOptions: {
        bar: {
          horizontal, borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last',
          barHeight: rows > 6 ? '72%' : '58%', columnWidth: rows > 12 ? '70%' : '46%',
        },
      },
      stroke: { show: true, width: 2, colors: ['#ffffff'] },
      dataLabels: { enabled: false },
      grid: {
        borderColor: COLORS.grid, strokeDashArray: 0,
        xaxis: { lines: { show: horizontal } }, yaxis: { lines: { show: !horizontal } },
        padding: { left: 6, right: 12 },
      },
      xaxis: {
        categories: spec.categories, max: horizontal ? spec.max : undefined,
        axisBorder: { show: !horizontal, color: '#c9d1cc' }, axisTicks: { show: false },
        labels: { style: { fontSize: '12px' }, formatter: horizontal ? (v) => `${Math.round(v)}${unit}` : undefined, trim: true },
        tickAmount: horizontal && !spec.max ? Math.min(6, Math.max(...series.flatMap((s) => s.data), 1)) : undefined,
      },
      yaxis: {
        max: horizontal ? undefined : spec.max, min: 0, forceNiceScale: true,
        labels: { style: { fontSize: '12px' }, maxWidth: 220, formatter: horizontal ? undefined : (v) => `${Math.round(v)}${unit}` },
      },
      legend: {
        show: Boolean(spec.series), position: 'top', horizontalAlign: 'left', fontSize: '13px',
        labels: { colors: COLORS.ink }, markers: { width: 10, height: 10, radius: 3 }, itemMargin: { horizontal: 12 },
      },
      tooltip: {
        theme: 'light', shared: Boolean(spec.series), intersect: false,
        y: { formatter: (v, { dataPointIndex }) => `${v}${unit}${spec.notes ? ` · ${spec.notes[dataPointIndex]}` : ''}` },
      },
      states: { hover: { filter: { type: 'darken', value: 0.9 } }, active: { filter: { type: 'none' } } },
    };
  };

  const draw = () => {
    if (!window.ApexCharts) return;
    const font = getComputedStyle(document.body).fontFamily;
    document.querySelectorAll('[data-chart]').forEach((el) => {
      if (el.dataset.drawn) return;
      let spec;
      try { spec = JSON.parse(el.dataset.chart); } catch { return; }
      if (!spec.categories?.length) return;
      el.dataset.drawn = '1';
      el.setAttribute('role', 'img');
      el.setAttribute('aria-label', `${el.previousElementSibling?.textContent || 'Chart'}. The figures are in the table below.`);
      new window.ApexCharts(el, options(spec, font)).render();
    });
  };
  if (document.readyState === 'complete') draw(); else window.addEventListener('load', draw);
})();
