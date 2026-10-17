import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:intl/intl.dart' show NumberFormat;

import 'theme.dart';

/// true si el teléfono pide reducir animaciones (accesibilidad).
bool reduceMotion(BuildContext context) => MediaQuery.maybeDisableAnimationsOf(context) ?? false;

/// Entrada suave: aparece y sube unos píxeles. Con [index] se escalona en listas.
class FadeSlideIn extends StatefulWidget {
  const FadeSlideIn({super.key, required this.child, this.index = 0, this.delay, this.offset = 18, this.duration = const Duration(milliseconds: 560)});

  final Widget child;
  final int index;
  final Duration? delay;
  final double offset;
  final Duration duration;

  @override
  State<FadeSlideIn> createState() => _FadeSlideInState();
}

class _FadeSlideInState extends State<FadeSlideIn> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: widget.duration);
  late final _a = CurvedAnimation(parent: _c, curve: Motion.curve);
  bool _started = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (reduceMotion(context)) {
      _c.value = 1;
      return;
    }
    final delay = widget.delay ?? Duration(milliseconds: 70 * math.min(widget.index, 8));
    if (delay == Duration.zero) {
      _c.forward();
    } else {
      Future.delayed(delay, () {
        if (mounted) _c.forward();
      });
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _a,
      child: AnimatedBuilder(
        animation: _a,
        builder: (_, child) => Transform.translate(offset: Offset(0, widget.offset * (1 - _a.value)), child: child),
        child: widget.child,
      ),
    );
  }
}

/// Cifra que cuenta desde cero ("12", "$4,350.00", "87 %"). Si el texto no es numérico se muestra tal cual.
class CountUpText extends StatelessWidget {
  const CountUpText(this.value, {super.key, this.style, this.duration = const Duration(milliseconds: 1100)});

  final String value;
  final TextStyle? style;
  final Duration duration;

  static final _re = RegExp(r'^(\D*?)(\d[\d,]*(?:\.\d+)?)(.*)$');

  @override
  Widget build(BuildContext context) {
    final m = _re.firstMatch(value);
    if (m == null || reduceMotion(context)) return Text(value, style: style);

    final raw = m.group(2)!;
    final target = double.tryParse(raw.replaceAll(',', '')) ?? 0;
    final decimals = raw.contains('.') ? raw.split('.').last.length : 0;
    final fmt = NumberFormat('${raw.contains(',') ? '#,##0' : '0'}${decimals > 0 ? '.${'0' * decimals}' : ''}', 'en_US');

    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: target),
      duration: duration,
      curve: Curves.easeOutExpo,
      builder: (_, v, _) => Text('${m.group(1)}${fmt.format(v)}${m.group(3)}', style: style),
    );
  }
}

/// Se encoge un poco al tocarlo (respuesta táctil en tarjetas y botones).
class Pressable extends StatefulWidget {
  const Pressable({super.key, required this.child, this.onTap, this.scale = .96});

  final Widget child;
  final VoidCallback? onTap;
  final double scale;

  @override
  State<Pressable> createState() => _PressableState();
}

class _PressableState extends State<Pressable> {
  bool _down = false;

  void _set(bool v) {
    if (widget.onTap != null && _down != v) setState(() => _down = v);
  }

  @override
  Widget build(BuildContext context) {
    return Listener(
      onPointerDown: (_) => _set(true),
      onPointerUp: (_) => _set(false),
      onPointerCancel: (_) => _set(false),
      child: AnimatedScale(scale: _down ? widget.scale : 1, duration: const Duration(milliseconds: 140), curve: Curves.easeOut, child: widget.child),
    );
  }
}

/// Sacude horizontalmente al cambiar [trigger] (p. ej. contraseña incorrecta).
class Shake extends StatefulWidget {
  const Shake({super.key, required this.trigger, required this.child});

  final int trigger;
  final Widget child;

  @override
  State<Shake> createState() => _ShakeState();
}

class _ShakeState extends State<Shake> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 520));

  @override
  void didUpdateWidget(Shake old) {
    super.didUpdateWidget(old);
    if (old.trigger != widget.trigger && !reduceMotion(context)) _c.forward(from: 0);
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
      builder: (_, child) => Transform.translate(offset: Offset(math.sin(_c.value * math.pi * 6) * 10 * (1 - _c.value), 0), child: child),
      child: widget.child,
    );
  }
}

/// Destello que recorre los bloques grises mientras carga la pantalla.
class Shimmer extends StatefulWidget {
  const Shimmer({super.key, required this.child});

  final Widget child;

  @override
  State<Shimmer> createState() => _ShimmerState();
}

class _ShimmerState extends State<Shimmer> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      child: widget.child,
      builder: (_, child) => ShaderMask(
        blendMode: BlendMode.srcATop,
        shaderCallback: (rect) {
          final dx = rect.width * (_c.value * 2.2 - 1.1);
          return LinearGradient(
            colors: const [Color(0xFFE6ECF4), Color(0xFFF7F9FC), Color(0xFFE6ECF4)],
            stops: const [.35, .5, .65],
            transform: _Slide(dx),
          ).createShader(rect);
        },
        child: child,
      ),
    );
  }
}

class _Slide extends GradientTransform {
  const _Slide(this.dx);

  final double dx;

  @override
  Matrix4? transform(Rect bounds, {TextDirection? textDirection}) => Matrix4.translationValues(dx, 0, 0);
}

/// Esqueleto de carga: encabezado y tarjetas con destello.
class SkeletonView extends StatelessWidget {
  const SkeletonView({super.key});

  @override
  Widget build(BuildContext context) {
    Widget box(double h, {double? w, double r = 16}) => Container(
      height: h,
      width: w,
      decoration: BoxDecoration(color: const Color(0xFFE6ECF4), borderRadius: BorderRadius.circular(r)),
    );

    return Shimmer(
      child: ListView(
        physics: const NeverScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        children: [
          const SizedBox(height: 8),
          box(14, w: 120, r: 7),
          const SizedBox(height: 10),
          box(26, w: 200, r: 9),
          const SizedBox(height: 22),
          Row(
            children: [
              Expanded(child: box(64)),
              const SizedBox(width: 10),
              Expanded(child: box(64)),
              const SizedBox(width: 10),
              Expanded(child: box(64)),
            ],
          ),
          const SizedBox(height: 18),
          box(120),
          const SizedBox(height: 14),
          box(84),
          const SizedBox(height: 14),
          box(84),
          const SizedBox(height: 14),
          box(84),
        ],
      ),
    );
  }
}
