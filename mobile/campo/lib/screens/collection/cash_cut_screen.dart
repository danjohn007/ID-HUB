import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../core/launch.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../motion.dart';
import '../../theme.dart';
import '../../widgets.dart';

/// Control de efectivo (sección 27): corte de caja abierto del cobrador y sus cobros.
/// El supervisor concilia "cobros registrados vs efectivo entregado" en el panel.
class CashCutScreen extends StatelessWidget {
  const CashCutScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Loader<Map<String, dynamic>>(
        load: () => context.read<Session>().api.get('/collection/cash-cut'),
        builder: (context, data, reload) {
          final cut = data['cash_cut'] is Map ? Map<String, dynamic>.from(data['cash_cut'] as Map) : null;
          final payments = (data['payments'] as List? ?? []).map(Payment.new).toList();
          if (cut == null) {
            return ListView(padding: EdgeInsets.zero, children: const [
              PageHeader(eyebrow: 'Efectivo', title: 'Mi corte de caja'),
              EmptyState(icon: Icons.point_of_sale_outlined, title: 'Sin corte abierto', message: 'Tu corte se abre solo con el primer cobro en efectivo.'),
            ]);
          }
          return ListView(padding: EdgeInsets.zero, children: [
            PageHeader(
              eyebrow: 'Efectivo · corte ${cut['folio']}',
              title: 'Mi corte de caja',
              bottom: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('Efectivo por entregar', style: TextStyle(color: AppColors.navy200, fontSize: 13)),
                CountUpText(Fmt.money(cut['expected_amount'] as num?), style: const TextStyle(color: Colors.white, fontSize: 38, fontWeight: FontWeight.w800, letterSpacing: -1)),
                const SizedBox(height: 10),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  _GlassTag(icon: Icons.receipt_long_rounded, text: '${cut['payments_count']} cobros'),
                  _GlassTag(icon: Icons.schedule_rounded, text: 'Abierto ${Fmt.dateTime(cut['opened_at'] as String?)}'),
                ]),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                FadeSlideIn(
                  index: 1,
                  child: Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: AppColors.info.withValues(alpha: .08),
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: AppColors.info.withValues(alpha: .2)),
                    ),
                    child: const Row(children: [
                      IconBadge(Icons.handshake_outlined, color: AppColors.info, size: 38),
                      SizedBox(width: 12),
                      Expanded(child: Text('Entrega este efectivo a tu supervisor. Él cierra y concilia el corte desde el panel.', style: TextStyle(fontSize: 13, height: 1.35))),
                    ]),
                  ),
                ),
                const SizedBox(height: 16),
                SectionCard(
                  index: 2,
                  title: 'Cobros del corte',
                  trailing: Text('${payments.length}', style: const TextStyle(color: AppColors.slate, fontWeight: FontWeight.w700)),
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 6),
                  child: payments.isEmpty
                      ? const Padding(padding: EdgeInsets.only(bottom: 12), child: Text('Sin cobros.', style: TextStyle(color: AppColors.slate)))
                      : Column(children: [
                          for (final (i, p) in payments.indexed) ...[
                            if (i > 0) const Divider(height: 1),
                            InkWell(
                              borderRadius: BorderRadius.circular(12),
                              onTap: p.receiptUrl == null ? null : () => Launch.navigate(context, p.receiptUrl),
                              child: Padding(
                                padding: const EdgeInsets.symmetric(vertical: 11),
                                child: Row(children: [
                                  const IconBadge(Icons.payments_rounded, color: AppColors.success, size: 40),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                      Text(p.client?.name ?? p.folio, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy)),
                                      Text('${p.folio} · ${Fmt.dateTime(p.paidAt)}', style: const TextStyle(color: AppColors.slate, fontSize: 12.5)),
                                    ]),
                                  ),
                                  Text(Fmt.money(p.amount), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.success)),
                                  if (p.receiptUrl != null) const Icon(Icons.chevron_right_rounded, color: AppColors.slate),
                                ]),
                              ),
                            ),
                          ],
                        ]),
                ),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}

class _GlassTag extends StatelessWidget {
  const _GlassTag({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .08),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.white.withValues(alpha: .1)),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 14, color: AppColors.navy200),
        const SizedBox(width: 6),
        Text(text, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
      ]),
    );
  }
}
