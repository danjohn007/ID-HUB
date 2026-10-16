import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../core/launch.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';

/// Control de efectivo (sección 27): corte de caja abierto del cobrador y sus cobros.
/// El supervisor concilia "cobros registrados vs efectivo entregado" en el panel.
class CashCutScreen extends StatelessWidget {
  const CashCutScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mi corte de caja')),
      body: Loader<Map<String, dynamic>>(
        load: () => context.read<Session>().api.get('/collection/cash-cut'),
        builder: (context, data, reload) {
          final cut = data['cash_cut'] is Map ? Map<String, dynamic>.from(data['cash_cut'] as Map) : null;
          final payments = (data['payments'] as List? ?? []).map(Payment.new).toList();
          if (cut == null) {
            return ListView(children: const [
              EmptyState(icon: Icons.point_of_sale_outlined, title: 'Sin corte abierto', message: 'Tu corte se abre solo con el primer cobro en efectivo.'),
            ]);
          }
          return ListView(padding: const EdgeInsets.all(16), children: [
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(color: AppColors.navy, borderRadius: BorderRadius.circular(20)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('Corte ${cut['folio']}', style: const TextStyle(color: Color(0xFFB7C4DC))),
                const SizedBox(height: 4),
                Text(Fmt.money(cut['expected_amount'] as num?), style: const TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.w900)),
                Text('${cut['payments_count']} cobros en efectivo · abierto ${Fmt.dateTime(cut['opened_at'] as String?)}', style: const TextStyle(color: Color(0xFFB7C4DC))),
              ]),
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: AppColors.info.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
              child: const Row(children: [
                Icon(Icons.info_outline, color: AppColors.info),
                SizedBox(width: 10),
                Expanded(child: Text('Entrega este efectivo a tu supervisor. Él cierra y concilia el corte desde el panel.', style: TextStyle(fontSize: 13))),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: 'Cobros del corte',
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
              child: payments.isEmpty
                  ? const Padding(padding: EdgeInsets.only(bottom: 12), child: Text('Sin cobros.', style: TextStyle(color: AppColors.slate)))
                  : Column(children: [
                      for (final p in payments)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(p.client?.name ?? p.folio, style: const TextStyle(fontWeight: FontWeight.w700)),
                          subtitle: Text('${p.folio} · ${Fmt.dateTime(p.paidAt)}'),
                          trailing: Text(Fmt.money(p.amount), style: const TextStyle(fontWeight: FontWeight.w800)),
                          onTap: p.receiptUrl == null ? null : () => Launch.navigate(context, p.receiptUrl),
                        ),
                    ]),
            ),
          ]);
        },
      ),
    );
  }
}
