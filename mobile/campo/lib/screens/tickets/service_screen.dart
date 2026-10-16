import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/geo.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../work/work_sections.dart';

/// 5. Ejecución de servicio: evidencias, comentarios, materiales, firma y estado (secciones 23-24).
class ServiceScreen extends StatefulWidget {
  const ServiceScreen({super.key, required this.ticketId});

  final int ticketId;

  @override
  State<ServiceScreen> createState() => _ServiceScreenState();
}

class _ServiceScreenState extends State<ServiceScreen> {
  final _loader = GlobalKey<LoaderState<Ticket>>();
  final _comment = TextEditingController();
  bool _internal = false;
  bool _sending = false;

  String get _base => '/tickets/${widget.ticketId}';

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  Future<Ticket> _load() async => Ticket((await context.read<Session>().api.get(_base))['data']);

  void _reload() => _loader.currentState?.reload();

  Future<void> _sendComment() async {
    final body = _comment.text.trim();
    if (body.length < 2) return;
    final api = context.read<Session>().api;
    setState(() => _sending = true);
    final ok = await runAction(context, () => api.post('$_base/comments', data: {'body': body, 'internal': _internal ? 1 : 0}), success: 'Comentario agregado');
    if (!mounted) return;
    setState(() => _sending = false);
    if (ok) {
      _comment.clear();
      _reload();
    }
  }

  Future<void> _status(String status, {String? note}) async {
    final api = context.read<Session>().api;
    final gps = await Geo.current();
    if (!mounted) return;
    final ok = await runAction(context, () => api.patch('$_base/status', data: {'status': status, 'note': note, 'lat': gps?.lat, 'lng': gps?.lng}));
    if (ok) _reload();
  }

  /// "Guardar servicio": marca el ticket como resuelto con la descripción de la solución.
  Future<void> _resolve(Ticket t) async {
    final missing = [
      if (t.attachments.where((a) => a.kind != 'firma').isEmpty) 'fotografías',
      if (t.signature == null) 'firma del cliente',
    ];
    final note = TextEditingController(text: t.resolution ?? '');
    final value = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Guardar servicio'),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (missing.isNotEmpty)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: AppColors.warning.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
              child: Text('Aún falta: ${missing.join(' y ')}.', style: const TextStyle(fontSize: 13)),
            ),
          TextField(
            controller: note,
            maxLines: 4,
            maxLength: 190,
            decoration: const InputDecoration(labelText: 'Solución aplicada', hintText: 'Ej. Se realineó la antena, señal -58 dBm'),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(ctx, note.text.trim()), child: const Text('Marcar resuelto')),
        ],
      ),
    );
    if (value == null) return;
    if (value.length < 5) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Describe la solución aplicada (mínimo 5 caracteres).')));
      return;
    }
    await _status('resuelto', note: value);
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Ejecución de servicio'),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(48),
            child: Container(color: Colors.white, child: const TabBar(tabs: [Tab(text: 'Evidencia'), Tab(text: 'Detalles')])),
          ),
        ),
        body: Loader<Ticket>(
          key: _loader,
          load: _load,
          builder: (context, t, reload) {
            final actions = WorkActions(context, _base, onChanged: _reload);
            final open = t.isOpen;
            return TabBarView(children: [
              ListView(padding: const EdgeInsets.all(16), children: [
                EvidenceSection(attachments: t.attachments, actions: actions, enabled: open),
                const SizedBox(height: 12),
                SectionCard(
                  title: 'Comentarios',
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    TextField(controller: _comment, minLines: 2, maxLines: 4, decoration: const InputDecoration(hintText: 'Escribe un avance o comentario…')),
                    Row(children: [
                      Checkbox(value: _internal, onChanged: (v) => setState(() => _internal = v ?? false)),
                      const Expanded(child: Text('Nota interna (no la ve el cliente)', style: TextStyle(fontSize: 13))),
                      IconButton.filled(onPressed: _sending ? null : _sendComment, icon: const Icon(Icons.send_rounded)),
                    ]),
                  ]),
                ),
                const SizedBox(height: 12),
                MaterialsSection(materials: t.materials, actions: actions, enabled: open),
                const SizedBox(height: 12),
                SignatureSection(signature: t.signature, actions: actions, suggestedName: t.client?.name, enabled: open),
                const SizedBox(height: 12),
                if (open) ...[
                  SectionCard(
                    title: 'Estado del trabajo',
                    child: Row(children: [
                      for (final (value, label) in const [('en_proceso', 'En proceso'), ('en_espera', 'En espera')]) ...[
                        Expanded(
                          child: ChoiceChip(
                            label: SizedBox(width: double.infinity, child: Text(label, textAlign: TextAlign.center)),
                            selected: t.status.value == value,
                            onSelected: (_) {
                              if (t.status.value != value) _status(value);
                            },
                          ),
                        ),
                        const SizedBox(width: 8),
                      ],
                    ]),
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(onPressed: () => _resolve(t), icon: const Icon(Icons.task_alt_rounded), label: const Text('Guardar servicio')),
                ] else
                  SectionCard(child: Row(children: [
                    const Icon(Icons.task_alt_rounded, color: AppColors.success),
                    const SizedBox(width: 10),
                    Expanded(child: Text('Ticket ${t.status.label.toLowerCase()}${t.resolution != null ? ': ${t.resolution}' : ''}')),
                  ])),
                const SizedBox(height: 24),
              ]),
              ListView(padding: const EdgeInsets.all(16), children: [
                SectionCard(
                  child: Column(children: [
                    InfoRow(icon: Icons.confirmation_number_outlined, label: 'Folio', value: t.folio),
                    InfoRow(icon: Icons.category_outlined, label: 'Categoría', value: t.categoryLabel),
                    InfoRow(icon: Icons.flag_outlined, label: 'Prioridad', value: t.priority.label),
                    InfoRow(icon: Icons.info_outline, label: 'Estado', value: t.status.label),
                    InfoRow(icon: Icons.person_outline, label: 'Cliente', value: t.client?.name),
                    InfoRow(icon: Icons.place_outlined, label: 'Dirección', value: t.client?.address),
                  ]),
                ),
                if (t.description != null) ...[
                  const SizedBox(height: 12),
                  SectionCard(title: 'Descripción del reporte', child: Text(t.description!)),
                ],
              ]),
            ]);
          },
        ),
      ),
    );
  }
}
