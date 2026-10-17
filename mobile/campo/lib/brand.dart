import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'theme.dart';

/// Isotipo ID-Hub (ondas de señal sobre "iD") en vector, para animarlo trazo por trazo.
/// Cada parte va de 0 (oculta) a 1 (completa). Coordenadas del diseño original: 512 × 518.
class IdHubMark extends StatelessWidget {
  const IdHubMark({super.key, required this.size, this.letters = 1, this.inner = 1, this.outer = 1, this.shine = 0, this.glow = 0});

  final double size;
  final double letters;
  final double inner;
  final double outer;

  /// Destello diagonal que cruza el cuadro (0 → 1).
  final double shine;

  /// Halo azul alrededor del cuadro (0 → 1).
  final double glow;

  static const aspect = 518 / 512;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size * aspect,
      child: CustomPaint(
        painter: _MarkPainter(letters: letters, inner: inner, outer: outer, shine: shine, glow: glow),
      ),
    );
  }
}

class _MarkPainter extends CustomPainter {
  _MarkPainter({required this.letters, required this.inner, required this.outer, required this.shine, required this.glow});

  final double letters, inner, outer, shine, glow;

  static final _tile = RRect.fromRectAndRadius(const Rect.fromLTWH(0, 0, 512, 518), const Radius.circular(122));

  static final Path _i = Path()
    ..moveTo(145, 396)
    ..lineTo(145, 274);

  static final Path _d = Path()
    ..moveTo(215, 350)
    ..lineTo(215, 273)
    ..lineTo(310, 273)
    ..arcToPoint(const Offset(310, 402), radius: const Radius.circular(64.5))
    ..lineTo(214, 402);

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.scale(size.width / 512);

    if (glow > 0) {
      canvas.drawRRect(
        _tile.inflate(10),
        Paint()
          ..color = AppColors.brand500.withValues(alpha: .55 * glow)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 60),
      );
    }

    canvas.drawRRect(
      _tile,
      Paint()
        ..shader = ui.Gradient.linear(
          const Offset(0, 0),
          const Offset(512, 518),
          [const Color(0xFF1C74F2), AppColors.mark, const Color(0xFF0153CF)],
          [0, .55, 1],
        ),
    );

    if (shine > 0 && shine < 1) {
      canvas.save();
      canvas.clipRRect(_tile);
      final x = -300 + 1100 * shine;
      canvas.drawRect(
        const Rect.fromLTWH(0, 0, 512, 518),
        Paint()
          ..shader = ui.Gradient.linear(
            Offset(x - 120, 0),
            Offset(x + 120, 200),
            [Colors.white.withValues(alpha: 0), Colors.white.withValues(alpha: .28), Colors.white.withValues(alpha: 0)],
            [0, .5, 1],
          ),
      );
      canvas.restore();
    }

    final stroke = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..strokeWidth = 29;

    if (letters > 0) {
      _drawPartial(canvas, _i, (letters * 1.6).clamp(0, 1), stroke);
      _drawPartial(canvas, _d, ((letters - .15) / .85).clamp(0, 1), stroke);
    }
    // Ondas: crecen del centro hacia los lados, como señal que se conecta.
    _arc(canvas, const Offset(256, 267), 84, -141.8, 103.6, inner, stroke..strokeWidth = 28);
    _arc(canvas, const Offset(256, 281), 160.6, -138.9, 97.8, outer, stroke..strokeWidth = 30);

    canvas.restore();
  }

  static void _drawPartial(Canvas canvas, Path path, double t, Paint paint) {
    if (t <= 0) return;
    if (t >= 1) {
      canvas.drawPath(path, paint);
      return;
    }
    final out = Path();
    var remaining = t * path.computeMetrics().fold<double>(0, (s, m) => s + m.length);
    for (final m in path.computeMetrics()) {
      if (remaining <= 0) break;
      out.addPath(m.extractPath(0, math.min(remaining, m.length)), Offset.zero);
      remaining -= m.length;
    }
    canvas.drawPath(out, paint);
  }

  static void _arc(Canvas canvas, Offset c, double r, double startDeg, double sweepDeg, double t, Paint paint) {
    if (t <= 0) return;
    final mid = (startDeg + sweepDeg / 2) * math.pi / 180;
    final sweep = sweepDeg * t * math.pi / 180;
    canvas.drawArc(Rect.fromCircle(center: c, radius: r), mid - sweep / 2, sweep, false, paint);
  }

  @override
  bool shouldRepaint(_MarkPainter old) => old.letters != letters || old.inner != inner || old.outer != outer || old.shine != shine || old.glow != glow;
}

/// Logotipo completo: isotipo + "ID HUB" debajo. [wordmark] (0 → 1) anima la palabra.
class BrandLockup extends StatelessWidget {
  const BrandLockup({super.key, this.width = 120, this.letters = 1, this.inner = 1, this.outer = 1, this.shine = 0, this.glow = 0, this.wordmark = 1});

  final double width;
  final double letters, inner, outer, shine, glow, wordmark;

  /// Alto total para un ancho dado (cuadro + espacio + palabra).
  static double heightFor(double width) => width * IdHubMark.aspect + width * .16 + width * .42;

  @override
  Widget build(BuildContext context) {
    final wordH = width * .42;
    return SizedBox(
      width: width,
      height: heightFor(width),
      child: Column(
        children: [
          IdHubMark(size: width, letters: letters, inner: inner, outer: outer, shine: shine, glow: glow),
          SizedBox(height: width * .16),
          SizedBox(
            height: wordH,
            child: ClipRect(
              child: Center(
                child: Opacity(
                  opacity: wordmark.clamp(0, 1),
                  child: Transform.translate(
                    offset: Offset(0, wordH * .6 * (1 - wordmark)),
                    child: Image.asset('assets/brand/wordmark_dark.png', height: wordH, semanticLabel: 'ID-Hub'),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Fondo de marca (como el panel del login web): navy profundo, brillos azul y turquesa,
/// retícula que se desvanece y ondas de señal saliendo de [focus].
class BrandBackdrop extends StatefulWidget {
  const BrandBackdrop({super.key, this.focus, this.ringRadius = 220, this.rings = 1, this.child});

  /// Origen de las ondas (en píxeles dentro del fondo). Nulo = 38 % de la altura.
  final Offset? focus;
  final double ringRadius;

  /// Intensidad de las ondas (0 → 1).
  final double rings;
  final Widget? child;

  @override
  State<BrandBackdrop> createState() => _BrandBackdropState();
}

class _BrandBackdropState extends State<BrandBackdrop> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(seconds: 18))..repeat();

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) {
      _c.stop();
    } else if (!_c.isAnimating) {
      _c.repeat();
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: _BackdropPainter(_c, focus: widget.focus, ringRadius: widget.ringRadius, rings: widget.rings),
      child: widget.child ?? const SizedBox.expand(),
    );
  }
}

class _BackdropPainter extends CustomPainter {
  _BackdropPainter(this.t, {this.focus, required this.ringRadius, required this.rings}) : super(repaint: t);

  final Animation<double> t;
  final Offset? focus;
  final double ringRadius;
  final double rings;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final v = t.value * 2 * math.pi;

    canvas.drawRect(rect, Paint()..shader = ui.Gradient.linear(rect.topCenter, rect.bottomCenter, const [AppColors.navy950, AppColors.navy]));

    // Brillos que flotan despacio
    void glow(Offset c, double r, Color color) => canvas.drawCircle(c, r, Paint()..shader = ui.Gradient.radial(c, r, [color, color.withValues(alpha: 0)]));
    glow(Offset(size.width * (.95 + .05 * math.sin(v)), size.height * (.04 + .03 * math.cos(v))), size.width * .95, AppColors.brand600.withValues(alpha: .38));
    glow(Offset(size.width * (.02 + .05 * math.cos(v)), size.height * (.98 + .02 * math.sin(v))), size.width * .9, AppColors.teal.withValues(alpha: .22));

    final f = focus ?? Offset(size.width / 2, size.height * .38);

    // Retícula de 44 px que se desvanece desde el centro
    final grid = Paint()
      ..strokeWidth = 1
      ..shader = ui.Gradient.radial(f, size.longestSide * .55, [Colors.white.withValues(alpha: .07), Colors.white.withValues(alpha: 0)]);
    for (double x = (f.dx % 44); x < size.width; x += 44) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), grid);
    }
    for (double y = (f.dy % 44); y < size.height; y += 44) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), grid);
    }

    // Ondas de señal (tres, desfasadas), igual que la animación "signal" de la web
    if (rings > 0) {
      for (var k = 0; k < 3; k++) {
        final p = (t.value * 5 + k / 3) % 1; // un ciclo cada 3.6 s
        final scale = .4 + 1.2 * Curves.easeOutCubic.transform(p);
        canvas.drawCircle(
          f,
          ringRadius * scale,
          Paint()
            ..style = PaintingStyle.stroke
            ..strokeWidth = 1.4
            ..color = AppColors.brand400.withValues(alpha: .45 * (1 - p) * rings),
        );
      }
    }
  }

  @override
  bool shouldRepaint(_BackdropPainter old) => old.focus != focus || old.rings != rings || old.ringRadius != ringRadius;
}

/// Decoración de los encabezados navy: degradado, brillo de marca y arcos de señal en la esquina.
class SignalPanel extends StatelessWidget {
  const SignalPanel({super.key, required this.child, this.radius = 28, this.padding = EdgeInsets.zero});

  final Widget child;
  final double radius;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final brand = Theme.of(context).colorScheme.primary;
    return ClipRRect(
      borderRadius: BorderRadius.vertical(bottom: Radius.circular(radius)),
      child: CustomPaint(
        painter: _PanelPainter(brand),
        child: Padding(padding: padding, child: child),
      ),
    );
  }
}

class _PanelPainter extends CustomPainter {
  _PanelPainter(this.brand);

  final Color brand;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    canvas.drawRect(
      rect,
      Paint()..shader = ui.Gradient.linear(rect.topLeft, rect.bottomRight, const [AppColors.navy950, AppColors.navy, AppColors.navy2], [0, .55, 1]),
    );

    final g = Offset(size.width * .92, -size.height * .1);
    canvas.drawCircle(g, size.width * .7, Paint()..shader = ui.Gradient.radial(g, size.width * .7, [brand.withValues(alpha: .42), brand.withValues(alpha: 0)]));

    // Arcos de señal decorativos (eco del isotipo)
    final c = Offset(size.width - 34, size.height + 6);
    final arc = Paint()
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..strokeWidth = 10;
    for (final (i, r) in [70.0, 120.0, 170.0].indexed) {
      arc.color = Colors.white.withValues(alpha: .07 - i * .018);
      canvas.drawArc(Rect.fromCircle(center: c, radius: r), -math.pi * .92, math.pi * .5, false, arc);
    }
  }

  @override
  bool shouldRepaint(_PanelPainter old) => old.brand != brand;
}
