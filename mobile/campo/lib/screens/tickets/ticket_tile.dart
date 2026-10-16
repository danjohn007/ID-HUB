import 'package:flutter/material.dart';

import '../../core/format.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';

/// Renglón de ticket: punto de prioridad, folio, categoría, cliente, prioridad y estado.
class TicketTile extends StatelessWidget {
  const TicketTile({super.key, required this.ticket, required this.onTap});

  final Ticket ticket;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final t = ticket;
    final pColor = AppColors.semantic(t.priority.color);
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(padding: const EdgeInsets.only(top: 4), child: Icon(Icons.circle, size: 12, color: pColor)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${t.folio} · ${t.categoryLabel}', style: const TextStyle(fontWeight: FontWeight.w800)),
              Text(t.client?.name ?? t.subject, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13)),
              if (t.isOpen && t.slaDueAt != null)
                Text('SLA ${Fmt.relative(t.slaDueAt)}',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: t.slaBreached ? AppColors.danger : (t.slaState == 'warning' ? AppColors.warning : AppColors.slate))),
            ]),
          ),
          const SizedBox(width: 8),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            StatusChip.of(t.priority, dense: true),
            const SizedBox(height: 4),
            Text(t.status.label, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.semantic(t.status.color))),
          ]),
        ]),
      ),
    );
  }
}
