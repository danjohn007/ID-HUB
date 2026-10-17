import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../brand.dart';
import '../core/api.dart';
import '../core/session.dart';
import '../motion.dart';
import '../theme.dart';

/// 1. Inicio / Login: usuario y contraseña del técnico o cobrador.
///
/// Con [intro] la pantalla abre con la animación de marca: el isotipo se dibuja trazo por trazo
/// en el centro, las ondas de señal "se conectan" y el logo sube a su lugar mientras aparece
/// el formulario. Si ya había sesión guardada, al terminar se llama [onIntroDone] y se entra directo.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, this.intro = false, this.onIntroDone});

  final bool intro;
  final VoidCallback? onIntroDone;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> with TickerProviderStateMixin {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _hide = true;
  String? _error;
  int _shakes = 0;

  /// Dibujo del logo en el centro (splash).
  late final _intro = AnimationController(vsync: this, duration: const Duration(milliseconds: 2300));

  /// El logo sube y aparece el formulario.
  late final _reveal = AnimationController(vsync: this, duration: const Duration(milliseconds: 1150));

  final _stackKey = GlobalKey();
  final _brandKey = GlobalKey();
  Rect? _target;
  late bool _overlay = widget.intro;
  bool _waiting = false;
  bool _started = false;

  // Tiempos del splash (fracciones de _intro)
  static const _tileIn = Interval(0, .26, curve: Curves.easeOutBack);
  static const _tileFade = Interval(0, .12);
  static const _letters = Interval(.14, .52, curve: Curves.easeInOutCubic);
  static const _innerArc = Interval(.46, .66, curve: Curves.easeOutCubic);
  static const _outerArc = Interval(.58, .80, curve: Curves.easeOutCubic);
  static const _glowIn = Interval(.55, .85, curve: Curves.easeOut);
  static const _wordIn = Interval(.62, .90, curve: Curves.easeOutCubic);
  static const _shineIn = Interval(.80, 1, curve: Curves.easeInOut);
  static const _taglineIn = Interval(.84, 1, curve: Curves.easeOut);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (!widget.intro || reduceMotion(context)) {
      _intro.value = 1;
      _overlay = false;
      if (widget.intro) {
        WidgetsBinding.instance.addPostFrameCallback((_) => _afterIntro());
      } else {
        _reveal.forward();
      }
    } else {
      _intro.forward().whenComplete(_afterIntro);
    }
    WidgetsBinding.instance.addPostFrameCallback((_) => _measure());
  }

  @override
  void dispose() {
    _listening?.removeListener(_onSession);
    _intro.dispose();
    _reveal.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  void _measure() {
    final b = _brandKey.currentContext?.findRenderObject() as RenderBox?;
    final s = _stackKey.currentContext?.findRenderObject() as RenderBox?;
    if (!mounted || b == null || s == null || !b.hasSize) return;
    final r = b.localToGlobal(Offset.zero, ancestor: s) & b.size;
    if (r != _target) setState(() => _target = r);
  }

  /// Terminó el dibujo: espera a que cargue la sesión guardada y decide.
  void _afterIntro() {
    if (!mounted) return;
    final session = context.read<Session>();
    if (session.restoring) {
      setState(() => _waiting = true);
      _listening = session..addListener(_onSession);
      return;
    }
    _finishIntro();
  }

  /// Sesión que se está esperando (para quitar el listener al terminar o al cerrar la pantalla).
  Session? _listening;

  void _onSession() {
    if (_listening?.restoring ?? true) return;
    _listening!.removeListener(_onSession);
    _listening = null;
    _finishIntro();
  }

  void _finishIntro() {
    if (!mounted) return;
    setState(() => _waiting = false);
    if (context.read<Session>().loggedIn) {
      widget.onIntroDone?.call(); // la raíz entra a la app con un fundido
      return;
    }
    _measure();
    _reveal.forward().whenComplete(() {
      if (mounted) setState(() => _overlay = false);
    });
    widget.onIntroDone?.call();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    if (!_form.currentState!.validate()) {
      setState(() => _shakes++);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<Session>().login(_email.text, _password.text);
      HapticFeedback.mediumImpact();
    } on ApiException catch (e) {
      _fail(e.detail);
    } catch (e) {
      _fail('$e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _fail(String message) {
    if (!mounted) return;
    HapticFeedback.heavyImpact();
    setState(() {
      _error = message;
      _shakes++;
    });
  }

  Future<void> _editServer() async {
    final session = context.read<Session>();
    final ctrl = TextEditingController(text: session.server);
    final value = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Servidor'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Dirección del panel de tu empresa (te la da el administrador).', style: TextStyle(color: AppColors.slate)),
            const SizedBox(height: 12),
            TextField(
              controller: ctrl,
              keyboardType: TextInputType.url,
              autocorrect: false,
              decoration: const InputDecoration(hintText: 'https://midominio.com'),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text), child: const Text('Guardar')),
        ],
      ),
    );
    if (value != null) await session.setServer(value);
  }

  /// Aparición escalonada de los bloques del formulario durante _reveal.
  Widget _stagger(int i, Widget child) {
    final a = CurvedAnimation(
      parent: _reveal,
      curve: Interval((.32 + i * .12).clamp(0, .9), (.78 + i * .1).clamp(0, 1), curve: Curves.easeOutCubic),
    );
    return AnimatedBuilder(
      animation: a,
      builder: (_, c) => Opacity(
        opacity: a.value,
        child: Transform.translate(offset: Offset(0, 40 * (1 - a.value)), child: c),
      ),
      child: child,
    );
  }

  static const _lockupW = 92.0;
  static const _splashW = 150.0;

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: lightStatusBar,
      child: Scaffold(
        backgroundColor: AppColors.navy950,
        body: LayoutBuilder(
          builder: (context, box) {
            final splashRect = Rect.fromCenter(center: Offset(box.maxWidth / 2, box.maxHeight * .44), width: _splashW, height: BrandLockup.heightFor(_splashW));
            // El teclado o un giro cambian el lugar del logo: se vuelve a medir.
            WidgetsBinding.instance.addPostFrameCallback((_) => _measure());

            return Stack(
              key: _stackKey,
              children: [
                Positioned.fill(
                  child: AnimatedBuilder(
                    animation: Listenable.merge([_intro, _reveal]),
                    builder: (_, _) {
                      final r = _lockupRect(splashRect);
                      return BrandBackdrop(
                        focus: Offset(r.center.dx, r.top + r.width * IdHubMark.aspect / 2),
                        ringRadius: 120 + 110 * (1 - Motion.emphasized.transform(_reveal.value)),
                        rings: _glowIn.transform(_intro.value),
                      );
                    },
                  ),
                ),
                SafeArea(child: _content(box)),
                if (_overlay) ..._splashLayer(splashRect),
              ],
            );
          },
        ),
      ),
    );
  }

  Rect _lockupRect(Rect splash) {
    if (!_overlay) return _target ?? splash;
    return Rect.lerp(splash, _target ?? splash, Motion.emphasized.transform(_reveal.value))!;
  }

  List<Widget> _splashLayer(Rect splashRect) {
    return [
      AnimatedBuilder(
        animation: Listenable.merge([_intro, _reveal]),
        builder: (_, _) {
          final t = _intro.value;
          final r = _lockupRect(splashRect);
          final fadeGlow = 1 - _reveal.value;
          return Positioned.fromRect(
            rect: r,
            child: IgnorePointer(
              child: Opacity(
                opacity: _tileFade.transform(t),
                child: Transform.scale(
                  scale: .55 + .45 * _tileIn.transform(t),
                  child: FittedBox(
                    child: BrandLockup(
                      width: _splashW,
                      letters: _letters.transform(t),
                      inner: _innerArc.transform(t),
                      outer: _outerArc.transform(t),
                      glow: _glowIn.transform(t) * fadeGlow,
                      shine: _shineIn.transform(t),
                      wordmark: _wordIn.transform(t),
                    ),
                  ),
                ),
              ),
            ),
          );
        },
      ),
      // Lema y espera (sólo durante el splash)
      AnimatedBuilder(
        animation: Listenable.merge([_intro, _reveal]),
        builder: (_, _) {
          final o = _taglineIn.transform(_intro.value) * (1 - (_reveal.value * 3).clamp(0, 1));
          return Positioned(
            left: 0,
            right: 0,
            top: splashRect.bottom + 22,
            child: IgnorePointer(
              child: Opacity(
                opacity: o,
                child: Column(
                  children: [
                    const Text(
                      'TU WISP BAJO CONTROL',
                      style: TextStyle(color: AppColors.navy200, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 3.2),
                    ),
                    const SizedBox(height: 18),
                    AnimatedOpacity(
                      opacity: _waiting ? 1 : 0,
                      duration: Motion.medium,
                      child: SizedBox(
                        width: 110,
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(4),
                          child: const LinearProgressIndicator(minHeight: 3, color: AppColors.brand400, backgroundColor: Color(0x22FFFFFF)),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    ];
  }

  Widget _content(BoxConstraints box) {
    final session = context.watch<Session>();
    final brand = Theme.of(context).colorScheme.primary;

    return AnimatedBuilder(
      animation: _reveal,
      builder: (_, child) => IgnorePointer(ignoring: _reveal.value < .4, child: child),
      child: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(22, 20, 22, 20),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              children: [
                // Lugar del logo: lo ocupa la capa del splash hasta que termina de subir.
                SizedBox(
                  key: _brandKey,
                  width: _lockupW,
                  height: BrandLockup.heightFor(_lockupW),
                  child: _overlay ? null : _stagger(-2, const BrandLockup(width: _lockupW)),
                ),
                const SizedBox(height: 14),
                _stagger(
                  0,
                  const Column(
                    children: [
                      Text(
                        'APP DE CAMPO',
                        style: TextStyle(color: AppColors.brand400, fontSize: 11.5, fontWeight: FontWeight.w800, letterSpacing: 2.6),
                      ),
                      SizedBox(height: 4),
                      Text('Técnicos y cobradores en acción', style: TextStyle(color: AppColors.navy200, fontSize: 14)),
                    ],
                  ),
                ),
                const SizedBox(height: 26),
                _stagger(1, Shake(trigger: _shakes, child: _card(session, brand))),
                const SizedBox(height: 18),
                _stagger(2, _ServerChip(host: Uri.tryParse(session.server)?.host ?? session.server, onTap: _editServer)),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _card(Session session, Color brand) {
    InputDecoration deco(String label, IconData icon, {Widget? suffix}) => InputDecoration(
      labelText: label,
      prefixIcon: Icon(icon, color: AppColors.slate),
      suffixIcon: suffix,
      fillColor: AppColors.surface,
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: Colors.transparent),
      ),
    );

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(26),
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .35), blurRadius: 40, offset: const Offset(0, 18))],
      ),
      padding: const EdgeInsets.fromLTRB(20, 22, 20, 18),
      child: Form(
        key: _form,
        child: AutofillGroup(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Inicia sesión',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: -.4, color: AppColors.navy),
              ),
              const SizedBox(height: 4),
              const Text('Con el correo y la contraseña que te dio tu empresa.', style: TextStyle(color: AppColors.slate, fontSize: 13.5)),
              const SizedBox(height: 18),
              AnimatedSize(
                duration: Motion.fast,
                curve: Motion.curve,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (session.expiredMessage != null && _error == null)
                      _Alert(session.expiredMessage!, color: AppColors.warning, icon: Icons.schedule_rounded),
                    if (_error != null) _Alert(_error!, color: AppColors.danger, icon: Icons.error_outline_rounded),
                  ],
                ),
              ),
              TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                textInputAction: TextInputAction.next,
                autofillHints: const [AutofillHints.email],
                decoration: deco('Correo', Icons.alternate_email_rounded),
                validator: (v) => v == null || !v.contains('@') ? 'Escribe tu correo' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _password,
                obscureText: _hide,
                textInputAction: TextInputAction.done,
                autofillHints: const [AutofillHints.password],
                onFieldSubmitted: (_) => _submit(),
                decoration: deco(
                  'Contraseña',
                  Icons.lock_outline_rounded,
                  suffix: IconButton(
                    icon: AnimatedSwitcher(
                      duration: Motion.fast,
                      transitionBuilder: (c, a) => ScaleTransition(scale: a, child: c),
                      child: Icon(_hide ? Icons.visibility_outlined : Icons.visibility_off_outlined, key: ValueKey(_hide), color: AppColors.slate),
                    ),
                    onPressed: () => setState(() => _hide = !_hide),
                  ),
                ),
                validator: (v) => v == null || v.isEmpty ? 'Escribe tu contraseña' : null,
              ),
              const SizedBox(height: 20),
              GlowButton(label: 'Entrar', icon: Icons.arrow_forward_rounded, busy: _busy, color: brand, onPressed: _submit),
              const SizedBox(height: 12),
              const Text(
                '¿Olvidaste tu contraseña? Pídele al administrador que la restablezca desde el panel.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12, color: AppColors.slate, height: 1.35),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Botón principal con degradado y halo de marca.
class GlowButton extends StatelessWidget {
  const GlowButton({super.key, required this.label, required this.onPressed, required this.color, this.icon, this.busy = false});

  final String label;
  final IconData? icon;
  final bool busy;
  final Color color;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final light = Color.lerp(color, Colors.white, .18)!;
    final enabled = onPressed != null && !busy;
    return Pressable(
      onTap: enabled ? onPressed : null,
      child: AnimatedContainer(
        duration: Motion.fast,
        height: 54,
        decoration: BoxDecoration(
          gradient: LinearGradient(colors: [color, light], begin: Alignment.centerLeft, end: Alignment.centerRight),
          borderRadius: BorderRadius.circular(15),
          boxShadow: [
            BoxShadow(
              color: color.withValues(alpha: busy ? .15 : .42),
              blurRadius: 22,
              offset: const Offset(0, 10),
            ),
          ],
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            borderRadius: BorderRadius.circular(15),
            onTap: enabled ? onPressed : null,
            child: Center(
              child: AnimatedSwitcher(
                duration: Motion.fast,
                child: busy
                    ? const SizedBox(
                        key: ValueKey('busy'),
                        height: 22,
                        width: 22,
                        child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                      )
                    : Row(
                        key: const ValueKey('idle'),
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            label,
                            style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w800, letterSpacing: .2),
                          ),
                          if (icon != null) ...[const SizedBox(width: 8), Icon(icon, color: Colors.white, size: 20)],
                        ],
                      ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ServerChip extends StatelessWidget {
  const _ServerChip({required this.host, required this.onTap});

  final String host;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white.withValues(alpha: .07),
      shape: StadiumBorder(side: BorderSide(color: Colors.white.withValues(alpha: .12))),
      child: InkWell(
        customBorder: const StadiumBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const _LiveDot(),
              const SizedBox(width: 9),
              Flexible(
                child: Text(
                  host,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: AppColors.navy200, fontSize: 12.5, fontWeight: FontWeight.w600),
                ),
              ),
              const SizedBox(width: 6),
              const Icon(Icons.edit_outlined, size: 14, color: AppColors.navy200),
            ],
          ),
        ),
      ),
    );
  }
}

/// Punto verde que late ("servidor").
class _LiveDot extends StatefulWidget {
  const _LiveDot();

  @override
  State<_LiveDot> createState() => _LiveDotState();
}

class _LiveDotState extends State<_LiveDot> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1600))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 14,
      height: 14,
      child: AnimatedBuilder(
        animation: _c,
        builder: (_, _) => Stack(
          alignment: Alignment.center,
          children: [
            Transform.scale(
              scale: 1 + _c.value * 1.4,
              child: Container(
                width: 7,
                height: 7,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: AppColors.success.withValues(alpha: .6 * (1 - _c.value)),
                ),
              ),
            ),
            Container(
              width: 7,
              height: 7,
              decoration: const BoxDecoration(shape: BoxShape.circle, color: AppColors.success),
            ),
          ],
        ),
      ),
    );
  }
}

class _Alert extends StatelessWidget {
  const _Alert(this.text, {required this.color, required this.icon});

  final String text;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .09),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: .25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: color, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              style: TextStyle(color: color, fontWeight: FontWeight.w600, fontSize: 13.5),
            ),
          ),
        ],
      ),
    );
  }
}
