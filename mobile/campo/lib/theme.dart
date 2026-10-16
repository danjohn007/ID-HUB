import 'package:flutter/material.dart';

/// Paleta del prototipo de la app del técnico: azul de marca sobre navy.
class AppColors {
  static const navy = Color(0xFF0B1B36);
  static const navy2 = Color(0xFF13284D);
  static const surface = Color(0xFFF3F6FB);
  static const success = Color(0xFF16A34A);
  static const danger = Color(0xFFDC2626);
  static const warning = Color(0xFFF59E0B);
  static const info = Color(0xFF0EA5E9);
  static const purple = Color(0xFF7C3AED);
  static const teal = Color(0xFF0D9488);
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

ThemeData buildTheme(Color brand) {
  final scheme = ColorScheme.fromSeed(seedColor: brand, primary: brand, surface: Colors.white);
  final base = ThemeData(useMaterial3: true, colorScheme: scheme, scaffoldBackgroundColor: AppColors.surface);

  return base.copyWith(
    appBarTheme: const AppBarTheme(
      backgroundColor: AppColors.navy,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: false,
      titleTextStyle: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: Colors.white),
    ),
    cardTheme: CardThemeData(
      color: Colors.white,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Color(0xFFE2E8F0))),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size(48, 50),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        minimumSize: const Size(48, 50),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFCBD5E1))),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFCBD5E1))),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: Colors.white,
      indicatorColor: brand.withValues(alpha: .12),
      labelTextStyle: WidgetStateProperty.resolveWith((s) => TextStyle(
            fontSize: 11,
            fontWeight: s.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
            color: s.contains(WidgetState.selected) ? brand : AppColors.slate,
          )),
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(color: s.contains(WidgetState.selected) ? brand : AppColors.slate)),
    ),
    tabBarTheme: TabBarThemeData(
      labelColor: brand,
      unselectedLabelColor: AppColors.slate,
      indicatorColor: brand,
      labelStyle: const TextStyle(fontWeight: FontWeight.w700),
    ),
    snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
  );
}
