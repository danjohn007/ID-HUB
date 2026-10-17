import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Paleta de la identidad ID-Hub: los mismos tokens del panel web (resources/css/app.css).
class AppColors {
  // Navy (fondos oscuros, encabezados, barra lateral de la web)
  static const navy950 = Color(0xFF071330);
  static const navy = Color(0xFF0B1B3A); // navy-900
  static const navy2 = Color(0xFF122545); // navy-800
  static const navy700 = Color(0xFF1C3259);
  static const navy200 = Color(0xFFB9C8E0);

  // Azul de marca
  static const brand400 = Color(0xFF59A3FB);
  static const brand500 = Color(0xFF3390F8);
  static const brand600 = Color(0xFF1A7FF5);

  /// Azul del isotipo (cuadro del logo).
  static const mark = Color(0xFF015FEA);

  static const surface = Color(0xFFF4F7FB);
  static const border = Color(0xFFE2E8F0);
  static const success = Color(0xFF22A06B);
  static const danger = Color(0xFFE5484D);
  static const warning = Color(0xFFF7881F);
  static const info = Color(0xFF0EA5E9);
  static const purple = Color(0xFF6048D0);
  static const teal = Color(0xFF00A0A8);
  static const slate = Color(0xFF64748B);

  /// Colores semánticos de los catálogos del servidor (config/wisp.php).
  static Color semantic(String? name, {Color? brand}) => switch (name) {
    'success' => success,
    'danger' => danger,
    'warning' => warning,
    'info' => info,
    'purple' => purple,
    'teal' => teal,
    'navy' => navy,
    'brand' => brand ?? info,
    _ => slate,
  };
}

/// Duraciones y curvas comunes de las animaciones.
class Motion {
  static const fast = Duration(milliseconds: 220);
  static const medium = Duration(milliseconds: 420);
  static const slow = Duration(milliseconds: 700);
  static const curve = Curves.easeOutCubic;
  static const emphasized = Curves.easeInOutCubicEmphasized;
}

/// Barra de estado con iconos claros, para pantallas con fondo navy.
const lightStatusBar = SystemUiOverlayStyle(
  statusBarColor: Colors.transparent,
  statusBarIconBrightness: Brightness.light,
  statusBarBrightness: Brightness.dark,
);

ThemeData buildTheme(Color brand) {
  final scheme = ColorScheme.fromSeed(seedColor: brand, primary: brand, surface: Colors.white);
  final base = ThemeData(useMaterial3: true, colorScheme: scheme, scaffoldBackgroundColor: AppColors.surface, fontFamily: 'Inter');
  final text = base.textTheme.apply(bodyColor: const Color(0xFF1E293B), displayColor: AppColors.navy);

  return base.copyWith(
    textTheme: text.copyWith(
      headlineSmall: text.headlineSmall?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -.4),
      titleLarge: text.titleLarge?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -.3),
      titleMedium: text.titleMedium?.copyWith(fontWeight: FontWeight.w700),
    ),
    pageTransitionsTheme: const PageTransitionsTheme(
      builders: {
        TargetPlatform.android: FadeForwardsPageTransitionsBuilder(backgroundColor: AppColors.surface),
        TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
      },
    ),
    appBarTheme: const AppBarTheme(
      // Igual al arranque del degradado de los encabezados (SignalPanel) para que se vean continuos.
      backgroundColor: AppColors.navy950,
      foregroundColor: Colors.white,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      systemOverlayStyle: lightStatusBar,
      titleTextStyle: TextStyle(fontFamily: 'Inter', fontSize: 18, fontWeight: FontWeight.w800, letterSpacing: -.2, color: Colors.white),
    ),
    cardTheme: CardThemeData(
      color: Colors.white,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: AppColors.border.withValues(alpha: .8)),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size(48, 52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        textStyle: const TextStyle(fontFamily: 'Inter', fontSize: 15, fontWeight: FontWeight.w700),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        minimumSize: const Size(48, 52),
        side: const BorderSide(color: Color(0xFFCBD5E1)),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        textStyle: const TextStyle(fontFamily: 'Inter', fontSize: 15, fontWeight: FontWeight.w600),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        textStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w700),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: Color(0xFFCBD5E1)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: Color(0xFFCBD5E1)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: brand, width: 1.8),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 15),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: Colors.white,
      surfaceTintColor: Colors.transparent,
      shadowColor: AppColors.navy.withValues(alpha: .15),
      elevation: 8,
      indicatorColor: brand.withValues(alpha: .12),
      indicatorShape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      labelTextStyle: WidgetStateProperty.resolveWith(
        (s) => TextStyle(
          fontFamily: 'Inter',
          fontSize: 11,
          fontWeight: s.contains(WidgetState.selected) ? FontWeight.w800 : FontWeight.w500,
          color: s.contains(WidgetState.selected) ? brand : AppColors.slate,
        ),
      ),
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(color: s.contains(WidgetState.selected) ? brand : AppColors.slate)),
    ),
    tabBarTheme: TabBarThemeData(
      labelColor: brand,
      unselectedLabelColor: AppColors.slate,
      indicatorColor: brand,
      labelStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w700),
    ),
    progressIndicatorTheme: ProgressIndicatorThemeData(color: brand, linearTrackColor: AppColors.border),
    dialogTheme: DialogThemeData(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22))),
    bottomSheetTheme: const BottomSheetThemeData(
      showDragHandle: true,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      contentTextStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w600, color: Colors.white),
    ),
  );
}
