import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/outbox.dart';
import '../theme.dart';

/// Aviso sobre la barra inferior: "N pendientes por enviar" (bandeja sin conexión).
/// Al tocarlo muestra la lista, permite reintentar y descartar lo que el servidor rechazó.
class OutboxBar extends StatelessWidget {
  const OutboxBar({super.key, required this.outbox});

  final Outbox outbox;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: outbox,
      builder: (context, _) {
        final pending = outbox.pendingCount;
        final failed = outbox.failedCount;
        if (pending == 0 && failed == 0) return const SizedBox.shrink();
        final color = failed > 0 ? AppColors.danger : AppColors.warning;
        return Material(
          color: color.withValues(alpha: .12),
          child: InkWell(
            onTap: () => showOutboxSheet(context, outbox),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Row(children: [
                Icon(failed > 0 ? Icons.error_outline_rounded : Icons.cloud_upload_outlined, size: 20, color: color),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    [
                      if (pending > 0) '$pending pendiente${pending == 1 ? '' : 's'} por enviar',
                      if (failed > 0) '$failed con error',
                    ].join(' · '),
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
                  ),
                ),
                if (outbox.flushing)
                  const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                else
                  const Icon(Icons.chevron_right_rounded, color: AppColors.slate),
              ]),
            ),
          ),
        );
      },
    );
  }
}

Future<void> showOutboxSheet(BuildContext context, Outbox outbox) {
  return showModalBottomSheet(
    context: context,
    showDragHandle: true,
    isScrollControlled: true,
    builder: (ctx) => ListenableBuilder(
      listenable: outbox,
      builder: (ctx, _) {
        final items = outbox.items;
        return SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(ctx).height * .7),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 0, 20, 4),
                child: Text('Pendientes por enviar', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              ),
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: Text('Se guardaron sin conexión y se envían solos, en orden, al recuperar señal. Quedan con la hora en que los hiciste.',
                    style: TextStyle(color: AppColors.slate, fontSize: 14)),
              ),
              if (items.isEmpty)
                const Padding(padding: EdgeInsets.all(20), child: Text('Todo enviado.', style: TextStyle(color: AppColors.slate))),
              Flexible(
                child: ListView(shrinkWrap: true, children: [
                  for (final item in items)
                    ListTile(
                      leading: Icon(item.failed ? Icons.error_outline_rounded : (outbox.waiting(item) ? Icons.pause_circle_outline_rounded : Icons.schedule_rounded),
                          color: item.failed ? AppColors.danger : AppColors.warning),
                      title: Text(item.label, maxLines: 2, overflow: TextOverflow.ellipsis),
                      subtitle: Text(item.failed
                          ? 'No se pudo enviar: ${item.error}'
                          : outbox.waiting(item)
                              ? 'En espera: reintenta o descarta la acción con error de este mismo trabajo.'
                              : 'Guardado ${Fmt.dateTime(item.createdAt.toIso8601String())}'),
                      trailing: item.failed
                          ? Row(mainAxisSize: MainAxisSize.min, children: [
                              IconButton(tooltip: 'Reintentar', icon: const Icon(Icons.refresh_rounded), onPressed: () => outbox.retry(item)),
                              IconButton(tooltip: 'Descartar', icon: const Icon(Icons.delete_outline_rounded), onPressed: () => _confirmDiscard(ctx, outbox, item)),
                            ])
                          : null,
                    ),
                ]),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: FilledButton.icon(
                  onPressed: outbox.flushing || outbox.pendingCount == 0 ? null : () => outbox.flush(force: true),
                  icon: const Icon(Icons.cloud_upload_outlined),
                  label: Text(outbox.flushing ? 'Enviando…' : 'Enviar ahora'),
                ),
              ),
            ]),
          ),
        );
      },
    ),
  );
}

Future<void> _confirmDiscard(BuildContext context, Outbox outbox, OutboxItem item) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: const Text('Descartar'),
      content: Text('¿Descartar "${item.label}"? No se enviará al servidor.'),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancelar')),
        FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Descartar')),
      ],
    ),
  );
  if (ok == true) await outbox.discard(item);
}
