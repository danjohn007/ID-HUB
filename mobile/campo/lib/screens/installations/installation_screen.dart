import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
import '../../core/format.dart';
import '../../core/geo.dart';
import '../../core/launch.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../work/work_sections.dart';

/// Orden de instalación (sección 40): iniciar, checklist, pruebas de señal y velocidad,
/// fotografías, materiales, firma y finalizar. La activación se hace en el panel.
/// Sin señal se trabaja con la orden guardada y todo queda en la bandeja de salida.
class InstallationScreen extends StatefulWidget {
  const InstallationScreen({super.key, required this.id});

  final int id;

  @override
  State<InstallationScreen> createState() => _InstallationScreenState();
}

class _InstallationScreenState extends State<InstallationScreen> {
  final _loader = GlobalKey<LoaderState<Installation>>();
  final _signal = TextEditingController();
  final _down = TextEditingController();
  final _up = TextEditingController();
  bool _measuresLoaded = false;

  /// Origen de los datos mostrados (servidor o copia guardada sin señal).
  Cached<Map<String, dynamic>>? _source;

  String get _base => '/installations/${widget.id}';

  @override
  void dispose() {
    _signal.dispose();
    _down.dispose();
    _up.dispose();
    super.dispose();
  }

  Future<Installation> _load() async {
    final res = await context.read<Session>().api.getCached(_base, cacheKey: CacheKeys.installation(widget.id));
    _source = res;
    final o = Installation(res.data['data']);
    if (!_measuresLoaded) {
      String fmt(dynamic v) => v == null ? '' : (v as num).toString().replaceAll(RegExp(r'\.0$'), '');
      _signal.text = fmt(o.measures['signal_dbm']);
      _down.text = fmt(o.measures['speed_down']);
      _up.text = fmt(o.measures['speed_up']);
      _measuresLoaded = true;
    }
    return o;
  }

  void _reload() => _loader.currentState?.reload();

  Future<void> _post(String path, String label, {bool gps = false}) async {
    final outbox = context.read<Session>().outbox;
    final point = gps ? await Geo.current() : null;
    if (!mounted) return;
    final ok = await runQueued(context, () => outbox.submit(path: '$_base/$path', data: point?.toJson(), label: '$label · instalación #${widget.id}'));
    if (ok) _reload();
  }

  Future<void> _toggle(ChecklistItem item) async {
    final outbox = context.read<Session>().outbox;
    final ok = await runQueued(
        context,
        () => outbox.submit(
            method: 'PATCH', path: '$_base/checklist', data: {'key': item.key, 'done': !item.done}, label: 'Checklist: ${item.label} · instalación #${widget.id}'),
        success: '');
    if (ok) _reload();
  }

  Future<void> _saveMeasures() async {
    double? n(TextEditingController c) => double.tryParse(c.text.replaceAll(',', '.').trim());
    final signal = n(_signal);
    final outbox = context.read<Session>().outbox;
    final ok = await runQueued(
        context,
        () => outbox.submit(
              method: 'PATCH',
              path: '$_base/measures',
              data: {
                'signal_dbm': signal == null ? null : (signal > 0 ? -signal : signal),
                'speed_down': n(_down),
                'speed_up': n(_up),
              },
              label: 'Mediciones · instalación #${widget.id}',
            ));
    if (ok) _reload();
  }

  Future<void> _complete(Installation o) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Finalizar instalación'),
        content: const Text('La orden quedará como "Instalada" y la oficina hará la activación del servicio. ¿Continuar?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Finalizar')),
        ],
      ),
    );
    if (ok == true) await _post('complete', 'Finalizar', gps: true);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Instalación')),
      body: Loader<Installation>(
        key: _loader,
        load: _load,
        builder: (context, o, reload) {
          final actions = WorkActions(context, _base, onChanged: _reload);
          final working = o.status.value == 'en_proceso';
          final pending = o.canWork && !working;
          final done = o.checklistDone;
          final total = o.checklist.length;

          return ListView(padding: EdgeInsets.zero, children: [
            HeroHeader(children: [
              Wrap(spacing: 6, children: [FolioTag(o.folio), DecoratedBox(decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)), child: StatusChip.of(o.status))]),
              const SizedBox(height: 10),
              Text(o.name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Colors.white)),
              Text([if (o.planName != null) 'Plan ${o.planName}', if (o.repeater != null) 'Repetidor ${o.repeater}'].join(' · '), style: const TextStyle(color: Color(0xFFB7C4DC))),
              if (o.scheduledAt != null) Text('Programada: ${Fmt.dateTime(o.scheduledAt)}', style: const TextStyle(color: Color(0xFFB7C4DC))),
            ]),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (_source?.fromCache == true) OfflineBanner(savedAt: _source!.savedAt),
                SectionCard(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    InfoRow(icon: Icons.place_outlined, label: 'Dirección', value: o.address),
                    if (o.references != null) InfoRow(icon: Icons.signpost_outlined, label: 'Referencias', value: o.references),
                    InfoRow(icon: Icons.phone_outlined, label: 'Teléfono', value: o.phone),
                    const SizedBox(height: 8),
                    Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                      RoundAction(icon: Icons.call_outlined, label: 'Llamar', onTap: o.phone == null ? null : () => Launch.call(context, o.phone), color: AppColors.success),
                      RoundAction(icon: Icons.chat_outlined, label: 'WhatsApp', onTap: o.whatsapp == null ? null : () => Launch.whatsapp(context, o.whatsapp), color: const Color(0xFF25D366)),
                      RoundAction(icon: Icons.navigation_outlined, label: 'Navegar', onTap: o.navigationUrl == null ? null : () => Launch.navigate(context, o.navigationUrl)),
                    ]),
                  ]),
                ),
                if (o.notes != null && o.notes!.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  SectionCard(title: 'Notas de la oficina', child: Text(o.notes!)),
                ],
                const SizedBox(height: 12),
                if (pending) FilledButton.icon(onPressed: () => _post('start', 'Inicio', gps: true), icon: const Icon(Icons.play_arrow_rounded), label: const Text('Iniciar instalación')),
                if (!o.canWork)
                  SectionCard(child: Row(children: [
                    const Icon(Icons.task_alt_rounded, color: AppColors.success),
                    const SizedBox(width: 10),
                    Expanded(child: Text('Orden ${o.status.label.toLowerCase()}. ${o.status.value == 'instalada' ? 'La oficina activará el servicio.' : ''}')),
                  ])),
                const SizedBox(height: 12),
                SectionCard(
                  title: 'Checklist de instalación',
                  trailing: Text('$done/$total', style: TextStyle(fontWeight: FontWeight.w800, color: done == total ? AppColors.success : AppColors.slate)),
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Column(children: [
                    for (final c in o.checklist)
                      CheckboxListTile(
                        contentPadding: EdgeInsets.zero,
                        dense: true,
                        value: c.done,
                        title: Text(c.label, style: const TextStyle(fontWeight: FontWeight.w600)),
                        subtitle: c.auto ? Text(c.key == 'firma' ? 'Se marca al guardar la firma' : 'Se marca al subir una foto', style: const TextStyle(fontSize: 12)) : null,
                        onChanged: !working || c.auto ? null : (_) => _toggle(c),
                      ),
                    if (!working && o.canWork) const Padding(padding: EdgeInsets.only(bottom: 8), child: Text('Inicia la instalación para marcar el checklist.', style: TextStyle(color: AppColors.slate, fontSize: 12))),
                  ]),
                ),
                const SizedBox(height: 12),
                SectionCard(
                  title: 'Pruebas de señal y velocidad',
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    TextField(controller: _signal, enabled: working, keyboardType: const TextInputType.numberWithOptions(signed: true, decimal: true), decoration: const InputDecoration(labelText: 'Señal (dBm)', hintText: '-58', suffixText: 'dBm')),
                    const SizedBox(height: 10),
                    Row(children: [
                      Expanded(child: TextField(controller: _down, enabled: working, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Bajada', suffixText: 'Mbps'))),
                      const SizedBox(width: 10),
                      Expanded(child: TextField(controller: _up, enabled: working, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Subida', suffixText: 'Mbps'))),
                    ]),
                    const SizedBox(height: 10),
                    OutlinedButton(onPressed: working ? _saveMeasures : null, child: const Text('Guardar mediciones')),
                  ]),
                ),
                const SizedBox(height: 12),
                EvidenceSection(attachments: o.attachments, actions: actions, slots: const ['antes', 'instalacion', 'equipo'], enabled: working),
                const SizedBox(height: 12),
                MaterialsSection(materials: o.materials, actions: actions, enabled: working),
                const SizedBox(height: 12),
                SignatureSection(signature: o.signature, actions: actions, suggestedName: o.name, enabled: working),
                if (working) ...[
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: done == total ? () => _complete(o) : null,
                    icon: const Icon(Icons.task_alt_rounded),
                    label: Text(done == total ? 'Finalizar instalación' : 'Faltan ${total - done} puntos del checklist'),
                  ),
                ],
                const SizedBox(height: 24),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}
