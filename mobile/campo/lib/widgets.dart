import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'core/api.dart';
import 'core/format.dart';
import 'models.dart';
import 'theme.dart';

/// Chip de estado con el color semántico del catálogo.
class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, this.color, this.dense = false});

  StatusChip.of(Catalog c, {Key? key, bool dense = false}) : this(c.label, key: key, color: c.color, dense: dense);

  final String label;
  final String? color;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.semantic(color, brand: Theme.of(context).colorScheme.primary);
    return Container(
      padding: EdgeInsets.symmetric(horizontal: dense ? 7 : 9, vertical: dense ? 2 : 4),
      decoration: BoxDecoration(color: c.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
      child: Text(label, style: TextStyle(color: c, fontWeight: FontWeight.w700, fontSize: dense ? 11 : 12)),
    );
  }
}

/// Tarjeta con título para agrupar secciones de una pantalla.
class SectionCard extends StatelessWidget {
  const SectionCard({super.key, this.title, required this.child, this.trailing, this.padding = const EdgeInsets.all(16)});

  final String? title;
  final Widget child;
  final Widget? trailing;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: padding,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (title != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Row(children: [
                Expanded(child: Text(title!, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: AppColors.navy))),
                ?trailing,
              ]),
            ),
          child,
        ]),
      ),
    );
  }
}

class InfoRow extends StatelessWidget {
  const InfoRow({super.key, required this.icon, required this.label, this.value, this.onTap});

  final IconData icon;
  final String label;
  final String? value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final row = Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, size: 18, color: AppColors.slate),
        const SizedBox(width: 10),
        SizedBox(width: 96, child: Text(label, style: const TextStyle(color: AppColors.slate, fontSize: 13))),
        Expanded(child: Text(value == null || value!.isEmpty ? '—' : value!, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13))),
      ]),
    );
    return onTap == null ? row : InkWell(onTap: onTap, child: row);
  }
}

/// Botón redondo con etiqueta (Llamar · WhatsApp · Navegar).
class RoundAction extends StatelessWidget {
  const RoundAction({super.key, required this.icon, required this.label, required this.onTap, this.color});

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).colorScheme.primary;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          CircleAvatar(radius: 24, backgroundColor: c.withValues(alpha: onTap == null ? .05 : .12), child: Icon(icon, color: onTap == null ? AppColors.slate : c)),
          const SizedBox(height: 6),
          Text(label, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: onTap == null ? AppColors.slate : null)),
        ]),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, this.message});

  final IconData icon;
  final String title;
  final String? message;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 48, horizontal: 24),
      child: Column(children: [
        Icon(icon, size: 48, color: AppColors.slate.withValues(alpha: .5)),
        const SizedBox(height: 12),
        Text(title, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        if (message != null) ...[
          const SizedBox(height: 6),
          Text(message!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.slate)),
        ],
      ]),
    );
  }
}

class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.error, required this.onRetry});

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final offline = error is ApiException && (error as ApiException).offline;
    return ListView(physics: const AlwaysScrollableScrollPhysics(), children: [
      EmptyState(
        icon: offline ? Icons.wifi_off_rounded : Icons.error_outline_rounded,
        title: offline ? 'Sin conexión' : 'No se pudo cargar',
        message: '$error',
      ),
      Center(child: OutlinedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('Reintentar'))),
    ]);
  }
}

/// Aviso cuando los datos vienen de la caché del teléfono (sin señal).
class OfflineBanner extends StatelessWidget {
  const OfflineBanner({super.key, this.savedAt});

  final DateTime? savedAt;

  @override
  Widget build(BuildContext context) {
    final when = savedAt == null ? '' : ' del ${Fmt.dateTime(savedAt!.toIso8601String())}';
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: AppColors.warning.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
      child: Row(children: [
        const Icon(Icons.wifi_off_rounded, color: AppColors.warning, size: 20),
        const SizedBox(width: 10),
        Expanded(child: Text('Sin señal: mostrando la información guardada$when. Las acciones necesitan conexión.', style: const TextStyle(fontSize: 12.5))),
      ]),
    );
  }
}

/// Carga asíncrona con "jalar para actualizar", error con reintento y estado vacío.
class Loader<T> extends StatefulWidget {
  const Loader({super.key, required this.load, required this.builder});

  final Future<T> Function() load;
  final Widget Function(BuildContext context, T data, Future<void> Function() reload) builder;

  @override
  State<Loader<T>> createState() => LoaderState<T>();
}

class LoaderState<T> extends State<Loader<T>> {
  T? _data;
  Object? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    setState(() => _loading = _data == null);
    try {
      final d = await widget.load();
      if (!mounted) return;
      setState(() {
        _data = d;
        _error = null;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _loading = false;
      });
      if (_data != null) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) return const Center(child: CircularProgressIndicator());
    if (_data == null) return RefreshIndicator(onRefresh: reload, child: ErrorView(error: _error ?? 'Sin datos', onRetry: reload));
    return RefreshIndicator(onRefresh: reload, child: widget.builder(context, _data as T, reload));
  }
}

/// Ejecuta una acción mostrando el resultado en un SnackBar. Devuelve true si terminó bien.
Future<bool> runAction(BuildContext context, Future<dynamic> Function() action, {String? success}) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    final res = await action();
    final msg = success ?? (res is Map ? res['message'] as String? : null);
    HapticFeedback.lightImpact();
    if (msg != null && msg.isNotEmpty) {
      messenger.showSnackBar(SnackBar(content: Text(msg), backgroundColor: AppColors.success));
    }
    return true;
  } catch (e) {
    messenger.showSnackBar(SnackBar(content: Text(e is ApiException ? e.detail : '$e'), backgroundColor: AppColors.danger));
    return false;
  }
}

/// Imagen remota (evidencias). En web usa <img> para no depender de CORS.
class NetImage extends StatelessWidget {
  const NetImage(this.url, {super.key, this.fit = BoxFit.cover});

  final String url;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    return Image.network(
      url,
      fit: fit,
      webHtmlElementStrategy: WebHtmlElementStrategy.prefer,
      errorBuilder: (_, _, _) => Container(color: const Color(0xFFE2E8F0), child: const Icon(Icons.broken_image_outlined, color: AppColors.slate)),
      loadingBuilder: (_, child, progress) => progress == null ? child : Container(color: const Color(0xFFF1F5F9)),
    );
  }
}

/// Encabezado oscuro de las pantallas de detalle (folio, título y chips).
class HeroHeader extends StatelessWidget {
  const HeroHeader({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 22),
      decoration: const BoxDecoration(
        color: AppColors.navy,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
      ),
      child: DefaultTextStyle(
        style: const TextStyle(color: Colors.white),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: children),
      ),
    );
  }
}

/// Encabezado de las pantallas de inicio: saludo, empresa y fecha.
class GreetingHeader extends StatelessWidget {
  const GreetingHeader({super.key, required this.name, required this.subtitle, this.bottom});

  final String name;
  final String subtitle;
  final Widget? bottom;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(20, MediaQuery.paddingOf(context).top + 16, 20, 20),
      decoration: const BoxDecoration(
        gradient: LinearGradient(colors: [AppColors.navy, AppColors.navy2], begin: Alignment.topLeft, end: Alignment.bottomRight),
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('${Fmt.greeting()},', style: const TextStyle(color: Color(0xFFB7C4DC), fontSize: 14)),
        Text(name.split(' ').first, style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800)),
        const SizedBox(height: 2),
        Text(subtitle, style: const TextStyle(color: Color(0xFFB7C4DC), fontSize: 13)),
        if (bottom != null) ...[const SizedBox(height: 18), bottom!],
      ]),
    );
  }
}

/// Cifra del día (Servicios · Clientes · Tickets).
class StatTile extends StatelessWidget {
  const StatTile({super.key, required this.value, required this.label, this.onTap});

  final String value;
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Material(
        color: Colors.white.withValues(alpha: .08),
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
            child: Column(children: [
              FittedBox(child: Text(value, style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800))),
              Text(label, style: const TextStyle(color: Color(0xFFB7C4DC), fontSize: 12)),
            ]),
          ),
        ),
      ),
    );
  }
}

/// Folio legible sobre el encabezado oscuro.
class FolioTag extends StatelessWidget {
  const FolioTag(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(color: Colors.white.withValues(alpha: .15), borderRadius: BorderRadius.circular(8)),
      child: Text(text, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12)),
    );
  }
}
