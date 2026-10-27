import 'dart:io' as io;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'brand.dart';
import 'core/api.dart';
import 'core/format.dart';
import 'core/outbox.dart';
import 'models.dart';
import 'motion.dart';
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

/// Tarjeta con título para agrupar secciones de una pantalla. Entra con un fundido;
/// [index] escalona varias tarjetas de la misma pantalla.
class SectionCard extends StatelessWidget {
  const SectionCard({super.key, this.title, required this.child, this.trailing, this.padding = const EdgeInsets.all(16), this.index = 0});

  final String? title;
  final Widget child;
  final Widget? trailing;
  final EdgeInsets padding;
  final int index;

  @override
  Widget build(BuildContext context) {
    return FadeSlideIn(
      index: index,
      child: Card(
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
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, size: 18, color: AppColors.slate),
        const SizedBox(width: 10),
        SizedBox(width: 108, child: Text(label, style: const TextStyle(color: AppColors.slate, fontSize: 14))),
        Expanded(child: Text(value == null || value!.isEmpty ? '—' : value!, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14))),
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
          Text(label, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: onTap == null ? AppColors.slate : null)),
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
    final brand = Theme.of(context).colorScheme.primary;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 40, horizontal: 24),
      child: Column(children: [
        _Floating(
          child: Container(
            width: 84,
            height: 84,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: RadialGradient(colors: [brand.withValues(alpha: .16), brand.withValues(alpha: .04)]),
              border: Border.all(color: brand.withValues(alpha: .12)),
            ),
            child: Icon(icon, size: 38, color: brand.withValues(alpha: .8)),
          ),
        ),
        const SizedBox(height: 16),
        Text(title, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        if (message != null) ...[
          const SizedBox(height: 6),
          Text(message!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.slate)),
        ],
      ]),
    );
  }
}

/// Flota suavemente arriba y abajo (iconos de estados vacíos).
class _Floating extends StatefulWidget {
  const _Floating({required this.child});

  final Widget child;

  @override
  State<_Floating> createState() => _FloatingState();
}

class _FloatingState extends State<_Floating> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 3200));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!reduceMotion(context) && !_c.isAnimating) _c.repeat(reverse: true);
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (_, child) => Transform.translate(offset: Offset(0, -6 * Curves.easeInOut.transform(_c.value)), child: child),
      child: widget.child,
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
        Expanded(child: Text('Sin señal: mostrando la información guardada$when. Lo que hagas se guarda y se envía al recuperar señal.', style: const TextStyle(fontSize: 13.5))),
      ]),
    );
  }
}

/// Carga asíncrona con "jalar para actualizar", error con reintento y estado vacío.
/// Recarga sola cuando la bandeja de salida termina de enviar acciones (Outbox.synced).
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
    Outbox.synced.addListener(_onSynced);
    reload();
  }

  @override
  void dispose() {
    Outbox.synced.removeListener(_onSynced);
    super.dispose();
  }

  void _onSynced() {
    if (mounted && _data != null) reload();
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
    if (_loading && _data == null) return const SkeletonView();
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
      messenger.showSnackBar(_snack(msg, Icons.check_circle_rounded, AppColors.success));
    }
    return true;
  } catch (e) {
    HapticFeedback.heavyImpact();
    messenger.showSnackBar(_snack(e is ApiException ? e.detail : '$e', Icons.error_outline_rounded, AppColors.danger));
    return false;
  }
}

/// Como [runAction] para acciones de campo que, sin señal, quedan en la bandeja de salida
/// (Outbox.submit). Devuelve true si se envió o se guardó para enviarse después.
Future<bool> runQueued(BuildContext context, Future<SendResult> Function() action, {String? success}) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    final res = await action();
    if (res.queued) {
      HapticFeedback.mediumImpact();
      messenger.showSnackBar(_snack(Outbox.offlineMessage, Icons.cloud_upload_outlined, AppColors.warning));
      return true;
    }
    final msg = success ?? res.message;
    HapticFeedback.lightImpact();
    if (msg != null && msg.isNotEmpty) {
      messenger.showSnackBar(_snack(msg, Icons.check_circle_rounded, AppColors.success));
    }
    return true;
  } catch (e) {
    HapticFeedback.heavyImpact();
    messenger.showSnackBar(_snack(e is ApiException ? e.detail : '$e', Icons.error_outline_rounded, AppColors.danger));
    return false;
  }
}

SnackBar _snack(String text, IconData icon, Color color) => SnackBar(
      backgroundColor: color,
      content: Row(children: [
        Icon(icon, color: Colors.white, size: 22),
        const SizedBox(width: 10),
        Expanded(child: Text(text)),
      ]),
    );

/// Imagen remota (evidencias). En web usa <img> para no depender de CORS.
class NetImage extends StatelessWidget {
  const NetImage(this.url, {super.key, this.fit = BoxFit.cover, this.localPath});

  final String url;
  final BoxFit fit;

  /// Foto o firma guardada en el teléfono que aún no se sube (bandeja de salida).
  final String? localPath;

  static Widget _waiting(BuildContext context, Object error, StackTrace? stack) =>
      Container(color: const Color(0xFFF1F5F9), child: const Icon(Icons.cloud_upload_outlined, color: AppColors.warning));

  @override
  Widget build(BuildContext context) {
    if (localPath != null) {
      return kIsWeb ? _waiting(context, '', null) : Image.file(io.File(localPath!), fit: fit, errorBuilder: _waiting);
    }
    if (url.isEmpty) return _waiting(context, '', null);
    return Image.network(
      url,
      fit: fit,
      webHtmlElementStrategy: WebHtmlElementStrategy.prefer,
      errorBuilder: (_, _, _) => Container(color: const Color(0xFFE2E8F0), child: const Icon(Icons.broken_image_outlined, color: AppColors.slate)),
      loadingBuilder: (_, child, progress) => progress == null ? child : Container(color: const Color(0xFFF1F5F9)),
    );
  }
}

/// Marca de "por subir": hecho sin señal, aún en la bandeja de salida.
class PendingMark extends StatelessWidget {
  const PendingMark({super.key});

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: 'Guardado sin señal: se sube al recuperarla',
      child: Container(
        padding: const EdgeInsets.all(3),
        decoration: BoxDecoration(color: Colors.white, shape: BoxShape.circle, border: Border.all(color: AppColors.warning.withValues(alpha: .4))),
        child: const Icon(Icons.cloud_upload_outlined, size: 14, color: AppColors.warning),
      ),
    );
  }
}

/// Encabezado oscuro de las pantallas de detalle (folio, título y chips).
class HeroHeader extends StatelessWidget {
  const HeroHeader({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      child: SignalPanel(
        radius: 24,
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 22),
        child: DefaultTextStyle(
          style: const TextStyle(fontFamily: 'Inter', color: Colors.white),
          child: FadeSlideIn(offset: 10, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: children)),
        ),
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
    final brand = Theme.of(context).colorScheme.primary;
    final first = name.split(' ').first;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: lightStatusBar,
      child: SignalPanel(
        padding: EdgeInsets.fromLTRB(20, MediaQuery.paddingOf(context).top + 16, 20, 22),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                FadeSlideIn(offset: 10, child: Text('${Fmt.greeting()},', style: const TextStyle(color: AppColors.navy200, fontSize: 14))),
                FadeSlideIn(
                  index: 1,
                  offset: 12,
                  child: Text(first, style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800, letterSpacing: -.6, height: 1.15)),
                ),
              ]),
            ),
            FadeSlideIn(
              index: 1,
              child: Container(
                width: 46,
                height: 46,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(colors: [brand, Color.lerp(brand, Colors.white, .25)!], begin: Alignment.topLeft, end: Alignment.bottomRight),
                  border: Border.all(color: Colors.white.withValues(alpha: .25), width: 2),
                  boxShadow: [BoxShadow(color: brand.withValues(alpha: .45), blurRadius: 16)],
                ),
                child: Text(first.isEmpty ? '?' : first[0].toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
              ),
            ),
          ]),
          const SizedBox(height: 4),
          FadeSlideIn(index: 2, offset: 10, child: Text(subtitle, style: const TextStyle(color: AppColors.navy200, fontSize: 14))),
          if (bottom != null) ...[const SizedBox(height: 18), FadeSlideIn(index: 3, child: bottom!)],
        ]),
      ),
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
      child: Pressable(
        onTap: onTap,
        child: Material(
          color: Colors.white.withValues(alpha: .07),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: Colors.white.withValues(alpha: .11))),
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 13, horizontal: 6),
              child: Column(children: [
                FittedBox(child: CountUpText(value, style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: -.4))),
                const SizedBox(height: 2),
                Text(label, style: const TextStyle(color: AppColors.navy200, fontSize: 13, fontWeight: FontWeight.w500)),
              ]),
            ),
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
      child: Text(text, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13)),
    );
  }
}

/// Encabezado navy de las pantallas principales (en lugar de la AppBar plana):
/// rótulo pequeño, título grande, acciones y un bloque inferior (buscador, pestañas, cifras).
class PageHeader extends StatelessWidget {
  const PageHeader({super.key, required this.title, this.eyebrow, this.subtitle, this.actions = const [], this.bottom});

  final String title;
  final String? eyebrow;
  final String? subtitle;
  final List<Widget> actions;
  final Widget? bottom;

  @override
  Widget build(BuildContext context) {
    final canPop = ModalRoute.of(context)?.canPop ?? false;
    final top = MediaQuery.paddingOf(context).top;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: lightStatusBar,
      child: SignalPanel(
        padding: EdgeInsets.fromLTRB(canPop ? 8 : 20, top + 10, 14, bottom == null ? 22 : 16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
            if (canPop) const BackButton(color: Colors.white),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (eyebrow != null)
                  FadeSlideIn(
                    offset: 8,
                    child: Text(eyebrow!.toUpperCase(), style: const TextStyle(color: AppColors.brand400, fontSize: 12, fontWeight: FontWeight.w800, letterSpacing: 2)),
                  ),
                FadeSlideIn(
                  index: 1,
                  offset: 10,
                  child: Text(title, style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800, letterSpacing: -.6, height: 1.2)),
                ),
              ]),
            ),
            ...actions,
          ]),
          if (subtitle != null)
            Padding(
              padding: EdgeInsets.only(left: canPop ? 48 : 0, top: 2),
              child: FadeSlideIn(index: 2, offset: 8, child: Text(subtitle!, style: const TextStyle(color: AppColors.navy200, fontSize: 14))),
            ),
          if (bottom != null)
            Padding(
              padding: EdgeInsets.only(top: 16, left: canPop ? 12 : 0, right: 6),
              child: FadeSlideIn(index: 3, child: bottom!),
            ),
        ]),
      ),
    );
  }
}

/// Botón redondo translúcido para las acciones del encabezado navy.
class HeaderAction extends StatelessWidget {
  const HeaderAction({super.key, required this.icon, required this.onTap, this.tooltip});

  final IconData icon;
  final VoidCallback onTap;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(left: 6),
      child: Material(
        color: Colors.white.withValues(alpha: .09),
        shape: CircleBorder(side: BorderSide(color: Colors.white.withValues(alpha: .12))),
        child: IconButton(onPressed: onTap, tooltip: tooltip, icon: Icon(icon, color: Colors.white, size: 21)),
      ),
    );
  }
}

/// Buscador translúcido sobre el encabezado navy.
class HeaderSearch extends StatelessWidget {
  const HeaderSearch({super.key, required this.hint, required this.onChanged});

  final String hint;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final border = OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: Colors.white.withValues(alpha: .12)));
    return TextField(
      onChanged: onChanged,
      textInputAction: TextInputAction.search,
      cursorColor: Colors.white,
      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w500),
      decoration: InputDecoration(
        isDense: true,
        filled: true,
        fillColor: Colors.white.withValues(alpha: .08),
        hintText: hint,
        hintStyle: const TextStyle(color: AppColors.navy200),
        prefixIcon: const Icon(Icons.search_rounded, color: AppColors.navy200),
        border: border,
        enabledBorder: border,
        focusedBorder: border.copyWith(borderSide: const BorderSide(color: AppColors.brand400, width: 1.5)),
      ),
    );
  }
}

/// Pestañas tipo píldora sobre el encabezado navy (usa el DefaultTabController de la pantalla).
class HeaderTabs extends StatelessWidget {
  const HeaderTabs({super.key, required this.tabs});

  final List<String> tabs;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 46,
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .08),
        borderRadius: BorderRadius.circular(15),
        border: Border.all(color: Colors.white.withValues(alpha: .1)),
      ),
      child: TabBar(
        tabs: [for (final t in tabs) Tab(text: t)],
        dividerHeight: 0,
        // Menos relleno lateral para que etiquetas como "En progreso" quepan completas en 3 pestañas.
        labelPadding: const EdgeInsets.symmetric(horizontal: 6),
        indicatorSize: TabBarIndicatorSize.tab,
        indicator: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(11),
          boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .2), blurRadius: 10, offset: const Offset(0, 3))],
        ),
        labelColor: AppColors.navy,
        unselectedLabelColor: AppColors.navy200,
        labelStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w800, fontSize: 14),
        unselectedLabelStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w600, fontSize: 14),
        splashBorderRadius: BorderRadius.circular(11),
        overlayColor: WidgetStatePropertyAll(Colors.white.withValues(alpha: .06)),
      ),
    );
  }
}

/// Icono dentro de un cuadro redondeado de color (listas y menús).
class IconBadge extends StatelessWidget {
  const IconBadge(this.icon, {super.key, required this.color, this.size = 42});

  final IconData icon;
  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * .32),
        gradient: LinearGradient(colors: [color.withValues(alpha: .18), color.withValues(alpha: .08)], begin: Alignment.topLeft, end: Alignment.bottomRight),
      ),
      child: Icon(icon, color: color, size: size * .52),
    );
  }
}

/// Etiqueta con icono y fondo suave ("SLA en 2 h", "15 días de atraso").
class Pill extends StatelessWidget {
  const Pill({super.key, required this.label, required this.color, this.icon, this.shrink = false});

  final String label;
  final Color color;
  final IconData? icon;

  /// Recorta la etiqueta con "…" si no cabe. Solo cuando el padre le da un ancho acotado (p. ej. dentro de Expanded).
  final bool shrink;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        if (icon != null) ...[Icon(icon, size: 13, color: color), const SizedBox(width: 4)],
        if (shrink)
          Flexible(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12.5)))
        else
          Text(label, style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12.5)),
      ]),
    );
  }
}

/// Tarjeta de lista con franja de color a la izquierda, entrada escalonada y respuesta al toque.
class AccentCard extends StatelessWidget {
  const AccentCard({super.key, required this.child, required this.accent, this.onTap, this.index = 0, this.padding = const EdgeInsets.fromLTRB(14, 14, 14, 14)});

  final Widget child;
  final Color accent;
  final VoidCallback? onTap;
  final int index;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return FadeSlideIn(
      index: index,
      child: Pressable(
        onTap: onTap,
        scale: .98,
        child: Container(
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: AppColors.border.withValues(alpha: .8)),
            boxShadow: [BoxShadow(color: AppColors.navy.withValues(alpha: .05), blurRadius: 14, offset: const Offset(0, 5))],
          ),
          child: Material(
            type: MaterialType.transparency,
            child: InkWell(
              borderRadius: BorderRadius.circular(18),
              onTap: onTap,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(18),
                child: IntrinsicHeight(
                  child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    Container(width: 5, color: accent),
                    Expanded(child: Padding(padding: padding, child: child)),
                  ]),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
