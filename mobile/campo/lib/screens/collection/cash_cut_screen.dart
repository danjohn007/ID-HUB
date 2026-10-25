import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
import '../../core/format.dart';
import '../../core/launch.dart';
import '../../core/outbox.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../motion.dart';
import '../../theme.dart';
import '../../widgets.dart';

class _CutData {
  _CutData(this.cut, this.payments, this.history, this.source);

  final Map<String, dynamic>? cut;
  final List<Payment> payments;
  final List<Map<String, dynamic>> history;
  final Cached<Map<String, dynamic>> source;
}

/// Control de efectivo (sección 27): corte de caja abierto del cobrador, sus cobros,
/// "Entregar corte" (cierra el corte con el importe esperado) e historial de cortes.
/// El supervisor registra el efectivo recibido y concilia en el panel. Los cobros hechos sin señal
/// se muestran aparte y el corte no se entrega hasta que lleguen al servidor.
class CashCutScreen extends StatefulWidget {
  const CashCutScreen({super.key});

  @override
  State<CashCutScreen> createState() => _CashCutScreenState();
}

class _CashCutScreenState extends State<CashCutScreen> {
  final _loader = GlobalKey<LoaderState<_CutData>>();

  Future<_CutData> _load() async {
    final api = context.read<Session>().api;
    final history = api
        .getCached('/collection/cash-cuts', query: {'per_page': 20}, cacheKey: CacheKeys.cashCuts)
        .then<List<Map<String, dynamic>>>((r) => (r.data['data'] as List? ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList(), onError: (_) => []);
    final res = await api.getCached('/collection/cash-cut', cacheKey: CacheKeys.cashCut);
    final data = res.data;
    return _CutData(
      data['cash_cut'] is Map ? Map<String, dynamic>.from(data['cash_cut'] as Map) : null,
      (data['payments'] as List? ?? []).map(Payment.new).toList(),
      await history,
      res,
    );
  }

  /// Confirmación con los totales y cierre del corte en el servidor (requiere señal).
  Future<void> _deliver(Map<String, dynamic> cut) async {
    final api = context.read<Session>().api;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.point_of_sale_rounded, color: AppColors.info, size: 40),
        title: Text('¿Entregar el corte ${cut['folio']}?'),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          _TotalRow('Cobros en efectivo', '${cut['payments_count']}'),
          _TotalRow('Abierto desde', Fmt.dateTime(cut['opened_at'] as String?)),
          const Divider(height: 20),
          _TotalRow('Efectivo a entregar', Fmt.money(cut['expected_amount'] as num?), strong: true),
          const SizedBox(height: 12),
          const Text('Se cerrará el corte con este importe. Los cobros nuevos irán a un corte nuevo. Entrega el efectivo a tu supervisor.',
              style: TextStyle(color: AppColors.slate, fontSize: 13, height: 1.35)),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Entregar corte')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final done = await runAction(context, () async {
      try {
        return await api.post('/collection/cash-cut/close');
      } on ApiException catch (e) {
        if (e.offline) throw ApiException('Sin conexión: el corte no se entregó. Intenta de nuevo cuando tengas señal.', offline: true);
        rethrow;
      }
    });
    if (done) _loader.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) {
    final outbox = context.read<Session>().outbox;
    return Scaffold(
      body: Loader<_CutData>(
        key: _loader,
        load: _load,
        // Se redibuja al guardar o enviar cobros desde otra pantalla (bandeja de salida).
        builder: (context, data, reload) => ListenableBuilder(
          listenable: outbox,
          builder: (context, _) {
              final unsent = outbox.unsent('/collection/payments');
              final cut = data.cut;
              final payments = data.payments;
              final history = data.history.where((h) => h['id'] != cut?['id']).toList();
              final banner = data.source.fromCache ? OfflineBanner(savedAt: data.source.savedAt) : null;
              if (cut == null) {
                return ListView(padding: EdgeInsets.zero, children: [
                  const PageHeader(eyebrow: 'Efectivo', title: 'Mi corte de caja'),
                  if (banner != null || unsent.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [?banner, if (unsent.isNotEmpty) _Unsent(items: unsent)]),
                    ),
                  if (unsent.isEmpty) const EmptyState(icon: Icons.point_of_sale_outlined, title: 'Sin corte abierto', message: 'Tu corte se abre solo con el primer cobro en efectivo.'),
                  if (history.isNotEmpty) Padding(padding: const EdgeInsets.fromLTRB(16, 16, 16, 24), child: _History(history: history)),
                ]);
              }
              final count = (cut['payments_count'] as num?) ?? 0;
              return ListView(padding: EdgeInsets.zero, children: [
                PageHeader(
                  eyebrow: 'Efectivo · corte ${cut['folio']}',
                  title: 'Mi corte de caja',
                  bottom: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Text('Efectivo por entregar', style: TextStyle(color: AppColors.navy200, fontSize: 13)),
                    CountUpText(Fmt.money(cut['expected_amount'] as num?), style: const TextStyle(color: Colors.white, fontSize: 38, fontWeight: FontWeight.w800, letterSpacing: -1)),
                    const SizedBox(height: 10),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      _GlassTag(icon: Icons.receipt_long_rounded, text: '$count cobros'),
                      _GlassTag(icon: Icons.schedule_rounded, text: 'Abierto ${Fmt.dateTime(cut['opened_at'] as String?)}'),
                    ]),
                  ]),
                ),
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    ?banner,
                    if (unsent.isNotEmpty) ...[_Unsent(items: unsent), const SizedBox(height: 12)],
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
                          Expanded(
                              child: Text('Al terminar tu jornada toca "Entregar corte" y entrega el efectivo a tu supervisor. Él registra lo recibido y concilia en el panel.',
                                  style: TextStyle(fontSize: 13, height: 1.35))),
                        ]),
                      ),
                    ),
                    const SizedBox(height: 12),
                    // Con cobros en el teléfono el corte saldría incompleto: primero deben llegar al servidor.
                    FilledButton.icon(
                      onPressed: count > 0 && unsent.isEmpty ? () => _deliver(cut) : null,
                      icon: const Icon(Icons.outbox_rounded),
                      label: Text(unsent.isEmpty ? 'Entregar corte' : 'Sube tus cobros para entregar el corte'),
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
                    if (history.isNotEmpty) ...[const SizedBox(height: 16), _History(history: history)],
                    const SizedBox(height: 8),
                  ]),
                ),
              ]);
          },
        ),
      ),
    );
  }
}

/// Cobros hechos sin señal: aún no están en el corte del servidor.
class _Unsent extends StatelessWidget {
  const _Unsent({required this.items});

  final List<OutboxItem> items;

  @override
  Widget build(BuildContext context) {
    final total = items.fold<num>(0, (sum, i) => sum + (num.tryParse('${i.fields['amount']}') ?? 0));
    final failed = items.where((i) => i.failed).length;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: .1),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.warning.withValues(alpha: .3)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const IconBadge(Icons.cloud_upload_outlined, color: AppColors.warning, size: 38),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${items.length} cobro${items.length == 1 ? '' : 's'} por subir · ${Fmt.money(total)}', style: const TextStyle(fontWeight: FontWeight.w800)),
              Text(
                failed > 0 ? '$failed con error: revísalos en "Pendientes por enviar".' : 'Se guardaron sin señal y se suman a tu corte al enviarse. Entrega el corte cuando lleguen.',
                style: const TextStyle(fontSize: 12.5, color: AppColors.slate, height: 1.3),
              ),
            ]),
          ),
        ]),
        const SizedBox(height: 6),
        for (final i in items)
          Padding(
            padding: const EdgeInsets.only(top: 4, left: 50),
            child: Text('${i.label}${i.failed ? ' · error' : ''}', style: TextStyle(fontSize: 12.5, color: i.failed ? AppColors.danger : AppColors.navy)),
          ),
      ]),
    );
  }
}

/// Historial de cortes: cerrado (por entregar), entregado o conciliado, con diferencias.
class _History extends StatelessWidget {
  const _History({required this.history});

  final List<Map<String, dynamic>> history;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      index: 3,
      title: 'Cortes anteriores',
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 6),
      child: Column(children: [
        for (final (i, c) in history.indexed) ...[
          if (i > 0) const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 10),
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Flexible(child: Text('${c['folio']}', overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy))),
                    const SizedBox(width: 8),
                    StatusChip.of(Catalog(c['status']), dense: true),
                  ]),
                  const SizedBox(height: 2),
                  Text(
                    '${c['payments_count']} cobros · ${Fmt.date(c['opened_at'] as String?)}${c['closed_at'] != null ? ' – ${Fmt.date(c['closed_at'] as String?)}' : ''}',
                    style: const TextStyle(color: AppColors.slate, fontSize: 12.5),
                  ),
                  if (c['difference'] is num && (c['difference'] as num).abs() > 0.009)
                    Text('Diferencia ${Fmt.money(c['difference'] as num)}', style: const TextStyle(color: AppColors.danger, fontSize: 12.5, fontWeight: FontWeight.w600)),
                ]),
              ),
              Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text(Fmt.money(c['expected_amount'] as num?), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                if (c['delivered_amount'] is num) Text('Entregado ${Fmt.money(c['delivered_amount'] as num)}', style: const TextStyle(color: AppColors.slate, fontSize: 12)),
              ]),
            ]),
          ),
        ],
      ]),
    );
  }
}

class _TotalRow extends StatelessWidget {
  const _TotalRow(this.label, this.value, {this.strong = false});

  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(children: [
        Expanded(child: Text(label, style: const TextStyle(color: AppColors.slate))),
        Text(value, style: TextStyle(fontWeight: strong ? FontWeight.w900 : FontWeight.w700, fontSize: strong ? 20 : 14)),
      ]),
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
