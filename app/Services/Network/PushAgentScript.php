<?php

namespace App\Services\Network;

use App\Models\NetworkDevice;

/**
 * Genera el script RouterOS (v7.13+) del "agente push" para MikroTik que no aceptan
 * conexiones entrantes desde el servidor (hosting compartido / cPanel).
 *
 * El script crea /system script "wispcontrol-agent" y un /system scheduler que cada N minutos:
 *  1) envía telemetría JSON (recursos, interfaces, sesiones, DHCP, registro inalámbrico, ping) a POST .../report
 *  2) descarga los comandos pendientes de GET .../commands y los aplica con /import.
 */
class PushAgentScript
{
    public static function reportUrl(NetworkDevice $d): string
    {
        return route('mikrotik.push.report', $d->push_token);
    }

    public static function commandsUrl(NetworkDevice $d): string
    {
        return route('mikrotik.push.commands', $d->push_token);
    }

    public static function build(NetworkDevice $d, int $interval = 2): string
    {
        $interval = max(1, min(5, $interval));
        $base = rtrim(str_replace('/report', '', static::reportUrl($d)), '/');
        $https = str_starts_with($base, 'https://') ? ' check-certificate=no' : '';
        $name = preg_replace('/[^A-Za-z0-9 ._-]/', '', $d->name);
        $date = now()->format('d/m/Y H:i');

        return <<<RSC
# =====================================================================
#  WISPCONTROL - Agente push para {$name}
#  Generado {$date}. Requiere RouterOS 7.13 o superior.
#  Pega este script completo en la Terminal del MikroTik (Winbox > New Terminal).
#  Cada {$interval} min envía telemetría y aplica los comandos pendientes.
# =====================================================================
/system scheduler remove [find name="wispcontrol-agent"]
/system script remove [find name="wispcontrol-agent"]
/system script add name="wispcontrol-agent" dont-require-permissions=yes policy=read,write,test,policy,ftp,sensitive source={
:local base "{$base}"
:local d ({})
:set (\$d->"identity") [/system identity get name]
:set (\$d->"resource") [/system resource print as-value]
:do { :set (\$d->"interfaces") [/interface print as-value proplist=name,type,running,disabled,rx-byte,tx-byte] } on-error={}
:do { :set (\$d->"ppp_active") [/ppp active print as-value proplist=name,service,caller-id,address,uptime] } on-error={}
:do { :set (\$d->"ppp_secrets") [/ppp secret print as-value proplist=name,service,profile,disabled,comment] } on-error={}
:do { :set (\$d->"hotspot_active") [/ip hotspot active print as-value proplist=user,address,mac-address,uptime] } on-error={}
:do { :set (\$d->"dhcp_leases") [/ip dhcp-server lease print as-value proplist=address,mac-address,host-name,status] } on-error={}
:do { :set (\$d->"addresses") [/ip address print as-value proplist=address,interface,disabled] } on-error={}
:do { :set (\$d->"registrations") [/interface wireless registration-table print as-value proplist=mac-address,signal-strength,tx-rate,rx-rate,uptime] } on-error={}
:do { :set (\$d->"ping") [/ping 8.8.8.8 count=5 as-value] } on-error={}
:local body [:serialize to=json value=\$d]
:do { /tool fetch url=(\$base . "/report") http-method=post http-header-field="Content-Type: application/json" http-data=\$body output=none{$https} } on-error={ :log warning "WISPCONTROL: no se pudo enviar el reporte" }
:do {
  /tool fetch url=(\$base . "/commands") dst-path="wispcontrol-cmd.rsc"{$https}
  :delay 1s
  /import file-name="wispcontrol-cmd.rsc"
  /file remove [find name="wispcontrol-cmd.rsc"]
} on-error={ :log warning "WISPCONTROL: no se pudieron aplicar los comandos" }
}
/system scheduler add name="wispcontrol-agent" interval={$interval}m start-time=startup policy=read,write,test,policy,ftp,sensitive on-event="/system script run wispcontrol-agent"
/system script run wispcontrol-agent
:log info "WISPCONTROL: agente instalado"
RSC;
    }
}
