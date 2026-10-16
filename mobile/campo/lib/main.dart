import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:provider/provider.dart';

import 'config.dart';
import 'core/session.dart';
import 'screens/login_screen.dart';
import 'screens/shell.dart';
import 'theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('es_MX');
  final session = Session();
  session.restore();
  runApp(ChangeNotifierProvider.value(value: session, child: const CampoApp()));
}

class CampoApp extends StatelessWidget {
  const CampoApp({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();

    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: buildTheme(session.brand),
      locale: const Locale('es', 'MX'),
      supportedLocales: const [Locale('es', 'MX'), Locale('es')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: session.restoring
          ? const _Splash()
          : session.loggedIn
              // La clave reinicia la navegación al cambiar de usuario.
              ? Shell(key: ValueKey('shell-${session.user!.id}'))
              : const LoginScreen(),
    );
  }
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: AppColors.navy,
      body: Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Image(image: AssetImage('assets/brand/logo_stacked_dark.png'), height: 130),
          SizedBox(height: 32),
          CircularProgressIndicator(color: Colors.white),
        ]),
      ),
    );
  }
}
