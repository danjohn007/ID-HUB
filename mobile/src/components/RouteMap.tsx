import { useMemo } from 'react';
import { StyleSheet, View } from 'react-native';
import { WebView } from 'react-native-webview';

import { api } from '@/lib/api';
import type { Coords, Stop } from '@/lib/types';
import { colors, radius } from '@/theme';

/**
 * Mapa de la ruta del día (Leaflet + OpenStreetMap, igual que <x-ui.map> del panel).
 * Marcadores numerados con los colores de FieldPlanner::mapData: atendida gris ✓, en sitio ámbar
 * con pulso, omitida gris claro y pendiente por prioridad; línea azul con el orden de la ruta.
 */
const PRIORITY: Record<string, string> = { critica: '#E5484D', alta: '#F7881F', media: '#1A7FF5', baja: '#22A06B' };

export function RouteMap({ stops, start, height = 260 }: { stops: Stop[]; start?: (Coords & { label?: string }) | null; height?: number }) {
  const html = useMemo(() => {
    const markers: unknown[] = [];
    const line: [number, number][] = [];
    if (start) {
      markers.push({ lat: start.lat, lng: start.lng, color: '#0B1B3A', size: 28, label: '', popup: start.label ?? 'Inicio', icon: 'technician' });
      line.push([start.lat, start.lng]);
    }
    stops.forEach((s, i) => {
      if (!s.location) return;
      const n = String(s.sequence ?? i + 1);
      const color =
        s.status === 'done' ? '#64748B' : s.status === 'skipped' ? '#CBD5E1' : s.status === 'arrived' ? '#F59E0B' : PRIORITY[s.priority ?? 'media'] ?? '#1A7FF5';
      markers.push({
        lat: s.location.lat,
        lng: s.location.lng,
        color,
        size: 26,
        pulse: s.status === 'arrived',
        label: s.status === 'done' ? `✓ ${n}` : s.status === 'skipped' ? `– ${n}` : n,
        popup: `<b>${n}. ${esc(s.title)}</b><br>${esc(s.subtitle)}<br><span style="color:#64748b">${esc(s.address)}</span>`,
        icon: s.type === 'installation_order' ? 'installation' : s.type === 'client' ? 'client' : 'ticket',
      });
      line.push([s.location.lat, s.location.lng]);
    });
    return page(markers, line);
  }, [stops, start]);

  return (
    <View style={[styles.wrap, { height }]}>
      <WebView
        originWhitelist={['*']}
        // Las teselas de OpenStreetMap requieren un Referer: se usa el dominio del servidor del WISP
        source={{ html, baseUrl: api.getServer() }}
        style={{ backgroundColor: colors.slate[100] }}
        javaScriptEnabled
        scrollEnabled={false}
        nestedScrollEnabled
      />
    </View>
  );
}

function esc(v: string | null | undefined): string {
  return (v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);
}

const ICONS: Record<string, string> = {
  client: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.47 3.84a.75.75 0 011.06 0l8.69 8.69a.75.75 0 101.06-1.06l-8.69-8.69a2.25 2.25 0 00-3.18 0l-8.69 8.69a.75.75 0 001.06 1.06l8.69-8.69z"/><path d="M12 5.43l8.16 8.16.09.08v6.2c0 1.04-.84 1.88-1.88 1.88H15a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-3a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H5.63a1.88 1.88 0 01-1.88-1.88v-6.2l.09-.08L12 5.43z"/></svg>',
  ticket: '<svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6a.75.75 0 00-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 000-1.5h-3.75V6z" clip-rule="evenodd"/></svg>',
  installation: '<svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 6.75a5.25 5.25 0 016.775-5.025.75.75 0 01.313 1.248l-3.32 3.319c.063.475.276.934.641 1.299.365.365.824.578 1.3.64l3.318-3.319a.75.75 0 011.248.313 5.25 5.25 0 01-5.472 6.756c-1.018-.086-1.87.1-2.309.634L7.344 21.3A3.298 3.298 0 112.7 16.657l8.684-7.151c.533-.44.72-1.291.634-2.309A5.342 5.342 0 0112 6.75z" clip-rule="evenodd"/></svg>',
  technician: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3.375 4.5C2.339 4.5 1.5 5.34 1.5 6.375V13.5h12V6.375c0-1.036-.84-1.875-1.875-1.875h-8.25zM13.5 15h-12v2.625c0 1.035.84 1.875 1.875 1.875h.375a3 3 0 116 0h3a.75.75 0 00.75-.75V15z"/><path d="M8.25 19.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0zM15.75 6.75a.75.75 0 00-.75.75v11.25c0 .087.015.17.042.248a3 3 0 015.958.464c.853-.175 1.522-.935 1.464-1.883a18.659 18.659 0 00-3.732-10.104 1.837 1.837 0 00-1.47-.725H15.75z"/><path d="M19.5 19.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0z"/></svg>',
};

function page(markers: unknown[], line: [number, number][]): string {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
html,body,#m{margin:0;height:100%;background:#f1f5f9;font-family:-apple-system,Roboto,sans-serif}
.wc-marker{display:flex;align-items:center;justify-content:center;border-radius:9999px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);color:#fff}
.wc-marker svg{width:60%;height:60%}
.wc-pulse{animation:p 1.6s infinite}@keyframes p{0%{box-shadow:0 0 0 0 rgba(229,72,77,.6)}70%{box-shadow:0 0 0 14px rgba(229,72,77,0)}100%{box-shadow:0 0 0 0 rgba(229,72,77,0)}}
.leaflet-tooltip{font-weight:700;font-size:11px;padding:1px 5px;border-radius:6px}
</style></head><body><div id="m"></div><script>
var markers=${JSON.stringify(markers)}, line=${JSON.stringify(line)}, icons=${JSON.stringify(ICONS)};
var map=L.map('m',{zoomControl:true,attributionControl:true}).setView([20.6,-100.4],12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
var b=[];
markers.forEach(function(m){
  var s=m.size||26;
  var icon=L.divIcon({className:'',html:'<div class="wc-marker'+(m.pulse?' wc-pulse':'')+'" style="width:'+s+'px;height:'+s+'px;background:'+m.color+'">'+(icons[m.icon]||'')+'</div>',iconSize:[s,s],iconAnchor:[s/2,s/2],popupAnchor:[0,-s/2]});
  var mk=L.marker([m.lat,m.lng],{icon:icon}).addTo(map);
  if(m.popup) mk.bindPopup(m.popup);
  if(m.label) mk.bindTooltip(m.label,{permanent:true,direction:'top',offset:[0,-12]});
  b.push([m.lat,m.lng]);
});
if(line.length>1) L.polyline(line,{color:'#1A7FF5',weight:4,opacity:.85}).addTo(map);
if(b.length===1) map.setView(b[0],15); else if(b.length>1) map.fitBounds(b,{padding:[30,30]});
</script></body></html>`;
}

const styles = StyleSheet.create({
  wrap: { borderRadius: radius.lg, overflow: 'hidden', borderWidth: 1, borderColor: colors.slate[200], backgroundColor: colors.slate[100] },
});
