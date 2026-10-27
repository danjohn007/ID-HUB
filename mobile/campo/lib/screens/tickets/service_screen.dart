import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
import '../../core/geo.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../work/work_sections.dart';

/// 5. Ejecución de servicio: evidencias, comentarios, materiales, firma y estado (secciones 23-24).
/// Sin señal se trabaja con el ticket guardado y todo queda en la bandeja de salida.
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

  /// Origen de los datos mostrados (servidor o copia guardada sin señal).
  Cached<Map<String, dynamic>>? _source;

  String get _base => '/tickets/${widget.ticketId}';

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  Future<Ticket> _load() async {
    final res = await context.read<Session>().api.getCached(_base, cacheKey: CacheKeys.ticket(widget.ticketId));
    _source = res;
    return Ticket(res.data['data']);
  }

  void _reload() => _loader.currentState?.reload();

  Future<void> _sendComment() async {
    final body = _comment.text.trim();
    if (body.length < 2) return;
    final session = context.read<Session>();
    setState(() => _sending = true);
    final ok = await runQueued(
        context,
        () => session.outbox.submit(
            path: '$_base/comments',
            data: {'body': body, 'internal': _internal ? 1 : 0},
            label: 'Comentario en ticket #${widget.ticketId}',
            meta: {'author': session.user?.name}),
        success: 'Comentario agregado');
    if (!mounted) return;
    setState(() => _sending = false);
    if (ok) {
      _comment.clear();
      _reload();
    }
  }

  Future<bool> _status(String status, {String? note, String? success}) async {
    final outbox = context.read<Session>().outbox;
    final gps = await Geo.current();
    if (!mounted) return false;
    final ok = await runQueued(
        context,
        () => outbox.submit(
            method: 'PATCH',
            path: '$_base/status',
            data: {'status': status, 'note': note, 'lat': gps?.lat, 'lng': gps?.lng},
            label: 'Estado del ticket #${widget.ticketId}'),
        success: success);
    if (ok) _reload();
    return ok;
  }

  /// "Guardar servicio": marca el ticket como resuelto con la descripción de la solución. El servidor registra
  /// la hora de salida y deja terminada la parada de la ruta (ya no hace falta marcarla en los tres puntos).
  Future<void> _resolve(Ticket t) async {
    // Evidencias obligatorias: sin fotografía no se guarda; sin firma hay que confirmar que el cliente no firmó.
    final hasPhoto = t.attachments.any((a) => a.kind != 'firma');
    final hasSignature = t.signature != null;
    final note = TextEditingController(text: t.resolution ?? '');
    var unsigned = false;
    final value = await showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialog) => AlertDialog(
          title: const Text('Guardar servicio'),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (!hasPhoto)
              _Notice(color: AppColors.danger, text: 'Sube al menos una fotografía del trabajo en la pestaña Evidencia antes de guardar.')
            else ...[
              if (!hasSignature) ...[
                const _Notice(color: AppColors.warning, text: 'Falta la firma del cliente.'),
                CheckboxListTile(
                  value: unsigned,
                  onChanged: (v) => setDialog(() => unsigned = v ?? false),
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  title: const Text('El cliente no pudo firmar (se avisa a la oficina)', style: TextStyle(fontSize: 14)),
                ),
              ],
              TextField(
                controller: note,
                maxLines: 4,
                maxLength: 190,
                decoration: const InputDecoration(labelText: 'Solución aplicada', hintText: 'Ej. Se realineó la antena, señal -58 dBm'),
              ),
            ],
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(hasPhoto ? 'Cancelar' : 'Entendido')),
            if (hasPhoto)
              FilledButton(onPressed: hasSignature || unsigned ? () => Navigator.pop(ctx, note.text.trim()) : null, child: const Text('Marcar resuelto')),
          ],
        ),
      ),
    );
    if (value == null || !mounted) return;
    if (value.length < 5) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Describe la solución aplicada (mínimo 5 caracteres).')));
      return;
    }
    if (!hasSignature) {
      final session = context.read<Session>();
      try {
        await session.outbox.submit(
            path: '$_base/comments',
            data: {'body': 'Servicio guardado sin firma del cliente.', 'internal': 1},
            label: 'Sin firma · ticket #${widget.ticketId}',
            meta: {'author': session.user?.name});
      } catch (_) {
        // La nota para la oficina es secundaria: si el servidor la rechaza, igual se guarda el servicio.
      }
      if (!mounted) return;
    }
    // Guardado: se regresa a la ruta (el ticket se cierra también, ver TicketScreen._execute).
    if (await _status('resuelto', note: value, success: 'Servicio guardado · salida registrada') && mounted) Navigator.of(context).pop(true);
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
                if (_source?.fromCache == true) OfflineBanner(savedAt: _source!.savedAt),
                EvidenceSection(attachments: t.attachments, actions: actions, enabled: open),
                const SizedBox(height: 12),
                SectionCard(
                  title: 'Comentarios',
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    TextField(controller: _comment, minLines: 2, maxLines: 4, decoration: const InputDecoration(hintText: 'Escribe un avance o comentario…')),
                    Row(children: [
                      Checkbox(value: _internal, onChanged: (v) => setState(() => _internal = v ?? false)),
                      const Expanded(child: Text('Nota interna (no la ve el cliente)', style: TextStyle(fontSize: 14))),
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

/// Aviso de color dentro del diálogo "Guardar servicio".
class _Notice extends StatelessWidget {
  const _Notice({required this.color, required this.text});

  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
        child: Text(text, style: const TextStyle(fontSize: 14)),
      );
}
