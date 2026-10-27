import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
import '../../core/format.dart';
import '../../core/launch.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../open.dart';
import '../tickets/ticket_tile.dart';

/// 3. Cliente: contacto, domicilio, plan, datos técnicos y tickets.
class ClientScreen extends StatelessWidget {
  const ClientScreen({super.key, required this.id});

  final int id;

  Future<(ClientDetail, List<Ticket>, Cached<Map<String, dynamic>>)> _load(BuildContext context) async {
    final api = context.read<Session>().api;
    final session = context.read<Session>();
    // Los tickets son opcionales: sin señal y sin copia guardada la ficha se muestra igual.
    final ticketsFuture = session.can('tickets.view')
        ? api.getCached('/tickets', query: {'client_id': id, 'per_page': 5}, cacheKey: CacheKeys.clientTickets(id)).then<List<Ticket>>(
            (r) => (r.data['data'] as List).map(Ticket.new).toList(),
            onError: (_) => <Ticket>[])
        : Future.value(<Ticket>[]);
    final client = await api.getCached('/clients/$id', cacheKey: CacheKeys.client(id));
    return (ClientDetail(client.data['data']), await ticketsFuture, client);
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return Scaffold(
      appBar: AppBar(title: Text('Cliente #$id')),
      body: Loader<(ClientDetail, List<Ticket>, Cached<Map<String, dynamic>>)>(
        load: () => _load(context),
        builder: (context, data, reload) {
          final (c, tickets, source) = data;
          return ListView(padding: const EdgeInsets.all(16), children: [
            if (source.fromCache) OfflineBanner(savedAt: source.savedAt),
            SectionCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  CircleAvatar(radius: 28, backgroundColor: AppColors.navy, child: Text(c.name.isEmpty ? '?' : c.name[0].toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800))),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(c.name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy)),
                      if (c.phone != null) Text(c.phone!, style: const TextStyle(color: AppColors.slate)),
                    ]),
                  ),
                ]),
                const SizedBox(height: 14),
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Icon(Icons.place_outlined, color: AppColors.slate, size: 20),
                  const SizedBox(width: 8),
                  Expanded(child: Text(c.fullAddress.isEmpty ? 'Sin dirección' : c.fullAddress)),
                ]),
                if (c.references != null && c.references!.isNotEmpty)
                  Padding(padding: const EdgeInsets.only(left: 28, top: 2), child: Text('Ref.: ${c.references}', style: const TextStyle(fontSize: 13, color: AppColors.slate))),
                const SizedBox(height: 12),
                Row(children: [StatusChip.of(c.status), const SizedBox(width: 8), StatusChip(c.online ? 'En línea' : 'Sin conexión', color: c.online ? 'success' : 'slate')]),
                const Divider(height: 28),
                InfoRow(icon: Icons.speed_outlined, label: 'Plan', value: [c.planName, c.planSpeed].whereType<String>().join(' · ')),
                InfoRow(icon: Icons.event_outlined, label: 'Instalación', value: c.installationDate == null ? null : Fmt.date(c.installationDate)),
                const SizedBox(height: 8),
                Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                  RoundAction(icon: Icons.call_outlined, label: 'Llamar', onTap: c.phone == null ? null : () => Launch.call(context, c.phone), color: AppColors.success),
                  RoundAction(icon: Icons.chat_outlined, label: 'WhatsApp', onTap: c.whatsapp == null ? null : () => Launch.whatsapp(context, c.whatsapp), color: const Color(0xFF25D366)),
                  RoundAction(icon: Icons.navigation_outlined, label: 'Navegar', onTap: c.navigationUrl == null ? null : () => Launch.navigate(context, c.navigationUrl)),
                ]),
              ]),
            ),
            if (session.isCollector) ...[
              const SizedBox(height: 12),
              FilledButton.icon(onPressed: () => Open.collect(context, c.id), icon: const Icon(Icons.payments_outlined), label: Text(c.balance > 0 ? 'Cobrar ${Fmt.money(c.balance)}' : 'Registrar visita de cobranza')),
            ],
            const SizedBox(height: 12),
            SectionCard(
              title: 'Servicio y red',
              child: Column(children: [
                InfoRow(icon: Icons.cell_tower_outlined, label: 'Repetidor', value: c.repeater),
                InfoRow(icon: Icons.map_outlined, label: 'Zona', value: c.zone),
                InfoRow(icon: Icons.network_check, label: 'Señal', value: c.signal == null ? null : '${c.signal!.toStringAsFixed(0)} dBm'),
                InfoRow(icon: Icons.lan_outlined, label: 'IP', value: c.ip),
                InfoRow(icon: Icons.memory_outlined, label: 'MAC', value: c.mac),
                InfoRow(icon: Icons.vpn_key_outlined, label: 'PPPoE', value: c.pppoe),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: 'Cuenta',
              child: Column(children: [
                InfoRow(icon: Icons.account_balance_wallet_outlined, label: 'Saldo', value: Fmt.money(c.balance)),
                InfoRow(icon: Icons.event_available_outlined, label: 'Próximo pago', value: c.nextDueDate == null ? null : Fmt.date(c.nextDueDate)),
              ]),
            ),
            if (tickets.isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(
                title: 'Tickets recientes',
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                child: Column(children: [for (final t in tickets) TicketTile(ticket: t, onTap: () => Open.ticket(context, t.id))]),
              ),
            ],
            if (c.notes != null && c.notes!.isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(title: 'Notas', child: Text(c.notes!)),
            ],
            const SizedBox(height: 24),
          ]);
        },
      ),
    );
  }
}
