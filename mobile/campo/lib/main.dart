import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:provider/provider.dart';

import 'config.dart';
import 'core/push.dart';
import 'core/session.dart';
import 'screens/login_screen.dart';
import 'screens/shell.dart';
import 'theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('es_MX');
  await Push.init();
  final session = Session();
  session.restore();
  runApp(ChangeNotifierProvider.value(value: session, child: const CampoApp()));
}

class CampoApp extends StatelessWidget {
  const CampoApp({super.key});

  @override
  Widget build(BuildContext context) {
    final brand = context.select<Session, Color>((s) => s.brand);

    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      scaffoldMessengerKey: Push.messengerKey,
      theme: buildTheme(brand),
      locale: const Locale('es', 'MX'),
      supportedLocales: const [Locale('es', 'MX'), Locale('es')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: const _Root(),
    );
  }
}

/// Al abrir: animación de marca (en la pantalla de login) y, según la sesión guardada,
/// formulario o directo a la app. Los cambios login ↔ app entran con un fundido.
class _Root extends StatefulWidget {
  const _Root();

  @override
  State<_Root> createState() => _RootState();
}

class _RootState extends State<_Root> {
  bool _introDone = false;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final inApp = _introDone && session.loggedIn;

    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 650),
      switchInCurve: Motion.curve,
      switchOutCurve: Curves.easeIn,
      transitionBuilder: (child, anim) => FadeTransition(
        opacity: anim,
        child: ScaleTransition(scale: Tween(begin: 1.04, end: 1.0).animate(anim), child: child),
      ),
      child: inApp
          // La clave reinicia la navegación al cambiar de usuario.
          ? Shell(key: ValueKey('shell-${session.user!.id}'))
          : LoginScreen(key: const ValueKey('login'), intro: !_introDone, onIntroDone: () => setState(() => _introDone = true)),
    );
  }
}
