import './bootstrap';
import L from 'leaflet';
import 'leaflet.markercluster';
import 'leaflet.heat';
import * as echarts from 'echarts/core';
import { LineChart, BarChart, PieChart, GaugeChart, ScatterChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, TitleComponent, DataZoomComponent, MarkLineComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([LineChart, BarChart, PieChart, GaugeChart, ScatterChart, GridComponent, TooltipComponent, LegendComponent, TitleComponent, DataZoomComponent, MarkLineComponent, CanvasRenderer]);

window.L = L;
window.echarts = echarts;

/* Paleta de marca (coincide con resources/css/app.css) */
export const palette = {
    brand: '#1A7FF5', navy: '#0B1B3A', success: '#22A06B', danger: '#E5484D',
    warning: '#F7881F', teal: '#00A0A8', purple: '#6048D0', slate: '#94A3B8', info: '#1A7FF5',
};
window.wcPalette = palette;

const statusColors = { online: palette.success, warning: palette.warning, offline: palette.danger, unknown: palette.slate, maintenance: palette.brand };

/* Íconos SVG (heroicons simplificados) por tipo de marcador */
const icons = {
    client: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.47 3.84a.75.75 0 011.06 0l8.69 8.69a.75.75 0 101.06-1.06l-8.69-8.69a2.25 2.25 0 00-3.18 0l-8.69 8.69a.75.75 0 001.06 1.06l8.69-8.69z"/><path d="M12 5.43l8.16 8.16.09.08v6.2c0 1.04-.84 1.88-1.88 1.88H15a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-3a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H5.63a1.88 1.88 0 01-1.88-1.88v-6.2l.09-.08L12 5.43z"/></svg>',
    repeater: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12.5a7 7 0 0114 0M8.5 13a3.5 3.5 0 017 0"/><circle cx="12" cy="13.5" r="1.6" fill="currentColor"/><path d="M12 15v6M9 21h6"/></svg>',
    tower: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 3l-5 18M12 3l5 18M8.6 15h6.8M10 9h4"/></svg>',
    technician: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3.375 4.5C2.339 4.5 1.5 5.34 1.5 6.375V13.5h12V6.375c0-1.036-.84-1.875-1.875-1.875h-8.25zM13.5 15h-12v2.625c0 1.035.84 1.875 1.875 1.875h.375a3 3 0 116 0h3a.75.75 0 00.75-.75V15z"/><path d="M8.25 19.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0zM15.75 6.75a.75.75 0 00-.75.75v11.25c0 .087.015.17.042.248a3 3 0 015.958.464c.853-.175 1.522-.935 1.464-1.883a18.659 18.659 0 00-3.732-10.104 1.837 1.837 0 00-1.47-.725H15.75z"/><path d="M19.5 19.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0z"/></svg>',
    collector: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 7.5a2.25 2.25 0 100 4.5 2.25 2.25 0 000-4.5z"/><path fill-rule="evenodd" d="M1.5 4.875C1.5 3.839 2.34 3 3.375 3h17.25c1.035 0 1.875.84 1.875 1.875v9.75c0 1.036-.84 1.875-1.875 1.875H3.375A1.875 1.875 0 011.5 14.625v-9.75zM8.25 9.75a3.75 3.75 0 117.5 0 3.75 3.75 0 01-7.5 0z" clip-rule="evenodd"/></svg>',
    ticket: '<svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6a.75.75 0 00-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 000-1.5h-3.75V6z" clip-rule="evenodd"/></svg>',
    prospect: '<svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M11.54 22.351l.07.04.028.016a.76.76 0 00.723 0l.028-.015.071-.041a16.975 16.975 0 001.144-.742 19.58 19.58 0 002.683-2.282c1.944-1.99 3.963-4.98 3.963-8.827a8.25 8.25 0 00-16.5 0c0 3.846 2.02 6.837 3.963 8.827a19.58 19.58 0 002.682 2.282 16.975 16.975 0 001.145.742zM12 13.5a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>',
    installation: '<svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 6.75a5.25 5.25 0 016.775-5.025.75.75 0 01.313 1.248l-3.32 3.319c.063.475.276.934.641 1.299.365.365.824.578 1.3.64l3.318-3.319a.75.75 0 011.248.313 5.25 5.25 0 01-5.472 6.756c-1.018-.086-1.87.1-2.309.634L7.344 21.3A3.298 3.298 0 112.7 16.657l8.684-7.151c.533-.44.72-1.291.634-2.309A5.342 5.342 0 0112 6.75z" clip-rule="evenodd"/></svg>',
    device: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M5.507 4.048A3 3 0 017.785 3h8.43a3 3 0 012.278 1.048l1.722 2.008A4.533 4.533 0 0019.5 6h-15c-.243 0-.482.02-.715.056l1.722-2.008z"/><path fill-rule="evenodd" d="M1.5 10.5a3 3 0 013-3h15a3 3 0 110 6h-15a3 3 0 01-3-3zm15 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm2.25.75a.75.75 0 100-1.5.75.75 0 000 1.5zM4.5 15a3 3 0 100 6h15a3 3 0 100-6h-15zm11.25 3.75a.75.75 0 100-1.5.75.75 0 000 1.5zM19.5 18a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" clip-rule="evenodd"/></svg>',
};

function markerIcon(m) {
    const size = m.size || (m.type === 'client' || m.type === 'prospect' ? 22 : 30);
    const color = m.color || statusColors[m.status] || palette.brand;
    const pulse = m.pulse ? ' wc-pulse' : '';
    return L.divIcon({
        className: '',
        html: `<div class="wc-marker${pulse}" style="width:${size}px;height:${size}px;background:${color}">${icons[m.type] || icons.device}</div>`,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        popupAnchor: [0, -size / 2],
    });
}

/* Polígono de sector de cobertura (azimut, apertura, alcance) */
function sectorPolygon(lat, lng, azimuth, beamwidth, rangeKm) {
    const pts = [[lat, lng]];
    const R = 6371;
    const start = azimuth - beamwidth / 2;
    for (let i = 0; i <= 24; i++) {
        const brng = ((start + (beamwidth * i) / 24) * Math.PI) / 180;
        const d = rangeKm / R;
        const la1 = (lat * Math.PI) / 180, lo1 = (lng * Math.PI) / 180;
        const la2 = Math.asin(Math.sin(la1) * Math.cos(d) + Math.cos(la1) * Math.sin(d) * Math.cos(brng));
        const lo2 = lo1 + Math.atan2(Math.sin(brng) * Math.sin(d) * Math.cos(la1), Math.cos(d) - Math.sin(la1) * Math.sin(la2));
        pts.push([(la2 * 180) / Math.PI, (lo2 * 180) / Math.PI]);
    }
    pts.push([lat, lng]);
    return pts;
}

const layerNames = {
    clients: 'Clientes', towers: 'Torres', repeaters: 'Repetidores', devices: 'Antenas / equipos', technicians: 'Técnicos',
    collectors: 'Cobradores', tickets: 'Tickets', installations: 'Instalaciones', prospects: 'Prospectos', coverage: 'Zonas de cobertura', demand: 'Concentración de demanda',
};

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Mapa Leaflet.  x-data="mapView({ id, center, zoom, markers, circles, sectors, heat, cluster, fit, picker })"
     * markers: [{lat, lng, type, status, color, layer, popup, url, pulse}]
     * picker: true => clic coloca marcador y emite $wire.set(pickerLat/pickerLng)
     */
    Alpine.data('mapView', (cfg = {}) => ({
        map: null,
        groups: {},
        pickerMarker: null,
        init() {
            const el = this.$refs.map || this.$el;
            this.map = L.map(el, { zoomControl: true, scrollWheelZoom: cfg.scrollWheelZoom ?? true }).setView(cfg.center || [20.5888, -100.3899], cfg.zoom || 13);
            const streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' });
            const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '&copy; Esri' });
            (cfg.satellite ? satellite : streets).addTo(this.map);
            this.baseLayers = { 'Mapa': streets, 'Satélite': satellite };
            this.render(cfg);
            if (cfg.picker) this.enablePicker(cfg);
            setTimeout(() => this.map.invalidateSize(), 150);
            new ResizeObserver(() => this.map && this.map.invalidateSize()).observe(el);
            if (cfg.id) {
                window.addEventListener('map-data-' + cfg.id, (e) => this.render({ ...cfg, ...(e.detail?.data || e.detail?.[0] || e.detail) }));
                window.addEventListener('map-focus-' + cfg.id, (e) => {
                    const d = e.detail?.[0] || e.detail;
                    if (d?.lat) this.map.setView([d.lat, d.lng], d.zoom || 16);
                });
            }
        },
        render(data) {
            Object.values(this.groups).forEach((g) => this.map.removeLayer(g));
            if (this.layersControl) this.map.removeControl(this.layersControl);
            this.groups = {};
            const bounds = [];
            const group = (name, clustered) => {
                if (!this.groups[name]) {
                    this.groups[name] = clustered ? L.markerClusterGroup({ maxClusterRadius: 40, showCoverageOnHover: false }) : L.layerGroup();
                }
                return this.groups[name];
            };

            (data.sectors || []).forEach((s) => {
                L.polygon(sectorPolygon(+s.lat, +s.lng, +s.azimuth, +s.beamwidth, +s.range_km), { color: s.color || palette.brand, weight: 1, fillOpacity: 0.12 })
                    .bindPopup(s.popup || '').addTo(group('coverage'));
            });
            (data.circles || []).forEach((c) => {
                L.circle([c.lat, c.lng], { radius: c.radius, color: c.color || palette.brand, weight: 1, fillOpacity: c.opacity ?? 0.08, dashArray: c.dash ? '4 4' : null })
                    .bindPopup(c.popup || '').addTo(group(c.layer || 'coverage'));
            });
            (data.lines || []).forEach((l) => {
                L.polyline(l.points, { color: l.color || palette.brand, weight: l.weight || 3, opacity: 0.8, dashArray: l.dash ? '6 6' : null }).addTo(group(l.layer || 'routes'));
            });
            if (data.heat && data.heat.length && L.heatLayer) {
                this.groups.demand = L.heatLayer(data.heat, { radius: 28, blur: 22, maxZoom: 16 });
            }
            (data.markers || []).forEach((m) => {
                if (m.lat == null || m.lng == null) return;
                const marker = L.marker([+m.lat, +m.lng], { icon: markerIcon(m), zIndexOffset: m.type === 'repeater' || m.type === 'tower' ? 1000 : 0 });
                if (m.popup) marker.bindPopup(m.popup);
                if (m.tooltip) marker.bindTooltip(m.tooltip, { permanent: !!m.permanentTooltip, direction: 'top', offset: [0, -12] });
                if (m.url && !m.popup) marker.on('click', () => (window.location.href = m.url));
                const layer = m.layer || m.type + 's';
                marker.addTo(group(layer, (data.cluster ?? cfg.cluster) && (m.type === 'client' || m.type === 'prospect')));
                bounds.push([+m.lat, +m.lng]);
            });

            Object.values(this.groups).forEach((g) => g.addTo(this.map));
            if (data.layersControl ?? cfg.layersControl ?? true) {
                const overlays = {};
                Object.entries(this.groups).forEach(([k, g]) => (overlays[layerNames[k] || k] = g));
                this.layersControl = L.control.layers(this.baseLayers, overlays, { collapsed: true }).addTo(this.map);
            }
            if ((data.fit ?? cfg.fit) && bounds.length) {
                this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
            }
        },
        enablePicker(cfg) {
            const latField = cfg.pickerLat || 'lat', lngField = cfg.pickerLng || 'lng';
            const place = (lat, lng) => {
                if (this.pickerMarker) this.pickerMarker.setLatLng([lat, lng]);
                else this.pickerMarker = L.marker([lat, lng], { draggable: true, icon: markerIcon({ type: 'prospect', color: palette.danger, size: 30 }) }).addTo(this.map)
                    .on('dragend', (e) => { const p = e.target.getLatLng(); this.$wire.set(latField, p.lat.toFixed(7)); this.$wire.set(lngField, p.lng.toFixed(7)); });
            };
            if (cfg.value?.lat) place(cfg.value.lat, cfg.value.lng);
            this.map.on('click', (e) => {
                place(e.latlng.lat, e.latlng.lng);
                this.$wire.set(latField, e.latlng.lat.toFixed(7));
                this.$wire.set(lngField, e.latlng.lng.toFixed(7));
            });
        },
        destroy() {
            this.map && this.map.remove();
        },
    }));

    /** Gráfica ECharts.  x-data="chart(options, id)"  (re-render con evento 'chart-data-{id}') */
    Alpine.data('chart', (options = {}, id = null) => ({
        instance: null,
        init() {
            const el = this.$refs.canvas || this.$el;
            this.instance = echarts.init(el, null, { renderer: 'canvas' });
            this.apply(options);
            new ResizeObserver(() => this.instance && this.instance.resize()).observe(el);
            if (id) window.addEventListener('chart-data-' + id, (e) => this.apply(e.detail?.options || e.detail?.[0] || e.detail));
        },
        apply(opts) {
            const base = {
                textStyle: { fontFamily: 'Inter Variable, sans-serif' },
                color: [palette.brand, palette.success, palette.warning, palette.purple, palette.teal, palette.danger, palette.navy, palette.slate],
                tooltip: { trigger: opts.series?.[0]?.type === 'pie' ? 'item' : 'axis', confine: true },
                grid: { left: 8, right: 12, top: 28, bottom: 8, containLabel: true },
            };
            this.instance.setOption({ ...base, ...opts }, true);
        },
        destroy() {
            this.instance && this.instance.dispose();
        },
    }));

    /** Firma en canvas → dataURL en la propiedad Livewire indicada. */
    Alpine.data('signaturePad', (model = 'signature') => ({
        drawing: false, ctx: null, empty: true,
        init() {
            const c = this.$refs.pad;
            const ratio = window.devicePixelRatio || 1;
            c.width = c.offsetWidth * ratio; c.height = c.offsetHeight * ratio;
            this.ctx = c.getContext('2d'); this.ctx.scale(ratio, ratio);
            this.ctx.lineWidth = 2.2; this.ctx.lineCap = 'round'; this.ctx.strokeStyle = '#0B1B3A';
            const pos = (e) => { const r = c.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return [t.clientX - r.left, t.clientY - r.top]; };
            const start = (e) => { e.preventDefault(); this.drawing = true; const [x, y] = pos(e); this.ctx.beginPath(); this.ctx.moveTo(x, y); };
            const move = (e) => { if (!this.drawing) return; e.preventDefault(); const [x, y] = pos(e); this.ctx.lineTo(x, y); this.ctx.stroke(); this.empty = false; };
            const end = () => { if (!this.drawing) return; this.drawing = false; this.$wire.set(model, c.toDataURL('image/png')); };
            c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
            c.addEventListener('touchstart', start, { passive: false }); c.addEventListener('touchmove', move, { passive: false }); c.addEventListener('touchend', end);
        },
        clear() { const c = this.$refs.pad; this.ctx.clearRect(0, 0, c.width, c.height); this.empty = true; this.$wire.set(model, null); },
    }));

    /** Obtiene ubicación GPS y la envía a Livewire: x-data="geo('lat','lng')" @click="locate()" */
    Alpine.data('geo', (latField = 'lat', lngField = 'lng') => ({
        locating: false, error: null,
        locate() {
            if (!navigator.geolocation) { this.error = 'GPS no disponible'; return; }
            this.locating = true;
            navigator.geolocation.getCurrentPosition(
                (p) => { this.$wire.set(latField, p.coords.latitude.toFixed(7)); this.$wire.set(lngField, p.coords.longitude.toFixed(7)); this.locating = false; },
                (err) => { this.error = err.message; this.locating = false; },
                { enableHighAccuracy: true, timeout: 15000 }
            );
        },
    }));

    /** Notificaciones tipo toast: window.dispatchEvent / Livewire dispatch('toast', {message, type}) */
    Alpine.data('toasts', () => ({
        items: [],
        add(detail) {
            const d = Array.isArray(detail) ? detail[0] : detail;
            const id = Date.now() + Math.random();
            this.items.push({ id, message: d.message || d, type: d.type || 'success' });
            setTimeout(() => this.remove(id), d.timeout || 4500);
        },
        remove(id) { this.items = this.items.filter((t) => t.id !== id); },
    }));
});
