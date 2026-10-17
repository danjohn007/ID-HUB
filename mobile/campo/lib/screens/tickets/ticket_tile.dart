import 'package:flutter/material.dart';

import '../../core/format.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';

/// Icono por categoría de ticket (catálogo ticket_categories del servidor).
IconData ticketIcon(Ticket t) => switch (Catalog(t.json['category']).value) {
      'sin_internet' => Icons.wifi_off_rounded,
      'lento' => Icons.speed_rounded,
      'antena' => Icons.settings_input_antenna_rounded,
      'router' => Icons.router_rounded,
      'pago' => Icons.payments_rounded,
      'plan' => Icons.upgrade_rounded,
      'domicilio' => Icons.home_work_rounded,
      'infraestructura' => Icons.cell_tower_rounded,
      _ => Icons.support_agent_rounded,
    };

/// Etiqueta del SLA con su color (vencido, por vencer o en tiempo). Nula si el ticket ya cerró.
Widget? slaPill(Ticket t) {
  if (!t.isOpen || t.slaDueAt == null) return null;
  final late = t.slaBreached || (Fmt.parse(t.slaDueAt)?.isBefore(DateTime.now()) ?? false);
  final color = late ? AppColors.danger : (t.slaState == 'warning' ? AppColors.warning : AppColors.success);
  return Pill(label: late ? 'SLA vencido ${Fmt.relative(t.slaDueAt)}' : 'SLA ${Fmt.relative(t.slaDueAt)}', color: color, icon: late ? Icons.timer_off_outlined : Icons.timer_outlined);
}

/// Renglón de ticket (dentro de tarjetas): icono de categoría, folio, cliente, prioridad, estado y SLA.
class TicketTile extends StatelessWidget {
  const TicketTile({super.key, required this.ticket, required this.onTap});

  final Ticket ticket;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final t = ticket;
    final pColor = AppColors.semantic(t.priority.color);
    final sla = slaPill(t);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          IconBadge(ticketIcon(t), color: pColor, size: 40),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${t.folio} · ${t.categoryLabel}', style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy)),
              Text(t.client?.name ?? t.subject, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13)),
              if (sla != null) Padding(padding: const EdgeInsets.only(top: 6), child: sla),
            ]),
          ),
          const SizedBox(width: 8),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            StatusChip.of(t.priority, dense: true),
            const SizedBox(height: 6),
            Text(t.status.label, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.semantic(t.status.color))),
          ]),
        ]),
      ),
    );
  }
}

/// Tarjeta de ticket para la lista "Mis tickets": franja con el color de la prioridad.
class TicketCard extends StatelessWidget {
  const TicketCard({super.key, required this.ticket, required this.onTap, this.index = 0});

  final Ticket ticket;
  final VoidCallback onTap;
  final int index;

  @override
  Widget build(BuildContext context) {
    final t = ticket;
    final pColor = AppColors.semantic(t.priority.color);
    final sColor = AppColors.semantic(t.status.color);
    final sla = slaPill(t);
    final client = t.client;

    return AccentCard(
      accent: pColor,
      index: index,
      onTap: onTap,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          IconBadge(ticketIcon(t), color: pColor, size: 42),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(t.folio, style: const TextStyle(color: AppColors.slate, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: .3)),
              Text(t.categoryLabel, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy, letterSpacing: -.2)),
            ]),
          ),
          StatusChip.of(t.priority, dense: true),
        ]),
        const SizedBox(height: 10),
        if (t.subject.isNotEmpty) Text(t.subject, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13.5, height: 1.3)),
        if (client != null) ...[
          const SizedBox(height: 6),
          Row(children: [
            const Icon(Icons.person_outline_rounded, size: 16, color: AppColors.slate),
            const SizedBox(width: 6),
            Expanded(child: Text(client.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13, fontWeight: FontWeight.w600))),
          ]),
        ],
        const SizedBox(height: 12),
        Row(children: [
          Container(width: 8, height: 8, decoration: BoxDecoration(color: sColor, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Text(t.status.label, style: TextStyle(color: sColor, fontWeight: FontWeight.w700, fontSize: 12.5)),
          const Spacer(),
          ?sla,
          const SizedBox(width: 4),
          const Icon(Icons.chevron_right_rounded, color: AppColors.slate, size: 20),
        ]),
      ]),
    );
  }
}
