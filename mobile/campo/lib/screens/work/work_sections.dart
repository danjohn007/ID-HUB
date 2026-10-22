import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../core/photos.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import 'material_picker.dart';
import 'signature_screen.dart';

/// Acciones de trabajo comunes a tickets (/tickets/{id}) e instalaciones (/installations/{id}).
class WorkActions {
  WorkActions(this.context, this.base, {required this.onChanged});

  final BuildContext context;
  final String base;
  final VoidCallback onChanged;

  /// Foto de evidencia: solo cámara; hora y GPS del momento de la captura. Sin señal queda en la bandeja.
  Future<void> addPhoto(String kind) async {
    final outbox = context.read<Session>().outbox;
    final photo = await Photos.capture();
    if (photo == null || !context.mounted) return;
    final gps = photo.gps;
    final ok = await runQueued(
        context,
        () => outbox.submit(
              path: '$base/attachments',
              data: {'kind': kind, 'lat': gps?.lat, 'lng': gps?.lng, 'taken_at': photo.takenAtIso},
              photo: photo,
              label: 'Foto ${EvidenceSection.labels[kind]?.toLowerCase() ?? kind}',
            ),
        success: 'Foto guardada${gps == null ? ' (sin GPS)' : ' con ubicación'}');
    if (ok) onChanged();
  }

  Future<void> addMaterial() async {
    final api = context.read<Session>().api;
    final pick = await showMaterialPicker(context);
    if (pick == null || !context.mounted) return;
    final ok = await runAction(context, () => api.post('$base/materials', data: pick));
    if (ok) onChanged();
  }

  Future<void> returnMaterial(UsedMaterial m) async {
    final api = context.read<Session>().api;
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Devolver material'),
        content: Text('¿Regresar ${Fmt.qty(m.quantity)} ${m.unit} de ${m.itemName} al inventario?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Devolver')),
        ],
      ),
    );
    if (confirm != true || !context.mounted) return;
    final ok = await runAction(context, () => api.delete('$base/materials/${m.id}'));
    if (ok) onChanged();
  }

  Future<void> sign(String? suggestedName) async {
    final api = context.read<Session>().api;
    final result = await Navigator.of(context).push<SignatureResult>(
      MaterialPageRoute(fullscreenDialog: true, builder: (_) => SignatureScreen(initialName: suggestedName)),
    );
    if (result == null || !context.mounted) return;
    final ok = await runAction(
      context,
      () => api.upload('$base/signature', fields: {'signed_by': result.name}, files: {'signature': MultipartFile.fromBytes(result.png, filename: 'firma.png')}),
    );
    if (ok) onChanged();
  }
}

/// Evidencia fotográfica (sección 24): ranuras antes / durante / después y fotos adicionales.
class EvidenceSection extends StatelessWidget {
  const EvidenceSection({super.key, required this.attachments, required this.actions, this.slots = const ['antes', 'durante', 'despues'], this.enabled = true});

  final List<Attachment> attachments;
  final WorkActions actions;
  final List<String> slots;
  final bool enabled;

  static const labels = {
    'antes': 'Antes',
    'durante': 'Durante',
    'despues': 'Después',
    'equipo': 'Equipo',
    'instalacion': 'Instalación',
    'otro': 'Otra',
  };

  @override
  Widget build(BuildContext context) {
    final photos = attachments.where((a) => a.kind != 'firma').toList();
    final extra = photos.where((a) => !slots.contains(a.kind) || photos.where((p) => p.kind == a.kind).first != a).toList();

    return SectionCard(
      title: 'Evidencia fotográfica',
      trailing: enabled
          ? PopupMenuButton<String>(
              tooltip: 'Agregar foto',
              icon: const Icon(Icons.add_a_photo_outlined),
              onSelected: actions.addPhoto,
              itemBuilder: (_) => [for (final e in labels.entries) PopupMenuItem(value: e.key, child: Text('Foto ${e.value.toLowerCase()}'))],
            )
          : null,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          for (final (i, kind) in slots.indexed) ...[
            if (i > 0) const SizedBox(width: 8),
            Expanded(child: _Slot(label: labels[kind] ?? kind, photo: photos.where((p) => p.kind == kind).firstOrNull, onAdd: enabled ? () => actions.addPhoto(kind) : null)),
          ],
        ]),
        if (extra.isNotEmpty) ...[
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final a in extra) SizedBox(width: 72, height: 72, child: _Thumb(photo: a)),
          ]),
        ],
        if (photos.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text('${photos.length} foto${photos.length == 1 ? '' : 's'} · ${photos.where((p) => p.hasGps).length} con GPS', style: const TextStyle(fontSize: 12, color: AppColors.slate)),
        ],
      ]),
    );
  }
}

class _Slot extends StatelessWidget {
  const _Slot({required this.label, this.photo, this.onAdd});

  final String label;
  final Attachment? photo;
  final VoidCallback? onAdd;

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      AspectRatio(
        aspectRatio: 1,
        child: photo != null
            ? _Thumb(photo: photo!)
            : Material(
                color: const Color(0xFFF1F5F9),
                borderRadius: BorderRadius.circular(12),
                child: InkWell(
                  borderRadius: BorderRadius.circular(12),
                  onTap: onAdd,
                  child: const Icon(Icons.add_a_photo_outlined, color: AppColors.slate),
                ),
              ),
      ),
      const SizedBox(height: 4),
      Text(label, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
    ]);
  }
}

class _Thumb extends StatelessWidget {
  const _Thumb({required this.photo});

  final Attachment photo;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: GestureDetector(
        onTap: () => showDialog(
          context: context,
          builder: (ctx) => Dialog(
            clipBehavior: Clip.antiAlias,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              InteractiveViewer(child: NetImage(photo.url, fit: BoxFit.contain)),
              ListTile(title: Text(photo.kindLabel), subtitle: Text('${Fmt.dateTime(photo.takenAt)}${photo.hasGps ? ' · con GPS' : ''}')),
            ]),
          ),
        ),
        child: photo.isImage ? NetImage(photo.url) : Container(color: const Color(0xFFE2E8F0), child: const Icon(Icons.description_outlined)),
      ),
    );
  }
}

/// Materiales usados: se descuentan del inventario y se pueden devolver si hubo error.
class MaterialsSection extends StatelessWidget {
  const MaterialsSection({super.key, required this.materials, required this.actions, this.enabled = true});

  final List<UsedMaterial> materials;
  final WorkActions actions;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: 'Materiales utilizados',
      trailing: enabled ? IconButton(tooltip: 'Agregar material', onPressed: actions.addMaterial, icon: const Icon(Icons.add_circle_outline)) : null,
      child: materials.isEmpty
          ? Text(enabled ? 'Registra el cable, conectores o equipos que uses; se descuentan del inventario.' : 'Sin materiales registrados.',
              style: const TextStyle(color: AppColors.slate, fontSize: 13))
          : Column(children: [
              for (final m in materials)
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.inventory_2_outlined, color: AppColors.slate),
                  title: Text(m.itemName, style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text('${Fmt.qty(m.quantity)} ${m.unit}${m.serial != null ? ' · S/N ${m.serial}' : ''}'),
                  trailing: enabled ? IconButton(tooltip: 'Devolver', onPressed: () => actions.returnMaterial(m), icon: const Icon(Icons.undo_rounded)) : null,
                ),
            ]),
    );
  }
}

/// Firma de conformidad del cliente.
class SignatureSection extends StatelessWidget {
  const SignatureSection({super.key, required this.signature, required this.actions, this.suggestedName, this.enabled = true});

  final Json? signature;
  final WorkActions actions;
  final String? suggestedName;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: 'Firma del cliente',
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (signature != null) ...[
          Container(
            height: 110,
            decoration: BoxDecoration(border: Border.all(color: const Color(0xFFE2E8F0)), borderRadius: BorderRadius.circular(12)),
            child: NetImage(signature!['url'] as String, fit: BoxFit.contain),
          ),
          const SizedBox(height: 6),
          Text('Firmó: ${signature!['signed_by'] ?? '—'}', style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
        ],
        if (enabled)
          OutlinedButton.icon(
            onPressed: () => actions.sign(signature?['signed_by'] as String? ?? suggestedName),
            icon: const Icon(Icons.draw_outlined),
            label: Text(signature == null ? 'Obtener firma del cliente' : 'Volver a firmar'),
          ),
      ]),
    );
  }
}
